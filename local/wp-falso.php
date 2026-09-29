<?php
/**
 * Um WordPress de mentira, para ver a LivIA rodando sem instalar WordPress.
 *
 * Diferente de livia/tests/stubs-wp.php, que guarda tudo em memória e morre com
 * o processo, este persiste opções, transients e a tabela de mensagens num
 * SQLite temporário — o servidor embutido do PHP abre um processo por
 * requisição, e a sessão da conversa precisa sobreviver de uma para a outra.
 *
 * Só serve para desenvolvimento. Não entra no pacote (ver empacotar.py).
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );

define( 'LIVIA_LOCAL_BANCO', getenv( 'LIVIA_LOCAL_BANCO' ) ?: sys_get_temp_dir() . '/livia-local.sqlite' );

// --------------------------------------------------------------- banco

/**
 * $wpdb sobre SQLite.
 *
 * As consultas da LivIA são SQL portável (COUNT, SUM de booleano, DATE(),
 * LIKE), então o SQLite responde as mesmas que o MySQL responderia. O que muda
 * é só a criação da tabela, que aqui é feita à mão em vez do dbDelta.
 */
class Livia_Wpdb_Local {
	public $prefix    = 'wp_';
	public $options   = 'wp_options';
	public $insert_id = 0;
	public $last_error = '';

	/** @var PDO */
	private $pdo;

	public function __construct( $arquivo ) {
		$this->pdo = new PDO( 'sqlite:' . $arquivo );
		$this->pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$this->pdo->exec( 'PRAGMA journal_mode = WAL' );
		$this->pdo->exec( 'CREATE TABLE IF NOT EXISTS wp_options ( option_name TEXT PRIMARY KEY, option_value TEXT )' );
		$this->pdo->exec(
			"CREATE TABLE IF NOT EXISTS wp_livia_mensagens (
				id INTEGER PRIMARY KEY AUTOINCREMENT,
				sessao TEXT NOT NULL,
				criado_em TEXT NOT NULL,
				pergunta TEXT NOT NULL,
				resposta TEXT NOT NULL,
				bloqueio TEXT DEFAULT NULL,
				erro TEXT DEFAULT NULL,
				tokens_entrada INTEGER NOT NULL DEFAULT 0,
				tokens_saida INTEGER NOT NULL DEFAULT 0,
				latencia_ms INTEGER NOT NULL DEFAULT 0,
				modelo TEXT NOT NULL DEFAULT '',
				truncada INTEGER NOT NULL DEFAULT 0,
				streaming INTEGER NOT NULL DEFAULT 0,
				origem TEXT NOT NULL DEFAULT 'modelo',
				pagina_id INTEGER NOT NULL DEFAULT 0,
				pagina_url TEXT NOT NULL DEFAULT '',
				pagina_titulo TEXT NOT NULL DEFAULT '',
				formulario TEXT NOT NULL DEFAULT '',
				util INTEGER DEFAULT NULL
			)"
		);
	}

	public function get_charset_collate() {
		return '';
	}

	public function esc_like( $texto ) {
		return addcslashes( (string) $texto, '_%\\' );
	}

	/** %s, %d e %f, como o $wpdb de verdade; aceita os argumentos soltos ou num array. */
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$pdo = $this->pdo;
		$i   = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $m ) use ( &$i, $args, $pdo ) {
				$v = isset( $args[ $i ] ) ? $args[ $i ] : '';
				++$i;
				if ( '%d' === $m[0] ) {
					return (string) (int) $v;
				}
				if ( '%f' === $m[0] ) {
					return (string) (float) $v;
				}
				return $pdo->quote( (string) $v );
			},
			$sql
		);
	}

	public function query( $sql ) {
		try {
			return $this->pdo->exec( $sql );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return false;
		}
	}

	private function linhas( $sql ) {
		try {
			return $this->pdo->query( $sql )->fetchAll( PDO::FETCH_ASSOC );
		} catch ( PDOException $e ) {
			$this->last_error = $e->getMessage();
			return array();
		}
	}

	public function get_results( $sql, $saida = OBJECT ) {
		$linhas = $this->linhas( $sql );
		if ( ARRAY_A === $saida ) {
			return $linhas;
		}
		if ( ARRAY_N === $saida ) {
			return array_map( 'array_values', $linhas );
		}
		return array_map(
			function ( $l ) {
				return (object) $l;
			},
			$linhas
		);
	}

	public function get_row( $sql, $saida = OBJECT ) {
		$r = $this->get_results( $sql, $saida );
		return $r ? $r[0] : null;
	}

	public function get_col( $sql ) {
		return array_map(
			function ( $l ) {
				return reset( $l );
			},
			$this->linhas( $sql )
		);
	}

	public function get_var( $sql ) {
		$l = $this->linhas( $sql );
		return $l ? reset( $l[0] ) : null;
	}

	public function insert( $tabela, $dados, $formatos = null ) {
		$colunas = array_keys( $dados );
		$st      = $this->pdo->prepare(
			'INSERT INTO ' . $tabela . ' (' . implode( ',', $colunas ) . ') VALUES (' . implode( ',', array_fill( 0, count( $colunas ), '?' ) ) . ')'
		);
		$st->execute( array_values( $dados ) );
		$this->insert_id = (int) $this->pdo->lastInsertId();
		return 1;
	}

	public function update( $tabela, $dados, $onde, $formatos = null, $formatos_onde = null ) {
		$set = implode( ',', array_map( function ( $c ) { return "$c = ?"; }, array_keys( $dados ) ) );
		$whr = implode( ' AND ', array_map( function ( $c ) { return "$c = ?"; }, array_keys( $onde ) ) );
		$st  = $this->pdo->prepare( "UPDATE $tabela SET $set WHERE $whr" );
		$st->execute( array_merge( array_values( $dados ), array_values( $onde ) ) );
		return $st->rowCount();
	}
}
$GLOBALS['wpdb'] = new Livia_Wpdb_Local( LIVIA_LOCAL_BANCO );

