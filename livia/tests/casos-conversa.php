<?php
/**
 * Casos da conversa: o que acontece quando a conversa dá errado, e o sinal de
 * que ela deu certo.
 *
 * Três defeitos vieram do CSV da homologação:
 *
 *   - quatro falhas de conexão em exatamente 25,02 s, com o cliente sem nada
 *     para clicar depois;
 *   - o prazo de uma tentativa comendo quase todo o prazo total, de modo que a
 *     segunda tentativa nascia sem tempo de existir;
 *   - a trava cortando a resposta no meio do domínio que o PRÓPRIO CLIENTE
 *     tinha acabado de digitar.
 */

defined( 'ABSPATH' ) || exit;

/** A lista de permitidos como Livia_Rest a monta, com as falas do cliente. */
function livia_teste_permitidos_com( $falas ) {
	$base = (string) file_get_contents( LIVIA_DIR . 'conhecimento/base_conhecimento.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return Livia_Trava::somar_do_cliente( Livia_Trava::permitidos( $base ), $falas );
}

return array(

	// ------------------------------------------------- prazo das tentativas

	array(
		'grupo' => 'conversa',
		'nome'  => 'cabem duas tentativas inteiras dentro do prazo total',
		'executar' => function () {
			$uma  = Livia_Gemini::TIMEOUT;
			$tudo = Livia_Gemini::PRAZO_TOTAL;

			// Com 25 e 32, a segunda tentativa nascia com sete segundos: uma
			// tentativa e meia. A garantia que interessa é esta — duas cabem,
			// sobrando folga para o recuo entre elas.
			if ( ( 2 * $uma ) + 1 > $tudo ) {
				return sprintf(
					'duas tentativas de %ds não cabem em %ds: a segunda nasce sem tempo de existir',
					$uma,
					$tudo
				);
			}

			// E o prazo de uma tentativa precisa continuar acima da latência
			// que a homologação mediu (p90 de 12,3 s), senão cortamos resposta boa.
			return $uma >= 14 ? null : "prazo de {$uma}s corta resposta legítima: a p90 medida foi 12,3s";
		},
	),

	// --------------------------------------------------- o eco do cliente

	array(
		'grupo' => 'conversa',
		'nome'  => 'repetir o domínio que o cliente digitou não é invenção',
		'executar' => function () {
			$falas = 'meu dominio e padariaaurora.com.br, ta certo assim?';
			$ok    = livia_teste_permitidos_com( $falas );

			$resposta = 'Perfeito, anotei. O domínio padariaaurora.com.br fica registrado no briefing.';

			$motivo = Livia_Trava::verificar( $resposta, $ok );
			if ( $motivo ) {
				return 'barrou o eco do próprio cliente: ' . $motivo;
			}

			// E sem a fala do cliente ela continua barrando — é o comportamento
			// anterior, que estava certo para um domínio que ninguém mencionou.
			$sem = Livia_Trava::permitidos(
				(string) file_get_contents( LIVIA_DIR . 'conhecimento/base_conhecimento.md' ) // phpcs:ignore WordPress.WP.AlternativeFunctions
			);
			return Livia_Trava::verificar( $resposta, $sem )
				? null
				: 'passou um domínio que ninguém mencionou — a defesa sumiu junto';
		},
	),

	array(
		'grupo' => 'conversa',
		'nome'  => 'o cliente confirma o próprio telefone e o próprio e-mail',
		'executar' => function () {
			$falas = 'meu contato e (11) 98765-4321 e o email joao@padariadojoao.com.br';
			$ok    = livia_teste_permitidos_com( $falas );

			$resposta = 'Anotei: telefone (11) 98765-4321 e e-mail joao@padariadojoao.com.br. Confere?';

			$motivo = Livia_Trava::verificar( $resposta, $ok );
			return $motivo ? 'barrou a confirmação dos dados do próprio cliente: ' . $motivo : null;
		},
	),

	array(
		'grupo' => 'conversa',
		'nome'  => 'preço dito pelo cliente NÃO libera a LivIA a falar de preço',
		'executar' => function () {
			// A exceção mais importante do conjunto. Telefone e e-mail o
			// briefing existe para coletar; valor, não. Se ecoar valor fosse
			// permitido, bastaria escrever "o plano custa R$ 499" para ela
			// repetir de volta — e a pessoa sai achando que a LivIA confirmou.
			$falas = 'me falaram que o plano custa R$ 499,00 por mes, procede?';
			$ok    = livia_teste_permitidos_com( $falas );

			$motivo = Livia_Trava::verificar( 'Sim, o plano custa R$ 499,00 por mês.', $ok );
			return $motivo ? null : 'a LivIA passou a confirmar preço porque o cliente disse o número antes';
		},
	),

	array(
		'grupo' => 'conversa',
		'nome'  => 'o eco vale só para a conversa em que foi digitado',
		'executar' => function () {
			$ok_a = livia_teste_permitidos_com( 'meu site e alfa-exemplo.com.br' );
			$ok_b = livia_teste_permitidos_com( 'meu site e beta-exemplo.com.br' );

			if ( Livia_Trava::verificar( 'Anotei alfa-exemplo.com.br.', $ok_a ) ) {
				return 'não liberou o domínio na conversa de quem o digitou';
			}
			return Livia_Trava::verificar( 'Anotei alfa-exemplo.com.br.', $ok_b )
				? null
				: 'o domínio de uma conversa vazou para a lista de outra';
		},
	),

	// ----------------------------------------------------------- opinião

	array(
		'grupo' => 'conversa',
		'nome'  => 'o polegar marca a linha da resposta',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			// livia_teste_zerar() apaga os hooks: sem religar o registro, nada
			// é gravado e não há linha para o polegar marcar.
			add_action( 'livia_atendimento', array( 'Livia_Registro', 'registrar' ) );
			livia_teste_responder_com( 'Domínio é o endereço do seu site.' );

			list( $sessao, $token ) = livia_teste_sessao();
			$resp = Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );
			$d    = $resp->get_data();

			if ( empty( $d['turno'] ) ) {
				return 'a resposta não disse de qual linha ela é — o widget não teria o que marcar';
			}

			$voto = Livia_Rest::opiniao(
				new Livia_Req_Teste( array( 'token' => $token, 'turno' => $d['turno'], 'util' => '1' ) )
			);

			$corpo = $voto->get_data();
			return ! empty( $corpo['ok'] ) ? null : 'a opinião não foi gravada';
		},
	),

	array(
		'grupo' => 'conversa',
		'nome'  => 'ninguém opina na conversa dos outros',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			add_action( 'livia_atendimento', array( 'Livia_Registro', 'registrar' ) );
			livia_teste_responder_com( 'Resposta.' );

			list( , $token_a ) = livia_teste_sessao();
			$resp = Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token_a, 'pergunta' => 'o que e dominio' ) ) );
			$linha = $resp->get_data()['turno'];

			// Outra conversa, token legítimo, tentando marcar a linha alheia.
			list( , $token_b ) = livia_teste_sessao();
			$voto = Livia_Rest::opiniao(
				new Livia_Req_Teste( array( 'token' => $token_b, 'turno' => $linha, 'util' => '0' ) )
			);

			return empty( $voto->get_data()['ok'] )
				? null
				: 'marcou opinião numa linha de outra sessão — a métrica vira ruído plantado';
		},
	),

	array(
		'grupo' => 'conversa',
		'nome'  => 'opinião sem token válido é recusada',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();

			$voto = Livia_Rest::opiniao(
				new Livia_Req_Teste( array( 'token' => 'inventado', 'turno' => 1, 'util' => '1' ) )
			);

			return 403 === $voto->get_status() ? null : 'aceitou opinião sem token: status ' . $voto->get_status();
		},
	),

	array(
		'grupo' => 'conversa',
		'nome'  => 'a tabela ganhou a coluna da opinião',
		'executar' => function () {
			livia_teste_zerar();

			// Um site que já rodava a versão anterior da tabela. É o caso que
			// importa: a migração roda por plugins_loaded, não só na ativação,
			// porque subir arquivos novos por cima é como uma atualização
			// acontece de verdade — e ninguém clica em "Ativar" de novo.
			update_option( Livia_Registro::OPCAO_VERSAO, 2 );
			Livia_Registro::conferir_tabela();

			$sql = '';
			foreach ( $GLOBALS['_stub_dbdelta'] as $pedido ) {
				$sql .= $pedido;
			}

			if ( false === strpos( $sql, 'util tinyint' ) ) {
				return 'a coluna util não entrou no CREATE TABLE';
			}
			return Livia_Registro::VERSAO_TABELA >= 3
				? null
				: 'a versão da tabela não subiu — quem já tem o plugin nunca ganharia a coluna';
		},
	),
);
