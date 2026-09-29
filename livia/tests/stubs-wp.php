<?php
/**
 * Stubs mínimos do WordPress, para testar as classes fora dele.
 *
 * As classes de núcleo da LivIA (Base, Trava, Gemini) recebem strings e devolvem
 * strings. Só encostam no WordPress para ler configuração e cache — que é o que
 * este arquivo finge. É o que permite a suíte da Fase 2 rodar em segundos, sem
 * banco, sem HTTP e sem cota.
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 'Este arquivo roda só por linha de comando.' );
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
// Constantes de formato do $wpdb. Faltavam aqui, e a falta ficou escondida
// atrás de um transient vazado de outro caso: o caminho de saúde nunca
// chegava a saude_24h(). Em produção o WordPress as define.
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );
define( 'OBJECT', 'OBJECT' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'LIVIA_DIR', dirname( __DIR__ ) . '/' );

class WP_Error {
	private $codigo;
	private $mensagem;
	public function __construct( $codigo = '', $mensagem = '' ) {
		$this->codigo   = $codigo;
		$this->mensagem = $mensagem;
	}
	public function get_error_code() {
		return $this->codigo;
	}
	public function get_error_message() {
		return $this->mensagem;
	}
}

function is_wp_error( $c ) {
	return $c instanceof WP_Error;
}

/**
 * $wpdb de mentira: guarda o que foi escrito em vez de escrever.
 *
 * Não tenta imitar MySQL — o que a suíte precisa saber é QUE se tentou gravar,
 * e com quais colunas.
 */
class Livia_Wpdb_Teste {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public $inseridos = array();
	public $consultas = array();
	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
	public $insert_id = 0;
	public $atualizacoes = array();
	public function insert( $tabela, $dados, $formatos = null ) {
		$this->inseridos[] = array( $tabela, $dados );
		// O $wpdb de verdade preenche isto, e o código depende dele para dizer
		// ao widget de qual linha a resposta é.
		$this->insert_id = count( $this->inseridos );
		return 1;
	}
	/**
	 * UPDATE de mentira, mas com o WHERE valendo de verdade.
	 *
	 * Um stub que devolvesse 1 sempre faria o caso "ninguém opina na conversa
	 * dos outros" passar sem testar nada — que é o pior tipo de teste verde.
	 * Aqui as linhas são as que insert() guardou, numeradas a partir de 1, do
	 * mesmo jeito que o AUTO_INCREMENT numera.
	 */
	public function update( $tabela, $dados, $onde, $formatos = null, $formatos_onde = null ) {
		$mexidas = 0;

		foreach ( $this->inseridos as $i => $linha ) {
			if ( $linha[0] !== $tabela ) {
				continue;
			}

			$atual = array_merge( array( 'id' => $i + 1 ), $linha[1] );

			$casa = true;
			foreach ( $onde as $campo => $valor ) {
				if ( ! isset( $atual[ $campo ] ) || (string) $atual[ $campo ] !== (string) $valor ) {
					$casa = false;
					break;
				}
			}
			if ( ! $casa ) {
				continue;
			}

			$this->inseridos[ $i ][1] = array_merge( $linha[1], $dados );
			++$mexidas;
		}

		$this->atualizacoes[] = array( $tabela, $dados, $onde, $mexidas );
		return $mexidas;
	}

	public function query( $sql ) {
		$this->consultas[] = $sql;
		return 0;
	}
	/**
	 * Guarda o SQL e os argumentos para os casos poderem conferir a consulta.
	 *
	 * Não interpola nada: o que se testa aqui é a FORMA da consulta (que o
	 * WHERE existe, que o LIKE entrou, que o LIMIT está lá), não o resultado —
	 * para isso seria preciso um MySQL, e a suíte deixaria de rodar em
	 * milissegundos.
	 */
	public $consultas_preparadas = array();

	public function prepare( $sql, ...$args ) {
		$this->consultas_preparadas[] = array( $sql, $args );
		return $sql;
	}

	/** Escapa os curingas do LIKE, como o $wpdb de verdade. */
	public function esc_like( $texto ) {
		return addcslashes( (string) $texto, '_%' . chr( 92 ) );
	}
	public function get_row( $sql, $saida = null ) {
		return null;
	}
	public function get_results( $sql, $saida = null ) {
		return array();
	}
	public function get_col( $sql ) {
		return array();
	}
	public function get_var( $sql ) {
		return null;
	}
}
$GLOBALS['wpdb']          = new Livia_Wpdb_Teste();
$GLOBALS['_stub_dbdelta'] = array();

