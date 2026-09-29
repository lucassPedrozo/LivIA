<?php
/**
 * Tela de configuração — e o primeiro lugar onde se olha quando algo está
 * estranho.
 *
 * A ordem da tela é a ordem das perguntas que alguém faz ao abri-la: ela está
 * no ar? com qual modelo? a cota aguenta o dia? Só depois vêm os campos.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Admin {

	const GRUPO  = 'livia_grupo';
	const PAGINA = 'livia';

	public static function iniciar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'registrar' ) );
		add_action( 'admin_post_livia_testar', array( __CLASS__, 'testar' ) );
		add_action( 'admin_post_livia_modelos', array( __CLASS__, 'recarregar_modelos' ) );
		add_action( 'admin_post_livia_restaurar', array( __CLASS__, 'restaurar_modelo' ) );
	}

	public static function menu() {
		$tela = add_options_page( 'LivIA', 'LivIA', 'manage_options', self::PAGINA, array( __CLASS__, 'render' ) );
		add_action( 'load-' . $tela, array( __CLASS__, 'estilo' ) );
	}

	/** Também usada pela tela de relatórios. */
	public static function estilo() {
		add_action(
			'admin_enqueue_scripts',
			function () {
				wp_enqueue_style( 'livia-admin', LIVIA_URL . 'admin/livia-admin.css', array(), LIVIA_VERSAO );
			}
		);
	}

	public static function registrar() {
		register_setting(
			self::GRUPO,
			Livia_Config::OPCAO,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Livia_Config', 'sanitizar' ),
				'default'           => Livia_Config::padroes(),
			)
		);
	}

	// ------------------------------------------------------------------ ações

	private static function voltar( $aviso = '' ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGINA );
		wp_safe_redirect( $aviso ? add_query_arg( 'livia_aviso', $aviso, $url ) : $url );
		exit;
	}

	private static function so_admin( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( $nonce );
	}

	/** Botão "testar": confirma chave e ID do modelo de uma vez. */
	public static function testar() {
		self::so_admin( 'livia_testar' );

		$resultado = Livia_Gemini::verificar_modelo();

		set_transient(
			Livia_Config::DIAG,
			array(
				'ok'       => ! is_wp_error( $resultado ),
				'mensagem' => is_wp_error( $resultado )
					? $resultado->get_error_message()
					: sprintf( 'Chave aceita e modelo "%s" disponível.', Livia_Config::modelo() ),
				'quando'   => time(),
			),
			HOUR_IN_SECONDS
		);

		self::voltar();
	}

	/** Pergunta à API quais modelos existem hoje para esta chave. */
	public static function recarregar_modelos() {
		self::so_admin( 'livia_modelos' );

		$lista = Livia_Modelos::disponiveis( true );

		set_transient(
			Livia_Config::DIAG,
			array(
				'ok'       => ! is_wp_error( $lista ),
				'mensagem' => is_wp_error( $lista )
					? $lista->get_error_message()
					: sprintf( 'A API listou %d modelos de texto para esta chave.', count( $lista ) ),
				'quando'   => time(),
			),
			HOUR_IN_SECONDS
		);

		self::voltar();
	}

	/** Tira a LivIA da reserva antes da hora. */
	public static function restaurar_modelo() {
		self::so_admin( 'livia_restaurar' );
		Livia_Modelos::restaurar();
		self::voltar( 'restaurado' );
	}

	// ---------------------------------------------------------------- pedaços

	/**
	 * O cabeçalho que as duas telas dividem: as abas e o que pede decisão.
	 *
	 * Antes eram duas páginas independentes no menu de Configurações, e para
	 * saber se a LivIA estava de pé enquanto se lia uma conversa era preciso ir
	 * e voltar. Agora é um lugar só com duas abas, e a faixa de atenção aparece
	 * nas duas — de onde quer que você entre, sabe se alguma coisa precisa de
	 * você.
	 *
	 * @param string $aba 'config' ou 'conversas'.
	 */
	public static function cabecalho( $aba ) {
		$config    = admin_url( 'options-general.php?page=' . self::PAGINA );
		$conversas = admin_url( 'options-general.php?page=' . Livia_Relatorios::PAGINA );
		?>
		<h1 class="livia-titulo">LivIA</h1>

		<nav class="nav-tab-wrapper wp-clearfix livia-abas">
			<a href="<?php echo esc_url( $config ); ?>"
				class="nav-tab <?php echo 'config' === $aba ? 'nav-tab-active' : ''; ?>">Configuração</a>
			<a href="<?php echo esc_url( $conversas ); ?>"
				class="nav-tab <?php echo 'conversas' === $aba ? 'nav-tab-active' : ''; ?>">Conversas</a>
		</nav>
		<?php
		self::atencao();
	}

	/**
	 * Só o que exige uma decisão de quem está lendo.
	 *
	 * A regra que mantém isto útil: nada aqui é informação de rotina. Um painel
	 * que avisa de tudo é um painel que ninguém lê — e aí o aviso que importava
	 * passa junto com os outros. Sem nada a dizer, uma linha discreta e pronto.
	 */
	private static function atencao() {
		$itens = array();

		if ( ! Livia_Config::esta_configurado() ) {
			$itens[] = array( 'ruim', 'Falta configurar', 'Sem chave, modelo ou base, o widget não aparece em página nenhuma.' );
		} elseif ( ! Livia_Config::esta_ativa() ) {
			$itens[] = array( 'atento', 'Desligada', 'O interruptor está desligado: ninguém vê a LivIA.' );
		}

		if ( Livia_Limites::disjuntor_aberto() ) {
			$itens[] = array( 'ruim', 'Cota do dia esgotada', 'Ela está encaminhando todo mundo para o atendimento até a virada do dia (UTC).' );
		} else {
			$cota = Livia_Limites::previsao();
			if ( ! empty( $cota['estoura_em'] ) ) {
				$itens[] = array(
					'atento',
					'A cota vai acabar hoje',
					'No ritmo desta manhã, o teto bate em ' . esc_html( self::duracao( $cota['estoura_em'] ) ) . '.',
				);
			}
		}

		$rebaixado = Livia_Modelos::rebaixado();
		if ( $rebaixado ) {
			$itens[] = array(
				'atento',
				'A reserva está atendendo',
				'O modelo principal falhou (' . esc_html( $rebaixado['motivo'] ) . ') e ela trocou sozinha.',
			);
		}

		$erro_cache = Livia_Cache::ultimo_erro();
		if ( $erro_cache ) {
			$itens[] = array( 'atento', 'O cache de contexto não pegou', esc_html( $erro_cache ) );
		}

		$problemas = self::problemas_de_hoje();
		if ( $problemas > 0 ) {
			$url = add_query_arg(
				array( 'page' => Livia_Relatorios::PAGINA, 'dias' => 1, 'problema' => 1 ),
				admin_url( 'options-general.php' )
			);
			$itens[] = array(
				'atento',
				$problemas . ( 1 === $problemas ? ' conversa com problema hoje' : ' conversas com problema hoje' ),
				'<a href="' . esc_url( $url ) . '">Ler as que deram errado</a>',
			);
		}

		if ( ! $itens ) {
			echo '<p class="livia-tranquilo">Nada pedindo atenção agora.</p>';
			return;
		}

		echo '<div class="livia-atencao">';
		foreach ( $itens as $i ) {
			printf(
				'<div class="livia-aviso e-%s"><strong>%s</strong><span>%s</span></div>',
				esc_attr( $i[0] ),
				esc_html( $i[1] ),
				wp_kses_post( $i[2] )
			);
		}
		echo '</div>';
	}

	/** Conversas de hoje em que alguma coisa deu errado. */
	private static function problemas_de_hoje() {
		$conversas = Livia_Registro::conversas( 1, 200, true );
		return is_array( $conversas ) ? count( $conversas ) : 0;
	}

	private static function cartao( $estado, $rotulo, $valor, $nota = '', $menor = false ) {
		printf(
			'<div class="livia-cartao e-%s"><span class="rotulo">%s</span>'
				. '<span class="valor%s">%s</span>%s</div>',
			esc_attr( $estado ),
			esc_html( $rotulo ),
			$menor ? ' menor' : '',
			esc_html( $valor ),
			$nota ? '<span class="nota">' . wp_kses_post( $nota ) . '</span>' : ''
		);
	}

	/** Quanto tempo, em português, sem a precisão que ninguém pediu. */
	public static function duracao( $segundos ) {
		$segundos = max( 0, (int) $segundos );

		if ( $segundos < 60 ) {
			return 'menos de um minuto';
		}
		if ( $segundos < 3600 ) {
			$m = (int) round( $segundos / 60 );
			return $m . ( 1 === $m ? ' minuto' : ' minutos' );
		}
		$h = $segundos / 3600;
		if ( $h < 24 ) {
			$h = round( $h, $h < 10 ? 1 : 0 );
			return str_replace( '.', ',', (string) $h ) . ( 1.0 === (float) $h ? ' hora' : ' horas' );
		}
		return 'mais de um dia';
	}

	/**
	 * O bloco de situação.
	 *
	 * Cada cartão responde a uma pergunta inteira sozinho — o número e o que
	 * ele significa. Um painel que obriga a interpretar o número é um painel
	 * que ninguém abre duas vezes.
	 */
	private static function situacao() {
		$estado  = Livia_Saude::estado();
		$modelos = Livia_Modelos::estado();
		$cota    = Livia_Limites::previsao();
		$cache   = Livia_Cache::estado();
		$dia     = Livia_Registro::saude_24h();

		$mapa = array(
			Livia_Saude::OK        => array( 'bom', 'No ar', 'Atendendo normalmente.' ),
			Livia_Saude::DEGRADADO => array( 'atento', 'Degradada', 'Responde, mas encaminhando mais do que deveria.' ),
			Livia_Saude::DESLIGADA => array( 'atento', 'Desligada', 'O widget não aparece em nenhuma página.' ),
			Livia_Saude::FORA      => array( 'ruim', 'Fora do ar', 'Falta chave, modelo ou base — veja abaixo.' ),
		);
		$r = isset( $mapa[ $estado ] ) ? $mapa[ $estado ] : $mapa[ Livia_Saude::FORA ];

		echo '<div class="livia-cartoes">';

		self::cartao( $r[0], 'Situação', $r[1], esc_html( $r[2] ) );

		// ---- modelo
		if ( $modelos['na_reserva'] ) {
			self::cartao(
				'atento',
				'Modelo em uso',
				$modelos['em_uso'],
				sprintf(
					'A reserva assumiu (%s). Tenta o principal em %s.',
					'cota' === $modelos['motivo'] ? 'cota esgotada' : 'modelo não encontrado',
					esc_html( self::duracao( $modelos['volta_em'] ) )
				),
				true
			);
		} else {
			self::cartao(
				'bom',
				'Modelo em uso',
				$modelos['em_uso'],
				$modelos['reserva']
					? 'Reserva configurada: <code>' . esc_html( $modelos['reserva'] ) . '</code>'
					: '<strong>Sem reserva.</strong> Um erro de cota vira erro para o cliente.',
				true
			);
		}

		// ---- cota do dia
		$usado = $cota['teto'] > 0 ? min( 100, round( $cota['usadas'] / $cota['teto'] * 100 ) ) : 0;
		$nivel = $usado >= 90 ? 'ruim' : ( $usado >= 60 ? 'atento' : 'bom' );

		$nota = sprintf(
			'<span class="livia-barra %s"><span style="width:%d%%"></span></span>',
			esc_attr( 'bom' === $nivel ? '' : $nivel ),
			(int) $usado
		);

		if ( $cota['estoura_em'] ) {
			$nivel = 'ruim';
			$nota .= sprintf(
				'No ritmo de hoje, o teto chega em <strong>%s</strong>.',
				esc_html( self::duracao( $cota['estoura_em'] ) )
			);
		} elseif ( $cota['confiavel'] ) {
			$nota .= sprintf(
				'No ritmo de hoje, fecha o dia em ~%d de %d.',
				(int) $cota['projetado'],
				(int) $cota['teto']
			);
		} else {
			// Com quinze minutos de amostra, três clientes seguidos projetariam
			// um teto que não existe. Melhor não dizer nada do que mentir.
			$nota .= 'Movimento ainda baixo para projetar o dia.';
		}

		self::cartao(
			$nivel,
			'Cota do dia',
			$cota['usadas'] . ' / ' . $cota['teto'],
			$nota
		);

		// ---- últimas 24h
		$msgs  = (int) $dia['mensagens'];
		$erros = (int) $dia['erros'];
		$taxa  = $msgs > 0 ? $erros / $msgs : 0;

		self::cartao(
			$msgs >= 10 && $taxa > 0.25 ? 'ruim' : ( $erros ? 'atento' : 'bom' ),
			'Últimas 24 horas',
			$msgs . ( 1 === $msgs ? ' mensagem' : ' mensagens' ),
			$msgs
				? sprintf(
					'%d respondidas na hora, sem API · %d com erro · %d barradas pela trava<br>p95 de %s ms nas que foram ao modelo',
					(int) $dia['atalhos'],
					$erros,
					(int) $dia['bloqueadas'],
					number_format_i18n( (int) $dia['p95_ms'] )
				)
				: 'Ninguém perguntou nada ainda.'
		);

		// ---- cache
		$modo_txt = array(
			'auto'   => 'automático',
			'sempre' => 'sempre ligado',
			'nunca'  => 'desligado',
		);
		self::cartao(
			$cache['ativo'] ? 'bom' : 'neutro',
			'Cache de contexto',
			$cache['ativo'] ? 'Em uso' : 'Parado',
			sprintf(
				'Modo %s · %d perguntas na última hora (liga a partir de %d).',
				esc_html( isset( $modo_txt[ $cache['modo'] ] ) ? $modo_txt[ $cache['modo'] ] : $cache['modo'] ),
				(int) $cache['movimento'],
				(int) $cache['minimo']
			),
			true
		);

		echo '</div>';

		if ( ! empty( $cache['ultimo_erro'] ) ) {
			printf(
				'<p class="description">A API recusou guardar a instrução: <code>%s</code></p>',
				esc_html( $cache['ultimo_erro'] )
			);
		}

		if ( $modelos['na_reserva'] ) {
			printf(
				'<form method="post" action="%s" style="margin:8px 0 0">'
					. '<input type="hidden" name="action" value="livia_restaurar">%s'
					. '<button type="submit" class="button button-small">Voltar para o principal agora</button>'
					. ' <span class="description">Use depois de resolver a cota — senão ela rebaixa de novo na primeira pergunta.</span>'
					. '</form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				wp_nonce_field( 'livia_restaurar', '_wpnonce', true, false )
			);
		}
	}

	/**
	 * O seletor de modelo.
	 *
	 * Lista o que a API oferece de verdade. Digitar o ID à mão era a origem de
	 * um 404 em toda resposta, para todo cliente, descoberto só no atendimento
	 * — e continua possível, porque uma lista que falhou não pode impedir
	 * alguém de configurar o plugin.
	 */
	private static function seletor( $campo, $valor, $lista, $vazio = null ) {
		$nome = Livia_Config::OPCAO . '[' . $campo . ']';

		// Sem lista, o campo continua editável. Uma lista que falhou não pode
		// impedir alguém de configurar o plugin.
		if ( ! is_array( $lista ) ) {
			printf(
				'<input type="text" class="regular-text code" name="%s" value="%s">'
					. '<p class="description">Clique em <strong>Atualizar lista de modelos</strong>, '
					. 'abaixo, para escolher de uma lista em vez de digitar.</p>',
				esc_attr( $nome ),
				esc_attr( $valor )
			);
			return;
		}

		echo '<select name="' . esc_attr( $nome ) . '" class="regular-text">';

		if ( null !== $vazio ) {
			printf(
				'<option value="" %s>%s</option>',
				selected( '', $valor, false ),
				esc_html( $vazio )
			);
		}

		$conhecido = '' === $valor;
		foreach ( $lista as $m ) {
			$conhecido = $conhecido || $m['id'] === $valor;
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $m['id'] ),
				selected( $m['id'], $valor, false ),
				esc_html( $m['id'] . ( $m['nome'] && $m['nome'] !== $m['id'] ? ' — ' . $m['nome'] : '' ) )
			);
		}

		// O modelo salvo sumiu da lista: mantém a opção para não trocar o
		// modelo de alguém por efeito colateral de abrir a tela.
		if ( ! $conhecido ) {
			printf(
				'<option value="%s" selected>%s (não está mais na lista)</option>',
				esc_attr( $valor ),
				esc_html( $valor )
			);
		}

		echo '</select>';
	}

	private static function linha_status( $ok, $titulo, $detalhe ) {
		printf(
			'<tr><td style="width:200px"><strong>%s</strong></td>'
				. '<td><span class="livia-etiqueta e-%s">%s</span></td>'
				. '<td>%s</td></tr>',
			esc_html( $titulo ),
			$ok ? 'bom' : 'ruim',
			$ok ? 'ok' : 'resolver',
			wp_kses_post( $detalhe )
		);
	}

	// ----------------------------------------------------------------- tela

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config    = Livia_Config::tudo();
		$tem_chave = '' !== Livia_Config::api_key();
		$info      = Livia_Base::info();
		$diag      = get_transient( Livia_Config::DIAG );
		// Só o que já está em cache: a tela não pode esperar a API para abrir.
		$lista     = Livia_Modelos::guardados();
		$bytes_base = ! empty( $info['bytes'] ) ? (int) $info['bytes'] : 0;
		?>
		<div class="wrap livia-tela">
			<?php self::cabecalho( 'config' ); ?>

			<?php settings_errors(); ?>

			<?php if ( isset( $_GET['livia_aviso'] ) && 'restaurado' === $_GET['livia_aviso'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Voltou a usar o modelo principal.</p></div>
			<?php endif; ?>

			<?php if ( is_array( $diag ) ) : ?>
				<div class="notice notice-<?php echo $diag['ok'] ? 'success' : 'error'; ?>">
					<p><?php echo esc_html( $diag['mensagem'] ); ?></p>
				</div>
			<?php endif; ?>

			<?php self::situacao(); ?>


			<form method="post" action="options.php">
				<?php settings_fields( self::GRUPO ); ?>

				<!-- ------------------------------------------------ atendimento -->
				<div class="livia-chave <?php echo Livia_Config::esta_ativa() ? '' : 'desligada'; ?>">
					<input type="checkbox" id="livia-ativa"
						name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[ATIVA]"
						value="1" <?php checked( Livia_Config::esta_ativa() ); ?>>
					<div>
						<label for="livia-ativa">LivIA ligada</label>
						<p class="description">
							Desligada, ela some de todas as páginas e as rotas param de responder —
							sem desativar o plugin, sem perder o registro nem a configuração.
							É o jeito de tirá-la do ar em um clique.
						</p>
					</div>
				</div>

				<div class="livia-secao">
					<h3>Modelo</h3>
					<p class="description">
						Quando o principal recusa por cota, a LivIA passa para a reserva sozinha e
						tenta o principal de novo <?php echo esc_html( self::duracao( Livia_Modelos::DESCANSO ) ); ?> depois.
						Sem reserva, um erro de cota vira erro para o cliente.
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label>Modelo principal</label></th>
							<td><?php self::seletor( 'GEMINI_MODEL', $config['GEMINI_MODEL'], $lista ); ?></td>
						</tr>
						<tr>
							<th scope="row"><label>Modelo reserva</label></th>
							<td>
								<?php self::seletor( 'GEMINI_MODEL_RESERVA', $config['GEMINI_MODEL_RESERVA'], $lista, '— sem reserva —' ); ?>
								<p class="description">
									Escolha um modelo de cota independente do principal. Reserva igual ao
									principal é guardada como "sem reserva".
								</p>
							</td>
						</tr>
					</table>
				</div>

				<!-- ------------------------------------------------------- voz -->
				<div class="livia-secao">
					<h3>Como ela se apresenta</h3>
					<p class="description">
						Ela não esconde o que é — se o cliente perguntar, responde com honestidade.
						O que estes campos controlam é só o que aparece antes de alguém perguntar.
					</p>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="livia-nome">Nome</label></th>
							<td>
								<input type="text" id="livia-nome" class="regular-text"
									name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[NOME]"
									value="<?php echo esc_attr( $config['NOME'] ); ?>">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-rotulo">Texto do botão</label></th>
							<td>
								<input type="text" id="livia-rotulo" class="regular-text" maxlength="24"
									name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[ROTULO]"
									value="<?php echo esc_attr( $config['ROTULO'] ); ?>">
								<p class="description">
									Fica ao lado do ícone, no botão que abre a conversa. Um balãozinho
									sozinho significa "chat" para quem já usa chat — e quem está
									preenchendo um briefing pela primeira vez não é essa pessoa.
									Em branco, o botão volta a ser um círculo só com o ícone.
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-cor">Cor</label></th>
							<td>
								<input type="color" id="livia-cor"
									name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[COR]"
									value="<?php echo esc_attr( Livia_Config::cor() ); ?>"
									style="width:60px;height:34px;padding:2px;vertical-align:middle">
								<span class="description">Cabeçalho, bolha e balões do cliente.</span>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-saudacao">Primeira fala</label></th>
							<td>
								<textarea id="livia-saudacao" class="large-text" rows="2"
									name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[SAUDACAO]"><?php echo esc_textarea( $config['SAUDACAO'] ); ?></textarea>
								<p class="description">O que ela escreve assim que a janela abre.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-convite">Convite</label></th>
							<td>
								<textarea id="livia-convite" class="large-text" rows="2"
									name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[CONVITE]"><?php echo esc_textarea( $config['CONVITE'] ); ?></textarea>
								<p class="description">
									Balãozinho que aparece ao lado da bolha depois de vinte segundos na
									página. Se for ignorado, some e não volta.
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-sugestoes">Perguntas de partida</label></th>
							<td>
								<textarea id="livia-sugestoes" class="large-text code" rows="3"
									name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[SUGESTOES]"><?php echo esc_textarea( $config['SUGESTOES'] ); ?></textarea>
								<p class="description">
									Uma por linha, no máximo três — viram botões logo abaixo da primeira
									fala e somem quando o cliente manda a primeira mensagem. Uma caixa de
									texto vazia não diz a ninguém o que dá para perguntar; três exemplos
									dizem.
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-canal">Canal de suporte</label></th>
							<td>
								<input type="text" id="livia-canal" class="regular-text"
									name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[CANAL_DE_SUPORTE]"
									value="<?php echo esc_attr( $config['CANAL_DE_SUPORTE'] ); ?>"
									placeholder="WhatsApp (47) 3433-5066">
								<p class="description">
									Texto puro — ela repete exatamente isto ao encaminhar. Sem link, sem HTML.
								</p>
							</td>
						</tr>
					</table>
				</div>

				<!-- --------------------------------------------------- chave/avançado -->
				<div class="livia-secao">
					<h3>Chave e desempenho</h3>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="livia-chave-api">Chave da API</label></th>
							<td>
								<?php if ( Livia_Config::chave_vem_de_constante() ) : ?>
									<p>
										<span class="livia-etiqueta e-bom">no wp-config.php</span>
										Definida na constante <code><?php echo esc_html( Livia_Config::CONSTANTE_CHAVE ); ?></code>.
										Não passa pelo banco, e este campo não a altera.
									</p>
								<?php else : ?>
									<input type="password" id="livia-chave-api" class="regular-text" autocomplete="off"
										name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[GEMINI_API_KEY]"
										placeholder="<?php echo $tem_chave ? 'chave salva — deixe em branco para manter' : 'AIzaSy...'; ?>">
									<p class="description">
										O lugar certo dela é o <code>wp-config.php</code>:
										<code>define( '<?php echo esc_html( Livia_Config::CONSTANTE_CHAVE ); ?>', '...' );</code>
										— chave no banco vai junto no backup.
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-base-modo">O que vai na pergunta</label></th>
							<td>
								<select id="livia-base-modo" name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[BASE_MODO]">
									<option value="selecao" <?php selected( 'selecao', Livia_Config::base_modo() ); ?>>Só a parte que a pergunta pede (recomendado)</option>
									<option value="inteira" <?php selected( 'inteira', Livia_Config::base_modo() ); ?>>A base inteira, sempre</option>
								</select>
								<p class="description">
									A base tem <?php echo esc_html( number_format_i18n( round( $bytes_base / 1024 ) ) ); ?> KB.
									Mandá-la inteira custava cerca de 14 mil tokens de entrada por mensagem —
									para respostas de 76. Na seleção, as regras vão sempre e o conteúdo vai
									por assunto: aferido nas 41 perguntas da homologação, é
									<strong>54% a menos</strong> por mensagem.
								</p>
								<p class="description">
									Se ela começar a dizer que não sabe coisas que sabia, volte para
									"a base inteira" e me conte qual foi a pergunta.
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-teto">Teto de chamadas por dia</label></th>
							<td>
								<input type="number" id="livia-teto" class="small-text" min="1" max="50000" step="1"
									name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[TETO_DIARIO]"
									value="<?php echo esc_attr( Livia_Config::teto_diario() ); ?>">
								<span class="description">por modelo</span>
								<p class="description">
									O disjuntor abre quando acaba, e a LivIA passa a encaminhar todo mundo
									para o atendimento em vez de dar erro. O número certo é o do plano do
									modelo que você escolheu — confira em
									<code>ai.google.dev/gemini-api/docs/rate-limits</code>, porque ele muda
									por modelo e muda com o tempo.
									<?php if ( Livia_Modelos::tem_reserva() ) : ?>
										Com a reserva configurada são duas cotas:
										<strong><?php echo esc_html( number_format_i18n( 2 * Livia_Config::teto_diario() ) ); ?></strong>
										chamadas no dia.
									<?php endif; ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="livia-cache">Cache de contexto</label></th>
							<td>
								<select id="livia-cache" name="<?php echo esc_attr( Livia_Config::OPCAO ); ?>[CACHE_MODO]">
									<option value="auto" <?php selected( 'auto', Livia_Config::cache_modo() ); ?>>Automático — liga quando há movimento</option>
									<option value="sempre" <?php selected( 'sempre', Livia_Config::cache_modo() ); ?>>Sempre ligado</option>
									<option value="nunca" <?php selected( 'nunca', Livia_Config::cache_modo() ); ?>>Desligado</option>
								</select>
								<p class="description">
									Guarda do lado da API a parte que não muda — as regras, que agora
									viajam separadas do conteúdo justamente para poderem ser cacheadas.
									Cobra armazenamento por hora, tenha movimento ou não. Em automático,
									ele se liga sozinho a partir de <?php echo (int) Livia_Cache::MINIMO_POR_HORA; ?> perguntas
									na última hora.
								</p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( 'Salvar' ); ?>
			</form>

			<?php if ( $tem_chave ) : ?>
				<div class="livia-filtros">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="livia_testar">
						<?php wp_nonce_field( 'livia_testar' ); ?>
						<button type="submit" class="button">Testar chave e modelo</button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="livia_modelos">
						<?php wp_nonce_field( 'livia_modelos' ); ?>
						<button type="submit" class="button">Atualizar lista de modelos</button>
					</form>
					<span class="description">
						A lista fica guardada por meio dia. Atualize depois de mexer nas permissões da chave.
					</span>
				</div>
			<?php endif; ?>

			<h2>O que precisa estar de pé</h2>
			<table class="widefat striped">
				<tbody>
				<?php
				self::linha_status(
					$tem_chave,
					'Chave da API',
					$tem_chave
						? 'Presente.'
						: 'Faltando. Sem ela a LivIA não responde a ninguém.'
				);

				self::linha_status(
					! empty( $info['ok'] ),
					'Base de conhecimento',
					! empty( $info['ok'] )
						? sprintf(
							'%s KB, modificada em %s.<br><code>%s</code>',
							esc_html( number_format_i18n( $info['bytes'] / 1024, 1 ) ),
							esc_html( wp_date( 'd/m/Y H:i', $info['modificado'] ) ),
							esc_html( $info['caminho'] )
						)
						: 'Não encontrada em <code>' . esc_html( $info['caminho'] ) . '</code>.'
				);

				$canal = Livia_Config::canal();
				self::linha_status(
					'' !== $canal,
					'Canal de suporte',
					'' !== $canal
						? esc_html( $canal )
						: 'Vazio. A LivIA vai repetir <code>' . esc_html( Livia_Base::MARCADOR ) . '</code> literalmente para o cliente.'
				);
				?>
				</tbody>
			</table>

			<?php self::painel_operacao(); ?>
		</div>
		<?php
	}

	/** Endereços e chaves que o monitoramento precisa. */
	private static function painel_operacao() {
		$saude = rest_url( Livia_Rest::NAMESPACE_API . '/saude' );
		?>
		<h2>Monitoramento</h2>
		<table class="widefat striped">
			<tbody>
				<tr>
					<td style="width:200px"><strong>Endereço público</strong></td>
					<td>
						<code><?php echo esc_html( $saude ); ?></code>
						<p class="description">
							Para o monitor de uptime. Responde <code>503</code> só quando ela realmente
							não responde; degradada e desligada saem <code>200</code> com <code>ok:false</code>.
						</p>
					</td>
				</tr>
				<tr>
					<td><strong>Relatório detalhado</strong></td>
					<td>
						<code><?php echo esc_html( $saude . '?chave=' . Livia_Saude::token() ); ?></code>
						<p class="description">
							Cota, latência, estado do cron e do cache. <strong>Não coloque este endereço
							no monitor público</strong> — a URL vai para o log do serviço.
						</p>
					</td>
				</tr>
				<tr>
					<td><strong>Shortcode</strong></td>
					<td>
						<code>[livia formulario="site-em-72h"]</code>
						<p class="description">
							Um por página, com um rótulo diferente por briefing — é o rótulo que
							separa as métricas depois.
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}