function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions
	return array(); // a tabela já nasce no construtor do banco local
}

// --------------------------------------------------------------- opções e transients

function get_option( $nome, $padrao = false ) {
	global $wpdb;
	$v = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM wp_options WHERE option_name = %s', $nome ) );
	return null === $v ? $padrao : unserialize( $v ); // phpcs:ignore
}
function update_option( $nome, $valor, $autoload = null ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'INSERT OR REPLACE INTO wp_options (option_name, option_value) VALUES (%s, %s)', $nome, serialize( $valor ) ) ); // phpcs:ignore
	return true;
}
function add_option( $nome, $valor = '', $x = '', $autoload = null ) {
	return false === get_option( $nome ) ? update_option( $nome, $valor ) : false;
}
function delete_option( $nome ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM wp_options WHERE option_name = %s', $nome ) );
	return true;
}
function get_transient( $nome ) {
	$t = get_option( '_transient_' . $nome );
	if ( ! is_array( $t ) ) {
		return false;
	}
	if ( $t['expira'] && $t['expira'] < time() ) {
		delete_option( '_transient_' . $nome );
		return false;
	}
	return $t['valor'];
}
function set_transient( $nome, $valor, $ttl = 0 ) {
	return update_option( '_transient_' . $nome, array( 'valor' => $valor, 'expira' => $ttl ? time() + $ttl : 0 ) );
}
function delete_transient( $nome ) {
	return delete_option( '_transient_' . $nome );
}

// --------------------------------------------------------------- hooks

$GLOBALS['_wp_hooks']      = array();
$GLOBALS['_wp_shortcodes'] = array();
$GLOBALS['_wp_estilos']    = array();
$GLOBALS['_wp_scripts']    = array();
$GLOBALS['_wp_localizado'] = array();

function add_filter( $tag, $fn, $prioridade = 10, $args = 1 ) {
	$GLOBALS['_wp_hooks'][ $tag ][] = $fn;
	return true;
}
function add_action( $tag, $fn, $prioridade = 10, $args = 1 ) {
	return add_filter( $tag, $fn );
}
function remove_all_filters( $tag ) {
	unset( $GLOBALS['_wp_hooks'][ $tag ] );
}
function apply_filters( $tag, $valor ) {
	$extra = array_slice( func_get_args(), 2 );
	foreach ( isset( $GLOBALS['_wp_hooks'][ $tag ] ) ? $GLOBALS['_wp_hooks'][ $tag ] : array() as $fn ) {
		$valor = call_user_func_array( $fn, array_merge( array( $valor ), $extra ) );
	}
	return $valor;
}
function do_action( $tag ) {
	$args = array_slice( func_get_args(), 1 );
	foreach ( isset( $GLOBALS['_wp_hooks'][ $tag ] ) ? $GLOBALS['_wp_hooks'][ $tag ] : array() as $fn ) {
		call_user_func_array( $fn, $args );
	}
}
function register_activation_hook( $arquivo, $fn ) {}
function register_deactivation_hook( $arquivo, $fn ) {}
function register_setting( $grupo, $nome, $args = array() ) {}
function register_rest_route( $ns, $rota, $args ) {
	$GLOBALS['_wp_rotas'][ '/' . $ns . $rota ] = $args;
	return true;
}

// --------------------------------------------------------------- shortcode e assets

