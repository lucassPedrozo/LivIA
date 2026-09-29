<?php
/**
 * Seleção de contexto: mandar só a parte da base que a pergunta pede.
 *
 * Na primeira homologação foram 527.565 tokens de entrada para 2.817 de saída —
 * 187 para 1. A base inteira, 56 KB, viajava em toda pergunta, inclusive em "o
 * que é domínio". No plano free da API o que aperta é token por minuto, e a
 * única alavanca que funciona sob qualquer leitura das regras é mandar menos.
 *
 * A base é partida em trechos pelos próprios títulos do documento. Uns vão
 * SEMPRE — identidade, guardrails, escalonamento, o que ela não sabe, o mapa de
 * URLs e o resumo de uma página. O resto é escolhido por pergunta.
 *
 * ## Por que o núcleo é o que é
 *
 * O que vai sempre não é "o mais importante": é **o que muda toda resposta, ou
 * o que a impede de inventar**. Tom de voz molda qualquer frase; a Seção 12 diz
 * o que ela não sabe e é a diferença entre "não tenho essa informação" e um
 * chute; o mapa de URLs alimenta a lista de endereços permitidos da trava.
 *
 * O Anexo B — resumo de uma página — é seguro contra erro de seleção: mesmo que
 * a escolha erre feio, ela ainda tem uma visão geral do negócio para reconhecer
 * o assunto e dizer que vai confirmar com a equipe.
 *
 * ## O que acontece quando a seleção erra
 *
 * Ela perde acesso a um trecho e responde que não sabe. É o lado certo de
 * errar: uma resposta a menos custa uma pergunta repetida; uma invenção custa a
 * confiança no atendimento. Nenhum guardrail depende da seleção — todos estão
 * no núcleo, e a trava roda fora do modelo de qualquer jeito.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Trechos {

	/**
	 * Quantos bytes de base escolhida acompanham a pergunta.
	 *
	 * Oito mil é folgado: o maior trecho do documento tem 2 KB, então cabem
	 * três ou quatro assuntos inteiros. Apertar mais economizaria pouco e
	 * começaria a cortar resposta boa.
	 */
	const ORCAMENTO = 8000;

	/** Só conta palavra com pelo menos isto. "de", "no", "os" não dizem nada. */
	const MINIMO_LETRAS = 3;

	/**
	 * As seções que vão em toda chamada, na ordem em que aparecem no documento.
	 *
	 * Casadas pelo começo do título, não pelo título inteiro: assim renomear
	 * "SEÇÃO 9 — FAQ" para "SEÇÃO 9 — PERGUNTAS FREQUENTES" não quebra nada.
	 */
	public static function fixas() {
		return apply_filters(
			'livia_trechos_fixos',
			array(
				'SEÇÃO 0',
				'SEÇÃO 1',
				'SEÇÃO 2',
				'SEÇÃO 11',
				'SEÇÃO 12',
				'ANEXO A',
				'ANEXO B',
			)
		);
	}

	/**
	 * Palavras que aparecem em toda pergunta e não separam nada.
	 *
	 * Sem esta lista, "como" e "para" casariam com o documento inteiro e a
	 * ordem dos trechos viraria sorteio.
	 */
	private static function vazias() {
		static $lista = null;
		if ( null === $lista ) {
			$lista = array_flip(
				array(
					'que', 'qual', 'quais', 'como', 'onde', 'quando', 'quem', 'porque', 'por', 'para',
					'com', 'sem', 'dos', 'das', 'nos', 'nas', 'uma', 'uns', 'umas', 'meu', 'minha',
					'meus', 'minhas', 'seu', 'sua', 'seus', 'suas', 'ele', 'ela', 'eles', 'elas',
					'isso', 'isto', 'esse', 'essa', 'este', 'esta', 'aquele', 'aquela', 'tem', 'ter',
					'tenho', 'temos', 'ser', 'sou', 'esta', 'estou', 'estao', 'fazer', 'faz', 'faco',
					'pode', 'posso', 'podem', 'poderia', 'quero', 'queria', 'gostaria', 'preciso',
					'precisa', 'vou', 'vai', 'vamos', 'nao', 'sim', 'mais', 'menos', 'muito', 'pouco',
					'tudo', 'nada', 'algum', 'alguma', 'outro', 'outra', 'mesmo', 'mesma', 'ainda',
					'agora', 'depois', 'antes', 'entao', 'aqui', 'ali', 'lah', 'sobre', 'entre',
					'ate', 'desde', 'apenas', 'tambem', 'porem', 'mas', 'oque', 'voce', 'voces',
					'gente', 'coisa', 'coisas', 'jeito', 'forma', 'favor', 'obrigado', 'oi', 'ola',
				)
			);
		}
		return $lista;
	}

	/**
	 * Texto em palavras comparáveis: sem acento, sem pontuação, sem as vazias.
	 *
	 * Reaproveita a normalização dos atalhos para que "Domínio" na pergunta e
	 * "domínio" no documento sejam a mesma palavra — inclusive quando o cliente
	 * escreve "dominio" sem acento, que é o caso comum no celular.
	 */
	public static function palavras( $texto ) {
		// Hífen entre letras some em vez de virar espaço. Sem isto, "e-mail" no
		// documento vira "e" + "mail", o "e" é curto demais e é descartado, e
		// "mail" nunca casa com "email", que é como o cliente escreve. Resultado
		// aferido: toda pergunta sobre e-mail selecionava zero trechos.
		$texto = preg_replace( '/(\p{L})-(\p{L})/u', '$1$2', (string) $texto );

		$limpo = Livia_Atalhos::normalizar( $texto );
		if ( '' === $limpo ) {
			return array();
		}

		$vazias = self::vazias();
		$saida  = array();

		foreach ( explode( ' ', $limpo ) as $palavra ) {
			if ( strlen( $palavra ) < self::MINIMO_LETRAS || isset( $vazias[ $palavra ] ) ) {
				continue;
			}
			// Plural simples cai no singular: "produtos" e "produto" são a
			// mesma busca. Radicalizar de verdade erraria mais do que acerta.
			if ( 's' === substr( $palavra, -1 ) && strlen( $palavra ) > 4 ) {
				$palavra = substr( $palavra, 0, -1 );
			}
			$saida[] = $palavra;
		}

		return $saida;
	}

	/**
	 * A base partida em trechos, pelos títulos do próprio documento.
	 *
	 * Seção de nível ## quando ela não tem subtítulos; cada ### quando tem. Os
	 * tamanhos saem entre 300 e 2.000 bytes, que é a granularidade certa: menor
	 * do que isso quebra uma explicação no meio, maior desperdiça orçamento.
	 *
	 * Todo trecho leva o título da seção-mãe junto. Sem isso, "8.4 Dados de
	 * contato exibidos no site" chega ao modelo sem dizer de que guia faz parte.
	 *
	 * @return array lista de array('chave','titulo','texto','fixo')
	 */
	public static function partir( $base ) {
		$base = (string) $base;

		static $memo = array();
		$assinatura  = md5( $base );
		if ( isset( $memo[ $assinatura ] ) ) {
			return $memo[ $assinatura ];
		}

		$fixas  = self::fixas();
		$partes = preg_split( '/^(##[^#].*)$/mu', $base, -1, PREG_SPLIT_DELIM_CAPTURE );
		$saida  = array();

		// O que vem antes do primeiro ## é o cabeçalho do arquivo: vai sempre.
		$preambulo = isset( $partes[0] ) ? trim( $partes[0] ) : '';
		if ( '' !== $preambulo ) {
			$saida[] = array(
				'chave'  => 'preambulo',
				'titulo' => 'início do documento',
				'texto'  => $preambulo,
				'fixo'   => true,
			);
		}

		for ( $i = 1; $i < count( $partes ); $i += 2 ) {
			$titulo = trim( $partes[ $i ] );
			$corpo  = isset( $partes[ $i + 1 ] ) ? $partes[ $i + 1 ] : '';
			$nome   = trim( ltrim( $titulo, '# ' ) );

			$fixo = false;
			foreach ( $fixas as $marca ) {
				if ( 0 === stripos( $nome, $marca ) ) {
					$fixo = true;
					break;
				}
			}

			if ( $fixo ) {
				$saida[] = array(
					'chave'  => $nome,
					'titulo' => $nome,
					'texto'  => rtrim( $titulo . "\n" . $corpo ),
					'fixo'   => true,
				);
				continue;
			}

			$sub = preg_split( '/^(###[^#].*)$/mu', $corpo, -1, PREG_SPLIT_DELIM_CAPTURE );

			if ( count( $sub ) < 3 ) {
				// Sem subtítulos. Se for uma lista de verbetes — **Termo** e a
				// definição embaixo —, cada verbete vira um trecho.
				//
				// Vale muito a pena: "o que é X" é a pergunta mais comum que
				// existe, e o glossário inteiro num pedaço só de 2,8 KB perdia
				// para qualquer subseção pequena na hora de caber no orçamento.
				$verbetes = self::verbetes( $titulo, $nome, $corpo );
				if ( $verbetes ) {
					$saida = array_merge( $saida, $verbetes );
					continue;
				}

				$saida[] = array(
					'chave'  => $nome,
					'titulo' => $nome,
					'texto'  => rtrim( $titulo . "\n" . $corpo ),
					'fixo'   => false,
				);
				continue;
			}

			// A abertura da seção, antes do primeiro ###, gruda no primeiro
			// pedaço: costuma ser a regra que vale para todos eles.
			$abertura = trim( $sub[0] );

			for ( $j = 1; $j < count( $sub ); $j += 2 ) {
				$subtitulo = trim( $sub[ $j ] );
				$subcorpo  = isset( $sub[ $j + 1 ] ) ? $sub[ $j + 1 ] : '';
				$cabeca    = $titulo . "\n\n" . ( 1 === $j && '' !== $abertura ? $abertura . "\n\n" : '' );

				$saida[] = array(
					'chave'  => $nome . ' › ' . trim( ltrim( $subtitulo, '# ' ) ),
					'titulo' => trim( ltrim( $subtitulo, '# ' ) ),
					'texto'  => rtrim( $cabeca . $subtitulo . "\n" . $subcorpo ),
					'fixo'   => false,
				);
			}
		}

		$memo[ $assinatura ] = $saida;
		return $saida;
	}

	/**
	 * Uma seção escrita como lista de verbetes, partida em um trecho por verbete.
	 *
	 * O formato é `**Termo**` numa linha só, e a definição logo abaixo. Menos de
	 * três verbetes não é lista: é texto com negrito, e aí não se parte nada.
	 *
	 * A abertura da seção acompanha todo verbete. São sessenta bytes que dizem
	 * como usar aquilo ("definições prontas, ela pode usar quase literalmente") —
	 * sem eles, o verbete chega como um fato solto.
	 *
	 * @return array lista de trechos, ou vazio se não for uma lista de verbetes.
	 */
	private static function verbetes( $titulo, $nome, $corpo ) {
		$partes = preg_split( '/^\*\*(.+?)\*\*\s*$/mu', $corpo, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( count( $partes ) < 7 ) { // abertura + pelo menos três pares
			return array();
		}

		$abertura = trim( $partes[0] );
		$saida    = array();

		for ( $i = 1; $i < count( $partes ); $i += 2 ) {
			$termo = trim( $partes[ $i ] );
			$def   = isset( $partes[ $i + 1 ] ) ? rtrim( $partes[ $i + 1 ] ) : '';

			if ( '' === trim( $def ) ) {
				continue;
			}

			$saida[] = array(
				'chave'  => $nome . ' › ' . $termo,
				'titulo' => $termo,
				'texto'  => $titulo . "\n\n" . ( '' !== $abertura ? $abertura . "\n\n" : '' )
					. '**' . $termo . '**' . $def,
				'fixo'   => false,
			);
		}

		return $saida;
	}

	/**
	 * Índice de busca: por trecho, quantas vezes cada palavra aparece.
	 *
	 * Guarda também os prefixos de quatro letras. É o que faz "logo" encontrar
	 * "logotipo" e "travei" encontrar "travou" — sem isso o cliente precisa
	 * adivinhar a palavra exata que o documento usa, e ele não vai adivinhar.
	 */
	private static function indice( array $candidatos ) {
		static $memo = array();

		$chaves = array();
		foreach ( $candidatos as $t ) {
			$chaves[] = $t['chave'];
		}
		$assinatura = md5( implode( '|', $chaves ) );
		if ( isset( $memo[ $assinatura ] ) ) {
			return $memo[ $assinatura ];
		}

		$linhas = array();
		foreach ( $candidatos as $t ) {
			$palavras = self::palavras( $t['texto'] );

			$prefixos = array();
			foreach ( $palavras as $p ) {
				if ( strlen( $p ) >= 4 ) {
					$prefixos[] = substr( $p, 0, 4 );
				}
			}

			$linhas[] = array(
				'conta'    => array_count_values( $palavras ),
				'prefixos' => array_count_values( $prefixos ),
				'cabeca'   => array_flip( self::palavras( $t['titulo'] ) ),
				'bytes'    => strlen( $t['texto'] ),
			);
		}

		$memo[ $assinatura ] = $linhas;
		return $linhas;
	}

	/**
	 * Quanto cada palavra vale.
	 *
	 * Palavra que está em todo trecho não distingue nada; palavra que está em
	 * dois distingue muito. É a ideia do IDF, na forma mais simples que resolve
	 * — "briefing" aparece no documento inteiro e quase não pontua, "logotipo"
	 * aparece em três trechos e puxa forte.
	 */
	private static function pesos( array $indice ) {
		$total = max( 1, count( $indice ) );
		$em    = array( 'palavra' => array(), 'prefixo' => array() );

		foreach ( $indice as $linha ) {
			foreach ( array_keys( $linha['conta'] ) as $p ) {
				$em['palavra'][ $p ] = isset( $em['palavra'][ $p ] ) ? $em['palavra'][ $p ] + 1 : 1;
			}
			foreach ( array_keys( $linha['prefixos'] ) as $p ) {
				$em['prefixo'][ $p ] = isset( $em['prefixo'][ $p ] ) ? $em['prefixo'][ $p ] + 1 : 1;
			}
		}

		$pesos = array( 'palavra' => array(), 'prefixo' => array() );
		foreach ( $em as $tipo => $contagem ) {
			foreach ( $contagem as $p => $quantos ) {
				$pesos[ $tipo ][ $p ] = log( 1 + ( $total / $quantos ) );
			}
		}
		return $pesos;
	}

	/**
	 * Os trechos que respondem esta consulta, dentro do orçamento.
	 *
	 * @param string $base      a base inteira, com o canal já substituído.
	 * @param string $consulta  pergunta atual + o que dá contexto a ela.
	 * @return array array('nucleo','trechos','titulos','bytes','total')
	 */
	public static function escolher( $base, $consulta, $orcamento = null ) {
		$base      = (string) $base;
		$orcamento = null === $orcamento
			? (int) apply_filters( 'livia_trechos_orcamento', self::ORCAMENTO )
			: (int) $orcamento;

		$partidos = self::partir( $base );

		$nucleo    = array();
		$candidatos = array();
		foreach ( $partidos as $t ) {
			if ( $t['fixo'] ) {
				$nucleo[] = $t['texto'];
			} else {
				$candidatos[] = $t;
			}
		}

		$termos = array_unique( self::palavras( $consulta ) );
		$indice = self::indice( $candidatos );
		$pesos  = self::pesos( $indice );

		$notas = array();
		foreach ( $indice as $i => $linha ) {
			$bruta = 0.0;

			foreach ( $termos as $termo ) {
				if ( isset( $linha['conta'][ $termo ] ) ) {
					$peso = isset( $pesos['palavra'][ $termo ] ) ? $pesos['palavra'][ $termo ] : 1.0;
					// Repetir dez vezes não vale dez: o terceiro já provou o assunto.
					$vezes = min( 3, $linha['conta'][ $termo ] );
					// Casar no título vale mais do que casar no meio do texto: é
					// o autor dizendo do que aquele pedaço trata.
					$bruta += $peso * $vezes * ( isset( $linha['cabeca'][ $termo ] ) ? 2.5 : 1.0 );
					continue;
				}

				// A palavra exata não está lá. O começo dela pode estar — e é
				// assim que "logo" acha "logotipo". Vale menos, de propósito:
				// é um palpite, não um acerto. Prefixo comum ("cont", que serve
				// a contato, conteúdo e contratar) já se pune sozinho, porque o
				// peso cai quanto mais trechos o contêm.
				if ( strlen( $termo ) < 4 ) {
					continue;
				}
				$prefixo = substr( $termo, 0, 4 );
				if ( isset( $linha['prefixos'][ $prefixo ] ) ) {
					$peso   = isset( $pesos['prefixo'][ $prefixo ] ) ? $pesos['prefixo'][ $prefixo ] : 1.0;
					$bruta += $peso * min( 3, $linha['prefixos'][ $prefixo ] ) * 0.5;
				}
			}

			if ( $bruta <= 0 ) {
				continue;
			}

			// Dividir pela raiz do tamanho evita que o trecho mais comprido
			// ganhe só por ser comprido — e ele custa mais orçamento.
			$notas[ $i ] = $bruta / sqrt( max( 1, $linha['bytes'] ) );
		}

		arsort( $notas );

		$escolhidos = array();
		$titulos    = array();
		$gasto      = 0;

		foreach ( $notas as $i => $nota ) {
			$tamanho = strlen( $candidatos[ $i ]['texto'] );
			if ( $gasto + $tamanho > $orcamento ) {
				// Não para no primeiro que não cabe: um trecho pequeno logo
				// abaixo ainda pode entrar e responder a pergunta.
				continue;
			}
			$escolhidos[] = $candidatos[ $i ]['texto'];
			$titulos[]    = $candidatos[ $i ]['chave'];
			$gasto       += $tamanho;
		}

		return array(
			'nucleo'  => implode( "\n\n", $nucleo ),
			'trechos' => implode( "\n\n---\n\n", $escolhidos ),
			'titulos' => $titulos,
			'bytes'   => $gasto,
			'total'   => strlen( $base ),
		);
	}
}
