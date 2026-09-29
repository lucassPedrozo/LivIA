<?php
/**
 * Conversa com a API do Gemini.
 *
 * Porte de chamar_gemini() do livia.py, com o que faltava para produção:
 * prazo curto, tentativa de novo com recuo, erros distinguidos e resposta
 * truncada tratada em vez de entregue pela metade.
 *
 * São dois caminhos. O normal usa a camada HTTP do WordPress (wp_remote_post),
 * que respeita proxy e os filtros do site. O de streaming usa cURL puro, porque
 * wp_remote_post espera o corpo inteiro e não entrega pedaço por pedaço. Os dois
 * compartilham a leitura da resposta, para não existirem duas ideias de "o que a
 * API respondeu".
 */

defined( 'ABSPATH' ) || exit;

class Livia_Gemini {

	const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta';

	/**
	 * Prazo de UMA tentativa.
	 *
	 * Era 25, e a homologação mostrou por que isso estava errado: quatro falhas
	 * de conexão em exatamente 25,02 s — o prazo batendo, não o modelo demorando.
	 * Com 25, a primeira tentativa comia 25 dos 32 segundos do prazo total e a
	 * segunda nascia com sete: uma tentativa e meia, na prática.
	 *
	 * Com 15 cabem DUAS tentativas inteiras dentro do mesmo prazo total, e quem
	 * está esperando descobre em quinze segundos, não em vinte e cinco, que vai
	 * haver uma segunda tentativa.
	 *
	 * Quinze e não doze porque a p90 medida foi de 12,3 s — com a base inteira,
	 * que era o dobro do que vai hoje. Cortar para doze mataria resposta boa.
	 */
	const TIMEOUT = 15;

	/** Prazo total, somando tentativas e recuos. Segura o processo do servidor. */
	const PRAZO_TOTAL = 32;

	const TENTATIVAS = 3;

	/**
	 * 512, não os 1024 do protótipo. Resposta curta soa mais humana, chega
	 * antes e devolve o processo do PHP-FPM mais cedo — que é o gargalo de
	 * rodar isto dentro do WordPress.
	 */
	const MAX_TOKENS = 512;

	/** Baixa de propósito: queremos a resposta da base, não criatividade. */
	const TEMPERATURA = 0.2;

	/**
	 * O ID do modelo existe e a chave funciona?
	 *
	 * Um ID errado vira 404 em toda resposta, para todo cliente, sem aviso.
	 * Melhor descobrir na tela de configuração do que no primeiro atendimento.
	 *
	 * @return true|WP_Error
	 */
	public static function verificar_modelo( $modelo = null, $chave = null ) {
		$modelo = null === $modelo ? Livia_Config::modelo() : Livia_Config::normalizar_modelo( $modelo );
		$chave  = null === $chave ? Livia_Config::api_key() : trim( $chave );

		if ( '' === $chave ) {
			return new WP_Error( 'sem_chave', 'Falta a chave da API.' );
		}
		if ( '' === $modelo ) {
			return new WP_Error( 'sem_modelo', 'Falta o ID do modelo.' );
		}

		$resposta = wp_remote_get(
			self::ENDPOINT . '/models/' . rawurlencode( $modelo ),
			array(
				'timeout' => 10,
				'headers' => array( 'X-goog-api-key' => $chave ),
			)
		);

		if ( is_wp_error( $resposta ) ) {
			return new WP_Error( 'conexao', 'Não consegui falar com a API: ' . $resposta->get_error_message() );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resposta );

		if ( 200 === $codigo ) {
			return true;
		}
		if ( 404 === $codigo ) {
			return new WP_Error( 'modelo_inexistente', sprintf( 'O modelo "%s" não existe para esta chave.', $modelo ) );
		}
		if ( 400 === $codigo || 401 === $codigo || 403 === $codigo ) {
			return new WP_Error( 'chave_invalida', 'A chave foi recusada. Confira se ela está ativa e liberada para a API Generative Language.' );
		}
		if ( 429 === $codigo ) {
			return new WP_Error( 'cota', 'A chave é válida, mas a cota está esgotada agora. Tente daqui a pouco.' );
		}

		return new WP_Error( 'http', sprintf( 'A API respondeu HTTP %d.', $codigo ) );
	}

	/** Códigos que valem tentar de novo: são transitórios. */
	private static function transitorio( $codigo ) {
		return in_array( (int) $codigo, array( 429, 500, 502, 503, 504 ), true );
	}

