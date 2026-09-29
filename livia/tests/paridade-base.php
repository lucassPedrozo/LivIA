<?php
/**
 * A base carregada pelo PHP é idêntica à carregada pelo Python?
 *
 *   php tests/paridade-base.php <arquivo-de-referencia> <canal-de-suporte>
 *
 * Divergir aqui significa que os casos_de_teste.json deixam de valer para as
 * duas implementações ao mesmo tempo — é a checagem que amarra o porte.
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 'Este arquivo roda só por linha de comando.' );
}

require_once __DIR__ . '/stubs-wp.php';
require_once LIVIA_DIR . 'includes/class-livia-config.php';
require_once LIVIA_DIR . 'includes/class-livia-base.php';

$referencia = $argv[1] ?? '';
$canal      = $argv[2] ?? '';

update_option(
	Livia_Config::OPCAO,
	array(
		'GEMINI_API_KEY'   => '',
		'GEMINI_MODEL'     => 'gemini-3.5-flash-lite',
		'CANAL_DE_SUPORTE' => $canal,
	)
);

$texto = Livia_Base::carregar();

if ( is_wp_error( $texto ) ) {
	fwrite( STDERR, "FALHOU: " . $texto->get_error_message() . "\n" );
	exit( 1 );
}

$esperado = file_get_contents( $referencia );

if ( $texto === $esperado ) {
	printf( "ok  paridade PHP/Python: %d bytes idênticos\n", strlen( $texto ) );
	printf( "ok  marcador substituído: %s\n", Livia_Base::marcador_pendente() ? 'NÃO' : 'sim' );
	exit( 0 );
}

fwrite( STDERR, sprintf( "FALHOU: PHP tem %d bytes, Python tem %d\n", strlen( $texto ), strlen( $esperado ) ) );
for ( $i = 0; $i < min( strlen( $texto ), strlen( $esperado ) ); $i++ ) {
	if ( $texto[ $i ] !== $esperado[ $i ] ) {
		fwrite( STDERR, sprintf( "primeira divergência no byte %d\n", $i ) );
		fwrite( STDERR, "  php:    ..." . substr( $texto, max( 0, $i - 40 ), 80 ) . "...\n" );
		fwrite( STDERR, "  python: ..." . substr( $esperado, max( 0, $i - 40 ), 80 ) . "...\n" );
		break;
	}
}
exit( 1 );
