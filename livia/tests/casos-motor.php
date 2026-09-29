<?php
/**
 * Casos do motor: atalhos, cadeia de modelos, interruptor e cache automático.
 *
 * É o bloco que nasceu do relato de homologação — "ela trava com um simples
 * 'oi'". Os dois culpados eram a base inteira indo à API para responder uma
 * saudação, e três tentativas com recuo contra um modelo de cota esgotada.
 */

defined( 'ABSPATH' ) || exit;

/** Configuração com reserva, para exercitar a troca. */
function livia_teste_config_com_reserva( $principal = 'gemini-3.5-flash-lite', $reserva = 'gemini-2.5-flash' ) {
	update_option(
		Livia_Config::OPCAO,
		array(
			'GEMINI_API_KEY'       => 'teste',
			'GEMINI_MODEL'         => $principal,
			'GEMINI_MODEL_RESERVA' => $reserva,
			'CANAL_DE_SUPORTE'     => 'WhatsApp (47) 3433-5066',
			'ATIVA'                => '1',
			'CACHE_MODO'           => 'nunca',
		)
	);
}

/**
 * Decide a resposta por modelo. $mapa é [id do modelo => 'ok'|código de erro].
 * $vistos coleciona, em ordem, com quem se tentou falar.
 */
function livia_teste_modelos( array $mapa, &$vistos ) {
	$vistos = array();
	add_filter(
		'livia_pre_falar_com',
		function ( $curto, $modelo ) use ( $mapa, &$vistos ) {
			$vistos[] = $modelo;
			$como     = isset( $mapa[ $modelo ] ) ? $mapa[ $modelo ] : 'ok';

			if ( 'ok' !== $como ) {
				return new WP_Error( $como, 'simulado' );
			}
			return array(
				'texto'    => 'Resposta de ' . $modelo,
				'uso'      => array( 'entrada' => 100, 'saida' => 20, 'total' => 120 ),
				'finish'   => 'STOP',
				'truncado' => false,
			);
		},
		10,
		2
	);
}