function add_shortcode( $tag, $fn ) {
	$GLOBALS['_wp_shortcodes'][ $tag ] = $fn;
}
function do_shortcode( $texto ) {
	return preg_replace_callback(
		'/\[(\w+)([^\]]*)\]/',
		function ( $m ) {
			if ( ! isset( $GLOBALS['_wp_shortcodes'][ $m[1] ] ) ) {
				return $m[0];
			}
			preg_match_all( '/(\w+)="([^"]*)"/', $m[2], $pares, PREG_SET_ORDER );
			$atts = array();
			foreach ( $pares as $p ) {
				$atts[ $p[1] ] = $p[2];
			}
			return call_user_func( $GLOBALS['_wp_shortcodes'][ $m[1] ], $atts );
		},
		$texto
	);
}
function shortcode_atts( $padroes, $atts, $tag = '' ) {
	return array_merge( $padroes, array_intersect_key( (array) $atts, $padroes ) );
}
function wp_enqueue_style( $id, $url = '', $deps = array(), $ver = false ) {
	$GLOBALS['_wp_estilos'][ $id ] = $url . ( $ver ? '?ver=' . $ver : '' );
}
function wp_enqueue_script( $id, $url = '', $deps = array(), $ver = false, $rodape = false ) {
	$GLOBALS['_wp_scripts'][ $id ] = $url . ( $ver ? '?ver=' . $ver : '' );
}
function wp_localize_script( $id, $objeto, $dados ) {
	$GLOBALS['_wp_localizado'][ $id ] = array( $objeto, $dados );
}
function wp_head() {
	foreach ( $GLOBALS['_wp_estilos'] as $id => $url ) {
		printf( "<link rel='stylesheet' id='%s-css' href='%s'>\n", esc_attr( $id ), esc_url( $url ) );
	}
}
function wp_footer() {
	foreach ( $GLOBALS['_wp_scripts'] as $id => $url ) {
		if ( isset( $GLOBALS['_wp_localizado'][ $id ] ) ) {
			list( $obj, $dados ) = $GLOBALS['_wp_localizado'][ $id ];
			printf( "<script>var %s = %s;</script>\n", $obj, wp_json_encode( $dados ) );
		}
		printf( "<script src='%s'></script>\n", esc_url( $url ) );
	}
}
function plugin_dir_path( $arquivo ) {
	return rtrim( dirname( $arquivo ), '/\\' ) . '/';
}
function plugin_dir_url( $arquivo ) {
	return '/livia/';
}

// --------------------------------------------------------------- páginas

/** Uma página só, publicada, para o relatório "por página" ter o que mostrar. */
$GLOBALS['_wp_paginas'] = array(
	42 => array( 'titulo' => 'Briefing — Site em 72h', 'url' => '/site-em-72h/' ),
	43 => array( 'titulo' => 'Briefing — Landing Page', 'url' => '/landing-page/' ),
	44 => array( 'titulo' => 'Briefing — Site Gerenciável', 'url' => '/site-gerenciavel/' ),
);
$GLOBALS['_wp_pagina_atual'] = 42;

function get_the_ID() { // phpcs:ignore WordPress.NamingConventions
	return $GLOBALS['_wp_pagina_atual'];
}
function get_post_status( $id ) {
	return isset( $GLOBALS['_wp_paginas'][ $id ] ) ? 'publish' : false;
}
function get_the_title( $id = 0 ) {
	return isset( $GLOBALS['_wp_paginas'][ $id ] ) ? $GLOBALS['_wp_paginas'][ $id ]['titulo'] : '';
}
function get_permalink( $id = 0 ) {
	return isset( $GLOBALS['_wp_paginas'][ $id ] ) ? home_url( $GLOBALS['_wp_paginas'][ $id ]['url'] ) : '';
}
function get_bloginfo( $o = '' ) {
	return 'Estúdio Exemplo';
}

// --------------------------------------------------------------- REST

class WP_Error {
	private $codigo;
	private $mensagem;
	private $dados;
	public function __construct( $codigo = '', $mensagem = '', $dados = null ) {
		$this->codigo   = $codigo;
		$this->mensagem = $mensagem;
		$this->dados    = $dados;
	}
	public function get_error_code() {
		return $this->codigo;
	}
	public function get_error_message() {
		return $this->mensagem;
	}
	public function get_error_data() {
		return $this->dados;
	}
}
function is_wp_error( $c ) {
	return $c instanceof WP_Error;
}

class WP_REST_Response {
	private $dados;
	private $status;
	public $cabecalhos = array();
	public function __construct( $dados = null, $status = 200 ) {
		$this->dados  = $dados;
		$this->status = $status;
	}
	public function get_data() {
		return $this->dados;
	}
	public function get_status() {
		return $this->status;
	}
	public function header( $nome, $valor ) {
		$this->cabecalhos[ $nome ] = $valor;
	}
}