	/**
	 * Monta o corpo da requisição. Público porque o caminho de streaming manda
	 * exatamente o mesmo corpo.
	 */
	public static function corpo( $instrucao, array $historico, array $opcoes = array() ) {
		$corpo = array(
			'contents'         => array_values( $historico ),
			'generationConfig' => array(
				'temperature'     => isset( $opcoes['temperatura'] ) ? (float) $opcoes['temperatura'] : self::TEMPERATURA,
				'topP'            => 0.8,
				'maxOutputTokens' => isset( $opcoes['max_tokens'] ) ? (int) $opcoes['max_tokens'] : self::MAX_TOKENS,
			),
		);

		// Com cache de contexto, a instrução já está guardada do lado da API e
		// mandá-la junto seria erro — os dois campos são mutuamente exclusivos.
		if ( ! empty( $opcoes['cache'] ) ) {
			$corpo['cachedContent'] = (string) $opcoes['cache'];
			return $corpo;
		}

		$corpo['system_instruction'] = array( 'parts' => array( array( 'text' => $instrucao ) ) );
		return $corpo;
	}

	/**
	 * Resolve o cache de contexto, se estiver ligado.
	 *
	 * Devolve as opções com a chave 'cache' preenchida, ou intactas. Falha em
	 * cachear nunca impede a resposta: o pior caso é gastar os tokens de sempre.
	 */
	private static function com_cache( $instrucao, array $opcoes ) {
		if ( isset( $opcoes['cache'] ) ) {
			return $opcoes;
		}
		$nome = Livia_Cache::nome( $instrucao, Livia_Config::modelo() );
		if ( $nome ) {
			$opcoes['cache'] = $nome;
		}
		return $opcoes;
	}

	/**
	 * Envia a conversa e devolve a resposta.
	 *
	 * @return array|WP_Error array com texto, uso, finish e truncado.
	 */
	public static function gerar( $instrucao, array $historico, array $opcoes = array() ) {
		// Atalho no estilo dos filtros "pre_" do WordPress: devolvendo algo
		// diferente de null, a chamada de rede não acontece. É o que permite
		// testar o fluxo inteiro do endpoint sem gastar cota — e serve também
		// para simular respostas em homologação.
		$curto = apply_filters( 'livia_pre_gerar', null, $instrucao, $historico, $opcoes );
		if ( null !== $curto ) {
			return $curto;
		}

		$chave = Livia_Config::api_key();

		if ( '' === $chave ) {
			return new WP_Error( 'sem_chave', 'A chave da API não está configurada.' );
		}

		Livia_Cache::registrar_uso();

		$limite = microtime( true ) + self::PRAZO_TOTAL;
		$cadeia = Livia_Modelos::cadeia();
		$ultimo = null;

		foreach ( $cadeia as $posicao => $modelo ) {
			$restam_modelos = $posicao < count( $cadeia ) - 1;

			$resultado = self::falar_com( $modelo, $instrucao, $historico, $opcoes, $chave, $limite, $restam_modelos );

			if ( ! is_wp_error( $resultado ) ) {
				$resultado['modelo'] = $modelo;
				return $resultado;
			}

			$ultimo = $resultado;

			// Cota estourada ou modelo que sumiu não melhoram tentando de novo:
			// o que resolve é trocar de modelo, e é o que a linha abaixo faz.
			if ( ! $restam_modelos || ! Livia_Modelos::vale_rebaixar( $resultado->get_error_code() ) ) {
				break;
			}

			Livia_Modelos::rebaixar( $resultado->get_error_code() );
		}

		return $ultimo ? $ultimo : new WP_Error( 'sem_tempo', 'A API não respondeu dentro do prazo.' );
	}

