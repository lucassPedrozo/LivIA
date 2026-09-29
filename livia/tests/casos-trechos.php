<?php
/**
 * Casos da seleção de contexto.
 *
 * O que se testa aqui não é "a seleção escolheu bem" — isso é julgamento, e
 * mora na bateria de comportamento. O que se testa é o contrato que a seleção
 * não pode quebrar nem quando escolhe mal:
 *
 *   - o núcleo vai sempre, com TODOS os guardrails dentro;
 *   - a instrução é idêntica entre perguntas, senão o cache de contexto morre;
 *   - a trava enxerga o que foi mandado, senão ela barra material legítimo;
 *   - existe uma volta atrás que manda a base inteira.
 */

defined( 'ABSPATH' ) || exit;

/** O que de fato viaja para a API nesta pergunta: instrução + turno decorado. */
function livia_teste_pedido( $pergunta, $contexto = array() ) {
	livia_teste_zerar();
	livia_teste_config();

	$sessao = Livia_Sessao::novo_id();
	$token  = Livia_Rest::assinar( $sessao, time() + 600 );
	if ( $contexto ) {
		Livia_Sessao::definir_contexto( $sessao, $contexto );
	}

	$capturado = array();
	add_filter(
		'livia_pre_gerar',
		function ( $curto, $instrucao, $historico ) use ( &$capturado ) {
			$turno = '';
			for ( $i = count( $historico ) - 1; $i >= 0; $i-- ) {
				if ( 'user' === $historico[ $i ]['role'] ) {
					$turno = $historico[ $i ]['parts'][0]['text'];
					break;
				}
			}
			$capturado = array( 'instrucao' => $instrucao, 'turno' => $turno );
			return array(
				'texto'    => 'ok',
				'uso'      => array( 'entrada' => 10, 'saida' => 5, 'total' => 15 ),
				'finish'   => 'STOP',
				'truncado' => false,
			);
		},
		10,
		3
	);

	Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => $pergunta ) ) );

	$capturado['bytes'] = strlen( $capturado['instrucao'] ) + strlen( $capturado['turno'] );
	return $capturado;
}

