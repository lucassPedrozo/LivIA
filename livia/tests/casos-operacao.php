<?php
/**
 * Casos dos cinco itens de operação: streaming resistente a buffer, fallback,
 * saúde, alerta e cache de contexto.
 *
 * O que dá para testar offline é a decisão — quando cair no fallback, quando
 * alertar, o que entra no corpo da requisição. O que não dá é o comportamento
 * do servidor de vocês; para isso existe o `curl -N` do runbook.
 */

defined( 'ABSPATH' ) || exit;

return array(

	// ------------------------------------------------------------ streaming

	array(
		'grupo' => 'streaming',
		'nome'  => 'linha de comentário SSE (o enchimento) é ignorada pelo leitor',
		'executar' => function () {
			// abrir_sse() manda 2 KB de comentário para estourar o buffer de
			// proxy. Se o leitor tratasse isso como evento, a conversa começaria
			// com um pedaço vazio.
			$leitor  = new Livia_Sse();
			$eventos = $leitor->receber(
				': ' . str_repeat( ' ', 2048 ) . "\n\n"
				. "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"Oi\"}]}}]}\n\n"
			);
			if ( 1 !== count( $eventos ) ) {
				return 'devolveu ' . count( $eventos ) . ' eventos, esperava 1';
			}
			return 'Oi' === Livia_Sse::texto_do_evento( $eventos[0] ) ? null : 'texto errado';
		},
	),

	// ------------------------------------------------------------ cache

	array(
		'grupo' => 'cache',
		'nome'  => 'desligado por padrão — a instrução vai inline',
		'executar' => function () {
			livia_teste_zerar();
			if ( Livia_Cache::ativo() ) {
				return 'o cache veio ligado de fábrica';
			}
			$corpo = Livia_Gemini::corpo( 'BASE', array() );
			if ( ! isset( $corpo['system_instruction'] ) ) {
				return 'a instrução não foi junto';
			}
			return isset( $corpo['cachedContent'] ) ? 'mandou cachedContent sem cache' : null;
		},
	),

	array(
		'grupo' => 'cache',
		'nome'  => 'com cache, a instrução NÃO vai junto (os campos se excluem)',
		'executar' => function () {
			$corpo = Livia_Gemini::corpo( 'BASE', array(), array( 'cache' => 'cachedContents/abc' ) );
			if ( isset( $corpo['system_instruction'] ) ) {
				return 'mandou a instrução E o cache — a API recusaria';
			}
			return 'cachedContents/abc' === $corpo['cachedContent'] ? null : 'nome do cache errado';
		},
	),

	array(
		'grupo' => 'cache',
		'nome'  => 'falha ao cachear não impede a resposta — só guarda o motivo',
		'executar' => function () {
			livia_teste_zerar();
			add_filter( 'livia_cache_contexto', '__return_true' );
			// A API recusa: instrução pequena demais, por exemplo.
			add_filter( 'livia_pre_criar_cache', function () {
				return false;
			} );

			$nome = Livia_Cache::nome( 'instrução curta', 'gemini-3.5-flash-lite' );
			remove_all_filters( 'livia_cache_contexto' );
			remove_all_filters( 'livia_pre_criar_cache' );

			if ( null !== $nome ) {
				return 'devolveu um nome que não existe';
			}
			// E o corpo continua carregando a instrução.
			$corpo = Livia_Gemini::corpo( 'BASE', array() );
			return isset( $corpo['system_instruction'] ) ? null : 'ficou sem instrução nenhuma';
		},
	),

	array(
		'grupo' => 'cache',
		'nome'  => 'mudar a base esquece o que estava guardado na API',
		'executar' => function () {
			livia_teste_zerar();
			set_transient(
				Livia_Cache::GUARDADO,
				array( 'assinatura' => 'x', 'nome' => 'cachedContents/velho', 'expira' => time() + 3600 ),
				3600
			);
			Livia_Base::limpar_cache();
			return false === get_transient( Livia_Cache::GUARDADO )
				? null
				: 'o cache sobreviveu a uma troca de base — a API responderia com a instrução antiga';
		},
	),

	// ------------------------------------------------------------ saúde

	array(
		'grupo' => 'saude',
		'nome'  => 'sem chave, o estado é "fora"',
		'executar' => function () {
			livia_teste_zerar();
			update_option( Livia_Config::OPCAO, array( 'GEMINI_API_KEY' => '', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'c' ) );
			$e = Livia_Saude::estado();
			return Livia_Saude::FORA === $e ? null : 'estado: ' . $e;
		},
	),

	array(
		'grupo' => 'saude',
		'nome'  => 'disjuntor aberto degrada, mas não derruba',
		'executar' => function () {
			livia_teste_zerar();
			update_option( Livia_Config::OPCAO, array( 'GEMINI_API_KEY' => 'k', 'GEMINI_MODEL' => 'm', 'CANAL_DE_SUPORTE' => 'c' ) );
			for ( $i = 0; $i < Livia_Limites::teto_diario(); $i++ ) {
				Livia_Limites::registrar_chamada();
			}

			$r = Livia_Saude::resumo();
			if ( Livia_Saude::DEGRADADO !== $r['estado'] ) {
				return 'estado: ' . $r['estado'];
			}
			// Degradado responde 200 com ok=false; só "fora" vira 503.
			return false === $r['ok'] ? null : 'disse que está ok com o disjuntor aberto';
		},
	),

	array(
		'grupo' => 'saude',
		'nome'  => 'o token de monitoramento não é adivinhável nem aceita qualquer coisa',
		'executar' => function () {
			$t = Livia_Saude::token();
			if ( 32 !== strlen( $t ) ) {
				return 'token com ' . strlen( $t ) . ' caracteres';
			}
			foreach ( array( '', 'admin', '0', str_repeat( 'a', 32 ) ) as $falso ) {
				if ( Livia_Saude::token_valido( $falso ) ) {
					return 'aceitou ' . var_export( $falso, true );
				}
			}
			return Livia_Saude::token_valido( $t ) ? null : 'recusou o próprio token';
		},
	),

	// ------------------------------------------------------------ alerta

	array(
		'grupo' => 'alerta',
		'nome'  => 'o disjuntor avisa UMA vez, não a cada requisição barrada',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_zerar_emails();
			update_option( 'admin_email', 'equipe@joinvix.com.br' );
			Livia_Alerta::iniciar();

			// Enche a cota do dia batendo exatamente no teto, e passa dele.
			for ( $i = 0; $i < Livia_Limites::TETO_DIARIO + 5; $i++ ) {
				Livia_Limites::registrar_chamada();
			}

			$emails = livia_teste_emails();
			if ( 1 !== count( $emails ) ) {
				return 'mandou ' . count( $emails ) . ' e-mails, esperava 1';
			}
			return false !== strpos( $emails[0]['assunto'], 'disjuntor' ) ? null : 'assunto errado';
		},
	),

	array(
		'grupo' => 'alerta',
		'nome'  => 'falha de chave só alerta depois de se repetir',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_zerar_emails();
			update_option( 'admin_email', 'equipe@joinvix.com.br' );

			$falha = array( 'sessao' => 'x', 'erro' => 'chave_invalida' );

			Livia_Alerta::observar( $falha );
			if ( livia_teste_emails() ) {
				return 'alertou na primeira falha — uma falha avulsa não é incidente';
			}

			Livia_Alerta::observar( $falha );
			Livia_Alerta::observar( $falha );

			$emails = livia_teste_emails();
			if ( 1 !== count( $emails ) ) {
				return 'mandou ' . count( $emails ) . ' e-mails na terceira falha';
			}
			return false !== strpos( $emails[0]['assunto'], 'chave' ) ? null : 'assunto errado';
		},
	),

	array(
		'grupo' => 'alerta',
		'nome'  => 'uma resposta boa zera a contagem de falhas',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_zerar_emails();
			update_option( 'admin_email', 'equipe@joinvix.com.br' );

			Livia_Alerta::observar( array( 'sessao' => 'x', 'erro' => 'chave_invalida' ) );
			Livia_Alerta::observar( array( 'sessao' => 'x', 'erro' => 'chave_invalida' ) );
			// Funcionou: o que interessa é falha SEGUIDA.
			Livia_Alerta::observar( array( 'sessao' => 'x', 'erro' => null ) );
			Livia_Alerta::observar( array( 'sessao' => 'x', 'erro' => 'chave_invalida' ) );

			return livia_teste_emails() ? 'alertou apesar de ter voltado a funcionar no meio' : null;
		},
	),

	array(
		'grupo' => 'alerta',
		'nome'  => 'sem destinatário válido, não estoura — só não manda',
		'executar' => function () {
			livia_teste_zerar();
			livia_teste_zerar_emails();
			update_option( 'admin_email', 'isso-nao-e-um-email' );

			Livia_Alerta::disjuntor_abriu( 200 );

			return livia_teste_emails() ? 'tentou mandar para um endereço inválido' : null;
		},
	),
);
