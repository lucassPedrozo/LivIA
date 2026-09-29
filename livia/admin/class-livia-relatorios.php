<?php
/**
 * As conversas: o que perguntaram, o que ela respondeu, e de onde.
 *
 * A tela é organizada por CONVERSA, não por mensagem. Uma lista plana de
 * mensagens não se lê: "não sei" e "ta, vamos começar" só querem dizer alguma
 * coisa junto do que veio antes. Cada linha abre e mostra o diálogo inteiro.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Relatorios {

	const PAGINA = 'livia-relatorios';

	public static function iniciar() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_livia_exportar', array( __CLASS__, 'exportar' ) );
		add_action( 'admin_post_livia_limpar', array( __CLASS__, 'limpar' ) );
	}

	public static function menu() {
		$tela = add_submenu_page(
			'options-general.php',
			'LivIA — conversas',
			'LivIA — conversas',
			'manage_options',
			self::PAGINA,
			array( __CLASS__, 'render' )
		);
		add_action( 'load-' . $tela, array( 'Livia_Admin', 'estilo' ) );

		// Registrada, mas fora do menu: virou aba dentro de "LivIA". Registrar
		// e esconder, em vez de não registrar, é o que mantém a URL antiga
		// funcionando — quem tiver isto nos favoritos não cai num 404.
		remove_submenu_page( 'options-general.php', self::PAGINA );
	}

	// ---------------------------------------------------------------- filtros

	private static function busca() {
		// phpcs:ignore WordPress.Security.NonceVerification
		$b = isset( $_GET['busca'] ) ? wp_unslash( $_GET['busca'] ) : '';
		return trim( sanitize_text_field( $b ) );
	}

	private static function dias() {
		$d = isset( $_GET['dias'] ) ? (int) $_GET['dias'] : 30; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $d, array( 1, 7, 30, 90 ), true ) ? $d : 30;
	}

	private static function so_problema() {
		return ! empty( $_GET['problema'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	private static function url( array $extra = array() ) {
		return add_query_arg(
			array_merge(
				array( 'page' => self::PAGINA, 'dias' => self::dias() ),
				self::so_problema() ? array( 'problema' => 1 ) : array(),
				'' !== self::busca() ? array( 'busca' => self::busca() ) : array(),
				$extra
			),
			admin_url( 'options-general.php' )
		);
	}

	// ----------------------------------------------------------------- ações

	/** Baixa a tabela inteira. É o formato que abre em qualquer lugar. */
	public static function exportar() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'livia_exportar' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=livia-conversas-' . gmdate( 'Y-m-d' ) . '.csv' );

		// BOM: sem isso o Excel em português abre os acentos errados.
		echo "\xEF\xBB\xBF";
		Livia_Registro::exportar_csv();
		exit;
	}

	/**
	 * Apaga registros.
	 *
	 * É irreversível e não tem desfazer, então a confirmação é dupla: a caixa
	 * de "tenho certeza" no formulário e um `confirm()` no clique. As duas são
	 * do lado de cá — quem apagar sem querer não tem para onde recorrer.
	 */
	public static function limpar() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		check_admin_referer( 'livia_limpar' );

		if ( empty( $_POST['confirmo'] ) ) {
			wp_safe_redirect( add_query_arg( 'livia_aviso', 'sem-confirmar', self::url() ) );
			exit;
		}

		$escopo = isset( $_POST['escopo'] ) ? sanitize_key( wp_unslash( $_POST['escopo'] ) ) : '';
		$sessao = isset( $_POST['sessao'] ) ? sanitize_key( wp_unslash( $_POST['sessao'] ) ) : '';

		if ( 'conversa' === $escopo && Livia_Sessao::id_valido( $sessao ) ) {
			$n = Livia_Registro::apagar_conversa( $sessao );
		} elseif ( in_array( $escopo, array( 'tudo', 'hoje', 'ate' ), true ) ) {
			$n = Livia_Registro::apagar( $escopo );
		} else {
			$n = 0;
		}

		wp_safe_redirect( add_query_arg( 'livia_apagadas', (int) $n, self::url() ) );
		exit;
	}

	// ---------------------------------------------------------------- pedaços

	private static function quando( $criado_em, $formato = 'd/m H:i' ) {
		return wp_date( $formato, strtotime( $criado_em . ' UTC' ) );
	}

	/** Etiqueta do que aconteceu com a mensagem. */
	private static function marca( array $l ) {
		if ( ! empty( $l['bloqueio'] ) ) {
			return array( 'ruim', 'barrada pela trava', $l['bloqueio'] );
		}
		if ( 'guarda' === $l['origem'] ) {
			return array( 'atento', 'barrada na guarda', $l['erro'] );
		}
		if ( ! empty( $l['erro'] ) ) {
			return array( 'ruim', 'erro da API', $l['erro'] );
		}
		if ( 'atalho' === $l['origem'] ) {
			return array( 'neutro', 'resposta pronta', 'sem custo de API' );
		}
		return array( '', '', '' );
	}

	private static function etiqueta( $tom, $texto ) {
		return sprintf(
			'<span class="livia-etiqueta e-%s">%s</span>',
			esc_attr( $tom ),
			esc_html( $texto )
		);
	}

	/** Barrinha proporcional: dá a forma da distribuição sem virar gráfico. */
	private static function proporcao( $valor, $maximo ) {
		if ( $maximo <= 0 ) {
			return '';
		}
		return sprintf(
			'<span class="livia-proporcao" style="width:%d%%"></span>',
			(int) max( 2, round( $valor / $maximo * 100 ) )
		);
	}

	private static function duracao_curta( $ini, $fim ) {
		$s = max( 0, strtotime( $fim ) - strtotime( $ini ) );
		if ( $s < 60 ) {
			return $s . 's';
		}
		return (int) round( $s / 60 ) . 'min';
	}

	// ------------------------------------------------------------------ tela

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$dias        = self::dias();
		$so_problema = self::so_problema();
		$busca       = self::busca();
		$conversas   = Livia_Registro::conversas( $dias, 40, $so_problema, $busca );
		$paginas     = Livia_Registro::por_pagina( $dias );
		$frequentes  = Livia_Registro::perguntas_frequentes( $dias );
		$falhas      = Livia_Registro::nao_funcionou( $dias );
		$serie       = Livia_Registro::por_dia( 14 );
		?>
		<div class="wrap livia-tela">
			<?php Livia_Admin::cabecalho( 'conversas' ); ?>

			<p class="description">
				Os dados pessoais da pergunta são removidos na gravação; a resposta dela fica
				intacta, porque é nela que se enxerga o que ela quase disse. Nada aqui sobrevive
				mais de <?php echo (int) Livia_Registro::RETENCAO_DIAS; ?> dias.
			</p>

			<?php self::avisos(); ?>

			<div class="livia-filtros">
				<div class="livia-grupo-botoes">
					<?php foreach ( array( 1 => 'Hoje', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias' ) as $d => $rotulo ) : ?>
						<a href="<?php echo esc_url( self::url( array( 'dias' => $d ) ) ); ?>"
							class="<?php echo $d === $dias ? 'ativo' : ''; ?>"><?php echo esc_html( $rotulo ); ?></a>
					<?php endforeach; ?>
				</div>

				<a class="button <?php echo $so_problema ? 'button-primary' : ''; ?>"
					href="<?php echo esc_url( self::url( array( 'problema' => $so_problema ? false : 1 ) ) ); ?>">
					<?php echo $so_problema ? '✓ Só com problema' : 'Só com problema'; ?>
				</a>

				<form class="livia-busca" method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGINA ); ?>">
					<input type="hidden" name="dias" value="<?php echo (int) $dias; ?>">
					<?php if ( $so_problema ) : ?>
						<input type="hidden" name="problema" value="1">
					<?php endif; ?>
					<label class="screen-reader-text" for="livia-busca">Procurar nas conversas</label>
					<input type="search" id="livia-busca" name="busca" value="<?php echo esc_attr( $busca ); ?>"
						placeholder="Procurar: logotipo, domínio, prazo…">
					<button type="submit" class="button">Procurar</button>
					<?php if ( '' !== $busca ) : ?>
						<a class="button-link" href="<?php echo esc_url( self::url( array( 'busca' => false ) ) ); ?>">limpar</a>
					<?php endif; ?>
				</form>

				<span class="separa"></span>

				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=livia_exportar' ), 'livia_exportar' ) ); ?>">Baixar CSV</a>
			</div>

			<?php self::painel_conversas( $conversas, $so_problema, $dias, $busca ); ?>
			<?php self::painel_falhas( $falhas ); ?>
			<?php self::painel_movimento( $serie ); ?>
			<?php self::painel_paginas( $paginas ); ?>
			<?php self::painel_perguntas( $frequentes ); ?>
			<?php self::painel_limpeza(); ?>
		</div>
		<?php
	}

	private static function avisos() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['livia_apagadas'] ) ) {
			$n = (int) $_GET['livia_apagadas'];
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				$n
					? esc_html( sprintf( '%d mensagem(ns) apagada(s). Não dá para desfazer.', $n ) )
					: 'Nada foi apagado.'
			);
		}
		if ( isset( $_GET['livia_aviso'] ) && 'sem-confirmar' === $_GET['livia_aviso'] ) {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. 'Nada foi apagado: falta marcar a confirmação.</p></div>';
		}
		// phpcs:enable
	}

	/**
	 * O painel principal: uma linha por conversa, que abre no diálogo inteiro.
	 *
	 * `<details>` e não JavaScript: o navegador já sabe abrir e fechar isto,
	 * funciona com teclado e com leitor de tela sem que a gente escreva nada.
	 */
	private static function painel_conversas( $conversas, $so_problema, $dias, $busca = '' ) {
		?>
		<h2>Conversas</h2>
		<p class="description">
			Da mais recente para a mais antiga. Clique numa linha para ler o diálogo inteiro —
			é lendo a conversa inteira que se percebe onde a LivIA se perdeu.
			<?php if ( '' !== $busca ) : ?>
				<br><strong>Procurando por “<?php echo esc_html( $busca ); ?>”.</strong>
				A busca traz a conversa inteira em que a palavra apareceu, não a linha solta.
			<?php endif; ?>
		</p>

		<?php if ( ! $conversas ) : ?>
			<div class="livia-vazio">
				<?php
				if ( '' !== $busca ) {
					echo 'Nenhuma conversa com “' . esc_html( $busca ) . '” nesse período.';
				} else {
					echo $so_problema ? 'Nenhuma conversa com problema nesse período.' : 'Nenhuma conversa nesse período.';
				}
				?>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<div class="livia-conversas">
		<?php foreach ( $conversas as $c ) : ?>
			<?php
			$problemas = (int) $c['problemas'];
			$primeira  = Livia_Registro::turnos( $c['sessao'] );
			$abertura  = $primeira ? $primeira[0]['pergunta'] : '';
			?>
			<details class="livia-conversa-item <?php echo $problemas ? 'tem-problema' : ''; ?>">
				<summary>
					<span class="livia-conversa-topo">
						<span class="livia-quando"><?php echo esc_html( self::quando( $c['comecou'] ) ); ?></span>
						<span class="livia-conversa-meta">
							<?php echo esc_html( $c['pagina'] ? $c['pagina'] : 'sem página' ); ?>
							<?php if ( $c['formulario'] ) : ?>
								· <?php echo esc_html( $c['formulario'] ); ?>
							<?php endif; ?>
						</span>
						<span class="livia-conversa-selos">
							<?php if ( $c['bloqueadas'] ) : ?>
								<?php echo self::etiqueta( 'ruim', (int) $c['bloqueadas'] . ' barrada(s)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<?php if ( $c['erros'] ) : ?>
								<?php echo self::etiqueta( 'ruim', (int) $c['erros'] . ' erro(s)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<?php if ( ! empty( $c['negativas'] ) ) : ?>
								<?php echo self::etiqueta( 'ruim', (int) $c['negativas'] . ' não ajudou' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<?php if ( ! empty( $c['positivas'] ) ) : ?>
								<?php echo self::etiqueta( 'bom', (int) $c['positivas'] . ' ajudou' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<?php if ( $c['pior_ms'] > 5000 ) : ?>
								<?php echo self::etiqueta( 'atento', 'lenta · ' . round( $c['pior_ms'] / 1000, 1 ) . 's' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php endif; ?>
							<span class="livia-conversa-turnos">
								<?php echo (int) $c['turnos']; ?> turnos · <?php echo esc_html( self::duracao_curta( $c['comecou'], $c['terminou'] ) ); ?>
							</span>
						</span>
					</span>
					<span class="livia-conversa-abertura"><?php echo esc_html( $abertura ); ?></span>
				</summary>

				<div class="livia-dialogo">
					<?php foreach ( $primeira as $t ) : ?>
						<?php list( $tom, $rotulo, $detalhe ) = self::marca( $t ); ?>
						<div class="livia-turno">
							<div class="livia-fala-cliente">
								<span class="livia-quem">cliente</span>
								<p><?php echo esc_html( $t['pergunta'] ); ?></p>
							</div>
							<div class="livia-fala-livia <?php echo $tom ? 'e-' . esc_attr( $tom ) : ''; ?>">
								<span class="livia-quem">
									LivIA
									<?php if ( $rotulo ) : ?>
										<?php echo self::etiqueta( $tom, $rotulo ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<?php if ( $detalhe ) : ?>
											<em><?php echo esc_html( $detalhe ); ?></em>
										<?php endif; ?>
									<?php endif; ?>
									<?php if ( null !== $t['util'] && '' !== $t['util'] ) : ?>
										<span class="livia-voto <?php echo $t['util'] ? 'e-sim' : 'e-nao'; ?>">
											<?php echo $t['util'] ? 'o cliente disse que ajudou' : 'o cliente disse que NÃO ajudou'; ?>
										</span>
									<?php endif; ?>
									<span class="livia-turno-num">
										<?php echo esc_html( self::quando( $t['criado_em'], 'H:i:s' ) ); ?>
										<?php if ( $t['latencia_ms'] ) : ?>
											· <?php echo (int) $t['latencia_ms']; ?> ms
										<?php endif; ?>
									</span>
								</span>
								<p><?php echo esc_html( $t['resposta'] ? $t['resposta'] : '— sem resposta —' ); ?></p>
							</div>
						</div>
					<?php endforeach; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="livia-apagar-conversa">
						<input type="hidden" name="action" value="livia_limpar">
						<input type="hidden" name="escopo" value="conversa">
						<input type="hidden" name="sessao" value="<?php echo esc_attr( $c['sessao'] ); ?>">
						<input type="hidden" name="confirmo" value="1">
						<?php wp_nonce_field( 'livia_limpar' ); ?>
						<button type="submit" class="button button-small button-link-delete"
							onclick="return confirm('Apagar esta conversa inteira? Não dá para desfazer.')">
							Apagar esta conversa
						</button>
						<span class="description">
							<?php echo (int) $c['tokens']; ?> tokens · <?php echo esc_html( $c['sessao'] ); ?>
						</span>
					</form>
				</div>
			</details>
		<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * O que não funcionou — a fila do que arrumar na base.
	 *
	 * Fica em cima das outras tabelas de propósito: é a única lista da tela que
	 * pede uma AÇÃO. As demais descrevem; esta manda fazer.
	 */
	private static function painel_falhas( $falhas ) {
		?>
		<h2>O que não funcionou</h2>
		<p class="description">
			Perguntas em que o cliente marcou que a resposta não ajudou, ou em que a trava
			cortou a resposta. Encaminhamento de propósito não entra aqui — preço e prazo de
			contrato TÊM que ir para a equipe, e contá-los como falha esconderia o que
			realmente precisa de conserto na base.
		</p>

		<?php if ( ! $falhas ) : ?>
			<div class="livia-vazio">Nada marcado como ruim nesse período.</div>
			<?php return; ?>
		<?php endif; ?>

		<div class="livia-tabela">
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Pergunta</th>
						<th class="livia-num">Vezes</th>
						<th>Por quê</th>
						<th>Última</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $falhas as $f ) : ?>
						<tr>
							<td><span class="livia-fala cortada"><?php echo esc_html( $f['pergunta'] ); ?></span></td>
							<td class="livia-num"><?php echo (int) $f['vezes']; ?></td>
							<td>
								<?php if ( $f['negativas'] ) : ?>
									<?php echo self::etiqueta( 'ruim', (int) $f['negativas'] . ' não ajudou' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php endif; ?>
								<?php if ( $f['bloqueadas'] ) : ?>
									<?php echo self::etiqueta( 'atento', (int) $f['bloqueadas'] . ' barrada(s)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<?php endif; ?>
							</td>
							<td class="livia-quando"><?php echo esc_html( self::quando( $f['ultima'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * O movimento dos últimos catorze dias.
	 *
	 * Barras em CSS, sem biblioteca nenhuma: são catorze números, e carregar um
	 * pacote de gráfico para isso custaria mais bytes do que a página inteira.
	 *
	 * Vinte e quatro horas dizem como está agora; não dizem se está piorando.
	 */
	private static function painel_movimento( $serie ) {
		$pico = 0;
		foreach ( $serie as $d ) {
			$pico = max( $pico, (int) $d['mensagens'] );
		}
		?>
		<h2>Movimento dos últimos 14 dias</h2>
		<p class="description">
			Mensagens por dia, e quanto disso foi ao modelo. A diferença entre as duas é o que
			os atalhos responderam de graça. Dia sem conversa aparece vazio, e não some — três
			dias parados não podem virar duas barras coladas.
		</p>

		<?php if ( ! $pico ) : ?>
			<div class="livia-vazio">Nenhuma conversa nos últimos 14 dias.</div>
			<?php return; ?>
		<?php endif; ?>

		<div class="livia-serie">
			<?php foreach ( $serie as $d ) : ?>
				<?php
				$total  = (int) $d['mensagens'];
				$modelo = (int) $d['ao_modelo'];
				$altura = $pico ? round( 100 * $total / $pico ) : 0;
				$parte  = $total ? round( 100 * $modelo / $total ) : 0;
				$titulo = sprintf(
					'%s — %d mensagem(ns), %d ao modelo, %d conversa(s)%s',
					gmdate( 'd/m', strtotime( $d['dia'] ) ),
					$total,
					$modelo,
					(int) $d['conversas'],
					$d['problemas'] ? ', ' . (int) $d['problemas'] . ' com problema' : ''
				);
				?>
				<div class="livia-dia" title="<?php echo esc_attr( $titulo ); ?>">
					<span class="livia-coluna">
						<span class="livia-parte" style="height:<?php echo (int) $altura; ?>%">
							<span class="livia-modelo" style="height:<?php echo (int) $parte; ?>%"></span>
						</span>
					</span>
					<span class="livia-dia-rotulo"><?php echo esc_html( gmdate( 'd/m', strtotime( $d['dia'] ) ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="description livia-legenda">
			<span class="livia-chip-modelo"></span> foi ao modelo
			<span class="livia-chip-total"></span> respondido na hora, sem cota
			<span class="livia-pico">pico: <?php echo (int) $pico; ?> mensagens num dia</span>
		</p>
		<?php
	}

	private static function painel_paginas( $paginas ) {
		$maximo = 0;
		foreach ( $paginas as $p ) {
			$maximo = max( $maximo, (int) $p['mensagens'] );
		}
		?>
		<h2>De onde vêm as perguntas</h2>
		<p class="description">
			Página com muita pergunta é página que não está se explicando sozinha. Muita coisa
			<em>barrada na guarda</em> costuma ser gente escrevendo textão no chat; muita
			<em>barrada pela trava</em> é a base induzindo a LivIA a inventar.
		</p>
		<div class="livia-tabela">
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Página</th>
						<th>Formulário</th>
						<th class="livia-num">Conversas</th>
						<th class="livia-num">Perguntas</th>
						<th class="livia-num">Por conversa</th>
						<th class="livia-num">Trava</th>
						<th class="livia-num">Guarda</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $paginas ) : ?>
					<tr><td colspan="7"><span class="description">Nenhuma conversa nesse período.</span></td></tr>
				<?php endif; ?>
				<?php foreach ( $paginas as $p ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $p['titulo'] ? $p['titulo'] : 'sem página identificada' ); ?></strong>
							<?php if ( $p['url'] ) : ?>
								<br><span class="description"><code><?php echo esc_html( $p['url'] ); ?></code></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $p['formulario'] ? $p['formulario'] : '—' ); ?></td>
						<td class="livia-num"><?php echo (int) $p['conversas']; ?></td>
						<td class="livia-num">
							<strong><?php echo (int) $p['mensagens']; ?></strong>
							<?php echo self::proporcao( (int) $p['mensagens'], $maximo ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</td>
						<td class="livia-num">
							<?php echo esc_html( $p['conversas'] ? number_format_i18n( $p['mensagens'] / $p['conversas'], 1 ) : '0' ); ?>
						</td>
						<td class="livia-num">
							<?php echo $p['bloqueadas'] ? self::etiqueta( 'ruim', (int) $p['bloqueadas'] ) : '0'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</td>
						<td class="livia-num">
							<?php echo $p['barradas'] ? self::etiqueta( 'atento', (int) $p['barradas'] ) : '0'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function painel_perguntas( $frequentes ) {
		$maximo = 0;
		foreach ( $frequentes as $f ) {
			$maximo = max( $maximo, (int) $f['vezes'] );
		}
		?>
		<h2>O que mais perguntam</h2>
		<p class="description">
			Perguntas iguais escritas de jeitos diferentes contam juntas. Pergunta que se repete
			<em>e</em> dá problema é a melhor candidata a virar uma seção nova da base.
		</p>
		<div class="livia-tabela">
			<table class="widefat striped">
				<thead>
					<tr>
						<th class="livia-num" style="width:7em">Vezes</th>
						<th class="livia-num" style="width:9em">Com problema</th>
						<th>Pergunta</th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $frequentes ) : ?>
					<tr><td colspan="3"><span class="description">Nada ainda.</span></td></tr>
				<?php endif; ?>
				<?php foreach ( $frequentes as $f ) : ?>
					<tr>
						<td class="livia-num">
							<strong><?php echo (int) $f['vezes']; ?></strong>
							<?php echo self::proporcao( (int) $f['vezes'], $maximo ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</td>
						<td class="livia-num">
							<?php echo $f['problemas'] ? self::etiqueta( 'ruim', (int) $f['problemas'] ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</td>
						<td><?php echo esc_html( $f['pergunta'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Limpar o registro.
	 *
	 * Fica no fim da página, dentro de uma caixa que não se confunde com o
	 * resto, e exige uma confirmação marcada à mão. A alternativa — um botão
	 * "limpar" solto no topo — é como se perde três meses de dado de cliente.
	 */
	private static function painel_limpeza() {
		$acao = admin_url( 'admin-post.php' );
		?>
		<h2>Limpar o registro</h2>
		<div class="livia-perigo">
			<p>
				<strong>Depois de apagar, não tem desfazer.</strong> Serve para tirar as conversas
				de teste da frente antes de a LivIA atender o primeiro cliente de verdade — se
				elas ficarem, toda média desta tela vai continuar contando com elas.
			</p>
			<p class="description">
				Se quiser guardar uma cópia antes, use <em>Baixar CSV</em> lá em cima.
			</p>

			<form method="post" action="<?php echo esc_url( $acao ); ?>">
				<input type="hidden" name="action" value="livia_limpar">
				<?php wp_nonce_field( 'livia_limpar' ); ?>

				<p>
					<label><input type="radio" name="escopo" value="hoje" checked> Só as de hoje</label><br>
					<label><input type="radio" name="escopo" value="ate"> Tudo que é anterior a hoje</label><br>
					<label><input type="radio" name="escopo" value="tudo"> <strong>Tudo</strong> — o registro inteiro</label>
				</p>

				<p>
					<label>
						<input type="checkbox" name="confirmo" value="1">
						Eu entendo que isto apaga os registros para sempre.
					</label>
				</p>

				<button type="submit" class="button button-link-delete"
					onclick="return confirm('Apagar os registros escolhidos? Não dá para desfazer.')">
					Apagar registros
				</button>
			</form>
		</div>
		<?php
	}
}
