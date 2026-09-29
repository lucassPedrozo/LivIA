<?php
/**
 * Plugin Name: LivIA — ajustes de homologação
 * Description: Afrouxa os limites da LivIA para a bateria de comportamento poder rodar. NUNCA instale em produção.
 *
 * Copie para wp-content/mu-plugins/ do ambiente de HOMOLOGAÇÃO.
 *
 * Por que isto existe: a bateria (testar.py) abre uma conversa nova por caso,
 * porque contexto de um caso não pode vazar no outro. São 48 conversas em
 * poucos minutos, e o teto de produção é 30 por hora — de propósito.
 *
 * O certo não é baixar a defesa em produção para o teste passar. É a homologação
 * declarar que ali os números são outros.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'livia_teto_sessoes_por_hora', function () { return 500; } );
add_filter( 'livia_teto_por_ip',           function () { return 500; } );
add_filter( 'livia_teto_por_sessao',       function () { return 50; } );
add_filter( 'livia_teto_diario',           function () { return 1000; } );

// Em homologação a conversa é de robô: não há dado pessoal para proteger, e ver
// a pergunta crua ajuda a depurar.
add_filter( 'livia_redigir_pii', '__return_false' );
