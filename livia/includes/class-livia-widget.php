<?php
/**
 * Coloca o widget numa página.
 *
 * Shortcode [livia] — assim a equipe escolhe onde ele entra, sem o plugin
 * adivinhar. Os arquivos só são carregados na página que tem o shortcode.
 *
 * O que sai daqui é uma âncora vazia: o widget inteiro é montado pelo
 * JavaScript, flutuando no canto da tela. O shortcode pode ficar em qualquer
 * lugar do conteúdo, porque o elemento se descola do fluxo da página.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Widget {

	public static function iniciar() {
		add_shortcode( 'livia', array( __CLASS__, 'render' ) );
	}

	public static function render( $atributos = array() ) {
		$atributos = shortcode_atts(
			array(
				// Rótulo do briefing, para o relatório por página ficar legível:
				// [livia formulario="site-em-72h"]
				'formulario' => '',
			),
			$atributos,
			'livia'
		);

		// Desligada ou sem configuração: nada é impresso. Um chat que aparece e
		// não responde é pior para o cliente do que chat nenhum.
		if ( ! Livia_Config::pode_atender() ) {
			return '';
		}

		// Duas âncoras na mesma página virariam dois widgets sobrepostos no
		// mesmo canto. Acontece quando alguém põe o shortcode no conteúdo e
		// também num bloco reutilizável.
		static $ja_saiu = false;
		if ( $ja_saiu ) {
			return '';
		}
		$ja_saiu = true;

		wp_enqueue_style( 'livia-widget', LIVIA_URL . 'public/livia-widget.css', array(), LIVIA_VERSAO );
		wp_enqueue_script( 'livia-widget', LIVIA_URL . 'public/livia-widget.js', array(), LIVIA_VERSAO, true );

		wp_localize_script( 'livia-widget', 'LiviaCfg', self::configuracao() );

		return sprintf(
			'<div data-livia data-pagina="%d" data-formulario="%s"></div>',
			(int) get_the_ID(),
			esc_attr( $atributos['formulario'] )
		);
	}

	/**
	 * O que o navegador precisa saber.
	 *
	 * Nenhum segredo aqui: endereço da API, textos e uma cor. A chave, os
	 * limites e o estado interno ficam do lado do servidor.
	 */
	private static function configuracao() {
		return array(
			'api'      => esc_url_raw( rest_url( Livia_Rest::NAMESPACE_API . '/' ) ),
			'nome'     => Livia_Config::nome(),

			/**
			 * A linha de baixo do cabeçalho.
			 *
			 * "responde na hora" e não "assistente virtual": a LivIA não esconde
			 * o que é — se perguntarem, ela conta —, mas também não abre a
			 * conversa com um rótulo que ninguém pediu. Filtro para quem
			 * preferir ser explícito desde o primeiro segundo.
			 */
			'estado'   => apply_filters( 'livia_estado_visivel', 'responde na hora' ),

			'org'      => apply_filters( 'livia_organizacao', get_bloginfo( 'name' ) ),
			'cor'      => Livia_Config::cor(),
			'saudacao' => Livia_Config::saudacao(),
			'convite'  => Livia_Config::convite(),
			'rotulo'   => Livia_Config::rotulo(),

			/**
			 * Perguntas de partida, como botões abaixo da primeira fala.
			 *
			 * Uma caixa de texto vazia com "pergunte do seu jeito mesmo" não
			 * diz a ninguém o que dá para perguntar. Três exemplos concretos
			 * dizem — e somem no primeiro envio, para não virar mobília.
			 */
			'sugestoes' => Livia_Config::sugestoes(),

			/**
			 * Aviso de privacidade. A conversa é gravada (Livia_Registro), então
			 * a pessoa precisa saber — e é o que dispensa qualquer discussão
			 * sobre base legal depois. Devolver string vazia some com o aviso, e
			 * a responsabilidade passa a ser de quem tirou.
			 */
			'aviso'    => apply_filters(
				'livia_aviso_privacidade',
				'Conversa registrada. Não envie senha nem dado de cartão.'
			),
		);
	}
}
