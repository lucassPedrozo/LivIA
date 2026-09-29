<?php
/**
 * Casos do streaming: leitura de SSE e a janela retida.
 *
 * O caso que justifica a fase inteira é "contato inventado nunca sai inteiro".
 * Ele roda a resposta byte a byte — o pior caso possível para a janela — e
 * confere que a sequência proibida jamais aparece no que foi para a tela.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Passa um texto pela janela retida em pedaços de N bytes.
 *
 * @return array [texto emitido, motivo do bloqueio ou null]
 */
function livia_teste_streamar( $texto, array $permitidos, $tamanho = 1 ) {
	$guarda  = new Livia_Stream( $permitidos );
	$na_tela = '';

	foreach ( str_split( $texto, $tamanho ) as $pedaco ) {
		$saida = $guarda->receber( $pedaco );
		if ( false === $saida ) {
			return array( $na_tela, $guarda->bloqueio() );
		}
		$na_tela .= $saida;
	}

	$fim = $guarda->finalizar();
	if ( false === $fim ) {
		return array( $na_tela, $guarda->bloqueio() );
	}

	return array( $na_tela . $fim, null );
}

function livia_teste_permitidos() {
	static $cache = null;
	if ( null === $cache ) {
		$cache = Livia_Trava::permitidos( Livia_Prompt::instrucao( Livia_Base::carregar() ) );
	}
	return $cache;
}