return array(

	// ------------------------------------------------------------ atalhos

	array(
		'grupo' => 'atalho',
		'nome'  => 'cortesia é respondida sem tocar na API',
		'executar' => function () {
			$cortesias = array(
				'oi', 'Oi!', 'OIII', 'ola', 'Olá!', 'bom dia', 'Boa tarde.',
				'tudo bem?', 'obrigado', 'Obrigada!', 'vlw', 'valeu',
				'tchau', 'flw', 'ok', 'entendi', 'beleza',
			);
			foreach ( $cortesias as $texto ) {
				if ( ! Livia_Atalhos::responder( $texto ) ) {
					return 'foi parar no modelo: ' . $texto;
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'atalho',
		'nome'  => 'saudação COM pergunta junto vai para o modelo',
		'executar' => function () {
			// O erro caro seria o contrário: responder "Oi! Como posso ajudar?"
			// a quem perguntou o preço no mesmo fôlego.
			$perguntas = array(
				'oi, quanto custa?',
				'bom dia, qual o prazo de entrega do site',
				'obrigado mas ainda tenho uma duvida sobre o dominio',
				'ok e o que acontece depois que eu envio',
				'oi',  // este SIM é atalho — fica aqui só para o contraste abaixo
			);
			foreach ( array_slice( $perguntas, 0, 4 ) as $texto ) {
				if ( Livia_Atalhos::responder( $texto ) ) {
					return 'tratou como cortesia: ' . $texto;
				}
			}
			return Livia_Atalhos::responder( 'oi' ) ? null : 'deixou de reconhecer o "oi" puro';
		},
	),

	array(
		'grupo' => 'atalho',
		'nome'  => 'nenhuma resposta pronta inventa contato, preço ou link',
		'executar' => function () {
			// As respostas de atalho não passam pela trava em produção — elas
			// não vêm do modelo. Então a garantia tem que ser aqui: se alguém
			// um dia colar um telefone numa delas, este caso reprova.
			$base       = Livia_Base::carregar();
			$permitidos = Livia_Trava::permitidos( $base );

			foreach ( Livia_Atalhos::familias() as $nome => $familia ) {
				foreach ( $familia['respostas'] as $resposta ) {
					$motivo = Livia_Trava::verificar( $resposta, $permitidos );
					if ( $motivo ) {
						return $nome . ': ' . $motivo;
					}
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'atalho',
		'nome'  => 'a mesma frase não sai duas vezes seguidas na mesma conversa',
		'executar' => function () {
			livia_teste_zerar();
			$sessao = Livia_Sessao::novo_id();

			$primeira = Livia_Atalhos::responder( 'oi', $sessao );
			for ( $i = 0; $i < 8; $i++ ) {
				$outra = Livia_Atalhos::responder( 'oi', $sessao );
				if ( $outra['texto'] === $primeira['texto'] ) {
					// Repetir palavra por palavra é o que mais denuncia que do
					// outro lado não tem gente.
					return 'repetiu a mesma resposta';
				}
				$primeira = $outra;
			}
			return null;
		},
	),

	array(
		'grupo' => 'atalho',
		'nome'  => '"oi" não gasta cota, mas conta para o limite de ritmo',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			$chamadas = 0;
			livia_teste_responder_com( 'não deveria ser chamado', $chamadas );

			list( $sessao, $token ) = livia_teste_sessao();
			$antes = Livia_Limites::usadas_hoje();

			$resp = Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'oi' ) ) );
			$d    = $resp->get_data();

			if ( 0 !== $chamadas ) {
				return 'chamou a API para responder "oi"';
			}
			if ( Livia_Limites::usadas_hoje() !== $antes ) {
				return 'consumiu cota do dia sem chamar a API';
			}
			if ( 'atalho' !== $d['origem'] ) {
				return 'não marcou a origem';
			}
			// Sem isto, "oi" viraria um caminho sem teto nenhum.
			$bucket = (int) floor( time() / Livia_Limites::JANELA );
			$marca  = get_transient( 'livia_rs_' . substr( $sessao, 0, 12 ) . '_' . $bucket );
			return $marca > 0 ? null : 'não contou no limite de ritmo';
		},
	),

	array(
		'grupo' => 'atalho',
		'nome'  => 'atalho entra no registro com origem própria',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			list( , $token ) = livia_teste_sessao_na_pagina();

			$vistos = livia_teste_capturar( function () use ( $token ) {
				Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'bom dia' ) ) );
			} );

			if ( 1 !== count( $vistos ) ) {
				return 'a mensagem sumiu do registro';
			}
			// Contar atalho como 'modelo' faria o custo por mensagem parecer
			// menor do que é, e a latência média, melhor do que é.
			return 'atalho' === $vistos[0]['origem'] ? null : 'origem: ' . $vistos[0]['origem'];
		},
	),

	array(
		'grupo' => 'atalho',
		'nome'  => 'com o disjuntor aberto, "oi" ainda é respondido',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			for ( $i = 0; $i < Livia_Limites::teto_diario(); $i++ ) {
				Livia_Limites::registrar_chamada();
			}

			list( , $token ) = livia_teste_sessao();
			$d = Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'oi' ) ) )->get_data();

			// Mandar para o WhatsApp alguém que só disse "bom dia" seria pior
			// atendimento do que a saudação que já sabíamos dar.
			return isset( $d['origem'] ) && 'atalho' === $d['origem']
				? null
				: 'encaminhou em vez de responder: ' . wp_json_encode( $d );
		},
	),

	// ------------------------------------------------------ cadeia de modelos

	array(
		'grupo' => 'modelos',
		'nome'  => 'cota do principal passa a vez para a reserva, na mesma pergunta',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva();
			$vistos = array();
			livia_teste_modelos( array( 'gemini-3.5-flash-lite' => 'cota', 'gemini-2.5-flash' => 'ok' ), $vistos );

			$r = Livia_Gemini::gerar( 'INSTRUCAO', array() );

			if ( is_wp_error( $r ) ) {
				return 'devolveu erro: ' . $r->get_error_code();
			}
			if ( array( 'gemini-3.5-flash-lite', 'gemini-2.5-flash' ) !== $vistos ) {
				return 'ordem errada: ' . wp_json_encode( $vistos );
			}
			return 'gemini-2.5-flash' === $r['modelo'] ? null : 'modelo devolvido: ' . $r['modelo'];
		},
	),

	array(
		'grupo' => 'modelos',
		'nome'  => 'depois de rebaixar, a pergunta seguinte NÃO tenta o principal',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva();
			$vistos = array();
			livia_teste_modelos( array( 'gemini-3.5-flash-lite' => 'cota', 'gemini-2.5-flash' => 'ok' ), $vistos );

			Livia_Gemini::gerar( 'INSTRUCAO', array() );
			$vistos = array();
			Livia_Gemini::gerar( 'INSTRUCAO', array() );

			// Era exatamente isto que fazia o cliente esperar: pagar de novo,
			// a cada pergunta, a espera de um modelo que já se sabia fora.
			return array( 'gemini-2.5-flash' ) === $vistos
				? null
				: 'voltou a bater no principal: ' . wp_json_encode( $vistos );
		},
	),

	array(
		'grupo' => 'modelos',
		'nome'  => 'sem reserva configurada, o erro vai para o cliente como antes',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva( 'gemini-3.5-flash-lite', '' );
			$vistos = array();
			livia_teste_modelos( array( 'gemini-3.5-flash-lite' => 'cota' ), $vistos );

			$r = Livia_Gemini::gerar( 'INSTRUCAO', array() );

			if ( ! is_wp_error( $r ) || 'cota' !== $r->get_error_code() ) {
				return 'não devolveu o erro de cota';
			}
			return Livia_Modelos::rebaixado() ? 'anotou rebaixamento sem ter para onde ir' : null;
		},
	),

	array(
		'grupo' => 'modelos',
		'nome'  => 'chave recusada NÃO rebaixa — a reserva usaria a mesma chave',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva();
			$vistos = array();
			livia_teste_modelos( array( 'gemini-3.5-flash-lite' => 'chave_invalida' ), $vistos );

			$r = Livia_Gemini::gerar( 'INSTRUCAO', array() );

			if ( array( 'gemini-3.5-flash-lite' ) !== $vistos ) {
				return 'tentou a reserva com uma chave que já tinha sido recusada';
			}
			if ( Livia_Modelos::rebaixado() ) {
				return 'rebaixou por um problema que a troca não resolve';
			}
			return is_wp_error( $r ) && 'chave_invalida' === $r->get_error_code() ? null : 'erro errado';
		},
	),

	array(
		'grupo' => 'modelos',
		'nome'  => 'a reserva assumir avisa uma vez, não a cada pergunta',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_zerar_emails();
			update_option( 'admin_email', 'equipe@joinvix.com.br' );
			livia_teste_config_com_reserva();
			Livia_Alerta::iniciar();

			$vistos = array();
			livia_teste_modelos( array( 'gemini-3.5-flash-lite' => 'cota', 'gemini-2.5-flash' => 'ok' ), $vistos );

			for ( $i = 0; $i < 5; $i++ ) {
				Livia_Gemini::gerar( 'INSTRUCAO', array() );
			}

			$emails = livia_teste_emails();
			if ( 1 !== count( $emails ) ) {
				return 'mandou ' . count( $emails ) . ' e-mails, esperava 1';
			}
			return false !== strpos( $emails[0]['assunto'], 'reserva' ) ? null : 'assunto: ' . $emails[0]['assunto'];
		},
	),

	array(
		'grupo' => 'modelos',
		'nome'  => 'trocar o modelo no painel cancela o rebaixamento em curso',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva();
			Livia_Modelos::rebaixar( 'cota' );

			if ( ! Livia_Modelos::rebaixado() ) {
				return 'nem chegou a rebaixar';
			}

			// O rebaixamento falava de um principal que não é mais o principal.
			Livia_Config::salvar(
				array(
					'GEMINI_MODEL'         => 'gemini-2.5-pro',
					'GEMINI_MODEL_RESERVA' => 'gemini-2.5-flash',
					'ATIVA'                => '1',
				)
			);

			return Livia_Modelos::rebaixado() ? 'manteve um rebaixamento obsoleto' : null;
		},
	),

	array(
		'grupo' => 'modelos',
		'nome'  => 'reserva igual ao principal é guardada como "sem reserva"',
		'executar' => function () {
			livia_teste_zerar();
			Livia_Config::salvar(
				array(
					'GEMINI_MODEL'         => 'gemini-2.5-flash',
					'GEMINI_MODEL_RESERVA' => 'Models/Gemini-2.5-Flash',
					'ATIVA'                => '1',
				)
			);
			// Fingir que há um plano B quando não há é pior do que não ter.
			return '' === Livia_Config::modelo_reserva() ? null : 'guardou a si mesma como reserva';
		},
	),

	// ---------------------------------------------------------- interruptor

	array(
		'grupo' => 'interruptor',
		'nome'  => 'desligada, a rota recusa e o registro guarda a tentativa',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			$config          = Livia_Config::tudo();
			$config['ATIVA'] = '0';
			update_option( Livia_Config::OPCAO, $config );

			$chamadas = 0;
			livia_teste_responder_com( 'não deveria ser chamado', $chamadas );
			list( , $token ) = livia_teste_sessao_na_pagina();

			$vistos = livia_teste_capturar( function () use ( $token ) {
				Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );
			} );

			if ( 0 !== $chamadas ) {
				return 'chamou a API com a LivIA desligada';
			}
			if ( 1 !== count( $vistos ) || 'desligada' !== $vistos[0]['erro'] ) {
				return 'não registrou a tentativa: ' . wp_json_encode( $vistos );
			}
			return null;
		},
	),

	array(
		'grupo' => 'interruptor',
		'nome'  => 'desligada não é o mesmo que quebrada, para o monitor',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();
			$config          = Livia_Config::tudo();
			$config['ATIVA'] = '0';
			update_option( Livia_Config::OPCAO, $config );

			$r = Livia_Saude::resumo();
			if ( Livia_Saude::DESLIGADA !== $r['estado'] ) {
				return 'estado: ' . $r['estado'];
			}
			// Um monitor que grita por causa de um interruptor é um monitor que
			// se aprende a ignorar.
			return false === $r['ok'] ? null : 'disse que está tudo ok estando desligada';
		},
	),

	// ---------------------------------------------------------- cache por uso

	array(
		'grupo' => 'painel',
		'nome'  => 'as perguntas de partida param em três, e sem linha vazia',
		'executar' => function () {
			livia_teste_zerar();
			Livia_Config::salvar(
				array(
					'ATIVA'     => '1',
					// Cinco linhas, duas delas em branco e uma com HTML.
					'SUGESTOES' => "Primeira

  Segunda  
<b>Terceira</b>
Quarta
Quinta",
				)
			);

			$s = Livia_Config::sugestoes();

			// São botões numa janela estreita: a quarta quebra a linha e some
			// do primeiro olhar, que é o único momento em que elas servem.
			if ( 3 !== count( $s ) ) {
				return 'devolveu ' . count( $s ) . ': ' . wp_json_encode( $s );
			}
			if ( array( 'Primeira', 'Segunda', 'Terceira' ) !== $s ) {
				return 'não limpou direito: ' . wp_json_encode( $s );
			}
			return null;
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'a cor do widget não aceita nada além de uma cor',
		'executar' => function () {
			livia_teste_zerar();
			$padrao = Livia_Config::padroes();

			// Este campo acaba dentro de um atributo de estilo na página do
			// cliente. É o único da configuração que tem esse destino.
			$lixo = array(
				'red; background:url(javascript:alert(1))',
				'#fff" onload="alert(1)',
				'expression(alert(1))',
				'var(--qualquer-coisa)',
				'#12345',
				'',
			);
			foreach ( $lixo as $tentativa ) {
				Livia_Config::salvar( array( 'COR' => $tentativa, 'ATIVA' => '1' ) );
				if ( Livia_Config::cor() !== $padrao['COR'] ) {
					return 'aceitou ' . var_export( $tentativa, true ) . ' -> ' . Livia_Config::cor();
				}
			}

			// E uma cor de verdade passa.
			Livia_Config::salvar( array( 'COR' => '#B32D2E', 'ATIVA' => '1' ) );
			return '#B32D2E' === Livia_Config::cor() ? null : 'recusou uma cor válida';
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'a previsão de cota cala a boca com amostra pequena',
		'executar' => function () {
			livia_teste_zerar();

			$p = Livia_Limites::previsao();

			// Três clientes seguidos às 00h15 projetariam um teto inexistente,
			// e o painel gritaria por nada.
			if ( 0 === (int) gmdate( 'H' ) && $p['confiavel'] ) {
				return 'projetou o dia com quinze minutos de amostra';
			}
			if ( null !== $p['estoura_em'] ) {
				return 'previu estouro sem uma única chamada';
			}
			return $p['teto'] === Livia_Limites::TETO_DIARIO ? null : 'teto errado';
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'a duração é escrita como gente fala',
		'executar' => function () {
			$casos = array(
				30    => 'menos de um minuto',
				60    => '1 minuto',
				600   => '10 minutos',
				3600  => '1 hora',
				7200  => '2 horas',
				90000 => 'mais de um dia',
			);
			foreach ( $casos as $segundos => $esperado ) {
				$saiu = Livia_Admin::duracao( $segundos );
				if ( $saiu !== $esperado ) {
					return $segundos . 's virou "' . $saiu . '", esperava "' . $esperado . '"';
				}
			}
			return null;
		},
	),

	array(
		'grupo' => 'painel',
		'nome'  => 'o seletor de modelos nunca vai à rede ao abrir a tela',
		'executar' => function () {
			livia_teste_zerar();
			$foi_a_rede = false;
			add_filter(
				'livia_pre_listar_modelos',
				function ( $curto ) use ( &$foi_a_rede ) {
					$foi_a_rede = true;
					return array();
				}
			);

			// Isto é o que a tela de configuração chama ao renderizar. Buscar a
			// lista aqui penduraria o wp-admin até quinze segundos sempre que a
			// API estivesse lenta.
			$lista = Livia_Modelos::guardados();

			if ( $foi_a_rede ) {
				return 'foi buscar a lista no meio do carregamento da página';
			}
			return null === $lista ? null : 'devolveu lista sem nunca ter perguntado';
		},
	),

	array(
		'grupo' => 'cache',
		'nome'  => 'em automático, o cache só liga quando há movimento',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva();
			$config               = Livia_Config::tudo();
			$config['CACHE_MODO'] = 'auto';
			update_option( Livia_Config::OPCAO, $config );

			if ( Livia_Cache::ativo() ) {
				return 'ligou com o site parado — pagaria armazenamento à toa';
			}

			for ( $i = 0; $i < Livia_Cache::MINIMO_POR_HORA; $i++ ) {
				Livia_Cache::registrar_uso();
			}

			return Livia_Cache::ativo() ? null : 'não ligou nem com movimento';
		},
	),

	array(
		'grupo' => 'cache',
		'nome'  => '"nunca" continua valendo mesmo com o site cheio',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva();
			for ( $i = 0; $i < 50; $i++ ) {
				Livia_Cache::registrar_uso();
			}
			// livia_teste_config_com_reserva grava CACHE_MODO 'nunca'.
			return Livia_Cache::ativo() ? 'ignorou a escolha explícita de quem configurou' : null;
		},
	),

	// ------------------------------------------------------ cota por modelo

	array(
		'grupo' => 'cota',
		'nome'  => 'esgotar o principal troca de modelo em vez de desligar',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva( 'principal-x', 'reserva-y' );

			// Gasta a cota do dia inteira contra o modelo em uso.
			for ( $i = 0; $i < Livia_Limites::teto_diario(); $i++ ) {
				Livia_Limites::registrar_chamada();
			}

			if ( ! Livia_Limites::modelo_esgotado( 'principal-x' ) ) {
				return 'o principal deveria estar esgotado';
			}
			if ( Livia_Limites::disjuntor_aberto() ) {
				return 'desligou a LivIA com a cota da reserva inteira sobrando';
			}

			$vistos = array();
			livia_teste_modelos( array(), $vistos );
			list( , $token ) = livia_teste_sessao();
			Livia_Rest::mensagem( new Livia_Req_Teste( array( 'token' => $token, 'pergunta' => 'o que e dominio' ) ) );

			if ( ! $vistos ) {
				return 'não chamou modelo nenhum';
			}
			return 'reserva-y' === $vistos[0]
				? null
				: 'insistiu no modelo esgotado: ' . $vistos[0];
		},
	),

	array(
		'grupo' => 'cota',
		'nome'  => 'esgotar os dois abre o disjuntor',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config_com_reserva( 'principal-x', 'reserva-y' );

			$teto = Livia_Limites::teto_diario();
			for ( $i = 0; $i < $teto; $i++ ) {
				Livia_Limites::registrar_chamada();
			}
			Livia_Modelos::rebaixar( 'cota' );
			for ( $i = 0; $i < $teto; $i++ ) {
				Livia_Limites::registrar_chamada();
			}

			if ( 0 !== Livia_Limites::restantes_hoje() ) {
				return 'ainda diz que sobra cota: ' . Livia_Limites::restantes_hoje();
			}
			return Livia_Limites::disjuntor_aberto() ? null : 'não abriu o disjuntor com as duas cotas no fim';
		},
	),

	array(
		'grupo' => 'cota',
		'nome'  => 'sem reserva, a capacidade do dia é a de um modelo só',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_config();

			$teto = Livia_Limites::teto_diario();
			if ( Livia_Limites::restantes_hoje() !== $teto ) {
				return 'contou cota de um modelo que não existe: ' . Livia_Limites::restantes_hoje();
			}

			livia_teste_zerar();
			livia_teste_config_com_reserva();
			return Livia_Limites::restantes_hoje() === 2 * $teto
				? null
				: 'com reserva deveria haver duas cotas, há ' . Livia_Limites::restantes_hoje();
		},
	),

	array(
		'grupo' => 'cota',
		'nome'  => 'o teto do dia sai da configuração, com limite de sanidade',
		'executar' => function () {
			livia_teste_zerar();

			foreach ( array( '50' => 50, '0' => 200, '-10' => 200, '999999' => 50000, 'abc' => 200 ) as $digitado => $esperado ) {
				update_option( Livia_Config::OPCAO, Livia_Config::sanitizar( array(
					'GEMINI_API_KEY' => 'teste',
					'GEMINI_MODEL'   => 'gemini-3.5-flash-lite',
					'TETO_DIARIO'    => (string) $digitado,
				) ) );

				if ( Livia_Limites::teto_diario() !== $esperado ) {
					return sprintf( 'teto "%s" virou %d, esperava %d', $digitado, Livia_Limites::teto_diario(), $esperado );
				}
			}
			return null;
		},
	),

);
