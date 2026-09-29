<?php
/**
 * Desinstalação: apaga tudo que o plugin criou.
 *
 * Chamado só quando o usuário APAGA o plugin, não quando desativa.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'livia_config' );
delete_option( 'livia_db_versao' );
delete_transient( 'livia_alerta_disjuntor' );
delete_transient( 'livia_alerta_chave' );
delete_transient( 'livia_falhas_chave' );
delete_transient( 'livia_base_cache' );
delete_transient( 'livia_diagnostico' );
delete_transient( 'livia_cache_ctx' );
delete_transient( 'livia_cache_erro' );

// As conversas em andamento ficam em transient, uma por sessão, com nome que
// não dá para listar de antemão. Elas expirariam sozinhas em duas horas, mas
// carregam o que o cliente escreveu — e "apagar tudo" não combina com esperar.
// Só alcança transient guardado no banco; com cache de objeto externo ligado,
// o que sobra lá expira no prazo normal.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB
	"DELETE FROM {$wpdb->options}
	  WHERE option_name LIKE '\_transient\_livia\_%'
	     OR option_name LIKE '\_transient\_timeout\_livia\_%'"
);

// O expurgo diário não pode continuar agendado depois que o plugin sai.
wp_clear_scheduled_hook( 'livia_diario' );

// Conversas dos clientes. Guarda dado pessoal — some junto com o plugin.
$tabela = $wpdb->prefix . 'livia_mensagens';
$wpdb->query( "DROP TABLE IF EXISTS `{$tabela}`" ); // phpcs:ignore WordPress.DB
