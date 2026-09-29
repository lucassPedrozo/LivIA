<?php
/**
 * Casos do registro: a redação de dado pessoal e o cálculo de percentil.
 *
 * A gravação em si depende do $wpdb e não é testada aqui — o que é testado é o
 * que decide o que vai parar no banco, que é onde mora o risco de LGPD.
 */

defined( 'ABSPATH' ) || exit;

return array(

	array(
		'grupo' => 'lgpd',
		'nome'  => 'e-mail, telefone, CPF, CNPJ e CEP saem do texto do cliente',
		'executar' => function () {
			$casos = array(
				'meu email e joao.silva@gmail.com'        => '[email]',
				'meu telefone e (47) 99123-4567'          => '[telefone]',
				'meu cpf e 123.456.789-00'                => '[cpf]',
				'o cnpj da empresa e 12.345.678/0001-99'  => '[cnpj]',
				'meu cep e 89000-000'                     => '[cep]',
			);
			foreach ( $casos as $entrada => $marca ) {
				$saida = Livia_Registro::redigir( $entrada );
				if ( false === strpos( $saida, $marca ) ) {
					return sprintf( 'não redigiu %s em "%s" — saiu "%s"', $marca, $entrada, $saida );
				}
				if ( preg_match( '/\d{5}/', $saida ) ) {
					return 'sobrou corrida de dígitos: ' . $saida;
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'lgpd',
		'nome'  => 'CNPJ não é confundido com telefone (a ordem das regras importa)',
		'executar' => function () {
			// 14 dígitos também casam com o padrão de telefone. Se o telefone
			// rodasse primeiro, o CNPJ sairia marcado errado.
			$saida = Livia_Registro::redigir( 'o cnpj e 12.345.678/0001-99' );
			if ( false === strpos( $saida, '[cnpj]' ) ) {
				return 'marcou como outra coisa: ' . $saida;
			}
			return false === strpos( $saida, '[telefone]' ) ? null : 'marcou como telefone';
		},
	),

	array(
		'grupo' => 'lgpd',
		'nome'  => 'a pergunta continua legível depois da redação',
		'executar' => function () {
			$saida = Livia_Registro::redigir( 'onde eu coloco meu telefone (47) 99123-4567 no formulario?' );
			$esperado = 'onde eu coloco meu telefone [telefone] no formulario?';
			return $saida === $esperado ? null : 'perdeu o sentido: ' . $saida;
		},
	),

	array(
		'grupo' => 'lgpd',
		'nome'  => 'texto sem dado pessoal passa intacto',
		'executar' => function () {
			$texto = 'o que eu escrevo em ramo de atividade? tenho uma padaria ha 12 anos';
			return Livia_Registro::redigir( $texto ) === $texto ? null : 'mexeu em texto limpo';
		},
	),

	array(
		'grupo' => 'lgpd',
		'nome'  => 'o filtro desliga a redação quando a equipe assumir o risco',
		'executar' => function () {
			add_filter( 'livia_redigir_pii', '__return_false' );
			$texto = 'meu email e joao@gmail.com';
			$saida = Livia_Registro::redigir( $texto );
			remove_all_filters( 'livia_redigir_pii' );
			return $saida === $texto ? null : 'o filtro não desligou';
		},
	),

	array(
		'grupo' => 'metricas',
		'nome'  => 'p95 de 1..100 é 95',
		'executar' => function () {
			$v = range( 1, 100 );
			$p = Livia_Registro::percentil( $v, 0.95 );
			return 95 === $p ? null : 'deu ' . $p;
		},
	),

	array(
		'grupo' => 'metricas',
		'nome'  => 'p95 não estoura com lista vazia nem com um valor só',
		'executar' => function () {
			if ( 0 !== Livia_Registro::percentil( array() ) ) {
				return 'lista vazia não deu zero';
			}
			return 42 === Livia_Registro::percentil( array( 42 ) ) ? null : 'lista de um elemento errou';
		},
	),

	array(
		'grupo' => 'metricas',
		'nome'  => 'p95 ordena antes de medir',
		'executar' => function () {
			// Latências chegam do banco na ordem que o banco quiser.
			$p = Livia_Registro::percentil( array( 900, 100, 300, 200, 150, 250, 180, 220, 190, 210 ) );
			return 900 === $p ? null : 'deu ' . $p . ', esperava 900';
		},
	),
);