/** Registra o CREATE TABLE que instalar() monta, sem tocar em banco. */
function dbDelta( $sql ) { // phpcs:ignore WordPress.NamingConventions
	$GLOBALS['_stub_dbdelta'][] = $sql;
	return array();
}

$GLOBALS['_stub_options']    = array();
$GLOBALS['_stub_transients'] = array();

function get_option( $nome, $padrao = false ) {
	return array_key_exists( $nome, $GLOBALS['_stub_options'] ) ? $GLOBALS['_stub_options'][ $nome ] : $padrao;
}
function update_option( $nome, $valor, $autoload = null ) {
	$GLOBALS['_stub_options'][ $nome ] = $valor;
	return true;
}
function delete_option( $nome ) {
	unset( $GLOBALS['_stub_options'][ $nome ] );
	return true;
}
function get_transient( $nome ) {
	return array_key_exists( $nome, $GLOBALS['_stub_transients'] ) ? $GLOBALS['_stub_transients'][ $nome ] : false;
}
function set_transient( $nome, $valor, $ttl = 0 ) {
	$GLOBALS['_stub_transients'][ $nome ] = $valor;
	return true;
}
function delete_transient( $nome ) {
	unset( $GLOBALS['_stub_transients'][ $nome ] );
	return true;
}

// --- hooks de mentira, mas de verdade -------------------------------------
// Filtro e ação chegaram a valer nos testes a partir da Fase 3: é assim que o
// caso substitui a chamada real ao Gemini por uma resposta de laboratório.

$GLOBALS['_stub_hooks'] = array();

function add_filter( $tag, $fn, $prioridade = 10, $args = 1 ) {
	$GLOBALS['_stub_hooks'][ $tag ][] = $fn;
	return true;
}
function add_action( $tag, $fn, $prioridade = 10, $args = 1 ) {
	return add_filter( $tag, $fn );
}
function remove_all_filters( $tag ) {
	unset( $GLOBALS['_stub_hooks'][ $tag ] );
}
function apply_filters( $tag, $valor ) {
	$extra = array_slice( func_get_args(), 2 );
	foreach ( isset( $GLOBALS['_stub_hooks'][ $tag ] ) ? $GLOBALS['_stub_hooks'][ $tag ] : array() as $fn ) {
		$valor = call_user_func_array( $fn, array_merge( array( $valor ), $extra ) );
	}
	return $valor;
}
function do_action( $tag ) {
	$args = array_slice( func_get_args(), 1 );
	foreach ( isset( $GLOBALS['_stub_hooks'][ $tag ] ) ? $GLOBALS['_stub_hooks'][ $tag ] : array() as $fn ) {
		call_user_func_array( $fn, $args );
	}
}

function wp_salt( $esquema = 'auth' ) {
	return 'sal-de-teste-nao-use-em-producao-' . $esquema;
}

function register_rest_route( $ns, $rota, $args ) {
	return true;
}

class WP_REST_Response {
	private $dados;
	private $status;
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
}

/** Requisição de mentira, com só o que Livia_Rest usa. */
class Livia_Req_Teste {
	private $params;
	public function __construct( array $params ) {
		$this->params = $params;
	}
	public function get_param( $nome ) {
		return isset( $this->params[ $nome ] ) ? $this->params[ $nome ] : null;
	}
}

/** Zera transients e hooks entre um caso e outro. */
function livia_teste_zerar() {
	$GLOBALS['_stub_transients'] = array();
	$GLOBALS['_stub_hooks']      = array();
	$GLOBALS['_stub_dbdelta']    = array();
	$GLOBALS['_stub_cron']       = array();
	$GLOBALS['wpdb']             = new Livia_Wpdb_Teste();
}
function sanitize_text_field( $s ) {
	$s = strip_tags( (string) $s );
	$s = preg_replace( '/[\r\n\t]+/', ' ', $s );
	return trim( preg_replace( '/ {2,}/', ' ', $s ) );
}
/** Igual ao do WordPress: só #rgb e #rrggbb, ou false. */
function sanitize_hex_color( $cor ) {
	$cor = (string) $cor;
	if ( '' === $cor ) {
		return '';
	}
	return preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $cor ) ? $cor : null;
}
function wp_strip_all_tags( $s ) {
	return strip_tags( (string) $s );
}

function wp_json_encode( $dados, $flags = 0 ) {
	return json_encode( $dados, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
}
function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max );
}

function __return_false() {
	return false;
}
function __return_true() {
	return true;
}