return array(

	// ------------------------------------------------------------------ SSE

	array(
		'grupo' => 'sse',
		'nome'  => 'evento partido entre dois pedaços é remontado',
		'executar' => function () {
			$leitor = new Livia_Sse();
			$bruto  = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Olá\"}]}}]}\n\n";

			$eventos = array();
			foreach ( array( substr( $bruto, 0, 20 ), substr( $bruto, 20 ) ) as $pedaco ) {
				$eventos = array_merge( $eventos, $leitor->receber( $pedaco ) );
			}

			if ( 1 !== count( $eventos ) ) {
				return 'devolveu ' . count( $eventos ) . ' eventos, esperava 1';
			}
			return 'Olá' === Livia_Sse::texto_do_evento( $eventos[0] ) ? null : 'texto errado';
		},
	),
	array(
		'grupo' => 'sse',
		'nome'  => 'vários eventos no mesmo pedaço, com [DONE] e linhas vazias',
		'executar' => function () {
			$leitor = new Livia_Sse();
			$bruto  = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"um \"}]}}]}\n\n"
				. "\n"
				. "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"dois\"}]}}]}\n\n"
				. "data: [DONE]\n\n";

			$eventos = $leitor->receber( $bruto );
			if ( 2 !== count( $eventos ) ) {
				return 'devolveu ' . count( $eventos ) . ' eventos, esperava 2';
			}
			$texto = Livia_Sse::texto_do_evento( $eventos[0] ) . Livia_Sse::texto_do_evento( $eventos[1] );
			return 'um dois' === $texto ? null : 'juntou errado: ' . $texto;
		},
	),
	array(
		'grupo' => 'sse',
		'nome'  => 'linha incompleta espera o próximo pedaço',
		'executar' => function () {
			$leitor  = new Livia_Sse();
			$eventos = $leitor->receber( 'data: {"candidates":[{"content":{"parts":[{"text":"meio' );
			return 0 === count( $eventos ) ? null : 'entregou evento a partir de linha incompleta';
		},
	),
	array(
		'grupo' => 'sse',
		'nome'  => 'usageMetadata e bloqueio de segurança são lidos',
		'executar' => function () {
			$leitor  = new Livia_Sse();
			$eventos = $leitor->receber(
				"data: {\"usageMetadata\":{\"promptTokenCount\":10,\"candidatesTokenCount\":5,\"totalTokenCount\":15}}\n\n"
				. "data: {\"promptFeedback\":{\"blockReason\":\"SAFETY\"}}\n\n"
			);
			$uso = Livia_Sse::uso_do_evento( $eventos[0] );
			if ( ! $uso || 15 !== $uso['total'] ) {
				return 'perdeu o usageMetadata';
			}
			return 'SAFETY' === Livia_Sse::bloqueio_do_evento( $eventos[1] ) ? null : 'não viu o bloqueio';
		},
	),

	// --------------------------------------------------------- janela retida

	array(
		'grupo' => 'janela',
		'nome'  => 'TELEFONE inventado nunca sai inteiro (byte a byte)',
		'executar' => function () {
			$resposta = 'Claro! Para falar com a equipe é só ligar para (11) 98765-4321 '
				. 'que eles resolvem tudo isso para você rapidinho.';

			list( $na_tela, $bloqueio ) = livia_teste_streamar( $resposta, livia_teste_permitidos(), 1 );

			if ( null === $bloqueio ) {
				return 'não bloqueou';
			}
			foreach ( array( '(11) 98765-4321', '98765-4321', '987654321' ) as $proibido ) {
				if ( false !== strpos( $na_tela, $proibido ) ) {
					return 'o telefone apareceu na tela: ' . $proibido;
				}
			}
			return null;
		},
	),
	array(
		'grupo' => 'janela',
		'nome'  => 'E-MAIL inventado nunca sai inteiro (byte a byte)',
		'executar' => function () {
			$resposta = 'Sem problema — manda tudo para suporte@joinvix.com.br que a equipe cuida disso.';
			list( $na_tela, $bloqueio ) = livia_teste_streamar( $resposta, livia_teste_permitidos(), 1 );

			if ( null === $bloqueio ) {
				return 'não bloqueou';
			}
			return false === strpos( $na_tela, 'suporte@joinvix.com.br' ) ? null : 'o e-mail apareceu na tela';
		},
	),
	array(
		'grupo' => 'janela',
		'nome'  => 'URL inventada nunca sai inteira (byte a byte)',
		'executar' => function () {
			$resposta = 'Para reenviar o material é só acessar joinvix.com.br/painel-do-cliente e fazer o upload de novo.';
			list( $na_tela, $bloqueio ) = livia_teste_streamar( $resposta, livia_teste_permitidos(), 1 );

			if ( null === $bloqueio ) {
				return 'não bloqueou';
			}
			return false === strpos( $na_tela, 'joinvix.com.br/painel-do-cliente' ) ? null : 'a URL apareceu na tela';
		},
	),
	array(
		'grupo' => 'janela',
		'nome'  => 'em pedaços grandes o contato também não escapa',
		'executar' => function () {
			$resposta = 'Pode ligar para (11) 98765-4321 a qualquer hora.';
			foreach ( array( 3, 17, 64, 999 ) as $tamanho ) {
				list( $na_tela, $bloqueio ) = livia_teste_streamar( $resposta, livia_teste_permitidos(), $tamanho );
				if ( null === $bloqueio ) {
					return "não bloqueou com pedaços de {$tamanho}";
				}
				if ( false !== strpos( $na_tela, '98765-4321' ) ) {
					return "vazou com pedaços de {$tamanho}";
				}
			}
			return null;
		},
	),
	array(
		'grupo' => 'janela',
		'nome'  => 'resposta limpa chega inteira e idêntica',
		'executar' => function () {
			$resposta = "Domínio é o endereço do seu site na internet — o que a pessoa digita pra te encontrar.\n\n"
				. "Se você ainda não tem um, escreva o nome que gostaria de usar e a palavra \"sugestão\".\n\n"
				. 'Quer que eu te explique outro campo?';

			list( $na_tela, $bloqueio ) = livia_teste_streamar( $resposta, livia_teste_permitidos(), 7 );

			if ( null !== $bloqueio ) {
				return 'bloqueou resposta boa: ' . $bloqueio;
			}
			return $na_tela === $resposta ? null : 'o texto entregue não bate com o original';
		},
	),
	array(
		'grupo' => 'janela',
		'nome'  => 'não parte acento no meio (nada de losango na tela)',
		'executar' => function () {
			// Cada acento é dois bytes; cortar entre eles produziria lixo.
			$resposta = str_repeat( 'ação órfã núcleo comissão avó ', 12 );

			$guarda  = new Livia_Stream( livia_teste_permitidos() );
			$na_tela = '';
			foreach ( str_split( $resposta, 1 ) as $b ) {
				$saida = $guarda->receber( $b );
				if ( false === $saida ) {
					return 'bloqueou texto inofensivo';
				}
				$na_tela .= $saida;
				// Todo pedaço já entregue tem que ser UTF-8 válido por si só.
				if ( '' !== $na_tela && ! mb_check_encoding( $na_tela, 'UTF-8' ) ) {
					return 'entregou UTF-8 quebrado';
				}
			}
			$na_tela .= $guarda->finalizar();
			return $na_tela === $resposta ? null : 'texto final diferente do original';
		},
	),
	array(
		'grupo' => 'janela',
		'nome'  => 'depois de bloquear, não sai mais nada',
		'executar' => function () {
			$guarda = new Livia_Stream( livia_teste_permitidos() );
			$guarda->receber( 'Liga para (11) 98765-4321.' );
			if ( null === $guarda->bloqueio() ) {
				return 'não bloqueou';
			}
			if ( false !== $guarda->receber( ' e mais texto' ) ) {
				return 'voltou a emitir depois do bloqueio';
			}
			return false === $guarda->finalizar() ? null : 'finalizar() liberou texto depois do bloqueio';
		},
	),
	array(
		'grupo' => 'janela',
		'nome'  => 'nada é emitido antes de a janela encher',
		'executar' => function () {
			$guarda = new Livia_Stream( livia_teste_permitidos() );
			$saida  = $guarda->receber( str_repeat( 'a', Livia_Stream::RETENCAO - 10 ) . ' ' );
			return '' === $saida ? null : 'emitiu ' . strlen( $saida ) . ' bytes cedo demais';
		},
	),
);
