<?php
/**
 * Casos das consultas que a tela de conversas passou a fazer.
 *
 * O que se testa é a FORMA da consulta, não o resultado: sem um MySQL não há
 * como pedir linhas de volta, e montar um faria a suíte deixar de rodar em
 * milissegundos. Mas a forma é onde moram os erros que importam — um WHERE que
 * some, um LIKE sem escape, um LIMIT que não entrou.
 *
 * O preenchimento de dias vazios é a exceção: aquilo é lógica em PHP, e roda
 * inteiro aqui.
 */

defined( 'ABSPATH' ) || exit;

/** O SQL da última consulta preparada, ou ''. */
function livia_teste_ultimo_sql() {
	$preparadas = $GLOBALS['wpdb']->consultas_preparadas;
	if ( ! $preparadas ) {
		return '';
	}
	$ultima = end( $preparadas );
	return $ultima[0];
}

/** Os argumentos da última consulta preparada. */
function livia_teste_ultimos_args() {
	$preparadas = $GLOBALS['wpdb']->consultas_preparadas;
	if ( ! $preparadas ) {
		return array();
	}
	$ultima = end( $preparadas );
	// prepare( $sql, $array ) e prepare( $sql, $a, $b ) chegam diferente aqui.
	return ( isset( $ultima[1][0] ) && is_array( $ultima[1][0] ) ) ? $ultima[1][0] : $ultima[1];
}