// --- alertas e saúde -------------------------------------------------------
$GLOBALS['_stub_emails'] = array();

function wp_mail( $para, $assunto, $corpo, $cabecalhos = '', $anexos = array() ) {
	$GLOBALS['_stub_emails'][] = array( 'para' => $para, 'assunto' => $assunto, 'corpo' => $corpo );
	return true;
}
function is_email( $e ) {
	return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
}
function home_url( $caminho = '' ) {
	return 'https://exemplo.com.br' . $caminho;
}
/**
 * Escapes e helpers de saída do wp-admin.
 *
 * Ficam aqui para os casos poderem renderizar as telas de verdade, em vez de
 * confiar que elas funcionam porque o PHP não reclamou da sintaxe.
 */
function current_user_can( $cap ) {
	return true;
}
function settings_errors( $slug = '' ) {}
function settings_fields( $grupo ) {}
function checked( $a, $b = true, $exibir = true ) {
	$s = (string) $a === (string) $b ? " checked='checked'" : '';
	if ( $exibir ) { echo $s; }
	return $s;
}
function submit_button( $texto = 'Salvar' ) {
	echo '<button type="submit" class="button button-primary">' . esc_html( $texto ) . '</button>';
}
function wp_nonce_field( $acao = -1, $nome = '_wpnonce', $referer = true, $exibir = true ) {
	$html = '<input type="hidden" name="' . esc_attr( $nome ) . '" value="teste">';
	if ( $exibir ) { echo $html; }
	return $html;
}
function wp_date( $formato, $ts = null ) {
	return gmdate( $formato, null === $ts ? time() : $ts );
}
function rest_url( $caminho = '' ) {
	return 'https://exemplo.com.br/wp-json/' . ltrim( (string) $caminho, '/' );
}
function get_locale() {
	return 'pt_BR';
}
function wp_nonce_url( $url, $acao = -1, $nome = '_wpnonce' ) {
	return add_query_arg( array( $nome => 'teste' ), $url );
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $t ) {
	return esc_html( $t );
}
function esc_textarea( $t ) {
	return esc_html( $t );
}
function esc_url( $u ) {
	return str_replace( array( '"', "'", '<', '>' ), '', (string) $u );
}
function esc_url_raw( $u ) {
	return esc_url( $u );
}
function wp_kses_post( $html ) {
	return (string) $html;
}
function add_query_arg( $args, $url = '' ) {
	$partes = array();
	foreach ( (array) $args as $k => $v ) {
		if ( false === $v || null === $v ) {
			continue;
		}
		$partes[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $v );
	}
	return $url . ( false === strpos( (string) $url, '?' ) ? '?' : '&' ) . implode( '&', $partes );
}
function number_format_i18n( $n, $casas = 0 ) {
	return number_format( (float) $n, (int) $casas, ',', '.' );
}
function selected( $a, $b, $exibir = true ) {
	$saida = (string) $a === (string) $b ? " selected='selected'" : '';
	if ( $exibir ) {
		echo $saida; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return $saida;
}
function remove_submenu_page( $pai, $slug ) {
	return false;
}

function admin_url( $caminho = '' ) {
	return 'https://exemplo.com.br/wp-admin/' . $caminho;
}
function wp_parse_url( $url, $componente = -1 ) {
	return parse_url( $url, $componente );
}
function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['_stub_cron'][ $hook ] ) ? $GLOBALS['_stub_cron'][ $hook ] : false;
}
function wp_schedule_event( $quando, $recorrencia, $hook ) {
	$GLOBALS['_stub_cron'][ $hook ] = $quando;
	return true;
}
function wp_unschedule_event( $quando, $hook ) {
	unset( $GLOBALS['_stub_cron'][ $hook ] );
	return true;
}
function livia_teste_emails() {
	return $GLOBALS['_stub_emails'];
}
function livia_teste_zerar_emails() {
	$GLOBALS['_stub_emails'] = array();
}

define( 'LIVIA_VERSAO', '0.1.0-teste' );
define( 'MINUTE_IN_SECONDS', 60 );

function remove_accents( $s ) {
	return Livia_Trava_Acentos::tirar( $s );
}
class Livia_Trava_Acentos {
	public static function tirar( $s ) {
		$de   = array( 'á','à','â','ã','é','ê','í','ó','ô','õ','ú','ç' );
		$para = array( 'a','a','a','a','e','e','i','o','o','o','u','c' );
		return str_replace( $de, $para, (string) $s );
	}
}
