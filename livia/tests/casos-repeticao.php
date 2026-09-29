<?php
/**
 * Casos da repetição: o bloco que nasceu de ler as 37 respostas da homologação.
 *
 * O número da equipe aparecia em 16 delas, e a construção "chama a equipe no
 * [canal] informando o domínio do seu site" em 9 — palavra por palavra. A causa
 * estava escrita na base: a Seção 11 dava uma FÓRMULA de encaminhamento e
 * mandava usá-la "sempre", e a Seção 9 se chamava "respostas prontas".
 *
 * Texto de base não se testa por igualdade — o que se testa é que as armadilhas
 * que produziram aquele comportamento não voltaram, e que a defesa que roda
 * fora do modelo (marcar a conversa em que o contato já foi dado) funciona.
 */

defined( 'ABSPATH' ) || exit;

/** A base como ela vai para a API, com o canal já substituído. */
function livia_teste_base_crua() {
	return (string) file_get_contents( LIVIA_DIR . 'conhecimento/base_conhecimento.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

/**
 * Responde o texto pedido e guarda o que foi mandado à API em cada chamada.
 *
 * $pedidos vira uma lista de ['instrucao' => ..., 'janela' => ...].
 */
function livia_teste_espiar_pedidos( $texto, &$pedidos ) {
	$pedidos = array();
	add_filter(
		'livia_pre_gerar',
		function ( $curto, $instrucao, $historico ) use ( $texto, &$pedidos ) {
			$pedidos[] = array( 'instrucao' => $instrucao, 'janela' => $historico );
			return array(
				'texto'    => $texto,
				'uso'      => array( 'entrada' => 100, 'saida' => 20, 'total' => 120 ),
				'finish'   => 'STOP',
				'truncado' => false,
			);
		},
		10,
		3
	);
}

/** O texto do último turno do cliente dentro de uma janela preparada. */
function livia_teste_ultimo_turno( array $janela ) {
	for ( $i = count( $janela ) - 1; $i >= 0; $i-- ) {
		if ( 'user' === $janela[ $i ]['role'] ) {
			return $janela[ $i ]['parts'][0]['text'];
		}
	}
	return '';
}

return array(

	// ------------------------------------------------------------ a base

	array(
		'grupo' => 'repeticao',
		'nome'  => 'a fórmula de encaminhamento saiu da base',
		'executar' => function () {
			$base = livia_teste_base_crua();

			// Era esta a frase que saía em 9 das 37 respostas.
			if ( false !== stripos( $base, 'informando o domínio do seu site' ) ) {
				return 'a fórmula literal continua na base — é ela que o modelo copia';
			}

			// E era este "sempre" que transformava exceção em rodapé.
			if ( preg_match( '/Sempre indicar/iu', $base ) ) {
				return 'a instrução de "sempre indicar" voltou';
			}

			return null;
		},
	),

	array(
		'grupo' => 'repeticao',
		'nome'  => 'nenhuma seção se anuncia como texto pronto para copiar',
		'executar' => function () {
			$base = livia_teste_base_crua();

			if ( false !== stripos( $base, 'RESPOSTAS PRONTAS' ) ) {
				return 'uma seção ainda promete respostas prontas — com temperatura 0.2 ela é recitada';
			}

			// A seção precisa dizer, em algum lugar, que dali não se copia.
			if ( false === stripos( $base, 'não é texto para copiar' ) ) {
				return 'a seção de perguntas frequentes não avisa que não é para copiar';
			}

			return null;
		},
	),

	array(
		'grupo' => 'repeticao',
		'nome'  => 'a base manda terminar na resposta quando ela ficou completa',
		'executar' => function () {
			$base = livia_teste_base_crua();

			foreach ( array( 'NÃO encaminhe quando', 'uma vez por conversa' ) as $exigido ) {
				if ( false === stripos( $base, $exigido ) ) {
					return 'falta na base a regra: ' . $exigido;
				}
			}
			return null;
		},
	),

	// ------------------------------------------------- reconhecer o canal

	array(
		'grupo' => 'repeticao',
		'nome'  => 'o contato é reconhecido escrito de qualquer jeito',
		'executar' => function () {
			$canal = 'WhatsApp (47) 3433-5066';

			$formas = array(
				'Chama no (47) 3433-5066 que eles resolvem.',
				'O número é 47 34335066.',
				'Fala com a equipe: +55 47 3433 5066',
				'Liga pra 4734335066 informando seu domínio.',
				"Contato:\n(47)3433.5066",
			);

			foreach ( $formas as $texto ) {
				if ( ! Livia_Prompt::contem_canal( $texto, $canal ) ) {
					return 'não reconheceu o contato em: ' . $texto;
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'repeticao',
		'nome'  => 'número que não é o contato não conta como encaminhamento',
		'executar' => function () {
			$canal = 'WhatsApp (47) 3433-5066';

			// Os números que a LivIA escreve o tempo todo. Um falso positivo aqui
			// faria ela CALAR o contato numa conversa em que nunca o deu.
			$inocentes = array(
				'São até 72 horas depois do material completo.',
				'A equipe insere até 15 páginas ou 15 produtos na montagem.',
				'O formulário aceita jpg, png, pdf e docx.',
				'Meu domínio é exemplo123456789.com.br',
			);

			foreach ( $inocentes as $texto ) {
				if ( Livia_Prompt::contem_canal( $texto, $canal ) ) {
					return 'confundiu com o contato: ' . $texto;
				}
			}

			// E canal vazio nunca casa com nada.
			return Livia_Prompt::contem_canal( 'qualquer coisa', '' )
				? 'canal vazio casou com uma resposta qualquer'
				: null;
		},
	),

	// -------------------------------------------- a marca na conversa

	array(
		'grupo' => 'repeticao',
		'nome'  => 'dado o contato uma vez, o turno seguinte manda não repetir',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			list( , $token ) = livia_teste_sessao();

			$pedidos = null;
			livia_teste_espiar_pedidos(
				"Essa parte é com a equipe.\n\nChama no (47) 3433-5066 com o seu domínio em mãos.",
				$pedidos
			);

			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'quanto custa o plano' ) ) );
			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'e o prazo' ) ) );

			if ( count( $pedidos ) < 2 ) {
				return 'esperava duas chamadas à API, houve ' . count( $pedidos );
			}

			$primeiro = livia_teste_ultimo_turno( $pedidos[0]['janela'] );
			$segundo  = livia_teste_ultimo_turno( $pedidos[1]['janela'] );

			if ( false !== stripos( $primeiro, 'JÁ passou o contato' ) ) {
				return 'avisou para não repetir logo na primeira pergunta da conversa';
			}
			if ( false === stripos( $segundo, 'JÁ passou o contato' ) ) {
				return 'não avisou para não repetir depois de o contato ter sido dado';
			}
			return null;
		},
	),

	array(
		'grupo' => 'repeticao',
		'nome'  => 'resposta que não deu o contato não marca a conversa',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			list( , $token ) = livia_teste_sessao();

			$pedidos = null;
			livia_teste_espiar_pedidos(
				'Domínio é o endereço do seu site na internet, o que a pessoa digita pra te achar.',
				$pedidos
			);

			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );
			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'e hospedagem' ) ) );

			$segundo = livia_teste_ultimo_turno( $pedidos[1]['janela'] );

			return false !== stripos( $segundo, 'JÁ passou o contato' )
				? 'calou um contato que nunca foi dado — a pessoa ficaria sem saber com quem falar'
				: null;
		},
	),

	array(
		'grupo' => 'repeticao',
		'nome'  => 'resposta barrada pela trava não marca a conversa',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			list( $sessao, $token ) = livia_teste_sessao();

			$pedidos = null;
			// Telefone que NÃO é o da equipe: a trava corta, e o cliente recebe o
			// texto de recusa no lugar. O contato daquela resposta ninguém leu.
			livia_teste_espiar_pedidos( 'Liga pra (11) 98888-7777 que eles resolvem.', $pedidos );

			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'qual o telefone de voces' ) ) );

			return Livia_Sessao::tem_marca( $sessao, 'encaminhou' )
				? 'marcou a conversa com base num texto que foi barrado antes de chegar ao cliente'
				: null;
		},
	),

	// ------------------------------------------------ o cache continua válido

	array(
		'grupo' => 'repeticao',
		'nome'  => 'o aviso vai no turno, não na instrução — o cache sobrevive',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			list( , $token ) = livia_teste_sessao();

			$pedidos = null;
			livia_teste_espiar_pedidos( 'Fala com a equipe no (47) 3433-5066.', $pedidos );

			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'quanto custa' ) ) );
			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'e o prazo' ) ) );

			// A instrução é o que o cache de contexto guarda. Duas variantes dela
			// fariam o cache ser recriado a cada alternância — gastando exatamente
			// o que ele existe para economizar.
			return $pedidos[0]['instrucao'] === $pedidos[1]['instrucao']
				? null
				: 'a instrução mudou entre os turnos: o cache de contexto seria refeito toda vez';
		},
	),

	array(
		'grupo' => 'repeticao',
		'nome'  => 'o aviso entra só no último turno do cliente',
		'executar' => function () {
			$janela = array(
				array( 'role' => 'user',  'parts' => array( array( 'text' => 'quanto custa' ) ) ),
				array( 'role' => 'model', 'parts' => array( array( 'text' => 'fala com a equipe' ) ) ),
				array( 'role' => 'user',  'parts' => array( array( 'text' => 'e o prazo' ) ) ),
			);

			$pronta = Livia_Prompt::preparar( $janela, true );

			if ( false !== stripos( $pronta[0]['parts'][0]['text'], 'JÁ passou o contato' ) ) {
				return 'decorou um turno antigo — o histórico viraria marcação repetida e token gasto';
			}
			if ( false === stripos( $pronta[2]['parts'][0]['text'], 'JÁ passou o contato' ) ) {
				return 'não decorou o último turno';
			}
			return $pronta[1]['parts'][0]['text'] === 'fala com a equipe'
				? null
				: 'mexeu na fala do modelo';
		},
	),

	// ------------------------------------------------ a marca sobrevive

	array(
		'grupo' => 'repeticao',
		'nome'  => 'a marca não é apagada pelo turno seguinte',
		'executar' => function () {
			livia_teste_zerar();
			$sessao = Livia_Sessao::novo_id();

			Livia_Sessao::acrescentar( $sessao, 'user', 'quanto custa' );
			Livia_Sessao::marcar( $sessao, 'encaminhou' );

			// gravar() é chamado por acrescentar() sem saber das marcas. Se ele
			// não preservar o que já estava lá, a marca dura um turno só e a
			// LivIA volta a repetir o contato na mensagem seguinte.
			Livia_Sessao::acrescentar( $sessao, 'model', 'fala com a equipe' );
			Livia_Sessao::acrescentar( $sessao, 'user', 'e o prazo' );

			if ( ! Livia_Sessao::tem_marca( $sessao, 'encaminhou' ) ) {
				return 'a marca se perdeu no turno seguinte';
			}

			// E definir_contexto também não pode levá-la junto.
			Livia_Sessao::definir_contexto( $sessao, array( 'pagina' => 'briefing' ) );

			if ( ! Livia_Sessao::tem_marca( $sessao, 'encaminhou' ) ) {
				return 'definir_contexto apagou a marca';
			}

			return Livia_Sessao::tem_marca( $sessao, 'nunca_marcada' )
				? 'inventou uma marca que ninguém pôs'
				: null;
		},
	),
);
