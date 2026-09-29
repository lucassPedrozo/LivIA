<?php
/**
 * Avisa quando a LivIA para de funcionar direito.
 *
 * Sem isto, ela degrada com elegância e em silêncio: se a chave morrer ou a cota
 * estourar, ela encaminha todo mundo para o atendimento e ninguém fica sabendo
 * até um cliente reclamar. Degradar bem é bom; degradar sem avisar não é.
 *
 * Dois níveis, e os dois com trava de repetição — um alerta que chega vinte
 * vezes é um alerta que ninguém lê:
 *
 *   imediato  — o disjuntor fechou, ou a chave parou de ser aceita
 *   diário    — resumo de ontem, só quando há o que dizer
 */

defined( 'ABSPATH' ) || exit;

class Livia_Alerta {

	/** Quantas falhas de chave seguidas antes de avisar. */
	const FALHAS_ATE_AVISAR = 3;

	/** Acima desta fração de erro no dia, o resumo vira alerta. */
	const TAXA_ERRO_LIMITE = 0.2;

	public static function iniciar() {
		add_action( 'livia_disjuntor_abriu', array( __CLASS__, 'disjuntor_abriu' ) );
		add_action( 'livia_modelo_rebaixado', array( __CLASS__, 'modelo_rebaixado' ), 10, 3 );
		add_action( 'livia_atendimento', array( __CLASS__, 'observar' ) );
		add_action( Livia_Registro::CRON, array( __CLASS__, 'resumo_diario' ) );
	}

	public static function destinatario() {
		return apply_filters( 'livia_email_alerta', get_option( 'admin_email' ) );
	}

	/**
	 * Manda no máximo um e-mail por assunto por janela.
	 *
	 * @return bool se chegou a enviar
	 */
	private static function enviar_uma_vez( $chave, $janela, $assunto, $corpo ) {
		if ( get_transient( $chave ) ) {
			return false;
		}
		set_transient( $chave, time(), $janela );

		$para = self::destinatario();
		if ( ! $para || ! is_email( $para ) ) {
			return false;
		}

		$site = wp_parse_url( home_url(), PHP_URL_HOST );

		return wp_mail(
			$para,
			sprintf( '[LivIA · %s] %s', $site, $assunto ),
			$corpo . "\n\n--\n" . admin_url( 'options-general.php?page=livia' ) . "\n"
		);
	}

	// ------------------------------------------------------------- imediatos

	/**
	 * O principal caiu e a reserva assumiu.
	 *
	 * Não é incidente: a LivIA continua atendendo, e é exatamente para isso que
	 * a reserva existe. Mas alguém precisa saber, porque cota que estoura hoje
	 * estoura de novo amanhã — e porque a reserva costuma ser um modelo mais
	 * caro, mais lento ou menos capaz, e ninguém quer descobrir isso na fatura.
	 */
	public static function modelo_rebaixado( $principal, $reserva, $motivo ) {
		$explicacao = 'cota' === $motivo
			? 'A cota do modelo principal se esgotou.'
			: 'O modelo principal não foi encontrado para esta chave — provavelmente o ID mudou ou foi descontinuado.';

		$corpo = $explicacao . "\n\n"
			. "A LivIA passou a responder com o modelo reserva." . "\n\n"
			. sprintf( "  principal: %s\n", $principal )
			. sprintf( "  reserva:   %s\n\n", $reserva )
			. sprintf(
				"Ela tenta o principal de novo sozinha daqui a %d minutos. Nada precisa ser\n"
				. "feito agora — este aviso existe para a troca não passar despercebida.\n\n",
				(int) round( Livia_Modelos::DESCANSO / 60 )
			)
			. "Se isso virar rotina, vale rever a cota da chave ou trocar o principal no painel.";

		// Uma hora de trava: a janela de rebaixamento é de dez minutos, e sem
		// isto uma cota diária esgotada renderia um e-mail a cada dez minutos
		// até a virada do dia.
		self::enviar_uma_vez(
			'livia_alerta_modelo',
			HOUR_IN_SECONDS,
			'a reserva assumiu',
			$corpo
		);
	}