	/**
	 * Uma conversa com UM modelo, com as tentativas dele.
	 *
	 * $pode_trocar diz se existe outro modelo depois deste. Quando existe, um
	 * 429 volta na hora em vez de gastar o recuo exponencial: a troca resolve
	 * em milissegundos o que a insistência não resolveria em trinta segundos.
	 *
	 * @return array|WP_Error
	 */
	private static function falar_com( $modelo, $instrucao, array $historico, array $opcoes, $chave, $limite, $pode_trocar ) {
		// Mesmo atalho dos outros caminhos de rede, um nível abaixo do
		// livia_pre_gerar: aqui o filtro sabe COM QUAL MODELO está falando, que
		// é o que permite testar a troca para a reserva sem rede nenhuma.
		$curto = apply_filters( 'livia_pre_falar_com', null, $modelo, $instrucao, $historico );
		if ( null !== $curto ) {
			return $curto;
		}

		$opcoes = self::com_cache( $instrucao, $opcoes );
		$url    = self::ENDPOINT . '/models/' . rawurlencode( $modelo ) . ':generateContent';
		$corpo  = wp_json_encode( self::corpo( $instrucao, $historico, $opcoes ) );

		$ultimo = null;

		for ( $tentativa = 1; $tentativa <= self::TENTATIVAS; $tentativa++ ) {
			$restante = $limite - microtime( true );
			if ( $restante < 2 ) {
				break;
			}

			$resposta = wp_remote_post(
				$url,
				array(
					'timeout'     => (int) min( self::TIMEOUT, $restante ),
					'headers'     => array(
						'Content-Type'   => 'application/json',
						'X-goog-api-key' => $chave,
					),
					'body'        => $corpo,
					'data_format' => 'body',
				)
			);

			if ( is_wp_error( $resposta ) ) {
				$ultimo = new WP_Error( 'conexao', 'Não consegui falar com a API: ' . $resposta->get_error_message() );
			} else {
				$codigo = (int) wp_remote_retrieve_response_code( $resposta );

				if ( 200 === $codigo ) {
					return self::interpretar( wp_remote_retrieve_body( $resposta ) );
				}

				$ultimo = self::erro_http( $codigo, wp_remote_retrieve_body( $resposta ) );

				if ( ! self::transitorio( $codigo ) ) {
					return $ultimo;
				}

				// Há reserva esperando: devolve agora e deixa quem chamou trocar.
				if ( $pode_trocar && Livia_Modelos::vale_rebaixar( $ultimo->get_error_code() ) ) {
					return $ultimo;
				}
			}

			// Recuo exponencial com um tico de aleatório, para várias conversas
			// que tomaram 429 ao mesmo tempo não voltarem todas no mesmo instante.
			if ( $tentativa < self::TENTATIVAS ) {
				$espera = ( 0.4 * pow( 2, $tentativa - 1 ) ) + ( wp_rand( 0, 300 ) / 1000 );
				if ( microtime( true ) + $espera < $limite ) {
					usleep( (int) ( $espera * 1000000 ) );
				}
			}
		}

		return $ultimo ? $ultimo : new WP_Error( 'sem_tempo', 'A API não respondeu dentro do prazo.' );
	}

	/**
	 * Mesma conversa, entregue pedaço por pedaço.
	 *
	 * wp_remote_post não faz streaming — ele espera o corpo inteiro — então
	 * aqui é cURL puro com CURLOPT_WRITEFUNCTION. É o único lugar do plugin que
	 * fala HTTP fora da camada do WordPress, e é por isso que a leitura do SSE
	 * e a janela retida moram em classes próprias, testáveis sem rede.
	 *
	 * $ao_pedaco recebe cada trecho de texto e devolve false para abortar —
	 * é assim que a trava interrompe uma resposta no meio.
	 *
	 * @return array|WP_Error
	 */
	public static function gerar_stream( $instrucao, array $historico, callable $ao_pedaco, array $opcoes = array() ) {
		$curto = apply_filters( 'livia_pre_gerar_stream', null, $instrucao, $historico, $ao_pedaco, $opcoes );
		if ( null !== $curto ) {
			return $curto;
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error( 'sem_curl', 'A extensão cURL não está disponível.' );
		}

		$chave = Livia_Config::api_key();
		if ( '' === $chave ) {
			return new WP_Error( 'sem_chave', 'A chave da API não está configurada.' );
		}

		Livia_Cache::registrar_uso();

		$cadeia = Livia_Modelos::cadeia();
		$ultimo = null;

		foreach ( $cadeia as $posicao => $modelo ) {
			$resultado = self::stream_com( $modelo, $instrucao, $historico, $ao_pedaco, $opcoes, $chave );

			if ( ! is_wp_error( $resultado ) ) {
				$resultado['modelo'] = $modelo;
				return $resultado;
			}

			$ultimo = $resultado;

			if ( $posicao >= count( $cadeia ) - 1 || ! Livia_Modelos::vale_rebaixar( $resultado->get_error_code() ) ) {
				break;
			}

			// Seguro trocar e tentar de novo: cota e modelo inexistente são
			// recusas de HTTP, anteriores ao primeiro pedaço de texto. Nada foi
			// escrito na tela do cliente, então nada será escrito duas vezes.
			Livia_Modelos::rebaixar( $resultado->get_error_code() );
		}

		return $ultimo ? $ultimo : new WP_Error( 'sem_texto', 'A API não devolveu conteúdo.' );
	}

