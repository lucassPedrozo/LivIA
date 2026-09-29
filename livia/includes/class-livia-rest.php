<?php
/**
 * As rotas que o widget chama.
 *
 *   POST /wp-json/livia/v1/sessao     abre a conversa
 *   POST /wp-json/livia/v1/conversa   pergunta e resposta em streaming (SSE)
 *   POST /wp-json/livia/v1/mensagem   a mesma coisa, resposta inteira em JSON
 *
 * Rota pública, sem login: a defesa não é autenticação, é o teto de entrada, o
 * limite de ritmo e o disjuntor. O token devolvido por /sessao é só um
 * obstáculo — obriga quem quiser abusar a passar pela rota de sessão, que
 * também tem limite — e não substitui nenhuma das três.
 *
 * Os dois caminhos de resposta passam por preparar(): as guardas moram num
 * lugar só, para não acontecer de uma delas existir num caminho e faltar no
 * outro.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Rest {

	const NAMESPACE_API = 'livia/v1';

	/** Prazo do token de conversa. Passou disso, o widget abre sessão de novo. */
	const VALIDADE_TOKEN = 7200;

	public static function iniciar() {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar' ) );
	}

	public static function registrar() {
		$publico = '__return_true';

		register_rest_route(
			self::NAMESPACE_API,
			'/sessao',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'abrir_sessao' ),
				'permission_callback' => $publico,
			)
		);

		register_rest_route(
			self::NAMESPACE_API,
			'/mensagem',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'mensagem' ),
				'permission_callback' => $publico,
			)
		);

		register_rest_route(
			self::NAMESPACE_API,
			'/saude',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'saude' ),
				'permission_callback' => $publico,
			)
		);

		register_rest_route(
			self::NAMESPACE_API,
			'/opiniao',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'opiniao' ),
				'permission_callback' => $publico,
			)
		);

		register_rest_route(
			self::NAMESPACE_API,
			'/conversa',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'conversa' ),
				'permission_callback' => $publico,
			)
		);
	}

	// ------------------------------------------------------------------ token

	public static function assinar( $sessao, $expira ) {
		$carga      = $sessao . '|' . $expira;
		$assinatura = hash_hmac( 'sha256', $carga, wp_salt( 'livia' ) );
		return rtrim( strtr( base64_encode( $carga . '|' . $assinatura ), '+/', '-_' ), '=' );
	}

	/** @return string|WP_Error id da sessão */
	public static function conferir( $token ) {
		$cru = base64_decode( strtr( (string) $token, '-_', '+/' ), true );
		if ( false === $cru ) {
			return new WP_Error( 'token_invalido', 'Token ilegível.' );
		}

		$partes = explode( '|', $cru );
		if ( 3 !== count( $partes ) ) {
			return new WP_Error( 'token_invalido', 'Token malformado.' );
		}

		list( $sessao, $expira, $assinatura ) = $partes;

		$esperada = hash_hmac( 'sha256', $sessao . '|' . $expira, wp_salt( 'livia' ) );
		if ( ! hash_equals( $esperada, $assinatura ) ) {
			return new WP_Error( 'token_invalido', 'Assinatura não confere.' );
		}
		if ( (int) $expira < time() ) {
			return new WP_Error( 'token_expirado', 'A conversa expirou.' );
		}
		if ( ! Livia_Sessao::id_valido( $sessao ) ) {
			return new WP_Error( 'token_invalido', 'Sessão inválida.' );
		}

		return $sessao;
	}

	// ------------------------------------------------------------------ sessão

	public static function abrir_sessao( $req ) {
		$pode = Livia_Limites::pode_abrir_sessao();
		if ( is_wp_error( $pode ) ) {
			return new WP_REST_Response( array( 'erro' => $pode->get_error_code() ), 429 );
		}

		if ( ! Livia_Config::esta_configurado() ) {
			return new WP_REST_Response(
				array(
					'disponivel' => false,
					'motivo'     => 'nao_configurada',
				),
				200
			);
		}

		$sessao = Livia_Sessao::novo_id();
		$expira = time() + self::VALIDADE_TOKEN;

		Livia_Sessao::definir_contexto( $sessao, self::contexto_da_pagina( $req ) );

		return new WP_REST_Response(
			array(
				'disponivel' => ! Livia_Limites::disjuntor_aberto(),
				'sessao'     => $sessao,
				'token'      => self::assinar( $sessao, $expira ),
				'expira_em'  => $expira,
				'teto_texto' => Livia_Limites::TETO_ENTRADA,
			),
			200
		);
	}

	// ------------------------------------------------------------------ saúde

	/**
	 * GET /wp-json/livia/v1/saude
	 *
	 * Sem token: só se está de pé. Com ?chave=<token>: os números todos.
	 * O token aparece na tela de configuração.
	 */
	public static function saude( $req ) {
		$detalhado = Livia_Saude::token_valido( $req->get_param( 'chave' ) );
		$corpo     = $detalhado ? Livia_Saude::relatorio() : Livia_Saude::resumo();

		// 503 só quando ela realmente não responde. "Degradado" sai 200 com
		// ok=false: ela continua atendendo, encaminhando para a equipe, e
		// derrubar o alerta de uptime por isso seria exagero.
		$status = ( Livia_Saude::FORA === $corpo['estado'] ) ? 503 : 200;

		$resposta = new WP_REST_Response( $corpo, $status );
		$resposta->header( 'Cache-Control', 'no-store' );
		return $resposta;
	}

	/**
	 * De qual página a conversa está saindo.
	 *
	 * O cliente manda só o ID do post; título e endereço saem do próprio
	 * WordPress. É de propósito: aceitar o título que o navegador manda seria
	 * deixar qualquer um escrever o que quisesse no relatório de métricas —
	 * e, pior, gravar texto arbitrário no banco.
	 *
	 * O rótulo do formulário vem do shortcode e é apenas saneado a slug. Quem
	 * conseguisse forjá-lo só bagunçaria a própria leitura.
	 */
	private static function contexto_da_pagina( $req ) {
		$id = (int) $req->get_param( 'pagina_id' );

		$contexto = array(
			'pagina_id'     => 0,
			'pagina_url'    => '',
			'pagina_titulo' => '',
			'formulario'    => '',
		);

		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			$contexto['pagina_id']     = $id;
			$contexto['pagina_url']    = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
			$contexto['pagina_titulo'] = wp_strip_all_tags( get_the_title( $id ) );
		}

		$formulario = (string) $req->get_param( 'formulario' );
		$formulario = strtolower( preg_replace( '/[^A-Za-z0-9\-]/', '', $formulario ) );
		$contexto['formulario'] = substr( $formulario, 0, 40 );

		return $contexto;
	}

	// ------------------------------------------------------------------ guardas

	/** Resposta que o widget sabe mostrar: sempre tem 'resposta'. */
	private static function falar( $texto, array $extra = array(), $status = 200 ) {
		return new WP_REST_Response( array_merge( array( 'resposta' => $texto ), $extra ), $status );
	}

	/**
	 * Tudo que precisa acontecer antes de gastar um token de API.
	 *
	 * Toda saída daqui é registrada, não só as que chegam ao modelo: pergunta
	 * longa demais, ritmo estourado, disjuntor aberto e sessão encerrada são
	 * exatamente o que alguém precisa ver para entender o que os clientes estão
	 * tentando perguntar e não conseguindo.
	 *
	 * @return array|WP_REST_Response contexto pronto, ou a resposta que encerra.
	 */
	private static function preparar( $req ) {
		$canal  = Livia_Config::canal();
		$inicio = microtime( true );

		// 1. Token: obriga a passar pela rota de sessão.
		$sessao = self::conferir( $req->get_param( 'token' ) );
		if ( is_wp_error( $sessao ) ) {
			// Sem sessão válida não há conversa a que pendurar o registro — e é
			// por aqui que entra quem está batendo no endpoint sem passar pela
			// abertura. Registrar daria a um script o poder de encher a tabela.
			return new WP_REST_Response(
				array(
					'erro'     => $sessao->get_error_code(),
					'resposta' => 'A nossa conversa expirou. Atualize a página que eu recomeço.',
				),
				403
			);
		}

		$base = array(
			'sessao'   => $sessao,
			'canal'    => $canal,
			'comecou'  => $inicio,
			'contexto' => Livia_Sessao::contexto( $sessao ),
		);

		// 1.5. O interruptor geral. Alguém pode ter desligado a LivIA com a
		// página do cliente já aberta — o widget some no próximo carregamento,
		// mas a aba que ficou aberta continuaria conversando.
		if ( ! Livia_Config::esta_ativa() ) {
			$texto = 'Estou fora do ar no momento. Fale com '
				. ( '' !== $canal ? $canal : 'a nossa equipe' ) . ' que eles te ajudam.';
			return self::recusar(
				$base,
				Livia_Prompt::limpar_entrada( $req->get_param( 'pergunta' ) ),
				$texto,
				'desligada',
				200,
				array( 'disponivel' => false )
			);
		}

		// 2. Teto de entrada — antes de virar token, antes de custar qualquer coisa.
		$pergunta = Livia_Prompt::limpar_entrada( $req->get_param( 'pergunta' ) );
		$aceita   = Livia_Limites::pergunta_aceitavel( $pergunta );
		if ( is_wp_error( $aceita ) ) {
			return self::recusar( $base, $pergunta, $aceita->get_error_message(), $aceita->get_error_code() );
		}

		// 3. Ritmo: teto da conversa e teto do IP.
		$pode = Livia_Limites::pode_perguntar( $sessao );
		if ( is_wp_error( $pode ) ) {
			// Só a primeira da janela vai para a tabela. As seguintes são a
			// própria enxurrada, e registrá-las daria ao abusador uma escrita
			// de banco por requisição.
			$marca = 'livia_log_rl_' . substr( $sessao, 0, 12 );
			if ( ! get_transient( $marca ) ) {
				set_transient( $marca, 1, Livia_Limites::JANELA );
				return self::recusar( $base, $pergunta, $pode->get_error_message(), 'muitas_perguntas', 429 );
			}
			return self::falar( $pode->get_error_message(), array( 'erro' => 'muitas_perguntas' ), 429 );
		}

		if ( Livia_Sessao::encerrada( $sessao ) ) {
			$texto = 'A gente já conversou bastante! Atualize a página para recomeçar, ou fale com '
				. ( '' !== $canal ? $canal : 'a nossa equipe' ) . '.';
			return self::recusar( $base, $pergunta, $texto, 'sessao_longa' );
		}

		// 4. Cortesia pura ("oi", "obrigado") não precisa de modelo nenhum.
		//
		// Vem DEPOIS dos limites de ritmo, senão "oi" viraria um caminho sem
		// teto, e ANTES do disjuntor de propósito: com a cota do dia estourada,
		// responder a uma saudação ainda é melhor do que encaminhar para o
		// WhatsApp alguém que só disse "bom dia".
		$atalho = Livia_Atalhos::responder( $pergunta, $sessao );
		if ( $atalho ) {
			Livia_Sessao::acrescentar( $sessao, 'user', $pergunta );
			Livia_Sessao::acrescentar( $sessao, 'model', $atalho['texto'] );

			$base['pergunta'] = $pergunta;
			self::anunciar( $base, $atalho['texto'], array( 'origem' => 'atalho', 'modelo' => '' ) );

			return self::falar( $atalho['texto'], array( 'origem' => 'atalho' ) );
		}

		// 5. Disjuntor: cota do dia acabou, ninguém chama a API.
		if ( Livia_Limites::disjuntor_aberto() ) {
			return self::recusar(
				$base,
				$pergunta,
				Livia_Limites::mensagem_do_disjuntor( $canal ),
				'disjuntor',
				200,
				array( 'disponivel' => false )
			);
		}

		// A cota do plano free é por modelo. Se o principal acabou hoje mas a
		// reserva não, trocar agora é o que mantém a LivIA de pé — e é melhor do
		// que descobrir isso através de um 429, que custa a viagem até a API.
		if ( Livia_Limites::modelo_esgotado() ) {
			Livia_Modelos::rebaixar( 'cota' );
		}

		$conhecimento = Livia_Base::carregar();
		if ( is_wp_error( $conhecimento ) ) {
			return self::recusar(
				$base,
				$pergunta,
				Livia_Gemini::mensagem_para_cliente( new WP_Error( 'sem_chave', '' ), $canal ),
				'base_ausente'
			);
		}

		$historico = Livia_Sessao::historico( $sessao );

		// A base inteira ia em toda pergunta: 14.258 tokens de entrada para 76
		// de saída. Agora vão as regras (que não mudam) mais os trechos que esta
		// pergunta pede. 'inteira' é a volta atrás, na tela de configuração.
		if ( 'inteira' === Livia_Config::base_modo() ) {
			$nucleo  = $conhecimento;
			$trechos = '';
			$titulos = array();
		} else {
			$escolha = Livia_Trechos::escolher( $conhecimento, self::consulta( $pergunta, $historico, $base['contexto'] ) );
			$nucleo  = $escolha['nucleo'];
			$trechos = $escolha['trechos'];
			$titulos = $escolha['titulos'];
		}

		$instrucao = Livia_Prompt::instrucao( $nucleo );
		$historico = Livia_Sessao::acrescentar( $sessao, 'user', $pergunta );

		// Conta antes de chamar. Contar depois deixaria uma rajada passar inteira.
		Livia_Limites::registrar_chamada();

		return array_merge(
			$base,
			array(
				'pergunta'   => $pergunta,
				'instrucao'  => $instrucao,
				'trechos'    => $titulos,
				// A trava confere o que ela disse contra o que estava no pedido.
				// Precisa ver os DOIS pedaços: um endereço que veio num trecho
				// escolhido é material legítimo, e barrá-lo cortaria a resposta
				// no meio — foi o que aconteceu na homologação com o domínio do
				// próprio cliente.
				'permitidos' => Livia_Trava::somar_do_cliente(
					Livia_Trava::permitidos( $instrucao . "\n" . $trechos ),
					self::falas_do_cliente( $historico )
				),
				'janela'     => Livia_Prompt::preparar(
					Livia_Sessao::janela( $historico ),
					Livia_Sessao::tem_marca( $sessao, 'encaminhou' ),
					$trechos
				),
			)
		);
	}

	/**
	 * O cliente disse se a resposta ajudou.
	 *
	 * É o sinal mais barato que existe: não custa chamada de API, não custa
	 * token, e responde a pergunta que nenhuma métrica de latência responde —
	 * "ela acertou?". Vira a fila de "o que arrumar na base" no painel.
	 *
	 * Sem opinião anônima solta: exige o mesmo token assinado da conversa, e a
	 * linha só é marcada se pertencer àquela sessão.
	 */
	public static function opiniao( $req ) {
		$sessao = self::conferir( $req->get_param( 'token' ) );
		if ( is_wp_error( $sessao ) ) {
			return new WP_REST_Response( array( 'erro' => $sessao->get_error_code() ), 403 );
		}

		$bruto = $req->get_param( 'util' );
		$util  = null;
		if ( '' !== $bruto && null !== $bruto ) {
			$util = in_array( $bruto, array( '1', 1, true, 'true' ), true );
		}

		$ok = Livia_Registro::opinar( (int) $req->get_param( 'turno' ), $sessao, $util );

		// 200 mesmo quando nada foi marcado. Isto é um polegar num balão de
		// chat: falhar na cara do cliente por causa de uma métrica interna
		// seria trocar a conversa dele pelo nosso relatório.
		return new WP_REST_Response( array( 'ok' => (bool) $ok ) );
	}

	/** Tudo que o cliente escreveu nesta conversa, para a trava saber o que é eco. */
	private static function falas_do_cliente( array $historico ) {
		$falas = array();
		foreach ( $historico as $turno ) {
			if ( 'user' === $turno['role'] && isset( $turno['parts'][0]['text'] ) ) {
				$falas[] = $turno['parts'][0]['text'];
			}
		}
		return implode( "\n", $falas );
	}

	/**
	 * O texto que a seleção de trechos usa para decidir o que mandar.
	 *
	 * A pergunta manda. O turno anterior do cliente só entra quando a pergunta
	 * não diz nada sozinha — "e o outro?", "pode?", "não entendi" — porque aí o
	 * assunto está na mensagem de antes, não nesta. Somar o histórico sempre
	 * faria toda conversa puxar os trechos do primeiro assunto até o fim.
	 *
	 * O contexto (qual formulário a pessoa está preenchendo) vai sempre: são
	 * duas ou três palavras, e é o sinal mais barato que existe para escolher
	 * entre as regras de Site Gerenciável, Landing Page e Site em 72h.
	 */
	private static function consulta( $pergunta, array $historico, array $contexto ) {
		$partes = array( $pergunta );

		foreach ( array( 'formulario', 'pagina' ) as $campo ) {
			if ( ! empty( $contexto[ $campo ] ) ) {
				$partes[] = str_replace( array( '-', '_', '/' ), ' ', (string) $contexto[ $campo ] );
			}
		}

		if ( count( Livia_Trechos::palavras( $pergunta ) ) < 2 ) {
			for ( $i = count( $historico ) - 1; $i >= 0; $i-- ) {
				if ( 'user' !== $historico[ $i ]['role'] ) {
					continue;
				}
				$partes[] = isset( $historico[ $i ]['parts'][0]['text'] ) ? $historico[ $i ]['parts'][0]['text'] : '';
				break;
			}
		}

		return implode( ' ', $partes );
	}

	/**
	 * Recusa registrada: o cliente recebe o texto e a mensagem vai para a tabela
	 * marcada como barrada na guarda, não como erro da API.
	 */
	private static function recusar( array $base, $pergunta, $texto, $codigo, $status = 200, array $extra = array() ) {
		$base['pergunta'] = $pergunta;
		self::anunciar( $base, $texto, array( 'erro' => $codigo, 'origem' => 'guarda' ) );
		return self::falar( $texto, array_merge( array( 'erro' => $codigo ), $extra ), $status );
	}

	/**
	 * A resposta foi barrada: a PERGUNTA fica no histórico e a resposta segura
	 * entra no lugar da inventada.
	 *
	 * O livia.py descartava a pergunta junto, e um "e o outro?" logo depois
	 * perdia a âncora. Aqui o histórico segue coerente.
	 */
	private static function assentar_bloqueio( array $ctx, $texto_original, $motivo, array $uso, $streaming = false ) {
		$segura = Livia_Trava::resposta_segura( $ctx['canal'] );
		Livia_Sessao::acrescentar( $ctx['sessao'], 'model', $segura );

		// A resposta registrada é a ORIGINAL, com a invenção dentro. É ela que
		// mostra o que induziu o erro — o texto seguro não ensina nada.
		self::anunciar( $ctx, $texto_original, array( 'bloqueio' => $motivo, 'uso' => $uso, 'streaming' => $streaming ) );

		return $segura;
	}

	/**
	 * Um evento só para todo desfecho: entregue, barrada ou falha da API.
	 *
	 * Assinatura única porque quem escuta (Livia_Registro) precisa dos três para
	 * calcular taxa de recusa e taxa de erro — e porque duas ações com formatos
	 * diferentes viram três com o tempo.
	 */
	private static function anunciar( array $ctx, $resposta, array $extra = array() ) {
		self::notar_encaminhamento( $ctx, $resposta, $extra );

		do_action(
			'livia_atendimento',
			array_merge(
				array(
					'sessao'      => $ctx['sessao'],
					'pergunta'    => isset( $ctx['pergunta'] ) ? $ctx['pergunta'] : '',
					'contexto'    => isset( $ctx['contexto'] ) ? $ctx['contexto'] : array(),
					'origem'      => 'modelo',
					'resposta'    => (string) $resposta,
					'bloqueio'    => null,
					'erro'        => null,
					'uso'         => array(),
					'truncada'    => false,
					'streaming'   => false,
					'latencia_ms' => isset( $ctx['comecou'] ) ? (int) round( ( microtime( true ) - $ctx['comecou'] ) * 1000 ) : 0,
					'modelo'      => Livia_Modelos::atual(),
				),
				$extra
			)
		);
	}

	/**
	 * Anota na conversa que o contato da equipe já foi dado.
	 *
	 * No turno seguinte isso vira uma linha no pedido, e a LivIA para de repetir
	 * o telefone a cada resposta. Nas 37 respostas da homologação o número
	 * apareceu em 16, e a mesma construção de encaminhamento em 9 — era a
	 * principal razão de o atendimento soar automático.
	 *
	 * Só conta resposta que chegou inteira ao cliente. Uma que a trava barrou
	 * foi substituída pelo texto de recusa, e uma que deu erro não chegou: nas
	 * duas, marcar faria a LivIA calar um contato que ninguém leu.
	 */
	private static function notar_encaminhamento( array $ctx, $resposta, array $extra ) {
		if ( ! empty( $extra['bloqueio'] ) || ! empty( $extra['erro'] ) ) {
			return;
		}
		if ( empty( $ctx['sessao'] ) || empty( $ctx['canal'] ) ) {
			return;
		}
		if ( Livia_Prompt::contem_canal( $resposta, $ctx['canal'] ) ) {
			Livia_Sessao::marcar( $ctx['sessao'], 'encaminhou' );
		}
	}

	// ------------------------------------------------------------------ JSON

	public static function mensagem( $req ) {
		$ctx = self::preparar( $req );
		if ( $ctx instanceof WP_REST_Response ) {
			return $ctx;
		}

		$saida = Livia_Gemini::gerar( $ctx['instrucao'], $ctx['janela'] );

		if ( is_wp_error( $saida ) ) {
			// Não houve resposta: a pergunta sai do histórico, senão o próximo
			// pedido iria com dois turnos "user" seguidos.
			Livia_Sessao::remover_ultimo( $ctx['sessao'] );
			self::anunciar( $ctx, '', array( 'erro' => $saida->get_error_code() ) );
			return self::falar(
				Livia_Gemini::mensagem_para_cliente( $saida, $ctx['canal'] ),
				array( 'erro' => $saida->get_error_code() )
			);
		}

		$bloqueio = Livia_Trava::verificar( $saida['texto'], $ctx['permitidos'] );
		if ( null !== $bloqueio ) {
			return self::falar(
				self::assentar_bloqueio( $ctx, $saida['texto'], $bloqueio, $saida['uso'] ),
				array(
					'bloqueada' => true,
					'motivo'    => $bloqueio,
				)
			);
		}

		Livia_Sessao::acrescentar( $ctx['sessao'], 'model', $saida['texto'] );
		self::anunciar( $ctx, $saida['texto'], array( 'uso' => $saida['uso'], 'truncada' => (bool) $saida['truncado'] ) );

		return self::falar(
			$saida['texto'],
			array(
				'bloqueada' => false,
				'truncada'  => (bool) $saida['truncado'],
				'turno'     => Livia_Registro::ultimo_id(),
			)
		);
	}

	// ------------------------------------------------------------------ SSE

	/**
	 * Assume a saída da requisição e desliga tudo que possa segurar bytes.
	 *
	 * Esvaziar os buffers do PHP não basta. Quem mais quebra streaming em
	 * hospedagem compartilhada é a compressão: com zlib.output_compression
	 * ligado, o PHP junta a resposta inteira para comprimir e só solta no fim —
	 * e o ob_end_flush() abaixo não tem efeito nenhum. O mod_deflate do Apache
	 * faz o mesmo, de fora.
	 *
	 * Nada aqui derruba a requisição se falhar: são todos best-effort, porque o
	 * pior resultado possível é a resposta chegar de uma vez, não chegar errada.
	 */
	private static function abrir_sse() {
		// A resposta pode demorar o prazo inteiro do modelo. O padrão de muitos
		// servidores é 30s, e cortar no meio deixaria o cliente sem nada.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( Livia_Gemini::TIMEOUT + 15 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		// Compressão: a causa nº 1 de streaming que não flui.
		@ini_set( 'zlib.output_compression', '0' );   // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@ini_set( 'output_buffering', '0' );          // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@ini_set( 'implicit_flush', '1' );            // phpcs:ignore WordPress.PHP.NoSilencedErrors

		// Apache: pede ao mod_deflate para não comprimir esta resposta.
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );         // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@apache_setenv( 'dont-vary', '1' );       // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		while ( ob_get_level() > 0 ) {
			if ( ! @ob_end_flush() ) {                // phpcs:ignore WordPress.PHP.NoSilencedErrors
				break; // buffer não removível: insistir daria laço infinito
			}
		}
		ob_implicit_flush( true );

		header_remove( 'Content-Encoding' );
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Accel-Buffering: no' );  // nginx e LiteSpeed honram
		header( 'Connection: keep-alive' );

		// Cliente que fecha a aba derruba o processo na próxima escrita, em vez
		// de segurar um worker do PHP-FPM até o fim do prazo.
		ignore_user_abort( false );

		// Enchimento inicial: alguns proxies só começam a repassar depois de
		// juntar alguns KB. Linha iniciada por ":" é comentário no protocolo SSE
		// — o navegador descarta, e o buffer do proxy estoura antes da resposta.
		echo ': ' . str_repeat( ' ', 2048 ) . "\n\n";
		flush();
	}

	private static function evento( $nome, array $dados ) {
		echo 'event: ' . $nome . "\n";
		echo 'data: ' . wp_json_encode( $dados ) . "\n\n";
		flush();
	}

	/**
	 * Pergunta e resposta em streaming.
	 *
	 * O ritmo humano — pausa de leitura, palavra por palavra — é do widget, não
	 * daqui. Um sleep() no servidor seguraria um processo do PHP-FPM pelo tempo
	 * inteiro da encenação, e processo preso é o gargalo desta arquitetura. O
	 * servidor entrega no ritmo da API; quem encena é o navegador.
	 */
	public static function conversa( $req ) {
		$ctx = self::preparar( $req );
		if ( $ctx instanceof WP_REST_Response ) {
			// Nada foi impresso ainda: sai como JSON normal, e o widget
			// reconhece pelo Content-Type.
			return $ctx;
		}

		// Sem cURL não há streaming possível. Em vez de devolver erro e obrigar o
		// widget a repetir a pergunta — gastando cota duas vezes — servimos a
		// resposta inteira pelo mesmo protocolo. O cliente não percebe diferença
		// além do texto chegar de uma vez.
		if ( ! function_exists( 'curl_init' ) ) {
			self::conversa_sem_streaming( $ctx );
		}

		$guarda = new Livia_Stream( $ctx['permitidos'] );

		self::abrir_sse();

		$saida = Livia_Gemini::gerar_stream(
			$ctx['instrucao'],
			$ctx['janela'],
			function ( $pedaco ) use ( $guarda ) {
				$liberado = $guarda->receber( $pedaco );
				if ( false === $liberado ) {
					return false; // aborta o stream: a trava barrou
				}
				if ( '' !== $liberado ) {
					self::evento( 'pedaco', array( 't' => $liberado ) );
				}
				return true;
			}
		);

		$uso = ( is_array( $saida ) && isset( $saida['uso'] ) ) ? $saida['uso'] : array();

		// A trava barrou no meio: o widget descarta o balão e mostra o texto seguro.
		if ( null !== $guarda->bloqueio() ) {
			self::despachar_bloqueio( $ctx, $guarda, $uso );
		}

		if ( is_wp_error( $saida ) ) {
			Livia_Sessao::remover_ultimo( $ctx['sessao'] );
			self::anunciar( $ctx, '', array( 'erro' => $saida->get_error_code(), 'streaming' => true ) );
			self::evento(
				'erro',
				array(
					'resposta' => Livia_Gemini::mensagem_para_cliente( $saida, $ctx['canal'] ),
					'erro'     => $saida->get_error_code(),
				)
			);
			exit;
		}

		// Varredura final e liberação do que ficou retido.
		$resto = $guarda->finalizar();
		if ( false === $resto ) {
			self::despachar_bloqueio( $ctx, $guarda, $uso );
		}

		if ( '' !== $resto ) {
			self::evento( 'pedaco', array( 't' => $resto ) );
		}

		$texto = $guarda->texto_completo();
		Livia_Sessao::acrescentar( $ctx['sessao'], 'model', $texto );
		self::anunciar( $ctx, $texto, array( 'uso' => $uso, 'truncada' => ! empty( $saida['truncado'] ), 'streaming' => true ) );

		self::evento( 'fim', array( 'truncada' => ! empty( $saida['truncado'] ), 'turno' => Livia_Registro::ultimo_id() ) );
		exit;
	}

	/**
	 * Caminho de contingência: responde no protocolo SSE sem streaming real.
	 *
	 * Mesmas guardas, mesma trava, mesmo registro — muda só que o texto sai num
	 * evento só. Nunca sai daqui sem encerrar a requisição.
	 */
	private static function conversa_sem_streaming( array $ctx ) {
		$saida = Livia_Gemini::gerar( $ctx['instrucao'], $ctx['janela'] );

		self::abrir_sse();

		if ( is_wp_error( $saida ) ) {
			Livia_Sessao::remover_ultimo( $ctx['sessao'] );
			self::anunciar( $ctx, '', array( 'erro' => $saida->get_error_code(), 'streaming' => false ) );
			self::evento(
				'erro',
				array(
					'resposta' => Livia_Gemini::mensagem_para_cliente( $saida, $ctx['canal'] ),
					'erro'     => $saida->get_error_code(),
				)
			);
			exit;
		}

		$bloqueio = Livia_Trava::verificar( $saida['texto'], $ctx['permitidos'] );
		if ( null !== $bloqueio ) {
			$segura = self::assentar_bloqueio( $ctx, $saida['texto'], $bloqueio, $saida['uso'] );
			self::evento( 'bloqueada', array( 'resposta' => $segura, 'motivo' => $bloqueio ) );
			exit;
		}

		Livia_Sessao::acrescentar( $ctx['sessao'], 'model', $saida['texto'] );
		self::anunciar( $ctx, $saida['texto'], array( 'uso' => $saida['uso'], 'truncada' => (bool) $saida['truncado'] ) );

		self::evento( 'pedaco', array( 't' => $saida['texto'] ) );
		self::evento( 'fim', array( 'truncada' => (bool) $saida['truncado'], 'turno' => Livia_Registro::ultimo_id() ) );
		exit;
	}

	private static function despachar_bloqueio( array $ctx, Livia_Stream $guarda, array $uso ) {
		$segura = self::assentar_bloqueio( $ctx, $guarda->texto_completo(), $guarda->bloqueio(), $uso, true );
		self::evento(
			'bloqueada',
			array(
				'resposta' => $segura,
				'motivo'   => $guarda->bloqueio(),
			)
		);
		exit;
	}
}
