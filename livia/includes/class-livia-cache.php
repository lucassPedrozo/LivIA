<?php
/**
 * Cache de contexto da API.
 *
 * A instrução vai inteira em toda pergunta — hoje são cerca de doze mil tokens
 * de entrada por resposta, e ela é byte a byte idêntica em todas as chamadas.
 * É exatamente o caso de uso do cache de contexto: a API guarda a instrução uma
 * vez e as chamadas seguintes só apontam para ela.
 *
 * **Desligado por padrão, de propósito.** Há um tamanho mínimo para o conteúdo
 * poder ser cacheado, e ele varia por modelo — a instrução da LivIA pode estar
 * abaixo dele. Em vez de supor, este código tenta, guarda o motivo exato da
 * recusa e continua funcionando sem cache. Ligar é uma linha:
 *
 *     add_filter( 'livia_cache_contexto', '__return_true' );
 *
 * Depois de ligar, confira em Configurações → LivIA ou na rota de saúde se ele
 * pegou. Se não pegou, o motivo estará lá escrito — provavelmente dizendo que a
 * instrução é pequena demais, e aí não há o que fazer além de desligar de novo.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Cache {

	const GUARDADO = 'livia_cache_ctx';
	const ULTIMO_ERRO = 'livia_cache_erro';

	/** Quanto tempo a API guarda a instrução. */
	const TTL = 3600;

	/** Margem antes do vencimento, para não usar um nome que expira no meio. */
	const MARGEM = 120;

	/** Prefixo do contador de movimento, um por hora cheia (UTC). */
	const MOVIMENTO = 'livia_mov_';

	/**
	 * Perguntas na janela recente a partir das quais o cache se paga.
	 *
	 * O cache cobra armazenamento por hora, independente de uso. Com pouca
	 * conversa, manter a instrução guardada custa mais do que os tokens que ela
	 * economiza; com movimento, é o contrário, e por larga margem. Seis é o
	 * ponto em que a conta vira para uma instrução do tamanho desta base.
	 */
	const MINIMO_POR_HORA = 6;

	/**
	 * Conta mais uma pergunta que foi ao modelo.
	 *
	 * Contador em transient, não consulta ao banco: isto roda no caminho da
	 * resposta ao cliente, e uma COUNT() por pergunta seria pagar em latência
	 * justamente a economia que estamos tentando fazer.
	 */
	public static function registrar_uso() {
		$chave = self::MOVIMENTO . gmdate( 'YmdH' );
		set_transient( $chave, ( (int) get_transient( $chave ) ) + 1, 2 * HOUR_IN_SECONDS );
	}

	/**
	 * Quantas perguntas na hora corrente e na anterior.
	 *
	 * Duas faixas em vez de uma porque a hora cheia zera de repente: às 14h01,
	 * uma janela de uma hora só enxergaria um minuto de movimento e desligaria
	 * o cache no meio do pico.
	 */
	public static function movimento() {
		return (int) get_transient( self::MOVIMENTO . gmdate( 'YmdH' ) )
			+ (int) get_transient( self::MOVIMENTO . gmdate( 'YmdH', time() - HOUR_IN_SECONDS ) );
	}

	/**
	 * O cache deve ser usado agora?
	 *
	 * Em 'auto', quem decide é o movimento — liga sozinho quando há conversa
	 * suficiente para compensar e se apaga sozinho quando o movimento cai. O
	 * filtro continua tendo a última palavra, para os testes e para quem quiser
	 * forçar um dos lados em código.
	 */
	public static function ativo() {
		$modo = Livia_Config::cache_modo();

		if ( 'nunca' === $modo ) {
			$padrao = false;
		} elseif ( 'sempre' === $modo ) {
			$padrao = true;
		} else {
			$padrao = self::movimento() >= self::MINIMO_POR_HORA;
		}

		return (bool) apply_filters( 'livia_cache_contexto', $padrao );
	}

	/**
	 * O nome do conteúdo cacheado para esta instrução, ou null.
	 *
	 * Null nunca é erro fatal: quem chama manda a instrução inline, como sempre.
	 *
	 * @return string|null
	 */
	public static function nome( $instrucao, $modelo ) {
		if ( ! self::ativo() ) {
			return null;
		}

		$assinatura = md5( $modelo . '|' . $instrucao );
		$guardado   = get_transient( self::GUARDADO );

		if ( is_array( $guardado )
			&& isset( $guardado['assinatura'], $guardado['nome'], $guardado['expira'] )
			&& $guardado['assinatura'] === $assinatura
			&& $guardado['expira'] > time() + self::MARGEM
		) {
			return $guardado['nome'];
		}

		return self::criar( $instrucao, $modelo, $assinatura );
	}

	/**
	 * Pede à API para guardar a instrução.
	 *
	 * @return string|null
	 */
	private static function criar( $instrucao, $modelo, $assinatura ) {
		// Mesmo atalho dos outros caminhos de rede: permite testar sem cota.
		$curto = apply_filters( 'livia_pre_criar_cache', null, $instrucao, $modelo );
		if ( null !== $curto ) {
			return is_string( $curto ) ? $curto : null;
		}

		$chave = Livia_Config::api_key();
		if ( '' === $chave ) {
			return null;
		}

		$resposta = wp_remote_post(
			Livia_Gemini::ENDPOINT . '/cachedContents',
			array(
				'timeout'     => 20,
				'headers'     => array(
					'Content-Type'   => 'application/json',
					'X-goog-api-key' => $chave,
				),
				'body'        => wp_json_encode(
					array(
						'model'             => 'models/' . $modelo,
						'systemInstruction' => array( 'parts' => array( array( 'text' => $instrucao ) ) ),
						'ttl'               => self::TTL . 's',
					)
				),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $resposta ) ) {
			return self::falhou( 'não consegui falar com a API: ' . $resposta->get_error_message() );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resposta );
		$dados  = json_decode( wp_remote_retrieve_body( $resposta ), true );

		if ( 200 !== $codigo || ! isset( $dados['name'] ) ) {
			$detalhe = isset( $dados['error']['message'] )
				? $dados['error']['message']
				: sprintf( 'HTTP %d', $codigo );
			return self::falhou( $detalhe );
		}

		$expira = isset( $dados['expireTime'] )
			? (int) strtotime( $dados['expireTime'] )
			: time() + self::TTL;

		set_transient(
			self::GUARDADO,
			array(
				'assinatura' => $assinatura,
				'nome'       => (string) $dados['name'],
				'expira'     => $expira,
			),
			max( 60, $expira - time() - self::MARGEM )
		);

		delete_transient( self::ULTIMO_ERRO );
		return (string) $dados['name'];
	}

	/**
	 * Guarda o motivo e desiste — sem barulho para o cliente.
	 *
	 * A janela curta é de propósito: se a causa for transitória, a próxima
	 * pergunta tenta de novo. Se for permanente (instrução pequena demais),
	 * o motivo fica visível no painel e alguém decide desligar o cache.
	 */
	private static function falhou( $motivo ) {
		set_transient( self::ULTIMO_ERRO, $motivo, 15 * MINUTE_IN_SECONDS );
		return null;
	}

	public static function ultimo_erro() {
		$e = get_transient( self::ULTIMO_ERRO );
		return is_string( $e ) ? $e : null;
	}

	/** Para a tela de configuração e a rota de saúde. */
	public static function estado() {
		$guardado = get_transient( self::GUARDADO );
		return array(
			'modo'        => Livia_Config::cache_modo(),
			'ativo'       => self::ativo(),
			'movimento'   => self::movimento(),
			'minimo'      => self::MINIMO_POR_HORA,
			'em_uso'      => is_array( $guardado ) && ! empty( $guardado['nome'] ),
			'expira_em'   => is_array( $guardado ) && isset( $guardado['expira'] ) ? gmdate( 'c', $guardado['expira'] ) : null,
			'ultimo_erro' => self::ultimo_erro(),
		);
	}

	/** Esquece o que estiver guardado — usado quando a base muda. */
	public static function esquecer() {
		delete_transient( self::GUARDADO );
		delete_transient( self::ULTIMO_ERRO );
	}
}
