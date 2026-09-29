<?php
/**
 * O registro dos atendimentos — e o ciclo de melhoria da base.
 *
 * O log em arquivo do protótipo foi ótimo para calibrar no terminal e não serve
 * para produção: escritas concorrentes se embaralham, não há rotação, e ele
 * guarda dado pessoal de cliente por tempo indeterminado.
 *
 * Aqui é tabela, com prazo de validade e com o que dá para medir: taxa de
 * recusa, taxa de bloqueio, consumo de token e latência. As linhas com
 * `bloqueio` preenchido são as mais valiosas do sistema inteiro — é nelas que
 * se descobre o que na base induziu a LivIA a inventar.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Registro {

	const VERSAO_TABELA = 3;
	const OPCAO_VERSAO  = 'livia_db_versao';
	/**
	 * O tique diário de manutenção. Tem mais de um assinante — o expurgo e o
	 * resumo de alertas — por isso o nome não fala de nenhum dos dois.
	 */
	const CRON          = 'livia_diario';

	/** Quanto tempo a conversa de um cliente fica guardada. */
	const RETENCAO_DIAS = 90;

	public static function iniciar() {
		add_action( 'livia_atendimento', array( __CLASS__, 'registrar' ) );
		add_action( self::CRON, array( __CLASS__, 'expurgar' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'manutencao' ) );
	}

	/**
	 * Migra o banco quando o plugin é atualizado sem passar pela ativação.
	 *
	 * register_activation_hook só dispara quando alguém clica em "Ativar". Subir
	 * uma versão nova por cima dos arquivos — que é como uma atualização acontece
	 * na prática — não dispara nada. Sem isto, a tabela ficaria na versão antiga
	 * enquanto o código já grava colunas novas, e cada insert falharia calado: o
	 * cliente veria a resposta normalmente e o registro simplesmente não
	 * existiria. A comparação lê uma opção autocarregada, então não custa query.
	 */
	/**
	 * O que precisa estar certo em toda carga, independente de ativação.
	 *
	 * Duas verificações baratas — uma opção autocarregada cada — que evitam os
	 * dois modos de falha silenciosa: tabela velha para código novo, e o tique
	 * diário que sumiu do agendamento (uma restauração de banco, um plugin de
	 * limpeza de cron, uma desativação seguida de reativação malsucedida).
	 */
	public static function manutencao() {
		self::conferir_tabela();
		// Idempotente: só agenda se não houver nada agendado.
		self::agendar_expurgo();
	}

	public static function conferir_tabela() {
		if ( (int) get_option( self::OPCAO_VERSAO ) < self::VERSAO_TABELA ) {
			self::instalar();
		}
	}

	public static function tabela() {
		global $wpdb;
		return $wpdb->prefix . 'livia_mensagens';
	}

	private static function retencao_dias() {
		return max( 1, (int) apply_filters( 'livia_retencao_dias', self::RETENCAO_DIAS ) );
	}

	// ------------------------------------------------------------------ tabela

	public static function instalar() {
		global $wpdb;

		if ( (int) get_option( self::OPCAO_VERSAO ) >= self::VERSAO_TABELA ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabela   = self::tabela();
		$collate  = $wpdb->get_charset_collate();

		// Formatação exigida pelo dbDelta: dois espaços depois de PRIMARY KEY,
		// uma definição por linha, tipos em minúsculas.
		$sql = "CREATE TABLE {$tabela} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sessao char(32) NOT NULL,
			criado_em datetime NOT NULL,
			pergunta text NOT NULL,
			resposta longtext NOT NULL,
			bloqueio varchar(255) DEFAULT NULL,
			erro varchar(60) DEFAULT NULL,
			tokens_entrada int(10) unsigned NOT NULL DEFAULT 0,
			tokens_saida int(10) unsigned NOT NULL DEFAULT 0,
			latencia_ms int(10) unsigned NOT NULL DEFAULT 0,
			modelo varchar(80) NOT NULL DEFAULT '',
			truncada tinyint(1) NOT NULL DEFAULT 0,
			streaming tinyint(1) NOT NULL DEFAULT 0,
			origem varchar(12) NOT NULL DEFAULT 'modelo',
			pagina_id bigint(20) unsigned NOT NULL DEFAULT 0,
			pagina_url varchar(255) NOT NULL DEFAULT '',
			pagina_titulo varchar(255) NOT NULL DEFAULT '',
			formulario varchar(40) NOT NULL DEFAULT '',
			util tinyint(1) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY criado_em (criado_em),
			KEY sessao (sessao),
			KEY bloqueio (bloqueio(32)),
			KEY pagina_id (pagina_id),
			KEY origem (origem),
			KEY util (util)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::OPCAO_VERSAO, self::VERSAO_TABELA, true );
	}

	public static function agendar_expurgo() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function desagendar_expurgo() {
		$quando = wp_next_scheduled( self::CRON );
		if ( $quando ) {
			wp_unschedule_event( $quando, self::CRON );
		}
	}

	// -------------------------------------------------------------------- LGPD

	/**
	 * Tira dado pessoal do texto do cliente.
	 *
	 * Só da PERGUNTA. A resposta da LivIA fica intacta de propósito: se ela
	 * contém um telefone, ou ele veio da base ou foi inventado — e nos dois
	 * casos é exatamente o que alguém precisa ver para corrigir a base. Redigir
	 * a resposta apagaria a evidência junto com o dado.
	 *
	 * A troca mantém a pergunta legível: "meu telefone é [telefone], onde
	 * coloco?" continua ensinando o que o cliente queria saber.
	 */
	public static function redigir( $texto ) {
		if ( ! apply_filters( 'livia_redigir_pii', true ) ) {
			return $texto;
		}

		$texto = (string) $texto;

		// Do mais específico para o mais genérico: CNPJ antes de CPF, CPF antes
		// de telefone, senão o padrão largo come o estreito.
		$texto = preg_replace( '/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/', '[cnpj]', $texto );
		$texto = preg_replace( '/\b\d{3}\.\d{3}\.\d{3}-\d{2}\b/', '[cpf]', $texto );
		$texto = preg_replace( '/[\w.+\-]+@[\w\-]+\.[\w.\-]+/u', '[email]', $texto );
		$texto = preg_replace( '/\b\d{5}-\d{3}\b/', '[cep]', $texto );

		// Quase a mesma expressão de Livia_Trava::RE_TELEFONE, com uma diferença
		// que só importa aqui: o grupo do código de país exige o separador junto
		// (\d{1,3} seguido de espaço), em vez de deixá-lo solto com \d{0,3}.
		// Com a forma da trava, " (47) 99123-4567" leva o espaço anterior embora
		// e o texto sai com as palavras grudadas. Lá isso é cosmético — a
		// mensagem de bloqueio é aparada; aqui estraga a frase que alguém vai
		// ler depois para entender o que o cliente queria.
		$texto = preg_replace( '/(?<!\d)(?:\+?\d{1,3}[\s.\-])?\(?\d{2}\)?[\s.\-]?\d{4,5}[\s.\-]?\d{4}(?!\d)/', '[telefone]', $texto );

		return $texto;
	}

	// ---------------------------------------------------------------- gravação

	/**
	 * O id da última linha gravada nesta requisição, ou 0.
	 *
	 * Existe para a rota de opinião: o widget precisa dizer DE QUAL resposta
	 * está falando, e o id da linha é o único identificador que não depende de
	 * contar turnos no navegador — contagem que desanda no primeiro recarregar
	 * de página.
	 *
	 * @var int
	 */
	private static $ultimo_id = 0;

	public static function ultimo_id() {
		return self::$ultimo_id;
	}

	public static function registrar( $dados ) {
		global $wpdb;

		self::$ultimo_id = 0;

		if ( ! is_array( $dados ) || empty( $dados['sessao'] ) ) {
			return;
		}

		$ctx = isset( $dados['contexto'] ) && is_array( $dados['contexto'] ) ? $dados['contexto'] : array();

		$gravou = $wpdb->insert(
			self::tabela(),
			array(
				'sessao'         => (string) $dados['sessao'],
				'criado_em'      => gmdate( 'Y-m-d H:i:s' ),
				'pergunta'       => self::redigir( isset( $dados['pergunta'] ) ? $dados['pergunta'] : '' ),
				'resposta'       => isset( $dados['resposta'] ) ? (string) $dados['resposta'] : '',
				'bloqueio'       => ! empty( $dados['bloqueio'] ) ? mb_substr( (string) $dados['bloqueio'], 0, 255 ) : null,
				'erro'           => ! empty( $dados['erro'] ) ? mb_substr( (string) $dados['erro'], 0, 60 ) : null,
				'tokens_entrada' => isset( $dados['uso']['entrada'] ) ? (int) $dados['uso']['entrada'] : 0,
				'tokens_saida'   => isset( $dados['uso']['saida'] ) ? (int) $dados['uso']['saida'] : 0,
				'latencia_ms'    => isset( $dados['latencia_ms'] ) ? (int) $dados['latencia_ms'] : 0,
				'modelo'         => isset( $dados['modelo'] ) ? (string) $dados['modelo'] : '',
				'truncada'       => ! empty( $dados['truncada'] ) ? 1 : 0,
				'streaming'      => ! empty( $dados['streaming'] ) ? 1 : 0,
				// Três origens, e a distinção importa para as métricas:
				// 'guarda'  — nem chegou ao modelo (pergunta longa, ritmo, disjuntor)
				// 'atalho'  — respondida pelo próprio PHP, sem rede e sem cota
				// 'modelo'  — foi à API
				// Sem isso, a taxa de erro da API viraria ficção e o custo por
				// mensagem pareceria menor do que é.
				'origem'         => self::origem( isset( $dados['origem'] ) ? $dados['origem'] : '' ),
				'pagina_id'      => isset( $ctx['pagina_id'] ) ? (int) $ctx['pagina_id'] : 0,
				'pagina_url'     => isset( $ctx['pagina_url'] ) ? mb_substr( (string) $ctx['pagina_url'], 0, 255 ) : '',
				'pagina_titulo'  => isset( $ctx['pagina_titulo'] ) ? mb_substr( (string) $ctx['pagina_titulo'], 0, 255 ) : '',
				'formulario'     => isset( $ctx['formulario'] ) ? mb_substr( (string) $ctx['formulario'], 0, 40 ) : '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		// Só quando a linha existe mesmo. Com a tabela ausente, o insert falha
		// calado e o insert_id continua com o valor de antes — o widget
		// receberia um número de linha que não é a resposta dele, e o polegar
		// marcaria outra conversa.
		self::$ultimo_id = $gravou ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Registra que o cliente achou (ou não achou) a resposta útil.
	 *
	 * A sessão entra no WHERE, não só o id: sem ela, quem descobrisse a rota
	 * marcaria opinião nas conversas dos outros, e a métrica que existe para
	 * guiar a melhoria da base viraria ruído plantado.
	 *
	 * @param int      $id     linha do registro.
	 * @param string   $sessao dona da linha.
	 * @param bool|null $util  true, false, ou null para desfazer.
	 * @return bool
	 */
	public static function opinar( $id, $sessao, $util ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id < 1 || ! Livia_Sessao::id_valido( $sessao ) ) {
			return false;
		}

		$linhas = $wpdb->update( // phpcs:ignore WordPress.DB
			self::tabela(),
			array( 'util' => null === $util ? null : ( $util ? 1 : 0 ) ),
			array( 'id' => $id, 'sessao' => (string) $sessao ),
			array( null === $util ? '%s' : '%d' ),
			array( '%d', '%s' )
		);

		return false !== $linhas && $linhas > 0;
	}

	private static function origem( $bruto ) {
		$bruto = (string) $bruto;
		return in_array( $bruto, array( 'guarda', 'atalho' ), true ) ? $bruto : 'modelo';
	}

	// ------------------------------------------------------------------ limpeza

	/**
	 * Apaga registros de propósito — não é o expurgo, é o botão.
	 *
	 * Existe porque a primeira coisa que se faz com a LivIA no ar é conversar
	 * com ela para ver se presta, e essas conversas de teste ficam misturadas
	 * com as de cliente para sempre, estragando toda média que o painel mostrar.
	 *
	 * @param string $escopo 'tudo' | 'hoje' | 'ate' (tudo que é anterior a hoje)
	 * @return int linhas apagadas
	 */
	public static function apagar( $escopo = 'tudo' ) {
		global $wpdb;
		$tabela = self::tabela();

		if ( 'hoje' === $escopo ) {
			$apagadas = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tabela} WHERE criado_em >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
					gmdate( 'Y-m-d 00:00:00' )
				)
			);
		} elseif ( 'ate' === $escopo ) {
			$apagadas = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$tabela} WHERE criado_em < %s", // phpcs:ignore WordPress.DB.PreparedSQL
					gmdate( 'Y-m-d 00:00:00' )
				)
			);
		} elseif ( 'tudo' === $escopo ) {
			// TRUNCATE seria mais rápido, mas DELETE respeita o mesmo caminho
			// de permissão do resto e não depende de privilégio extra no banco.
			$apagadas = $wpdb->query( "DELETE FROM {$tabela}" ); // phpcs:ignore WordPress.DB
		} else {
			// Escopo que não reconhecemos apaga NADA. Um `else` que apagasse a
			// tabela inteira transformaria qualquer erro de digitação, hoje ou
			// daqui a um ano, em perda total do registro.
			return 0;
		}

		return max( 0, (int) $apagadas );
	}

	// ---------------------------------------------------------------- conversas

	/**
	 * As conversas, e não as mensagens soltas.
	 *
	 * Uma linha por sessão. Ler atendimento em lista plana de mensagens não
	 * funciona: "não sei" e "ta vamos começar" só querem dizer alguma coisa
	 * junto do que veio antes.
	 *
	 * @return array
	 */
	public static function conversas( $dias = 30, $limite = 40, $apenas_problema = false, $busca = '' ) {
		global $wpdb;
		$tabela = self::tabela();
		$desde  = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $dias ) * DAY_IN_SECONDS ) );

		$filtro = $apenas_problema ? 'HAVING problemas > 0' : '';

		// A busca é por conversa inteira, não por turno: quem procura "logotipo"
		// quer LER a conversa em que o assunto apareceu, não a linha solta. Por
		// isso o LIKE entra num IN de sessões, e não no WHERE de fora.
		$onde  = 'criado_em >= %s';
		$vars  = array( $desde );
		$busca = trim( (string) $busca );

		if ( '' !== $busca ) {
			$curinga = '%' . $wpdb->esc_like( $busca ) . '%';
			$onde   .= " AND sessao IN (
				SELECT sessao FROM {$tabela}
				WHERE criado_em >= %s AND ( pergunta LIKE %s OR resposta LIKE %s )
			)";
			$vars[] = $desde;
			$vars[] = $curinga;
			$vars[] = $curinga;
		}

		$vars[] = (int) $limite;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					sessao,
					MIN(criado_em) AS comecou,
					MAX(criado_em) AS terminou,
					COUNT(*) AS turnos,
					MAX(pagina_titulo) AS pagina,
					MAX(formulario) AS formulario,
					SUM(bloqueio IS NOT NULL) AS bloqueadas,
					SUM(erro IS NOT NULL) AS erros,
					SUM(origem = 'atalho') AS atalhos,
					SUM(tokens_entrada + tokens_saida) AS tokens,
					MAX(latencia_ms) AS pior_ms,
					SUM(util = 0) AS negativas,
					SUM(util = 1) AS positivas,
					-- Um polegar para baixo é problema tanto quanto um erro: a
					-- resposta chegou inteira e mesmo assim não serviu. É o
					-- único sinal que diz isso, e é o mais barato de todos.
					SUM(bloqueio IS NOT NULL) + SUM(erro IS NOT NULL) + SUM(util = 0) AS problemas
				FROM {$tabela}
				WHERE {$onde}
				GROUP BY sessao
				{$filtro}
				ORDER BY comecou DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$vars
			),
			ARRAY_A
		);
	}

	/**
	 * O que não funcionou: as perguntas que ela errou ou não pôde responder.
	 *
	 * Duas fontes, e as duas são fatos, não interpretação:
	 *
	 *   - o cliente marcou o polegar para baixo;
	 *   - a trava cortou a resposta.
	 *
	 * Encaminhamento de propósito NÃO entra. Preço, prazo de contrato e problema
	 * técnico têm que ser encaminhados: contá-los como falha encheria a lista do
	 * comportamento correto e escondia o que de fato precisa de conserto.
	 *
	 * Agrupa por pergunta normalizada — a mesma dúvida escrita de dez jeitos é
	 * uma lacuna só na base, e vendo as dez separadas ninguém percebe isso.
	 */
	public static function nao_funcionou( $dias = 30, $limite = 15 ) {
		global $wpdb;
		$tabela = self::tabela();
		$desde  = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $dias ) * DAY_IN_SECONDS ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					LOWER(TRIM(pergunta)) AS chave,
					MIN(pergunta) AS pergunta,
					COUNT(*) AS vezes,
					SUM(util = 0) AS negativas,
					SUM(bloqueio IS NOT NULL) AS bloqueadas,
					MAX(sessao) AS sessao,
					MAX(criado_em) AS ultima
				FROM {$tabela}
				WHERE criado_em >= %s
				  AND ( util = 0 OR bloqueio IS NOT NULL )
				GROUP BY chave
				ORDER BY vezes DESC, ultima DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$desde,
				(int) $limite
			),
			ARRAY_A
		);
	}

	/**
	 * Movimento e consumo por dia.
	 *
	 * Vinte e quatro horas dizem como está agora; não dizem se está piorando. A
	 * série é o que separa um pico de um dia ruim de uma curva que sobe desde
	 * segunda — e é olhando a curva que se decide trocar de modelo ou apertar a
	 * seleção antes de bater no teto.
	 */
	public static function por_dia( $dias = 14 ) {
		global $wpdb;
		$tabela = self::tabela();
		$dias   = max( 2, min( 90, (int) $dias ) );
		$desde  = gmdate( 'Y-m-d 00:00:00', time() - ( $dias * DAY_IN_SECONDS ) );

		$linhas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(criado_em) AS dia,
					COUNT(*) AS mensagens,
					COUNT(DISTINCT sessao) AS conversas,
					SUM(origem = 'modelo') AS ao_modelo,
					SUM(tokens_entrada + tokens_saida) AS tokens,
					SUM(bloqueio IS NOT NULL) + SUM(erro IS NOT NULL) AS problemas
				FROM {$tabela}
				WHERE criado_em >= %s
				GROUP BY dia
				ORDER BY dia ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				$desde
			),
			ARRAY_A
		);

		// Dia sem conversa nenhuma não vem do banco, e um gráfico que pula os
		// dias vazios mente sobre o ritmo: três dias parados viram uma barra
		// colada na outra. Aqui os buracos são preenchidos com zero.
		$mapa = array();
		foreach ( (array) $linhas as $l ) {
			$mapa[ $l['dia'] ] = $l;
		}

		$serie = array();
		for ( $i = $dias - 1; $i >= 0; $i-- ) {
			$dia     = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$serie[] = isset( $mapa[ $dia ] )
				? $mapa[ $dia ]
				: array( 'dia' => $dia, 'mensagens' => 0, 'conversas' => 0, 'ao_modelo' => 0, 'tokens' => 0, 'problemas' => 0 );
		}

		return $serie;
	}

	/** Os turnos de uma conversa, em ordem. */
	public static function turnos( $sessao ) {
		global $wpdb;
		$tabela = self::tabela();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT criado_em, pergunta, resposta, bloqueio, erro, origem, latencia_ms,
						tokens_entrada, tokens_saida, modelo, util
				 FROM {$tabela} WHERE sessao = %s ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL
				(string) $sessao
			),
			ARRAY_A
		);
	}

	/** Apaga uma conversa inteira. */
	public static function apagar_conversa( $sessao ) {
		global $wpdb;
		$tabela = self::tabela();
		return (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$tabela} WHERE sessao = %s", (string) $sessao ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/** Apaga o que passou da retenção. Roda sozinho, uma vez por dia. */
	public static function expurgar() {
		global $wpdb;
		$tabela = self::tabela();
		$limite = gmdate( 'Y-m-d H:i:s', time() - ( self::retencao_dias() * DAY_IN_SECONDS ) );

		return $wpdb->query(
			$wpdb->prepare( "DELETE FROM {$tabela} WHERE criado_em < %s", $limite ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	// ----------------------------------------------------------------- métricas

	/** p95 sem depender de função de percentil do banco (MySQL 5.7 não tem). */
	public static function percentil( array $valores, $p = 0.95 ) {
		if ( ! $valores ) {
			return 0;
		}
		sort( $valores, SORT_NUMERIC );
		$indice = (int) ceil( $p * count( $valores ) ) - 1;
		return (int) $valores[ max( 0, min( $indice, count( $valores ) - 1 ) ) ];
	}

	/**
	 * O retrato de um dia.
	 *
	 * $dia é 0 para hoje, 1 para ontem, e assim por diante — em UTC, que é como
	 * criado_em é gravado.
	 */
	public static function resumo( $dia = 0 ) {
		global $wpdb;
		$tabela = self::tabela();

		$inicio = gmdate( 'Y-m-d 00:00:00', time() - ( (int) $dia * DAY_IN_SECONDS ) );
		$fim    = gmdate( 'Y-m-d 23:59:59', time() - ( (int) $dia * DAY_IN_SECONDS ) );

		$linha = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS mensagens,
					COUNT(DISTINCT sessao) AS atendimentos,
					SUM(bloqueio IS NOT NULL) AS bloqueadas,
					SUM(erro IS NOT NULL) AS erros,
					SUM(truncada) AS truncadas,
					SUM(tokens_entrada) AS tokens_entrada,
					SUM(tokens_saida) AS tokens_saida
				FROM {$tabela} WHERE criado_em BETWEEN %s AND %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$inicio,
				$fim
			),
			ARRAY_A
		);

		$latencias = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT latencia_ms FROM {$tabela} WHERE criado_em BETWEEN %s AND %s AND erro IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL
				$inicio,
				$fim
			)
		);

		$mensagens = isset( $linha['mensagens'] ) ? (int) $linha['mensagens'] : 0;

		return array(
			'data'            => gmdate( 'd/m/Y', strtotime( $inicio ) ),
			'mensagens'       => $mensagens,
			'atendimentos'    => isset( $linha['atendimentos'] ) ? (int) $linha['atendimentos'] : 0,
			'bloqueadas'      => isset( $linha['bloqueadas'] ) ? (int) $linha['bloqueadas'] : 0,
			'erros'           => isset( $linha['erros'] ) ? (int) $linha['erros'] : 0,
			'truncadas'       => isset( $linha['truncadas'] ) ? (int) $linha['truncadas'] : 0,
			'tokens'          => ( isset( $linha['tokens_entrada'] ) ? (int) $linha['tokens_entrada'] : 0 )
								+ ( isset( $linha['tokens_saida'] ) ? (int) $linha['tokens_saida'] : 0 ),
			'p95_ms'          => self::percentil( array_map( 'intval', (array) $latencias ) ),
			'por_atendimento' => $mensagens && $linha['atendimentos']
				? round( $mensagens / (int) $linha['atendimentos'], 1 )
				: 0,
		);
	}

	/**
	 * Recorte das últimas 24 horas, para a rota de saúde.
	 *
	 * Janela deslizante, não "hoje": um monitor que pergunta às 00h05 não pode
	 * receber um retrato vazio e concluir que está tudo bem.
	 */
	public static function saude_24h() {
		global $wpdb;
		$tabela = self::tabela();
		$desde  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$linha = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS mensagens,
					COUNT(DISTINCT sessao) AS atendimentos,
					SUM(bloqueio IS NOT NULL) AS bloqueadas,
					SUM(erro IS NOT NULL) AS erros,
					SUM(tokens_entrada + tokens_saida) AS tokens,
					SUM(origem = 'atalho') AS atalhos,
					SUM(util = 1) AS uteis,
					SUM(util = 0) AS inuteis,
					MAX(criado_em) AS ultima
				FROM {$tabela} WHERE criado_em >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$desde
			),
			ARRAY_A
		);

		// A latência só conta de quem foi ao modelo. Misturar os atalhos, que
		// respondem em milissegundos, faria a média parecer ótima justamente
		// quando a parte lenta está piorando.
		$tempos = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT latencia_ms FROM {$tabela}
				  WHERE criado_em >= %s AND origem = 'modelo' AND latencia_ms > 0", // phpcs:ignore WordPress.DB.PreparedSQL
				$desde
			)
		);

		return array(
			'mensagens'    => isset( $linha['mensagens'] ) ? (int) $linha['mensagens'] : 0,
			'atendimentos' => isset( $linha['atendimentos'] ) ? (int) $linha['atendimentos'] : 0,
			'bloqueadas'   => isset( $linha['bloqueadas'] ) ? (int) $linha['bloqueadas'] : 0,
			'erros'        => isset( $linha['erros'] ) ? (int) $linha['erros'] : 0,
			'tokens'       => isset( $linha['tokens'] ) ? (int) $linha['tokens'] : 0,
			'atalhos'      => isset( $linha['atalhos'] ) ? (int) $linha['atalhos'] : 0,
			'p95_ms'       => self::percentil( array_map( 'intval', (array) $tempos ) ),
			'ultima'       => ! empty( $linha['ultima'] ) ? gmdate( 'c', strtotime( $linha['ultima'] . ' UTC' ) ) : null,
		);
	}

	/**
	 * Qual página está gerando mais pergunta.
	 *
	 * Agrupa por página, não por formulário: duas páginas podem usar o mesmo
	 * briefing, e o que interessa saber é onde o cliente trava.
	 */
	public static function por_pagina( $dias = 30, $limite = 20 ) {
		global $wpdb;
		$tabela = self::tabela();
		$desde  = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $dias ) * DAY_IN_SECONDS ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					pagina_id,
					MAX(pagina_titulo) AS titulo,
					MAX(pagina_url) AS url,
					MAX(formulario) AS formulario,
					COUNT(*) AS mensagens,
					COUNT(DISTINCT sessao) AS conversas,
					SUM(bloqueio IS NOT NULL) AS bloqueadas,
					SUM(origem = 'guarda') AS barradas,
					SUM(tokens_entrada + tokens_saida) AS tokens
				FROM {$tabela}
				WHERE criado_em >= %s
				GROUP BY pagina_id
				ORDER BY mensagens DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$desde,
				(int) $limite
			),
			ARRAY_A
		);
	}

	/**
	 * As perguntas que mais se repetem.
	 *
	 * Agrupa pela pergunta normalizada — minúscula e sem pontuação — senão
	 * "o que é domínio?" e "o que e dominio" contariam como coisas diferentes.
	 * É a lista que diz o que falta na base.
	 */
	public static function perguntas_frequentes( $dias = 30, $limite = 25 ) {
		global $wpdb;
		$tabela = self::tabela();
		$desde  = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $dias ) * DAY_IN_SECONDS ) );

		$linhas = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pergunta, bloqueio, origem FROM {$tabela} WHERE criado_em >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$desde
			),
			ARRAY_A
		);

		$grupos = array();
		foreach ( (array) $linhas as $l ) {
			$chave = preg_replace( '/[^a-z0-9 ]/', '', strtolower( remove_accents( $l['pergunta'] ) ) );
			$chave = trim( preg_replace( '/\s+/', ' ', $chave ) );
			if ( '' === $chave ) {
				continue;
			}
			if ( ! isset( $grupos[ $chave ] ) ) {
				$grupos[ $chave ] = array( 'pergunta' => $l['pergunta'], 'vezes' => 0, 'problemas' => 0 );
			}
			++$grupos[ $chave ]['vezes'];
			if ( ! empty( $l['bloqueio'] ) || 'guarda' === $l['origem'] ) {
				++$grupos[ $chave ]['problemas'];
			}
		}

		uasort(
			$grupos,
			function ( $a, $b ) {
				return $b['vezes'] - $a['vezes'];
			}
		);

		return array_slice( array_values( $grupos ), 0, (int) $limite );
	}

	/** As últimas mensagens, para quem quiser simplesmente ler. */
	public static function recentes( $limite = 50, $apenas_problema = false ) {
		global $wpdb;
		$tabela = self::tabela();
		$onde   = $apenas_problema ? "WHERE bloqueio IS NOT NULL OR erro IS NOT NULL" : '';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT criado_em, sessao, pergunta, resposta, bloqueio, erro, origem,
						pagina_titulo, formulario, latencia_ms
				 FROM {$tabela} {$onde}
				 ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $limite
			),
			ARRAY_A
		);
	}

	/** Tudo o que está guardado, em CSV — o formato que abre em qualquer lugar. */
	/**
	 * Neutraliza célula que o Excel leria como fórmula.
	 *
	 * O texto da pergunta vem de qualquer pessoa na internet. Um cliente que
	 * escrevesse =HYPERLINK(...) ou =cmd|'/c ...'!A1 estaria plantando código
	 * que dispara quando alguém DA EQUIPE abre o relatório — do anônimo direto
	 * para a máquina de quem atende, com o aviso do Excel como única barreira.
	 *
	 * O apóstrofo à frente é o que o Excel, o LibreOffice e o Sheets tratam
	 * como "isto é texto". Aparece na célula, e é para aparecer: o relatório
	 * ficar um caractere mais feio é preço barato.
	 */
	public static function celula( $valor ) {
		$valor = (string) $valor;
		if ( '' === $valor ) {
			return $valor;
		}
		// A tabulação e o retorno de carro entram porque o Excel os ignora ao
		// decidir se a célula começa com fórmula.
		return in_array( $valor[0], array( '=', '+', '-', '@', chr( 9 ), chr( 13 ) ), true ) ? "'" . $valor : $valor;
	}

	public static function exportar_csv() {
		global $wpdb;
		$tabela = self::tabela();

		$saida = fopen( 'php://output', 'w' );
		fputcsv(
			$saida,
			array(
				'quando', 'sessao', 'origem', 'pagina', 'url', 'formulario',
				'pergunta', 'resposta', 'bloqueio', 'erro',
				'tokens_entrada', 'tokens_saida', 'latencia_ms', 'modelo',
			)
		);

		// Em blocos: a tabela pode ter dezenas de milhares de linhas e carregar
		// tudo em memória derrubaria o PHP antes de gerar o arquivo.
		$passo  = 500;
		$ultimo = 0;
		do {
			$linhas = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$tabela} WHERE id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
					$ultimo,
					$passo
				),
				ARRAY_A
			);
			foreach ( (array) $linhas as $l ) {
				$ultimo = (int) $l['id'];
				fputcsv(
					$saida,
					array(
						$l['criado_em'],
						$l['sessao'],
						$l['origem'],
						self::celula( $l['pagina_titulo'] ),
						self::celula( $l['pagina_url'] ),
						self::celula( $l['formulario'] ),
						self::celula( $l['pergunta'] ),
						self::celula( $l['resposta'] ),
						self::celula( $l['bloqueio'] ),
						self::celula( $l['erro'] ),
						(int) $l['tokens_entrada'],
						(int) $l['tokens_saida'],
						(int) $l['latencia_ms'],
						self::celula( $l['modelo'] ),
					)
				);
			}
		} while ( count( (array) $linhas ) === $passo );

		fclose( $saida );
	}

	/**
	 * As respostas que a trava barrou — as linhas mais importantes de ler.
	 *
	 * Cada uma é uma pergunta que a LivIA não soube responder sem inventar. Ou
	 * falta a informação na base, ou a base induziu o erro.
	 */
	public static function barradas( $limite = 20 ) {
		global $wpdb;
		$tabela = self::tabela();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT criado_em, pergunta, resposta, bloqueio
				 FROM {$tabela} WHERE bloqueio IS NOT NULL
				 ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $limite
			),
			ARRAY_A
		);
	}
}
