<?php
/**
 * Configuração da LivIA: chave da API, modelo e canal de suporte.
 *
 * Equivale ao .env do protótipo em Python. As três chaves têm o mesmo nome e o
 * mesmo significado, para que a base de conhecimento e os casos de teste não
 * precisem saber em qual das duas implementações estão rodando.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Config {

	const OPCAO     = 'livia_config';
	const DIAG      = 'livia_diagnostico';

	/**
	 * Definindo esta constante no wp-config.php, a chave nunca toca o banco.
	 * É o lugar certo dela: dump de banco e backup de plugin vazam wp_options.
	 */
	const CONSTANTE_CHAVE = 'LIVIA_GEMINI_API_KEY';

	public static function padroes() {
		return array(
			'GEMINI_API_KEY'       => '',
			'GEMINI_MODEL'         => 'gemini-3.5-flash-lite',
			'GEMINI_MODEL_RESERVA' => '',
			'CANAL_DE_SUPORTE'     => '',

			// Desligada, a LivIA some da página em vez de aparecer quebrada.
			'ATIVA'                => '1',

			// auto | sempre | nunca — ver Livia_Cache.
			'CACHE_MODO'           => 'auto',

			// selecao | inteira — ver Livia_Trechos. 'selecao' manda só a parte
			// da base que a pergunta pede, e é o que segura o consumo dentro do
			// plano free. 'inteira' existe como volta atrás: se a seleção passar
			// a errar, é um clique, não uma edição de PHP.
			'BASE_MODO'            => 'selecao',

			// Chamadas por dia POR MODELO antes de o disjuntor abrir. O número
			// certo é o do plano do modelo que você escolheu — ver Livia_Limites.
			'TETO_DIARIO'          => '200',

			// Aparência e voz do widget.
			'NOME'                 => 'LivIA',
			'COR'                  => '#1f5f8b',
			'SAUDACAO'             => 'Oi! Tô aqui pra ajudar com o briefing. Travou em alguma parte?',
			'CONVITE'              => 'Travou em alguma parte? Me chama que eu te explico.',

			// O texto dentro do botão que abre a conversa. Um botão redondo sem
			// rótulo depende do ícone falar sozinho, e ícone de balão significa
			// "chat" para quem já usa chat — não para quem está preenchendo um
			// formulário pela primeira vez.
			'ROTULO'               => 'Dúvidas?',

			// Uma por linha. Viram botões abaixo da primeira fala.
			'SUGESTOES'            => "O que é domínio?
Quanto tempo demora?
O que preciso enviar?",
		);
	}

	public static function tudo() {
		$salvo = get_option( self::OPCAO, array() );
		if ( ! is_array( $salvo ) ) {
			$salvo = array();
		}
		return array_merge( self::padroes(), $salvo );
	}

	/** A constante do wp-config.php sempre ganha da opção do banco. */
	public static function api_key() {
		if ( self::chave_vem_de_constante() ) {
			return trim( constant( self::CONSTANTE_CHAVE ) );
		}
		$tudo = self::tudo();
		return trim( $tudo['GEMINI_API_KEY'] );
	}

	public static function chave_vem_de_constante() {
		return defined( self::CONSTANTE_CHAVE ) && '' !== trim( (string) constant( self::CONSTANTE_CHAVE ) );
	}

	public static function modelo() {
		$tudo = self::tudo();
		return self::normalizar_modelo( $tudo['GEMINI_MODEL'] );
	}

	/**
	 * Para onde a LivIA vai quando o modelo principal não responde.
	 *
	 * Vazio é uma escolha legítima: sem reserva, um erro de cota vira erro para
	 * o cliente, que é o comportamento de antes.
	 */
	public static function modelo_reserva() {
		$tudo = self::tudo();
		return self::normalizar_modelo( $tudo['GEMINI_MODEL_RESERVA'] );
	}

	public static function canal() {
		$tudo = self::tudo();
		return trim( $tudo['CANAL_DE_SUPORTE'] );
	}

	/**
	 * O interruptor geral.
	 *
	 * Desligada, o widget não é sequer impresso na página e as rotas recusam
	 * com uma mensagem clara. É o que permite tirar a LivIA do ar em um clique,
	 * sem desativar o plugin e sem perder o registro nem a configuração.
	 */
	public static function esta_ativa() {
		$tudo = self::tudo();
		return '1' === (string) $tudo['ATIVA'];
	}

	public static function nome() {
		$tudo = self::tudo();
		$nome = trim( (string) $tudo['NOME'] );
		return '' !== $nome ? $nome : 'LivIA';
	}

	/**
	 * A cor de destaque do widget, sempre em #rrggbb.
	 *
	 * Vai para o CSS como valor de uma variável. Sanitizada com força total —
	 * é o único campo de configuração cujo conteúdo acaba dentro de um atributo
	 * de estilo, e "cor" é um jeito clássico de tentar injetar outra coisa.
	 */
	public static function cor() {
		$tudo = self::tudo();
		$cor  = sanitize_hex_color( $tudo['COR'] );
		if ( ! $cor ) {
			$padroes = self::padroes();
			$cor     = $padroes['COR'];
		}
		return $cor;
	}

	public static function saudacao() {
		$tudo = self::tudo();
		return trim( (string) $tudo['SAUDACAO'] );
	}

	/**
	 * O texto do botão que abre a conversa.
	 *
	 * Vazio é resposta legítima, e não falta de resposta: sem rótulo o botão
	 * volta a ser o círculo com o ícone, que o CSS já desenha. Devolver o
	 * padrão aqui tornaria essa escolha impossível de fazer pelo painel.
	 */
	public static function rotulo() {
		$tudo = self::tudo();
		return trim( (string) $tudo['ROTULO'] );
	}

	public static function convite() {
		$tudo = self::tudo();
		return trim( (string) $tudo['CONVITE'] );
	}

	/**
	 * As perguntas de partida, como lista.
	 *
	 * No máximo três: são botões numa janela estreita, e a quarta quebra a
	 * linha e some do primeiro olhar — que é justamente o momento em que elas
	 * servem para alguma coisa.
	 */
	public static function sugestoes() {
		$tudo  = self::tudo();
		$linhas = preg_split( '/\R/', (string) $tudo['SUGESTOES'] );
		$saida = array();

		foreach ( (array) $linhas as $linha ) {
			$linha = trim( $linha );
			if ( '' !== $linha ) {
				$saida[] = $linha;
			}
		}

		return array_slice( $saida, 0, 3 );
	}

	public static function teto_diario() {
		$tudo = self::tudo();
		$teto = (int) $tudo['TETO_DIARIO'];
		return $teto > 0 ? $teto : (int) self::padroes()['TETO_DIARIO'];
	}

	/** 'selecao' ou 'inteira'. Ver Livia_Trechos. */
	public static function base_modo() {
		$tudo = self::tudo();
		$modo = strtolower( trim( (string) $tudo['BASE_MODO'] ) );
		return 'inteira' === $modo ? 'inteira' : 'selecao';
	}

	public static function cache_modo() {
		$tudo  = self::tudo();
		$modo  = strtolower( trim( (string) $tudo['CACHE_MODO'] ) );
		return in_array( $modo, array( 'auto', 'sempre', 'nunca' ), true ) ? $modo : 'auto';
	}

	/**
	 * Tem tudo que precisa para funcionar.
	 *
	 * Repare que o interruptor NÃO entra aqui: "configurado" e "ligado" são
	 * coisas diferentes. Misturá-los faria a tela de configuração acusar falta
	 * de chave quando o único problema é que alguém desligou a LivIA.
	 */
	public static function esta_configurado() {
		return '' !== self::api_key() && '' !== self::modelo() && Livia_Base::existe();
	}

	/** Configurada E ligada: é o que o widget e as rotas checam. */
	public static function pode_atender() {
		return self::esta_ativa() && self::esta_configurado();
	}

	/** "models/gemini-x" e "gemini-x" são a mesma coisa; guardamos sem o prefixo. */
	public static function normalizar_modelo( $modelo ) {
		$modelo = strtolower( trim( (string) $modelo ) );
		$modelo = preg_replace( '#^models/#', '', $modelo );
		return preg_replace( '/[^a-z0-9._\-]/', '', $modelo );
	}

	/**
	 * Sanitiza o formulário do admin. Chamado pela Settings API.
	 *
	 * Campo de chave em branco significa "mantenha a que está salva" — assim a
	 * chave nunca precisa ser reimpressa numa página do wp-admin para ser mantida.
	 */
	public static function sanitizar( $bruto ) {
		$atual      = self::tudo();
		$bruto      = is_array( $bruto ) ? $bruto : array();
		$padroes    = self::padroes();
		$padrao_cor = $padroes['COR'];

		$chave = isset( $bruto['GEMINI_API_KEY'] ) ? trim( sanitize_text_field( $bruto['GEMINI_API_KEY'] ) ) : '';
		if ( '' === $chave ) {
			$chave = $atual['GEMINI_API_KEY'];
		}

		$modelo = isset( $bruto['GEMINI_MODEL'] ) ? self::normalizar_modelo( $bruto['GEMINI_MODEL'] ) : '';
		if ( '' === $modelo ) {
			$modelo = $padroes['GEMINI_MODEL'];
		}

		$reserva = isset( $bruto['GEMINI_MODEL_RESERVA'] ) ? self::normalizar_modelo( $bruto['GEMINI_MODEL_RESERVA'] ) : '';

		// Reserva igual ao principal não é reserva. Guardar vazio deixa isso
		// explícito no painel em vez de fingir que há um plano B.
		if ( $reserva === $modelo ) {
			$reserva = '';
		}

		$ativa = ! empty( $bruto['ATIVA'] ) ? '1' : '0';

		// Texto puro nos três campos de voz: eles são impressos no widget, e
		// link markdown ou HTML no meio de um balão de chat sai como lixo.
		$nome     = isset( $bruto['NOME'] ) ? trim( sanitize_text_field( $bruto['NOME'] ) ) : '';
		$saudacao = isset( $bruto['SAUDACAO'] ) ? trim( sanitize_text_field( wp_strip_all_tags( $bruto['SAUDACAO'] ) ) ) : '';
		$convite  = isset( $bruto['CONVITE'] ) ? trim( sanitize_text_field( wp_strip_all_tags( $bruto['CONVITE'] ) ) ) : '';

		// Cabe dentro de um botão: passou de vinte e quatro caracteres, o botão
		// vira uma barra e deixa de parecer um botão.
		$rotulo = isset( $bruto['ROTULO'] ) ? trim( sanitize_text_field( wp_strip_all_tags( $bruto['ROTULO'] ) ) ) : '';
		$rotulo = function_exists( 'mb_substr' ) ? mb_substr( $rotulo, 0, 24 ) : substr( $rotulo, 0, 24 );

		// Campo de várias linhas: sanitize_text_field come a quebra, então é
		// linha a linha, e o que sobra é remontado com quebras de verdade.
		$sugestoes = array();
		if ( isset( $bruto['SUGESTOES'] ) ) {
			foreach ( (array) preg_split( '/\R/', (string) $bruto['SUGESTOES'] ) as $linha ) {
				$linha = trim( sanitize_text_field( wp_strip_all_tags( $linha ) ) );
				if ( '' !== $linha ) {
					$sugestoes[] = $linha;
				}
			}
		}

		$cor = isset( $bruto['COR'] ) ? sanitize_hex_color( $bruto['COR'] ) : '';
		if ( ! $cor ) {
			$cor = $padrao_cor;
		}

		$cache_modo = isset( $bruto['CACHE_MODO'] ) ? strtolower( trim( (string) $bruto['CACHE_MODO'] ) ) : 'auto';
		if ( ! in_array( $cache_modo, array( 'auto', 'sempre', 'nunca' ), true ) ) {
			$cache_modo = 'auto';
		}

		// Teto absurdo é erro de digitação, não intenção: 100 mil chamadas por
		// dia não existe em plano nenhum, e salvar isso desliga o disjuntor na
		// prática. Teto zero ou negativo desligaria a LivIA inteira.
		$teto_diario = isset( $bruto['TETO_DIARIO'] ) ? (int) $bruto['TETO_DIARIO'] : 0;
		if ( $teto_diario < 1 ) {
			// Campo em branco, zero ou texto volta ao padrão — nunca a "1".
			// Salvar teto 1 desligaria a LivIA depois da primeira mensagem do
			// dia, e quem digitou não saberia por quê.
			$teto_diario = (int) $padroes['TETO_DIARIO'];
		}
		$teto_diario = min( 50000, $teto_diario );

		$base_modo = isset( $bruto['BASE_MODO'] ) ? strtolower( trim( (string) $bruto['BASE_MODO'] ) ) : 'selecao';
		if ( ! in_array( $base_modo, array( 'selecao', 'inteira' ), true ) ) {
			$base_modo = 'selecao';
		}

		// Texto puro, sempre. A LivIA repete o canal exatamente como recebe, e
		// link markdown ou HTML no meio de um balão de chat sai como lixo.
		$canal = isset( $bruto['CANAL_DE_SUPORTE'] ) ? $bruto['CANAL_DE_SUPORTE'] : '';
		$canal = trim( sanitize_text_field( wp_strip_all_tags( $canal ) ) );

		Livia_Base::limpar_cache();
		delete_transient( self::DIAG );

		// Trocar de modelo invalida o rebaixamento em curso: ele falava de um
		// principal que não é mais o principal.
		if ( $modelo !== self::modelo() || $reserva !== self::modelo_reserva() ) {
			Livia_Modelos::restaurar();
		}

		return array(
			'GEMINI_API_KEY'       => $chave,
			'GEMINI_MODEL'         => $modelo,
			'GEMINI_MODEL_RESERVA' => $reserva,
			'CANAL_DE_SUPORTE'     => $canal,
			'ATIVA'                => $ativa,
			'CACHE_MODO'           => $cache_modo,
			'BASE_MODO'            => $base_modo,
			'TETO_DIARIO'          => (string) $teto_diario,
			'NOME'                 => '' !== $nome ? $nome : $padroes['NOME'],
			'COR'                  => $cor,
			'SAUDACAO'             => '' !== $saudacao ? $saudacao : $padroes['SAUDACAO'],
			'CONVITE'              => '' !== $convite ? $convite : $padroes['CONVITE'],
			// Sem `?: padrão`: aqui o vazio é escolha de quem quer o botão redondo.
			'ROTULO'               => $rotulo,
			'SUGESTOES'            => implode( "
", array_slice( $sugestoes, 0, 3 ) ),
		);
	}

	public static function salvar( array $valores ) {
		// autoload 'no': a chave não é carregada em toda requisição do site.
		return update_option( self::OPCAO, self::sanitizar( $valores ), false );
	}

	/** Roda na ativação e guarda um diagnóstico para a tela de configuração. */
	public static function ao_ativar() {
		delete_transient( self::DIAG );
		Livia_Base::limpar_cache();
	}
}