	public static function disjuntor_abriu( $total ) {
		$corpo = "A LivIA atingiu o teto de chamadas do dia e parou de consultar a API.\n\n"
			. sprintf( "Chamadas usadas: %d\n", (int) $total )
			. "A partir de agora ela encaminha todo mundo para o atendimento humano, com a\n"
			. "mensagem certa — ninguém fica sem resposta. O contador zera à meia-noite (UTC).\n\n"
			. "Se isso está acontecendo cedo demais no dia, é sinal de volume real acima do\n"
			. "esperado, ou de alguém abusando do endpoint. Os números por hora estão no painel.";

		self::enviar_uma_vez( 'livia_alerta_disjuntor', DAY_IN_SECONDS, 'o disjuntor de cota abriu', $corpo );
	}

	/**
	 * Fica de olho no que passa pelo endpoint.
	 *
	 * Só reage a falha de chave: é o único erro que deixa a LivIA completamente
	 * muda e que não se resolve sozinho com o tempo.
	 */
	public static function observar( $dados ) {
		$erro = isset( $dados['erro'] ) ? $dados['erro'] : null;

		if ( ! in_array( $erro, array( 'chave_invalida', 'sem_chave' ), true ) ) {
			// Uma resposta que deu certo zera a contagem: o que interessa é
			// falha seguida, não falha avulsa.
			if ( null === $erro ) {
				delete_transient( 'livia_falhas_chave' );
			}
			return;
		}

		$seguidas = (int) get_transient( 'livia_falhas_chave' ) + 1;
		set_transient( 'livia_falhas_chave', $seguidas, HOUR_IN_SECONDS );

		if ( $seguidas < self::FALHAS_ATE_AVISAR ) {
			return;
		}

		$corpo = "A API está recusando a chave da LivIA.\n\n"
			. sprintf( "Falhas seguidas: %d\n", $seguidas )
			. sprintf( "Erro reportado: %s\n\n", (string) $erro )
			. "Enquanto isso durar, a LivIA não responde nada — ela avisa o cliente que está\n"
			. "com um problema e manda falar com a equipe.\n\n"
			. "O que costuma ser: chave revogada, cota da conta encerrada, ou o ID do modelo\n"
			. "mudou. O botão \"Testar chave e modelo\" na tela de configuração diz qual dos três.";

		self::enviar_uma_vez( 'livia_alerta_chave', 6 * HOUR_IN_SECONDS, 'a chave da API parou de funcionar', $corpo );
	}

	// ----------------------------------------------------------------- diário

	/**
	 * Resumo de ontem — e só quando há o que dizer.
	 *
	 * Um e-mail diário dizendo "está tudo bem" vira regra de caixa de entrada em
	 * duas semanas, e aí o dia em que não estiver bem também não é lido.
	 */
	public static function resumo_diario() {
		$r = Livia_Registro::resumo( 1 );

		if ( 0 === $r['mensagens'] ) {
			return;
		}

		$taxa_erro = $r['erros'] / $r['mensagens'];
		$vale      = ( $taxa_erro > self::TAXA_ERRO_LIMITE ) || ( $r['bloqueadas'] > 0 );

		if ( ! $vale ) {
			return;
		}

		$corpo = sprintf( "Resumo da LivIA em %s:\n\n", $r['data'] )
			. sprintf( "  conversas ............ %d\n", $r['atendimentos'] )
			. sprintf( "  perguntas ............ %d\n", $r['mensagens'] )
			. sprintf( "  barradas pela trava .. %d\n", $r['bloqueadas'] )
			. sprintf( "  erros de API ......... %d (%.0f%%)\n", $r['erros'], $taxa_erro * 100 )
			. sprintf( "  tokens ............... %d\n", $r['tokens'] )
			. sprintf( "  latência p95 ......... %d ms\n", $r['p95_ms'] );

		if ( $r['bloqueadas'] > 0 ) {
			$corpo .= "\nAs respostas barradas são as linhas mais úteis que a LivIA produz: cada uma\n"
				. "é uma pergunta que a induziu a inventar. O cliente não viu nenhuma delas.\n"
				. "Vale ler no painel e ajustar a base.";
		}

		if ( $taxa_erro > self::TAXA_ERRO_LIMITE ) {
			$corpo .= "\n\nA taxa de erro passou do limiar. Confira a chave, o ID do modelo e a cota.";
		}

		// Janela curta: este é o alerta que deve poder chegar de novo amanhã.
		self::enviar_uma_vez( 'livia_alerta_resumo', 20 * HOUR_IN_SECONDS, 'resumo de ontem', $corpo );
	}
}