return array(

	// ------------------------------------------------------------- busca

	array(
		'grupo' => 'painel',
		'nome'  => 'a busca procura na conversa inteira, não na linha solta',
		'executar' => function () {
			livia_teste_zerar();
			Livia_Registro::conversas( 30, 40, false, 'logotipo' );

			$sql = livia_teste_ultimo_sql();

			if ( false === strpos( $sql, 'sessao IN (' ) ) {
				return 'filtrou turno em vez de conversa: quem procura "logotipo" quer LER a conversa';
			}
			if ( false === strpos( $sql, 'pergunta LIKE' ) || false === strpos( $sql, 'resposta LIKE' ) ) {
				return 'a busca não olha os dois lados do diálogo';
			}

			$args = livia_teste_ultimos_args();
			foreach ( $args as $a ) {
				if ( is_string( $a ) && false !== strpos( $a, 'logotipo' ) ) {
					return 0 === strpos( $a, '%' ) ? null : 'o termo foi sem curinga';
				}
			}
			return 'o termo procurado não chegou aos argumentos';
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'sem busca, a consulta não ganha LIKE nenhum',
		'executar' => function () {
			livia_teste_zerar();
			Livia_Registro::conversas( 30, 40, false, '' );

			// Um LIKE que sempre entra, mesmo vazio, vira varredura de tabela
			// inteira em todo carregamento da tela.
			return false === strpos( livia_teste_ultimo_sql(), 'LIKE' )
				? null
				: 'entrou um LIKE mesmo sem ninguém ter procurado nada';
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'quem procura "100%" não recebe a tabela inteira',
		'executar' => function () {
			livia_teste_zerar();
			Livia_Registro::conversas( 30, 40, false, '100%_teste' );

			foreach ( livia_teste_ultimos_args() as $a ) {
				if ( is_string( $a ) && false !== strpos( $a, 'teste' ) ) {
					// Sem esc_like, o % e o _ do termo viram curinga de LIKE e a
					// busca casa com tudo — silenciosamente.
					return ( false !== strpos( $a, chr( 92 ) . '%' ) && false !== strpos( $a, chr( 92 ) . '_' ) )
						? null
						: 'os curingas do termo não foram escapados: ' . $a;
				}
			}
			return 'o termo não chegou aos argumentos';
		},
	),

	// --------------------------------------------------- o que não funcionou

	array(
		'grupo' => 'painel',
		'nome'  => 'a fila de conserto conta polegar para baixo e resposta barrada',
		'executar' => function () {
			livia_teste_zerar();
			Livia_Registro::nao_funcionou( 30 );

			$sql = livia_teste_ultimo_sql();

			if ( false === strpos( $sql, 'util = 0' ) || false === strpos( $sql, 'bloqueio IS NOT NULL' ) ) {
				return 'a fila não olha as duas fontes de falha';
			}
			if ( false === strpos( $sql, 'GROUP BY chave' ) ) {
				return 'não agrupa: a mesma dúvida escrita de dez jeitos apareceria dez vezes';
			}
			// Encaminhamento NÃO pode entrar: preço e prazo de contrato TÊM que
			// ir para a equipe, e contá-los como falha encheria a lista do
			// comportamento correto.
			return false === strpos( $sql, 'erro IS NOT NULL' )
				? null
				: 'erro de API entrou na fila de conserto da base — não é falha da base';
		},
	),

	// -------------------------------------------------------- série diária

	array(
		'grupo' => 'painel',
		'nome'  => 'dia sem conversa aparece vazio, não some',
		'executar' => function () {
			livia_teste_zerar();
			$serie = Livia_Registro::por_dia( 14 );

			if ( 14 !== count( $serie ) ) {
				return 'devolveu ' . count( $serie ) . ' dias em vez de 14';
			}

			// Três dias parados não podem virar duas barras coladas: um gráfico
			// que pula os buracos mente sobre o ritmo.
			foreach ( $serie as $d ) {
				foreach ( array( 'dia', 'mensagens', 'conversas', 'ao_modelo', 'tokens', 'problemas' ) as $campo ) {
					if ( ! array_key_exists( $campo, $d ) ) {
						return 'dia preenchido sem o campo ' . $campo;
					}
				}
			}

			$primeiro = $serie[0]['dia'];
			$ultimo   = $serie[13]['dia'];
			if ( $primeiro >= $ultimo ) {
				return 'a série não está do mais antigo para o mais novo';
			}
			return gmdate( 'Y-m-d' ) === $ultimo ? null : 'o último dia da série não é hoje';
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'a janela da série tem limite dos dois lados',
		'executar' => function () {
			livia_teste_zerar();

			// Um número absurdo vindo da URL não pode virar uma varredura de
			// anos, nem um gráfico de uma barra só.
			if ( 90 !== count( Livia_Registro::por_dia( 9999 ) ) ) {
				return 'não limitou o teto da janela';
			}
			return 2 === count( Livia_Registro::por_dia( 0 ) ) ? null : 'não segurou o piso da janela';
		},
	),

	// ------------------------------------------------------ faixa de atenção

	array(
		'grupo' => 'painel',
		'nome'  => 'a faixa de atenção cala quando não há o que decidir',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();

			ob_start();
			Livia_Admin::cabecalho( 'config' );
			$html = ob_get_clean();

			if ( false === strpos( $html, 'nav-tab' ) ) {
				return 'o cabeçalho não trouxe as abas';
			}
			// Um painel que avisa de tudo é um painel que ninguém lê.
			return false !== strpos( $html, 'livia-tranquilo' )
				? null
				: 'inventou aviso com tudo em ordem: ' . wp_strip_all_tags( $html );
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'cota esgotada aparece na faixa, nas duas abas',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();

			for ( $i = 0; $i < Livia_Limites::teto_diario(); $i++ ) {
				Livia_Limites::registrar_chamada();
			}

			foreach ( array( 'config', 'conversas' ) as $aba ) {
				ob_start();
				Livia_Admin::cabecalho( $aba );
				$html = ob_get_clean();

				if ( false === strpos( $html, 'Cota do dia esgotada' ) ) {
					return 'a aba "' . $aba . '" não avisou que a cota acabou';
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'desligada, a faixa diz isso antes de qualquer outra coisa',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();

			$tudo = get_option( Livia_Config::OPCAO, array() );
			$tudo['ATIVA'] = '0';
			update_option( Livia_Config::OPCAO, $tudo );

			ob_start();
			Livia_Admin::cabecalho( 'config' );
			$html = ob_get_clean();

			return false !== strpos( $html, 'Desligada' )
				? null
				: 'a tela não disse que ninguém está vendo a LivIA';
		},
	),
	// ---------------------------------------------- as telas renderizam

	array(
		'grupo' => 'painel',
		'nome'  => 'as duas telas do wp-admin renderizam inteiras',
		'executar' => function () {
			// Até agora a suíte só lia a sintaxe destes arquivos. Um `esc_html`
			// escrito errado, uma variável que não existe naquele escopo, uma
			// chamada a método que mudou de nome: nada disso aparecia aqui —
			// aparecia como tela branca no wp-admin de quem instalou.
			livia_teste_zerar();
			livia_teste_config();

			foreach ( array( 'Livia_Admin', 'Livia_Relatorios' ) as $tela ) {
				ob_start();
				try {
					call_user_func( array( $tela, 'render' ) );
				} catch ( Throwable $e ) {
					ob_end_clean();
					return $tela . ' quebrou ao renderizar: ' . $e->getMessage();
				}
				$html = ob_get_clean();

				if ( strlen( $html ) < 500 ) {
					return $tela . ' devolveu quase nada: ' . strlen( $html ) . ' bytes';
				}
				if ( false === strpos( $html, 'nav-tab' ) ) {
					return $tela . ' saiu sem as abas';
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'a tela de conversas renderiza com o registro vazio',
		'executar' => function () {
			// O primeiro dia de vida do plugin é este: nenhuma conversa, todas
			// as consultas voltando vazias. É quando uma tela de painel costuma
			// dividir por zero.
			livia_teste_zerar();
			livia_teste_config();

			ob_start();
			try {
				Livia_Relatorios::render();
			} catch ( Throwable $e ) {
				ob_end_clean();
				return 'quebrou sem nenhuma conversa gravada: ' . $e->getMessage();
			}
			$html = ob_get_clean();

			return false !== strpos( $html, 'livia-vazio' )
				? null
				: 'sem dados, a tela não mostrou nenhum estado vazio';
		},
	),
);
