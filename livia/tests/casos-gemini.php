<?php
/**
 * Casos do cliente Gemini — só a parte que não usa rede.
 *
 * interpretar() é onde mora o risco: é ela que decide se a resposta vai para o
 * cliente, se vira erro, e se um texto cortado no meio da frase é entregue
 * assim mesmo. Dá para testar inteira com JSON de mentira, sem gastar cota.
 *
 * Cada caso devolve null quando passa, ou a descrição do problema.
 */

defined( 'ABSPATH' ) || exit;

/** Monta uma resposta da API igual à que o Gemini devolve. */
function livia_teste_payload( $texto, $finish = 'STOP', $uso = array( 12, 34 ) ) {
	return array(
		'candidates'    => array(
			array(
				'content'      => array( 'parts' => array( array( 'text' => $texto ) ) ),
				'finishReason' => $finish,
			),
		),
		'usageMetadata' => array(
			'promptTokenCount'     => $uso[0],
			'candidatesTokenCount' => $uso[1],
			'totalTokenCount'      => $uso[0] + $uso[1],
		),
	);
}

return array(

	array(
		'grupo' => 'gemini',
		'nome'  => 'resposta normal devolve texto e contabiliza tokens',
		'executar' => function () {
			$r = Livia_Gemini::interpretar( livia_teste_payload( '  Domínio é o endereço do seu site.  ' ) );
			if ( is_wp_error( $r ) ) {
				return 'virou erro: ' . $r->get_error_code();
			}
			if ( 'Domínio é o endereço do seu site.' !== $r['texto'] ) {
				return 'não aparou os espaços: ' . var_export( $r['texto'], true );
			}
			if ( 12 !== $r['uso']['entrada'] || 34 !== $r['uso']['saida'] || 46 !== $r['uso']['total'] ) {
				return 'usageMetadata perdido: ' . wp_json_encode( $r['uso'] );
			}
			if ( $r['truncado'] ) {
				return 'marcou como truncada uma resposta inteira';
			}
			return null;
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'sem usageMetadata não quebra (fica zerado)',
		'executar' => function () {
			$p = livia_teste_payload( 'Oi!' );
			unset( $p['usageMetadata'] );
			$r = Livia_Gemini::interpretar( $p );
			if ( is_wp_error( $r ) ) {
				return 'virou erro: ' . $r->get_error_code();
			}
			return 0 === $r['uso']['total'] ? null : 'deveria zerar o uso';
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'MAX_TOKENS recua até a última frase inteira',
		'executar' => function () {
			$cortado = 'O prazo começa quando a equipe recebe tudo. Se faltar alguma coi';
			$r       = Livia_Gemini::interpretar( livia_teste_payload( $cortado, 'MAX_TOKENS' ) );
			if ( is_wp_error( $r ) ) {
				return 'virou erro: ' . $r->get_error_code();
			}
			if ( 'O prazo começa quando a equipe recebe tudo.' !== $r['texto'] ) {
				return 'não cortou direito: ' . var_export( $r['texto'], true );
			}
			return $r['truncado'] ? null : 'não marcou como truncada';
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'MAX_TOKENS sem nenhuma frase inteira vira erro',
		'executar' => function () {
			$r = Livia_Gemini::interpretar( livia_teste_payload( 'O prazo começa quando a equ', 'MAX_TOKENS' ) );
			if ( ! is_wp_error( $r ) ) {
				return 'entregou um fragmento sem frase: ' . var_export( $r['texto'], true );
			}
			return 'truncado' === $r->get_error_code() ? null : 'código errado: ' . $r->get_error_code();
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'pergunta barrada pela API vira bloqueado_seguranca',
		'executar' => function () {
			$r = Livia_Gemini::interpretar( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) );
			if ( ! is_wp_error( $r ) ) {
				return 'não virou erro';
			}
			return 'bloqueado_seguranca' === $r->get_error_code() ? null : 'código errado: ' . $r->get_error_code();
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'resposta barrada por SAFETY vira bloqueado_seguranca',
		'executar' => function () {
			$r = Livia_Gemini::interpretar( livia_teste_payload( '', 'SAFETY' ) );
			if ( ! is_wp_error( $r ) ) {
				return 'não virou erro';
			}
			return 'bloqueado_seguranca' === $r->get_error_code() ? null : 'código errado: ' . $r->get_error_code();
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'candidatos vazios viram sem_texto',
		'executar' => function () {
			$r = Livia_Gemini::interpretar( array( 'candidates' => array() ) );
			if ( ! is_wp_error( $r ) ) {
				return 'não virou erro';
			}
			return 'sem_texto' === $r->get_error_code() ? null : 'código errado: ' . $r->get_error_code();
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'JSON quebrado vira resposta_ilegivel',
		'executar' => function () {
			$r = Livia_Gemini::interpretar( '{isso nao e json' );
			if ( ! is_wp_error( $r ) ) {
				return 'não virou erro';
			}
			return 'resposta_ilegivel' === $r->get_error_code() ? null : 'código errado: ' . $r->get_error_code();
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'cada erro tem a sua frase para o cliente',
		'executar' => function () {
			$cota  = Livia_Gemini::mensagem_para_cliente( new WP_Error( 'cota', '' ), 'WhatsApp (47) 3433-5066' );
			$seg   = Livia_Gemini::mensagem_para_cliente( new WP_Error( 'bloqueado_seguranca', '' ), 'WhatsApp (47) 3433-5066' );
			$rede  = Livia_Gemini::mensagem_para_cliente( new WP_Error( 'conexao', '' ), 'WhatsApp (47) 3433-5066' );

			if ( false === strpos( $cota, 'muitas conversas' ) ) {
				return 'cota não usa a frase de cota';
			}
			if ( $seg === $rede ) {
				return 'bloqueio de segurança está dizendo "a conexão falhou" — mentira para o cliente';
			}
			if ( false === strpos( $seg, '(47) 3433-5066' ) ) {
				return 'bloqueio de segurança não oferece o canal de suporte';
			}
			return null;
		},
	),

	array(
		'grupo' => 'gemini',
		'nome'  => 'o corpo enviado leva a base como system_instruction',
		'executar' => function () {
			$corpo = Livia_Gemini::corpo( 'BASE AQUI', array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'oi' ) ) ) ) );
			if ( 'BASE AQUI' !== $corpo['system_instruction']['parts'][0]['text'] ) {
				return 'a base não foi para system_instruction';
			}
			if ( 0.2 !== $corpo['generationConfig']['temperature'] ) {
				return 'temperatura não é 0.2';
			}
			if ( 512 !== $corpo['generationConfig']['maxOutputTokens'] ) {
				return 'maxOutputTokens não é 512';
			}
			return null;
		},
	),
);
