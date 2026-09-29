<?php
/**
 * Casos da configuração e do carregamento da base.
 *
 * Estas asserções existiam desde a Fase 1, mas só num script solto que nunca
 * entrou na suíte — ou seja, deixaram de valer no dia seguinte. Aqui elas ficam.
 *
 * Cada caso devolve a configuração ao estado padrão no fim, para não contaminar
 * os grupos seguintes.
 */

defined( 'ABSPATH' ) || exit;

function livia_teste_config_padrao() {
	Livia_Config::salvar(
		array(
			'GEMINI_API_KEY'   => 'chave-de-teste',
			'GEMINI_MODEL'     => 'gemini-3.5-flash-lite',
			'CANAL_DE_SUPORTE' => 'WhatsApp (47) 3433-5066',
		)
	);
	Livia_Base::limpar_cache();
}

/** Roda o caso e devolve a configuração ao padrão, dê certo ou errado. */
function livia_teste_com_config( callable $corpo ) {
	try {
		return $corpo();
	} finally {
		livia_teste_config_padrao();
	}
}

return array(

	array(
		'grupo' => 'config',
		'nome'  => 'o ID do modelo é normalizado ("Models/Gemini-3.5-Flash-Lite")',
		'executar' => function () {
			return livia_teste_com_config( function () {
				Livia_Config::salvar(
					array(
						'GEMINI_API_KEY'   => 'k',
						'GEMINI_MODEL'     => 'Models/Gemini-3.5-Flash-Lite',
						'CANAL_DE_SUPORTE' => 'x',
					)
				);
				$m = Livia_Config::modelo();
				return 'gemini-3.5-flash-lite' === $m ? null : 'saiu "' . $m . '"';
			} );
		},
	),

	array(
		'grupo' => 'config',
		'nome'  => 'campo de chave em branco MANTÉM a chave salva',
		'executar' => function () {
			return livia_teste_com_config( function () {
				Livia_Config::salvar( array( 'GEMINI_API_KEY' => 'segredo-1', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'c' ) );
				// Salvar os outros campos não pode exigir redigitar a chave — e a
				// chave nunca é reimpressa na tela para poder ser mantida.
				Livia_Config::salvar( array( 'GEMINI_API_KEY' => '', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'outro' ) );
				return 'segredo-1' === Livia_Config::api_key() ? null : 'perdeu a chave ao salvar o resto';
			} );
		},
	),

	array(
		'grupo' => 'config',
		'nome'  => 'modelo em branco volta ao padrão em vez de ficar vazio',
		'executar' => function () {
			return livia_teste_com_config( function () {
				Livia_Config::salvar( array( 'GEMINI_API_KEY' => 'k', 'GEMINI_MODEL' => '', 'CANAL_DE_SUPORTE' => 'c' ) );
				$padroes = Livia_Config::padroes();
				return Livia_Config::modelo() === $padroes['GEMINI_MODEL'] ? null : 'ficou "' . Livia_Config::modelo() . '"';
			} );
		},
	),

	array(
		'grupo' => 'config',
		'nome'  => 'rótulo em branco FICA em branco (é o botão redondo)',
		'executar' => function () {
			return livia_teste_com_config( function () {
				// Ao contrário do nome e do modelo, aqui o vazio é escolha: o
				// CSS desenha o botão redondo quando não há rótulo, e devolver
				// o padrão deixaria esse desenho inalcançável pelo painel.
				Livia_Config::salvar(
					array( 'GEMINI_API_KEY' => 'k', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'c', 'ROTULO' => '   ' )
				);
				if ( '' !== Livia_Config::rotulo() ) {
					return 'voltou o padrão: "' . Livia_Config::rotulo() . '"';
				}

				// E o que foi escrito continua cabendo no botão.
				Livia_Config::salvar(
					array( 'GEMINI_API_KEY' => 'k', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'c', 'ROTULO' => str_repeat( 'a', 60 ) )
				);
				$tamanho = function_exists( 'mb_strlen' ) ? mb_strlen( Livia_Config::rotulo() ) : strlen( Livia_Config::rotulo() );
				return 24 === $tamanho ? null : 'passou de 24 caracteres: ' . $tamanho;
			} );
		},
	),

	array(
		'grupo' => 'config',
		'nome'  => 'o canal é forçado a texto puro (link vira lixo no balão)',
		'executar' => function () {
			return livia_teste_com_config( function () {
				Livia_Config::salvar(
					array(
						'GEMINI_API_KEY'   => 'k',
						'GEMINI_MODEL'     => 'm',
						'CANAL_DE_SUPORTE' => '<a href="https://wa.me/554734335066">fale conosco</a>',
					)
				);
				$c = Livia_Config::canal();
				return 'fale conosco' === $c ? null : 'saiu "' . $c . '"';
			} );
		},
	),

	array(
		'grupo' => 'config',
		'nome'  => 'canal vazio deixa o marcador literal (igual ao livia.py)',
		'executar' => function () {
			return livia_teste_com_config( function () {
				Livia_Config::salvar( array( 'GEMINI_API_KEY' => 'k', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => '' ) );
				Livia_Base::limpar_cache();
				if ( ! Livia_Base::marcador_pendente() ) {
					return 'substituiu por alguma coisa mesmo sem canal configurado';
				}
				return null;
			} );
		},
	),

	array(
		'grupo' => 'config',
		'nome'  => 'trocar o canal invalida o cache da base',
		'executar' => function () {
			return livia_teste_com_config( function () {
				Livia_Config::salvar( array( 'GEMINI_API_KEY' => 'k', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'Canal Um' ) );
				Livia_Base::limpar_cache();
				if ( false === strpos( Livia_Base::carregar(), 'Canal Um' ) ) {
					return 'não substituiu o marcador';
				}

				// Sem invalidação, a base ficaria servindo o canal antigo até o
				// cache expirar — e ninguém entenderia por quê.
				Livia_Config::salvar( array( 'GEMINI_API_KEY' => 'k', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'Canal Dois' ) );
				$base = Livia_Base::carregar();

				if ( false !== strpos( $base, 'Canal Um' ) ) {
					return 'serviu o canal antigo do cache';
				}
				return false !== strpos( $base, 'Canal Dois' ) ? null : 'não trocou para o canal novo';
			} );
		},
	),

	array(
		'grupo' => 'config',
		'nome'  => 'esta_configurado() exige chave, modelo e base',
		'executar' => function () {
			return livia_teste_com_config( function () {
				Livia_Config::salvar( array( 'GEMINI_API_KEY' => '', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'c' ) );
				// A chave em branco mantém a anterior, então zeramos na marra.
				update_option( Livia_Config::OPCAO, array( 'GEMINI_API_KEY' => '', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'c' ) );
				return Livia_Config::esta_configurado() ? 'disse que está pronta sem chave nenhuma' : null;
			} );
		},
	),
);
