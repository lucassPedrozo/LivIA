<?php
/**
 * Servidor local da LivIA: o widget, as rotas e o painel, sem WordPress.
 *
 *   php -S localhost:8765 local/servidor.php
 *
 * Rode da raiz do projeto. Depois abra:
 *
 *   http://localhost:8765/                                       a página com o widget
 *   http://localhost:8765/wp-admin/options-general.php?page=livia             configuração
 *   http://localhost:8765/wp-admin/options-general.php?page=livia-relatorios  conversas
 *
 * Com GEMINI_API_KEY no .env, a conversa vai ao modelo de verdade. Sem chave, o
 * modelo é trocado por respostas de laboratório (local/respostas.php) — que
 * passam pela mesma trava, pela mesma janela retida e pelo mesmo registro.
 *
 * Para encher o painel com conversas fictícias: php local/semear.php
 */

$caminho = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

// Arquivo estático do plugin (CSS, JS): o servidor embutido serve sozinho.
if ( preg_match( '#^/(livia|local)/.+\.(css|js|png|svg)$#', $caminho ) && is_file( dirname( __DIR__ ) . $caminho ) ) {
	return false;
}

require __DIR__ . '/wp-falso.php';
require __DIR__ . '/configurar.php';
require dirname( __DIR__ ) . '/livia/livia.php';

do_action( 'plugins_loaded' );

// ------------------------------------------------------------------ REST

if ( 0 === strpos( $caminho, '/wp-json/' ) ) {
	do_action( 'rest_api_init' );

	$rota = substr( $caminho, strlen( '/wp-json' ) );
	if ( ! isset( $GLOBALS['_wp_rotas'][ $rota ] ) ) {
		http_response_code( 404 );
		exit( '{"code":"rest_no_route"}' );
	}

	$corpo  = json_decode( (string) file_get_contents( 'php://input' ), true );
	$params = array_merge( $_GET, is_array( $corpo ) ? $corpo : array(), $_POST );

	$args     = $GLOBALS['_wp_rotas'][ $rota ];
	$resposta = call_user_func( $args['callback'], new WP_REST_Request( $params ) );

	// Quem fala SSE imprime e sai lá dentro. Chegando aqui, é JSON.
	if ( $resposta instanceof WP_REST_Response ) {
		http_response_code( $resposta->get_status() );
		foreach ( $resposta->cabecalhos as $n => $v ) {
			header( "$n: $v" );
		}
		$resposta = $resposta->get_data();
	}
	header( 'Content-Type: application/json; charset=utf-8' );
	echo wp_json_encode( $resposta );
	exit;
}

// ------------------------------------------------------------------ wp-admin

if ( 0 === strpos( $caminho, '/wp-admin/' ) ) {
	do_action( 'admin_menu' );
	do_action( 'admin_init' );

	$pagina = isset( $_GET['page'] ) ? $_GET['page'] : Livia_Admin::PAGINA;
	$render = Livia_Relatorios::PAGINA === $pagina ? array( 'Livia_Relatorios', 'render' ) : array( 'Livia_Admin', 'render' );

	ob_start();
	call_user_func( $render );
	$conteudo = ob_get_clean();

	require __DIR__ . '/tela-admin.php';
	exit;
}

// ------------------------------------------------------------------ a página

$GLOBALS['_wp_pagina_atual'] = 42;
$ancora = do_shortcode( '[livia formulario="site-em-72h"]' );

require __DIR__ . '/tela-briefing.php';
