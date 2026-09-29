<?php
/**
 * O que não precisa de modelo nenhum para ser respondido.
 *
 * Toda mensagem hoje carrega a base inteira — cerca de doze mil tokens de
 * entrada — antes de o modelo escrever a primeira letra. Para "quanto custa?"
 * isso se justifica: a resposta está na base. Para "oi", não: o modelo lê doze
 * mil tokens para devolver uma saudação que já sabíamos escrever.
 *
 * É de onde vinha a travada em mensagens simples. Aqui elas são respondidas
 * pelo próprio PHP, sem rede, em milissegundos e sem cota.
 *
 * O corte é estreito de propósito. Só entra mensagem que é EXATAMENTE cortesia
 * — "oi", "obrigado", "tchau". Basta o cliente colar uma pergunta junto
 * ("oi, quanto custa?") para o atalho não valer e a pergunta seguir o caminho
 * normal. Errar para o lado de mandar ao modelo custa tempo; errar para o outro
 * lado custa uma resposta errada, que é bem pior.
 *
 * As respostas não contêm contato, preço nem prazo — há caso de teste passando
 * cada uma delas pela trava justamente para garantir que continue assim.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Atalhos {

	/**
	 * Quantas palavras uma mensagem pode ter e ainda ser considerada cortesia.
	 *
	 * "oi tudo bem entao" tem quatro. Passou disso, tem conteúdo junto e a
	 * conversa é do modelo.
	 */
	const MAX_PALAVRAS = 4;

	/** Lembra a última resposta usada, para ela não sair duas vezes seguidas. */
	const ULTIMA = 'livia_atalho_';

	/**
	 * As famílias de cortesia.
	 *
	 * A comparação é sobre o texto normalizado — sem acento, sem pontuação,
	 * minúsculo, espaços colapsados — e é de igualdade, nunca de "contém".
	 */
	public static function familias() {
		return array(

			'saudacao' => array(
				'gatilhos' => array(
					'oi', 'ola', 'opa', 'eai', 'e ai', 'eae', 'hey', 'hello', 'alo',
					'oi tudo bem', 'ola tudo bem', 'tudo bem', 'tudo bom', 'como vai',
					'oi bom dia', 'oi boa tarde', 'oi boa noite',
					'bom dia', 'boa tarde', 'boa noite',
					'oi td bem', 'blz', 'beleza',
				),
				'respostas' => array(
					"Oi! Tudo bem?\nTô aqui pra ajudar com o briefing. O que travou?",
					"Oi, tudo certo?\nPode perguntar à vontade — qual parte do formulário tá te pegando?",
					"Oi!\nMe conta o que você tá tentando preencher que eu te explico.",
				),
			),

			'agradecimento' => array(
				'gatilhos' => array(
					'obrigado', 'obrigada', 'obg', 'brigado', 'brigada', 'vlw', 'valeu',
					'muito obrigado', 'muito obrigada', 'obrigado mesmo', 'show',
					'perfeito', 'otimo', 'legal', 'top', 'massa', 'entendi obrigado',
				),
				'respostas' => array(
					'Imagina! Qualquer outra dúvida é só chamar.',
					'De nada! Se pintar mais alguma coisa no meio do preenchimento, é só falar.',
					'Que bom que ajudou! Tô por aqui.',
				),
			),

			'despedida' => array(
				'gatilhos' => array(
					'tchau', 'flw', 'falou', 'ate mais', 'ate logo', 'adeus', 'bye',
					'ate mais tarde', 'obrigado tchau', 'vlw flw', 'ate breve',
				),
				'respostas' => array(
					'Até mais! Boa sorte com o briefing.',
					'Tchau! Se precisar de mais alguma coisa, é só abrir aqui de novo.',
				),
			),

			'confirmacao' => array(
				'gatilhos' => array(
					'ok', 'okay', 'certo', 'entendi', 'ta bom', 'ta certo',
					'sim', 'uhum', 'aham', 'isso', 'exato', 'combinado', 'ciente',
				),
				'respostas' => array(
					'Certo! Qualquer dúvida no resto do formulário, me chama.',
					'Beleza. Tô aqui se precisar.',
				),
			),
		);
	}

	/**
	 * Normaliza para comparar: sem acento, sem pontuação, sem repetição.
	 *
	 * "Oiiii!!! Tudo bem???" e "oi tudo bem" viram a mesma coisa. A redução de
	 * letra repetida é o que cobre o alongamento típico de chat sem precisar de
	 * uma entrada na lista para cada quantidade de "i".
	 */
	public static function normalizar( $texto ) {
		$texto = remove_accents( (string) $texto );
		$texto = strtolower( $texto );

		// Emoji e pontuação viram espaço: "oi :)" é uma saudação.
		$texto = preg_replace( '/[^a-z0-9\s]/u', ' ', $texto );

		// "oiiii" -> "oi", "bommm" -> "bom". Só corridas de TRÊS ou mais: assim
		// "isso" e "beleza" continuam inteiros, e o alongamento de chat some sem
		// precisar de uma entrada na lista para cada quantidade de letra.
		$texto = preg_replace( '/(.)\1{2,}/u', '$1', $texto );

		return trim( preg_replace( '/\s+/', ' ', $texto ) );
	}

	/**
	 * A resposta pronta para esta mensagem, ou null.
	 *
	 * @param string $pergunta texto do cliente, já limpo
	 * @param string $sessao   para não repetir a mesma frase na mesma conversa
	 * @return array|null array com texto e familia
	 */
	public static function responder( $pergunta, $sessao = '' ) {
		/**
		 * Desliga tudo de uma vez, para quem preferir que cada palavra venha do
		 * modelo: add_filter( 'livia_atalhos', '__return_false' ).
		 */
		if ( ! apply_filters( 'livia_atalhos', true ) ) {
			return null;
		}

		$chave = self::normalizar( $pergunta );
		if ( '' === $chave ) {
			return null;
		}

		// Barreira de tamanho antes da comparação: uma mensagem longa não é
		// cortesia nem que comece por "oi".
		if ( count( explode( ' ', $chave ) ) > self::MAX_PALAVRAS ) {
			return null;
		}

		foreach ( self::familias() as $nome => $familia ) {
			if ( ! in_array( $chave, $familia['gatilhos'], true ) ) {
				continue;
			}
			return array(
				'familia' => $nome,
				'texto'   => self::escolher( $familia['respostas'], $nome, $sessao ),
			);
		}

		return null;
	}

	/**
	 * Uma das respostas, evitando a última usada nesta conversa.
	 *
	 * Repetir a mesma frase palavra por palavra é o que mais denuncia que do
	 * outro lado não tem gente. Variar custa um transient curto.
	 */
	private static function escolher( array $respostas, $familia, $sessao ) {
		if ( 1 === count( $respostas ) ) {
			return $respostas[0];
		}

		$memoria = '';
		if ( '' !== (string) $sessao ) {
			$memoria = self::ULTIMA . $familia . '_' . substr( (string) $sessao, 0, 12 );
			$ultima  = get_transient( $memoria );
			if ( is_string( $ultima ) ) {
				$sobraram = array_values( array_diff( $respostas, array( $ultima ) ) );
				if ( $sobraram ) {
					$respostas = $sobraram;
				}
			}
		}

		$escolhida = $respostas[ wp_rand( 0, count( $respostas ) - 1 ) ];

		if ( '' !== $memoria ) {
			set_transient( $memoria, $escolhida, HOUR_IN_SECONDS );
		}

		return $escolhida;
	}
}
