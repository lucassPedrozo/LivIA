<?php
/**
 * Estado da LivIA, para monitoramento externo.
 *
 * Dois níveis, de propósito. O público devolve só se está de pé — é o que um
 * monitor de uptime consome, e não conta nada de útil para quem não deveria
 * saber. O detalhado exige um token e traz os números: cota, erros, latência,
 * quando o expurgo roda.
 *
 * A separação existe porque a alternativa seria escolher entre um endpoint de
 * monitoramento que ninguém consegue usar sem configurar credencial, ou um
 * painel de operação aberto na internet.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Saude {

	/** De pé e respondendo normalmente. */
	const OK = 'ok';

	/** Responde, mas encaminhando: cota do dia estourada ou erro demais. */
	const DEGRADADO = 'degradado';

	/** Não responde: falta chave, modelo ou base. */
	const FORA = 'fora';

	/**
	 * Desligada no painel. Estado próprio, e não FORA, porque a diferença
	 * importa para quem lê o alerta às três da manhã: "quebrou" pede ação,
	 * "alguém desligou" não.
	 */
	const DESLIGADA = 'desligada';

	/** Acima disto, em 24h, a LivIA está degradada mesmo que de pé. */
	const TAXA_ERRO_LIMITE = 0.25;

	/**
	 * Token de monitoramento.
	 *
	 * Derivado do salt do site: não precisa ser guardado em lugar nenhum, muda
	 * se o salt mudar, e não é adivinhável. A tela de configuração mostra o
	 * valor para quem tem acesso ao wp-admin.
	 */
	public static function token() {
		return substr( hash_hmac( 'sha256', 'livia-saude', wp_salt( 'livia' ) ), 0, 32 );
	}

	public static function token_valido( $recebido ) {
		return is_string( $recebido ) && hash_equals( self::token(), $recebido );
	}

	/**
	 * O estado, em uma palavra.
	 *
	 * @return string OK, DEGRADADO ou FORA
	 */
	public static function estado() {
		if ( ! Livia_Config::esta_configurado() ) {
			return self::FORA;
		}
		if ( ! Livia_Config::esta_ativa() ) {
			return self::DESLIGADA;
		}
		if ( Livia_Limites::disjuntor_aberto() ) {
			return self::DEGRADADO;
		}

		$d = Livia_Registro::saude_24h();
		if ( $d['mensagens'] >= 10 && $d['erros'] / $d['mensagens'] > self::TAXA_ERRO_LIMITE ) {
			return self::DEGRADADO;
		}

		return self::OK;
	}

	/**
	 * O que o monitor de uptime lê.
	 *
	 * "fora" responde HTTP 503, para o monitor alertar sem precisar interpretar
	 * o corpo. "degradado" responde 200 com ok=false: a LivIA continua atendendo
	 * — encaminhando todo mundo para a equipe — e derrubar o alerta de uptime
	 * por isso seria exagero. Quem quiser acordar alguém nesse caso olha o `ok`.
	 *
	 * "desligada" também sai 200: foi uma decisão de alguém, não uma falha, e um
	 * monitor gritando por causa de um interruptor é um monitor que se aprende
	 * a ignorar.
	 */
	public static function resumo() {
		$estado = self::estado();
		return array(
			'ok'     => self::OK === $estado,
			'estado' => $estado,
			// defined(): uma rota de saúde que dá fatal é pior que rota nenhuma —
			// o monitor veria 500 em vez do diagnóstico.
			'versao' => defined( 'LIVIA_VERSAO' ) ? LIVIA_VERSAO : '?',
		);
	}

	/** Tudo que ajuda a entender por que o estado é o que é. */
	public static function relatorio() {
		$info  = Livia_Base::info();
		$d     = Livia_Registro::saude_24h();
		$proxi = wp_next_scheduled( Livia_Registro::CRON );

		$chave_em = 'ausente';
		if ( '' !== Livia_Config::api_key() ) {
			$chave_em = Livia_Config::chave_vem_de_constante() ? 'constante' : 'banco';
		}

		return array_merge(
			self::resumo(),
			array(
				'configuracao' => array(
					'chave_em'         => $chave_em,
					'ativa'            => Livia_Config::esta_ativa(),
					'modelo'           => Livia_Config::modelo(),
					'canal_definido'   => '' !== Livia_Config::canal(),
					'marcador_pendente'=> Livia_Base::marcador_pendente(),
				),
				'base'         => array(
					'encontrada' => ! empty( $info['ok'] ),
					'bytes'      => isset( $info['bytes'] ) ? (int) $info['bytes'] : 0,
					'modificada' => isset( $info['modificado'] ) ? gmdate( 'c', $info['modificado'] ) : null,
				),
				'streaming'    => array(
					'curl' => function_exists( 'curl_init' ),
				),
				'cache'        => Livia_Cache::estado(),
				'modelos'      => Livia_Modelos::estado(),
				'cota'         => Livia_Limites::previsao() + array(
					'disjuntor' => Livia_Limites::disjuntor_aberto(),
				),
				'ultimas_24h'  => $d,
				'expurgo'      => array(
					'agendado'  => (bool) $proxi,
					'proximo'   => $proxi ? gmdate( 'c', $proxi ) : null,
					'wp_cron'   => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
					'retencao'  => Livia_Registro::RETENCAO_DIAS,
				),
			)
		);
	}
}