function livia_teste_base() {
	return (string) file_get_contents( LIVIA_DIR . 'conhecimento/base_conhecimento.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}

return array(

	// ------------------------------------------------------- o que encolheu

	array(
		'grupo' => 'trechos',
		'nome'  => 'a pergunta comum não carrega mais a base inteira',
		'executar' => function () {
			$base   = livia_teste_base();
			$pedido = livia_teste_pedido( 'o que e dominio' );

			if ( $pedido['bytes'] >= strlen( $base ) ) {
				return sprintf( 'mandou %d bytes; a base inteira tem %d', $pedido['bytes'], strlen( $base ) );
			}

			// Meio é folgado: o aferido nas 41 perguntas reais foi 43%.
			$fatia = $pedido['bytes'] / strlen( $base );
			return $fatia < 0.6 ? null : sprintf( 'economizou pouco: %.0f%% da base ainda vai junto', $fatia * 100 );
		},
	),

	array(
		'grupo' => 'trechos',
		'nome'  => 'a pergunta sobre domínio leva o trecho sobre domínio',
		'executar' => function () {
			$pedido = livia_teste_pedido( 'o que e dominio' );
			return false !== mb_strpos( $pedido['turno'], 'endereço do seu site' )
				? null
				: 'a explicação de domínio não foi junto — ela responderia que não sabe';
		},
	),

	// ------------------------------------------- o núcleo é inegociável

	array(
		'grupo' => 'trechos',
		'nome'  => 'os guardrails vão em toda pergunta, escolha o que escolher',
		'executar' => function () {
			// Perguntas de assuntos distantes: nenhuma delas pode perder as
			// regras. Se um guardrail dependesse da seleção, bastaria perguntar
			// de outro assunto para ficar sem ele.
			$perguntas = array(
				'o que e dominio',
				'quanto custa',
				'asdfgh qwerty zxcvb',
				'me escreve um poema sobre gatos',
			);

			$exigido = array(
				'REGRAS DE OURO'       => 'os guardrails',
				'Nunca invente'        => 'a regra de não inventar',
				'IDENTIDADE DA LIVIA'  => 'o tom de voz',
				'O QUE VOCÊ NÃO SABE'  => 'a lista do que ela não sabe',
				'ESCALONAMENTO'        => 'quando encaminhar',
				'LEMBRETE FINAL'       => 'a defesa contra injeção',
			);

			foreach ( $perguntas as $p ) {
				$pedido = livia_teste_pedido( $p );
				foreach ( $exigido as $marca => $oque ) {
					if ( false === mb_strpos( $pedido['instrucao'], $marca ) ) {
						return sprintf( 'perdeu %s na pergunta "%s"', $oque, $p );
					}
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'trechos',
		'nome'  => 'pergunta que não casa com nada ainda recebe instrução inteira',
		'executar' => function () {
			$pedido = livia_teste_pedido( 'xkcd zzzz qqqq' );

			if ( strlen( $pedido['instrucao'] ) < 5000 ) {
				return 'a instrução veio vazia ou quase: ' . strlen( $pedido['instrucao'] ) . ' bytes';
			}
			// O resumo de uma página é o seguro contra erro de seleção.
			return false !== mb_strpos( $pedido['instrucao'], 'RESUMO DE UMA PÁGINA' )
				? null
				: 'ficou sem o resumo geral — sem trecho e sem resumo ela não reconhece nem o assunto';
		},
	),

	// ------------------------------------------------- o cache sobrevive

	array(
		'grupo' => 'trechos',
		'nome'  => 'duas perguntas diferentes mandam a MESMA instrução',
		'executar' => function () {
			$a = livia_teste_pedido( 'o que e dominio' );
			$b = livia_teste_pedido( 'quantos produtos eu posso colocar' );

			if ( $a['instrucao'] !== $b['instrucao'] ) {
				return 'a instrução mudou com a pergunta: o cache de contexto seria recriado a cada mensagem, '
					. 'gastando mais do que economiza';
			}
			// E os trechos, que mudam, têm que estar mesmo mudando.
			return $a['turno'] !== $b['turno']
				? null
				: 'os trechos não mudaram entre duas perguntas de assuntos diferentes';
		},
	),

	// ------------------------------------------------------- a trava

	array(
		'grupo' => 'trechos',
		'nome'  => 'a trava enxerga o que foi mandado no turno, não só a instrução',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();

			$base    = livia_teste_base();
			$escolha = Livia_Trechos::escolher( $base, 'o que e dominio' );
			$instr   = Livia_Prompt::instrucao( $escolha['nucleo'] );

			// Como Livia_Rest monta: instrução MAIS os trechos escolhidos.
			$com  = Livia_Trava::permitidos( $instr . "\n" . $escolha['trechos'] );
			$sem  = Livia_Trava::permitidos( $instr );

			// joinvix.com.br é o exemplo que ela usa ao explicar domínio, e ele
			// vive no trecho escolhido. Montar a lista só com a instrução faria
			// a trava cortar a resposta no meio de um endereço legítimo.
			$achou = function ( $listas ) {
				foreach ( $listas as $lista ) {
					if ( is_array( $lista ) && isset( $lista['joinvix.com.br'] ) ) {
						return true;
					}
				}
				return false;
			};

			if ( ! $achou( $com ) ) {
				return 'a lista de permitidos não cobre o endereço que foi mandado no trecho';
			}
			return count( $com, COUNT_RECURSIVE ) >= count( $sem, COUNT_RECURSIVE )
				? null
				: 'somar os trechos ENCOLHEU a lista de permitidos';
		},
	),

	// --------------------------------------------------- a volta atrás

	array(
		'grupo' => 'trechos',
		'nome'  => 'o modo "inteira" volta a mandar a base toda',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			$tudo = get_option( Livia_Config::OPCAO, array() );
			$tudo['BASE_MODO'] = 'inteira';
			update_option( Livia_Config::OPCAO, $tudo );

			$sessao = Livia_Sessao::novo_id();
			$token  = Livia_Rest::assinar( $sessao, time() + 600 );

			$instrucao = '';
			add_filter(
				'livia_pre_gerar',
				function ( $curto, $instr ) use ( &$instrucao ) {
					$instrucao = $instr;
					return array(
						'texto'    => 'ok',
						'uso'      => array( 'entrada' => 1, 'saida' => 1, 'total' => 2 ),
						'finish'   => 'STOP',
						'truncado' => false,
					);
				},
				10,
				2
			);

			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );

			// Uma seção que a seleção nunca escolheria para "o que é domínio".
			return false !== mb_strpos( $instrucao, 'SEÇÃO 10' )
				? null
				: 'o modo "inteira" não mandou a base toda — não há volta atrás se a seleção errar';
		},
	),

	// ----------------------------------------------- partir o documento

	array(
		'grupo' => 'trechos',
		'nome'  => 'o glossário é partido em verbetes, não fica num bloco só',
		'executar' => function () {
			$partidos = Livia_Trechos::partir( livia_teste_base() );

			$verbetes = 0;
			foreach ( $partidos as $t ) {
				if ( false !== mb_strpos( $t['chave'], 'GLOSSÁRIO' ) ) {
					++$verbetes;
				}
			}

			// Inteiro num pedaço só de 2,8 KB, o glossário perdia para qualquer
			// subseção pequena — e "o que é X" é a pergunta mais comum que existe.
			return $verbetes >= 8 ? null : "o glossário virou {$verbetes} trecho(s); esperava um por verbete";
		},
	),

	array(
		'grupo' => 'trechos',
		'nome'  => '"e-mail" e "email" são a mesma palavra',
		'executar' => function () {
			// O hífen virava espaço, "e" era descartado por ser curto, e sobrava
			// "mail" — que não casa com "email". Toda pergunta sobre e-mail
			// selecionava zero trechos.
			$com  = Livia_Trechos::palavras( 'meu e-mail de contato' );
			$sem  = Livia_Trechos::palavras( 'meu email de contato' );

			if ( ! in_array( 'email', $com, true ) ) {
				return 'e-mail com hífen não virou "email": ' . implode( ',', $com );
			}
			if ( $com !== $sem ) {
				return 'as duas grafias dão listas diferentes';
			}

			$pedido = livia_teste_pedido( 'que email eu ponho aqui' );
			return false !== mb_strpos( $pedido['turno'], 'O QUE A SUA BASE DIZ' )
				? null
				: 'pergunta sobre e-mail não selecionou trecho nenhum';
		},
	),

	array(
		'grupo' => 'trechos',
		'nome'  => 'a fala do cliente continua sendo a última coisa cercada',
		'executar' => function () {
			$pedido = livia_teste_pedido( 'o que e dominio' );
			$turno  = $pedido['turno'];

			$base_diz = mb_strpos( $turno, 'O QUE A SUA BASE DIZ' );
			$abre     = mb_strpos( $turno, Livia_Prompt::ABRE );
			$fecha    = mb_strpos( $turno, Livia_Prompt::FECHA );
			$nota     = mb_strpos( $turno, 'não instrução' );

			if ( false === $base_diz || false === $abre || false === $nota ) {
				return 'o turno perdeu uma das três partes';
			}

			// A ordem é a defesa: material da base, fala cercada, e a regra por
			// último — a última instrução é a que o modelo lê por último.
			return ( $base_diz < $abre && $abre < $fecha && $fecha < $nota )
				? null
				: 'a ordem do turno mudou e a re-ancoragem deixou de ser a última coisa';
		},
	),
);
