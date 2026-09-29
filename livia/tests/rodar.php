<?php
/**
 * A suíte offline da LivIA.
 *
 *   php livia/tests/rodar.php
 *
 * Sem composer, sem vendor/, sem banco, sem HTTP e sem gastar cota da API.
 * É para rodar a cada mudança — se custar mais que um segundo ou exigir setup,
 * ninguém roda, e um teste que ninguém roda não protege nada.
 *
 * Sai com código 1 se algo falhar, para o CI reprovar o push.
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 'Este arquivo roda só por linha de comando.' );
}

require_once __DIR__ . '/stubs-wp.php';
require_once LIVIA_DIR . 'includes/class-livia-config.php';
require_once LIVIA_DIR . 'includes/class-livia-base.php';
require_once LIVIA_DIR . 'includes/class-livia-trava.php';
require_once LIVIA_DIR . 'includes/class-livia-gemini.php';
require_once LIVIA_DIR . 'includes/class-livia-sessao.php';
require_once LIVIA_DIR . 'includes/class-livia-limites.php';
require_once LIVIA_DIR . 'includes/class-livia-prompt.php';
require_once LIVIA_DIR . 'includes/class-livia-rest.php';
require_once LIVIA_DIR . 'includes/class-livia-sse.php';
require_once LIVIA_DIR . 'includes/class-livia-stream.php';
require_once LIVIA_DIR . 'includes/class-livia-cache.php';
require_once LIVIA_DIR . 'includes/class-livia-modelos.php';
require_once LIVIA_DIR . 'includes/class-livia-atalhos.php';
require_once LIVIA_DIR . 'includes/class-livia-trechos.php';
require_once LIVIA_DIR . 'includes/class-livia-registro.php';
require_once LIVIA_DIR . 'includes/class-livia-saude.php';
require_once LIVIA_DIR . 'includes/class-livia-alerta.php';
require_once LIVIA_DIR . 'admin/class-livia-admin.php';
require_once LIVIA_DIR . 'admin/class-livia-relatorios.php';

$comeco = microtime( true );

// A base é carregada pelo caminho de produção, não lida direto: assim a suíte
// também cobre a normalização de quebra de linha e a troca do canal.
update_option(
	Livia_Config::OPCAO,
	array(
		'GEMINI_API_KEY'   => 'teste',
		'GEMINI_MODEL'     => 'gemini-3.5-flash-lite',
		'CANAL_DE_SUPORTE' => 'WhatsApp (47) 3433-5066',
	)
);

$base = Livia_Base::carregar();
if ( is_wp_error( $base ) ) {
	fwrite( STDERR, 'não consegui carregar a base: ' . $base->get_error_message() . PHP_EOL );
	exit( 1 );
}

$permitidos = Livia_Trava::permitidos( $base );

// Os casos da trava são dados (texto + veredito). Viram executáveis aqui, para
// o runner ter um formato só — e para portar a PHPUnit sem tocar nos dados.
$fabrica = require __DIR__ . '/casos-trava.php';
$casos   = array();

foreach ( $fabrica( $base ) as $caso ) {
	$casos[] = array(
		'grupo'    => $caso['grupo'],
		'nome'     => $caso['nome'],
		'executar' => function () use ( $caso, $permitidos ) {
			$motivo   = Livia_Trava::verificar( $caso['texto'], $permitidos );
			$bloqueou = null !== $motivo;

			if ( $bloqueou !== $caso['bloqueia'] ) {
				return $caso['bloqueia']
					? 'deveria ter bloqueado, mas deixou passar'
					: 'bloqueou indevidamente — ' . $motivo;
			}
			if ( $bloqueou && ! empty( $caso['motivo'] ) && false === strpos( $motivo, $caso['motivo'] ) ) {
				return sprintf( 'bloqueou pelo motivo errado: esperava "%s", veio "%s"', $caso['motivo'], $motivo );
			}
			return null;
		},
	);
}

$casos = array_merge( $casos, require __DIR__ . '/casos-gemini.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-endpoint.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-streaming.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-privacidade.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-operacao.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-registro.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-motor.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-repeticao.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-trechos.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-conversa.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-painel.php' );
$casos = array_merge( $casos, require __DIR__ . '/casos-css.php' );

// Por último de propósito: estes casos mexem na configuração e no cache da base.
$casos = array_merge( $casos, require __DIR__ . '/casos-config.php' );

$falhas    = array();
$grupo_ant = null;

foreach ( $casos as $caso ) {
	if ( $caso['grupo'] !== $grupo_ant ) {
		echo PHP_EOL . strtoupper( $caso['grupo'] ) . PHP_EOL;
		$grupo_ant = $caso['grupo'];
	}

	$problema = call_user_func( $caso['executar'] );

	if ( null === $problema ) {
		printf( "  ok    %s\n", $caso['nome'] );
		continue;
	}

	printf( "  FALHA %s\n        %s\n", $caso['nome'], $problema );
	$falhas[] = $caso['nome'];
}

$ms = round( ( microtime( true ) - $comeco ) * 1000 );

echo PHP_EOL;
if ( $falhas ) {
	printf( "%d de %d falharam (%d ms)\n", count( $falhas ), count( $casos ), $ms );
	exit( 1 );
}
printf( "todos os %d casos passaram (%d ms)\n", count( $casos ), $ms );
exit( 0 );