	/**
	 * Um stream com UM modelo.
	 *
	 * @return array|WP_Error
	 */
	private static function stream_com( $modelo, $instrucao, array $historico, callable $ao_pedaco, array $opcoes, $chave ) {
		$opcoes = self::com_cache( $instrucao, $opcoes );

		$url = self::ENDPOINT . '/models/' . rawurlencode( $modelo )
			. ':streamGenerateContent?alt=sse';

		$leitor    = new Livia_Sse();
		$uso       = array( 'entrada' => 0, 'saida' => 0, 'total' => 0 );
		$finish    = '';
		$bloqueio  = '';
		$texto     = '';
		$erro_http = '';
		$abortado  = false;

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode( self::corpo( $instrucao, $historico, $opcoes ) ),
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'Accept: text/event-stream',
					'X-goog-api-key: ' . $chave,
				),
				CURLOPT_TIMEOUT        => self::TIMEOUT,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_WRITEFUNCTION  => function ( $recurso, $bytes ) use (
					&$leitor, &$uso, &$finish, &$bloqueio, &$texto, &$erro_http, &$abortado, $ao_pedaco
				) {
					// A escrita também recebe o corpo de erro quando a API
					// responde 4xx/5xx. Aí não é SSE: é JSON de erro.
					$codigo = (int) curl_getinfo( $recurso, CURLINFO_RESPONSE_CODE );
					if ( $codigo && 200 !== $codigo ) {
						$erro_http .= $bytes;
						return strlen( $bytes );
					}

					foreach ( $leitor->receber( $bytes ) as $evento ) {
						$motivo = Livia_Sse::bloqueio_do_evento( $evento );
						if ( '' !== $motivo ) {
							$bloqueio = $motivo;
							$abortado = true;
							return 0;
						}

						$novo = Livia_Sse::texto_do_evento( $evento );
						if ( '' !== $novo ) {
							$texto .= $novo;
							if ( false === call_user_func( $ao_pedaco, $novo ) ) {
								$abortado = true;
								return 0; // devolver menos que o recebido aborta o cURL
							}
						}

						$u = Livia_Sse::uso_do_evento( $evento );
						if ( $u ) {
							$uso = $u;
						}
						$f = Livia_Sse::finish_do_evento( $evento );
						if ( '' !== $f ) {
							$finish = $f;
						}
					}

					return strlen( $bytes );
				},
			)
		);

		$ok     = curl_exec( $ch );
		$codigo = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$falha  = curl_error( $ch );
		curl_close( $ch );

		if ( '' !== $bloqueio ) {
			return new WP_Error( 'bloqueado_seguranca', 'A API barrou a resposta: ' . $bloqueio );
		}
		if ( $abortado ) {
			// Fomos nós que paramos o stream, de propósito.
			return array(
				'texto'    => $texto,
				'uso'      => $uso,
				'finish'   => $finish,
				'truncado' => false,
				'abortado' => true,
			);
		}
		if ( $codigo && 200 !== $codigo ) {
			return self::erro_http( $codigo, $erro_http );
		}
		if ( false === $ok && '' !== $falha ) {
			return new WP_Error( 'conexao', 'Não consegui falar com a API: ' . $falha );
		}
		if ( '' === trim( $texto ) ) {
			return new WP_Error( 'sem_texto', 'A API não devolveu conteúdo.' );
		}

		return array(
			'texto'    => $texto,
			'uso'      => $uso,
			'finish'   => $finish,
			'truncado' => ( 'MAX_TOKENS' === $finish ),
			'abortado' => false,
		);
	}

	private static function erro_http( $codigo, $corpo ) {
		$detalhe = '';
		$dados   = json_decode( (string) $corpo, true );
		if ( isset( $dados['error']['message'] ) ) {
			$detalhe = ' ' . $dados['error']['message'];
		}

		if ( 429 === $codigo ) {
			return new WP_Error( 'cota', 'A cota da API está esgotada.' . $detalhe );
		}
		if ( in_array( $codigo, array( 400, 401, 403 ), true ) ) {
			return new WP_Error( 'chave_invalida', 'A API recusou a requisição.' . $detalhe );
		}
		if ( $codigo >= 500 ) {
			return new WP_Error( 'indisponivel', 'A API está fora do ar.' . $detalhe );
		}
		return new WP_Error( 'http', sprintf( 'A API respondeu HTTP %d.%s', $codigo, $detalhe ) );
	}

	/**
	 * Lê a resposta inteira da API — o caminho sem streaming.
	 *
	 * @param string|array $json
	 * @return array|WP_Error
	 */
	public static function interpretar( $json ) {
		$dados = is_array( $json ) ? $json : json_decode( (string) $json, true );

		if ( ! is_array( $dados ) ) {
			return new WP_Error( 'resposta_ilegivel', 'A API devolveu algo que não é JSON.' );
		}

		$uso = array(
			'entrada' => isset( $dados['usageMetadata']['promptTokenCount'] ) ? (int) $dados['usageMetadata']['promptTokenCount'] : 0,
			'saida'   => isset( $dados['usageMetadata']['candidatesTokenCount'] ) ? (int) $dados['usageMetadata']['candidatesTokenCount'] : 0,
			'total'   => isset( $dados['usageMetadata']['totalTokenCount'] ) ? (int) $dados['usageMetadata']['totalTokenCount'] : 0,
		);

		$candidatos = isset( $dados['candidates'] ) && is_array( $dados['candidates'] ) ? $dados['candidates'] : array();

		if ( ! $candidatos ) {
			// A API barrou a PERGUNTA, antes de gerar qualquer coisa.
			$motivo = isset( $dados['promptFeedback']['blockReason'] ) ? $dados['promptFeedback']['blockReason'] : '';
			if ( '' !== $motivo ) {
				return new WP_Error( 'bloqueado_seguranca', 'A API barrou a pergunta: ' . $motivo );
			}
			return new WP_Error( 'sem_texto', 'A API não devolveu conteúdo.' );
		}

		$candidato = $candidatos[0];
		$finish    = isset( $candidato['finishReason'] ) ? $candidato['finishReason'] : '';

		if ( 'SAFETY' === $finish || 'PROHIBITED_CONTENT' === $finish ) {
			return new WP_Error( 'bloqueado_seguranca', 'A API barrou a resposta: ' . $finish );
		}

		$texto = '';
		if ( isset( $candidato['content']['parts'] ) && is_array( $candidato['content']['parts'] ) ) {
			foreach ( $candidato['content']['parts'] as $parte ) {
				if ( isset( $parte['text'] ) ) {
					$texto .= $parte['text'];
				}
			}
		}
		$texto = trim( $texto );

		if ( '' === $texto ) {
			return new WP_Error( 'sem_texto', sprintf( 'Resposta sem texto (finishReason: %s).', '' !== $finish ? $finish : '?' ) );
		}

		// Estourou o teto de tokens: o texto está cortado no meio da frase. O
		// protótipo entregava assim mesmo. Aqui, recuamos até a última frase
		// inteira — melhor uma resposta mais curta do que uma que para no meio.
		$truncado = ( 'MAX_TOKENS' === $finish );
		if ( $truncado ) {
			$inteiro = self::cortar_no_fim_da_frase( $texto );
			if ( '' === $inteiro ) {
				return new WP_Error( 'truncado', 'A resposta veio cortada e não sobrou nenhuma frase inteira.' );
			}
			$texto = $inteiro;
		}

		return array(
			'texto'    => $texto,
			'uso'      => $uso,
			'finish'   => $finish,
			'truncado' => $truncado,
		);
	}

	/** Recua até o último ponto final, para a resposta não parar no meio da frase. */
	public static function cortar_no_fim_da_frase( $texto ) {
		$texto = rtrim( $texto );
		$fim   = 0;
		foreach ( array( '.', '!', '?', "\n" ) as $marca ) {
			$pos = strrpos( $texto, $marca );
			if ( false !== $pos && $pos > $fim ) {
				$fim = $pos;
			}
		}
		return $fim > 0 ? rtrim( substr( $texto, 0, $fim + 1 ) ) : '';
	}

	/**
	 * O que o cliente lê quando deu errado.
	 *
	 * Cada motivo tem a sua frase. Dizer "a conexão falhou" quando a API recusou
	 * por segurança, ou quando a cota acabou, é mentir para o cliente — e some
	 * com a informação que faria alguém agir.
	 */
	public static function mensagem_para_cliente( $erro, $canal = '' ) {
		$codigo  = is_wp_error( $erro ) ? $erro->get_error_code() : '';
		$contato = '' !== trim( (string) $canal ) ? trim( $canal ) : 'a nossa equipe';

		if ( 'cota' === $codigo ) {
			return "Estou com muitas conversas ao mesmo tempo agora.\nEspere um minutinho e pergunte de novo.";
		}
		if ( 'bloqueado_seguranca' === $codigo ) {
			return "Essa eu não consegui responder.\n"
				. "Se for dúvida sobre o formulário, tenta me perguntar de outro jeito — "
				. "ou fale com {$contato}.";
		}
		if ( 'sem_chave' === $codigo || 'chave_invalida' === $codigo ) {
			return "Estou com um problema aqui do meu lado e não consigo responder agora.\n"
				. "Enquanto isso, {$contato} te atende normalmente.";
		}

		return "Não consegui responder agora — parece que a conexão falhou.\n"
			. 'Tente perguntar de novo daqui a pouco.';
	}
}
