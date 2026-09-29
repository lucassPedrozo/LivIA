<?php
/**
 * A base de conhecimento — o cérebro da LivIA.
 *
 * Mesmo arquivo, mesma leitura e mesma substituição que carregar_base() faz no
 * livia.py. Se as duas divergirem, os casos_de_teste.json param de significar
 * alguma coisa: eles testam o comportamento da base, não o da linguagem.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Base {

	const MARCADOR = '[CANAL_DE_SUPORTE]';
	const CACHE    = 'livia_base_cache';

	/** @var string|null memo por requisição */
	private static $memo = null;

	public static function caminho() {
		return apply_filters( 'livia_caminho_base', LIVIA_DIR . 'conhecimento/base_conhecimento.md' );
	}

	public static function existe() {
		$caminho = self::caminho();
		return is_string( $caminho ) && is_readable( $caminho ) && filesize( $caminho ) > 0;
	}

	/**
	 * A base com o canal de suporte já substituído.
	 *
	 * O contato mora na configuração, não espalhado pelo texto. Para trocar o
	 * WhatsApp da equipe, muda um campo — a base não é tocada.
	 *
	 * Quando o canal está vazio, o marcador é deixado literal, exatamente como
	 * o protótipo faz. A tela de configuração avisa; o comportamento não muda
	 * escondido.
	 *
	 * @return string|WP_Error
	 */
	public static function carregar() {
		if ( null !== self::$memo ) {
			return apply_filters( 'livia_base_conhecimento', self::$memo );
		}

		$caminho = self::caminho();
		if ( ! self::existe() ) {
			return new WP_Error(
				'base_ausente',
				sprintf( 'Não encontrei a base de conhecimento em %s.', $caminho )
			);
		}

		$canal      = Livia_Config::canal();
		$assinatura = md5( $caminho . '|' . filemtime( $caminho ) . '|' . filesize( $caminho ) . '|' . $canal );

		$cache = get_transient( self::CACHE );
		if ( is_array( $cache ) && isset( $cache['assinatura'], $cache['texto'] ) && $cache['assinatura'] === $assinatura ) {
			self::$memo = $cache['texto'];
			return apply_filters( 'livia_base_conhecimento', self::$memo );
		}

		$texto = file_get_contents( $caminho ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $texto || '' === trim( $texto ) ) {
			return new WP_Error( 'base_ilegivel', sprintf( 'A base em %s está vazia ou não pôde ser lida.', $caminho ) );
		}

		// Quebra de linha sempre LF, venha o arquivo do Windows ou do Linux.
		// O Python normaliza isso sozinho ao ler; o PHP nao. Sem esta linha, a
		// mesma base vira dois prompts diferentes: um CR a mais por linha, e
		// duas implementacoes que nao podem mais ser comparadas byte a byte.
		$texto = str_replace( array( "\r\n", "\r" ), "\n", $texto );

		if ( '' !== $canal ) {
			$texto = str_replace( self::MARCADOR, $canal, $texto );
		}

		set_transient( self::CACHE, array( 'assinatura' => $assinatura, 'texto' => $texto ), DAY_IN_SECONDS );
		self::$memo = $texto;

		return apply_filters( 'livia_base_conhecimento', $texto );
	}

	public static function limpar_cache() {
		self::$memo = null;
		delete_transient( self::CACHE );

		// A instrução guardada na API foi montada a partir desta base. Mudou a
		// base, o que está lá não vale mais.
		if ( class_exists( 'Livia_Cache' ) ) {
			Livia_Cache::esquecer();
		}
	}

	/** O marcador sobrou no texto? Então o cliente vai lê-lo literalmente. */
	public static function marcador_pendente() {
		$texto = self::carregar();
		if ( is_wp_error( $texto ) ) {
			return false;
		}
		return false !== strpos( $texto, self::MARCADOR );
	}

	/** Dados para a tela de configuração. */
	public static function info() {
		$caminho = self::caminho();
		if ( ! self::existe() ) {
			return array( 'ok' => false, 'caminho' => $caminho );
		}
		return array(
			'ok'         => true,
			'caminho'    => $caminho,
			'bytes'      => filesize( $caminho ),
			'modificado' => filemtime( $caminho ),
		);
	}
}
