<?php
/**
 * Casos do CSS. Sem navegador.
 *
 * Não dá para medir pixel daqui, e não é o que interessa: o que quebrou em
 * produção não foi um número errado, foi uma regra do TEMA ganhando da nossa
 * por especificidade. O Elementor imprime `.elementor-kit-614 button { padding:
 * 20px 36px; border-radius: 30px }` — 0-1-1 — e isso passa por cima de
 * `.livia-fechar`, que vale 0-1-0. O X do cabeçalho virou um retângulo azul de
 * 72x40 no site de vocês.
 *
 * A defesa é uma regra de escrita: toda regra do widget começa com
 * `.livia-raiz`. Regra de escrita só vale se alguém cobrar — e quem cobra é
 * isto aqui, que lê o arquivo como texto e reclama do que passou.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Os seletores de um arquivo CSS, um por elemento.
 *
 * Um analisador de CSS de verdade seria exagero: o que se quer é a lista de
 * seletores, e para isso basta apagar comentários, ignorar o miolo dos blocos
 * e o conteúdo de @keyframes (onde `from`, `to` e `50%` não são seletores).
 */
function livia_teste_seletores( $arquivo ) {
	$css = file_get_contents( $arquivo );
	$css = preg_replace( '#/\*.*?\*/#s', '', $css );

	// Fora o miolo dos @keyframes: 'from', 'to' e '0%' não são seletores.
	$css = preg_replace( '/@keyframes[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/s', '', $css );

	$seletores = array();
	foreach ( explode( '}', $css ) as $pedaco ) {
		$corte = strpos( $pedaco, '{' );
		if ( false === $corte ) {
			continue;
		}
		$cabeca = trim( substr( $pedaco, 0, $corte ) );
		// Abertura de @media/@supports deixa resto na frente do seletor.
		$cabeca = trim( preg_replace( '/^.*\{/s', '', $cabeca ) );
		if ( '' === $cabeca || '@' === substr( $cabeca, 0, 1 ) ) {
			continue;
		}
		// A vírgula separa seletores — menos dentro de parênteses, onde ela
		// separa argumentos de :where(), :is() e :not().
		$atual = '';
		$fundo = 0;
		foreach ( str_split( $cabeca . ',' ) as $letra ) {
			if ( '(' === $letra ) {
				++$fundo;
			} elseif ( ')' === $letra ) {
				--$fundo;
			}
			if ( ',' === $letra && 0 === $fundo ) {
				$um = trim( preg_replace( '/\s+/', ' ', $atual ) );
				if ( '' !== $um ) {
					$seletores[] = $um;
				}
				$atual = '';
				continue;
			}
			$atual .= $letra;
		}
	}
	return $seletores;
}

return array(

	array(
		'grupo' => 'css',
		'nome'  => 'toda regra do widget carrega .livia-raiz',
		'executar' => function () {
			$soltos = array();
			foreach ( livia_teste_seletores( LIVIA_DIR . 'public/livia-widget.css' ) as $s ) {
				if ( false === strpos( $s, '.livia-raiz' ) ) {
					$soltos[] = $s;
				}
			}
			// Sem o prefixo a regra vale 0-1-0 e perde para qualquer
			// `.classe-do-tema elemento`. É assim que o widget chega torto na
			// casa de quem instalou — e sem erro nenhum no console.
			return $soltos
				? count( $soltos ) . ' regra(s) sem o prefixo: ' . implode( ' | ', array_slice( $soltos, 0, 5 ) )
				: null;
		},
	),

	array(
		'grupo' => 'css',
		'nome'  => 'o widget não pinta nada fora do próprio território',
		'executar' => function () {
			foreach ( livia_teste_seletores( LIVIA_DIR . 'public/livia-widget.css' ) as $s ) {
				// A raiz é o único ponto de contato com a página, e `body` só
				// aparece para desviar da barra do admin.
				if ( 0 !== strpos( $s, '.livia-raiz' ) && 0 !== strpos( $s, 'body.admin-bar .livia-raiz' ) ) {
					return 'seletor começa fora da raiz: ' . $s;
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'css',
		'nome'  => 'o CSS do painel não repinta classe do WordPress solta',
		'executar' => function () {
			foreach ( livia_teste_seletores( LIVIA_DIR . 'admin/livia-admin.css' ) as $s ) {
				if ( false === strpos( $s, 'livia' ) ) {
					// `.button-link-delete` sozinho repinta o botão de apagar de
					// qualquer tela que venha a carregar este arquivo.
					return 'regra sem escopo nosso: ' . $s;
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'css',
		'nome'  => 'chaves e comentários fecham nos dois arquivos',
		'executar' => function () {
			foreach ( array( 'public/livia-widget.css', 'admin/livia-admin.css' ) as $rel ) {
				$css = file_get_contents( LIVIA_DIR . $rel );
				if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
					return $rel . ': chaves desemparelhadas';
				}
				if ( substr_count( $css, '/*' ) !== substr_count( $css, '*/' ) ) {
					// Um comentário sem fecho engole o resto do arquivo, e o
					// navegador não avisa: simplesmente para de estilizar.
					return $rel . ': comentário sem fecho';
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'css',
		'nome'  => 'o véu existe no CSS e no JavaScript, e fecha a conversa',
		'executar' => function () {
			$css = file_get_contents( LIVIA_DIR . 'public/livia-widget.css' );
			$js  = file_get_contents( LIVIA_DIR . 'public/livia-widget.js' );

			if ( false === strpos( $css, '.livia-veu' ) ) {
				return 'o CSS não tem a camada que escurece a página';
			}
			if ( false === strpos( $js, 'livia-veu' ) ) {
				return 'o JavaScript não cria o véu';
			}
			// A ordem no HTML é o que põe a janela por cima do véu: não há
			// z-index entre os dois, de propósito.
			if ( strpos( $js, 'livia-veu' ) > strpos( $js, 'livia-janela' ) ) {
				return 'o véu foi escrito depois da janela e cobriria a conversa';
			}
			return false !== strpos( $js, "ui.veu.addEventListener( 'click', fechar )" )
				? null
				: 'clicar fora não fecha: o véu não tem ouvinte de clique';
		},
	),
);
