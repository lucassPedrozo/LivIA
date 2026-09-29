<?php
/**
 * Plugin Name:       LivIA
 * Description:       Atendente virtual que tira dúvidas de quem está preenchendo os briefings da Joinvix. Responde só o que está na base de conhecimento; fora disso, encaminha para o atendimento humano.
 * Version:           0.7.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Joinvix
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'LIVIA_VERSAO', '0.7.0' );
define( 'LIVIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'LIVIA_URL', plugin_dir_url( __FILE__ ) );

require_once LIVIA_DIR . 'includes/class-livia-config.php';
require_once LIVIA_DIR . 'includes/class-livia-base.php';
require_once LIVIA_DIR . 'includes/class-livia-trava.php';
require_once LIVIA_DIR . 'includes/class-livia-cache.php';
require_once LIVIA_DIR . 'includes/class-livia-modelos.php';
require_once LIVIA_DIR . 'includes/class-livia-atalhos.php';
require_once LIVIA_DIR . 'includes/class-livia-trechos.php';
require_once LIVIA_DIR . 'includes/class-livia-gemini.php';
require_once LIVIA_DIR . 'includes/class-livia-sessao.php';
require_once LIVIA_DIR . 'includes/class-livia-limites.php';
require_once LIVIA_DIR . 'includes/class-livia-prompt.php';
require_once LIVIA_DIR . 'includes/class-livia-sse.php';
require_once LIVIA_DIR . 'includes/class-livia-stream.php';
require_once LIVIA_DIR . 'includes/class-livia-rest.php';
require_once LIVIA_DIR . 'includes/class-livia-widget.php';
require_once LIVIA_DIR . 'includes/class-livia-registro.php';
require_once LIVIA_DIR . 'includes/class-livia-saude.php';
require_once LIVIA_DIR . 'includes/class-livia-alerta.php';

Livia_Rest::iniciar();
Livia_Widget::iniciar();
Livia_Registro::iniciar();
Livia_Alerta::iniciar();

if ( is_admin() ) {
	require_once LIVIA_DIR . 'admin/class-livia-admin.php';
	require_once LIVIA_DIR . 'admin/class-livia-relatorios.php';
	add_action( 'plugins_loaded', array( 'Livia_Admin', 'iniciar' ) );
	add_action( 'plugins_loaded', array( 'Livia_Relatorios', 'iniciar' ) );
}

register_activation_hook( __FILE__, 'livia_ao_ativar' );
register_deactivation_hook( __FILE__, 'livia_ao_desativar' );

function livia_ao_ativar() {
	Livia_Config::ao_ativar();
	Livia_Registro::instalar();
	Livia_Registro::agendar_expurgo();
}

function livia_ao_desativar() {
	Livia_Base::limpar_cache();
	Livia_Registro::desagendar_expurgo();
}
