<?php
/**
 * Configuração do ambiente local.
 *
 * Lê o .env da raiz (o mesmo do livia.py e do testar.py) e grava na opção do
 * plugin, como a tela de configuração gravaria. Sem chave no .env, liga as
 * respostas de laboratório no lugar do modelo.
 */

$env = array();
$arq = dirname( __DIR__ ) . '/.env';
if ( is_readable( $arq ) ) {
	foreach ( file( $arq, FILE_IGNORE_NEW_LINES ) as $linha ) {
		if ( preg_match( '/^\s*([A-Z_]+)\s*=\s*(.*)$/', $linha, $m ) ) {
			$env[ $m[1] ] = trim( $m[2], " \t\"'" );
		}
	}
}

$chave = isset( $env['GEMINI_API_KEY'] ) ? $env['GEMINI_API_KEY'] : '';

// LIVIA_LABORATORIO=1 força as respostas de laboratório mesmo com chave — é
// assim que as capturas do README saem iguais toda vez, sem gastar cota.
if ( getenv( 'LIVIA_LABORATORIO' ) ) {
	$chave                   = '';
	$env['CANAL_DE_SUPORTE'] = 'WhatsApp (00) 0000-0000';
}

$atual = get_option( 'livia_config', array() );
if ( ! is_array( $atual ) ) {
	$atual = array();
}

update_option(
	'livia_config',
	array_merge(
		array(
			'GEMINI_MODEL'         => isset( $env['GEMINI_MODEL'] ) ? $env['GEMINI_MODEL'] : 'gemini-3.5-flash-lite',
			'GEMINI_MODEL_RESERVA' => 'gemini-3.5-flash',
		),
		$atual,
		array( 'CANAL_DE_SUPORTE' => isset( $env['CANAL_DE_SUPORTE'] ) ? $env['CANAL_DE_SUPORTE'] : 'WhatsApp (00) 0000-0000' ),
		// Sem chave real, uma chave de mentira: o plugin se considera
		// configurado e o filtro abaixo impede que ela chegue à rede.
		array( 'GEMINI_API_KEY' => '' !== $chave ? $chave : 'laboratorio' )
	)
);

if ( '' === $chave ) {
	require __DIR__ . '/respostas.php';
}