/** Requisição com o corpo JSON e a query string, que é o que o widget manda. */
class WP_REST_Request {
	private $params;
	public function __construct( array $params ) {
		$this->params = $params;
	}
	public function get_param( $nome ) {
		return isset( $this->params[ $nome ] ) ? $this->params[ $nome ] : null;
	}
}

function rest_url( $caminho = '' ) {
	return home_url( '/wp-json/' . ltrim( $caminho, '/' ) );
}

// --------------------------------------------------------------- utilidades

function home_url( $caminho = '' ) {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost:8765';
	return 'http://' . $host . '/' . ltrim( $caminho, '/' );
}
function admin_url( $caminho = '' ) {
	return home_url( '/wp-admin/' . ltrim( $caminho, '/' ) );
}
function is_admin() {
	return 0 === strpos( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '', '/wp-admin' );
}
function current_user_can( $cap ) {
	return true;
}
function wp_salt( $esquema = 'auth' ) {
	return 'sal-do-ambiente-local-' . $esquema;
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) );
}
function sanitize_key( $k ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
}
function sanitize_hex_color( $cor ) {
	return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', (string) $cor ) ? $cor : null;
}
function wp_strip_all_tags( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function wp_json_encode( $dados, $flags = 0 ) {
	return json_encode( $dados, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max ?: PHP_INT_MAX );
}
function __return_false() {
	return false;
}
function __return_true() {
	return true;
}
function wp_mail( $para, $assunto, $corpo, $cabecalhos = '', $anexos = array() ) {
	return true; // no ambiente local, alerta nenhum sai de verdade
}
function is_email( $e ) {
	return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
}
function wp_date( $formato, $ts = null ) {
	return date( $formato, null === $ts ? time() : $ts );
}
function get_locale() {
	return 'pt_BR';
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_textarea( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $u ) {
	return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' );
}
function esc_url_raw( $u ) {
	return (string) $u;
}
function wp_kses_post( $html ) {
	return $html;
}
function wp_unslash( $v ) {
	return is_string( $v ) ? stripslashes( $v ) : $v;
}
function add_query_arg( $args, $url = '' ) {
	$sep = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}
function number_format_i18n( $n, $casas = 0 ) {
	return number_format( (float) $n, $casas, ',', '.' );
}
function checked( $a, $b = true, $exibir = true ) {
	$r = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
	if ( $exibir ) {
		echo $r; // phpcs:ignore
	}
	return $r;
}
function selected( $a, $b = true, $exibir = true ) {
	$r = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
	if ( $exibir ) {
		echo $r; // phpcs:ignore
	}
	return $r;
}
function settings_errors( $slug = '' ) {}
function settings_fields( $grupo ) {}
function submit_button( $texto = 'Salvar alterações' ) {
	echo '<p class="submit"><button type="button" class="button button-primary">' . esc_html( $texto ) . '</button></p>';
}
function wp_nonce_field( $acao = -1, $nome = '_wpnonce', $referer = true, $exibir = true ) {
	$r = '<input type="hidden" name="' . esc_attr( $nome ) . '" value="local">';
	if ( $exibir ) {
		echo $r; // phpcs:ignore
	}
	return $r;
}
function wp_nonce_url( $url, $acao = -1, $nome = '_wpnonce' ) {
	return add_query_arg( array( $nome => 'local' ), $url );
}
function check_admin_referer( $acao = -1 ) {
	return true;
}
function wp_parse_url( $url, $componente = -1 ) {
	return parse_url( $url, $componente );
}
function wp_next_scheduled( $hook ) {
	return time() + HOUR_IN_SECONDS;
}
function wp_schedule_event( $quando, $recorrencia, $hook ) {
	return true;
}
function wp_unschedule_event( $quando, $hook ) {
	return true;
}
function remove_accents( $s ) {
	$t = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s ); // phpcs:ignore
	return false === $t ? $s : $t;
}
function nocache_headers() {
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
}
function wp_safe_redirect( $url ) {
	header( 'Location: ' . $url );
}
function wp_die( $msg = '' ) {
	exit( esc_html( $msg ) );
}
function add_options_page( $t, $m, $cap, $slug, $fn ) {
	return 'settings_page_' . $slug;
}
function add_submenu_page( $pai, $t, $m, $cap, $slug, $fn ) {
	return 'settings_page_' . $slug;
}
function remove_submenu_page( $pai, $slug ) {}
function wp_remote_get( $url, $args = array() ) {
	return new WP_Error( 'offline', 'Sem rede no ambiente local.' );
}
function wp_remote_post( $url, $args = array() ) {
	return new WP_Error( 'offline', 'Sem rede no ambiente local.' );
}
function wp_remote_retrieve_body( $r ) {
	return '';
}
function wp_remote_retrieve_response_code( $r ) {
	return 0;
}
