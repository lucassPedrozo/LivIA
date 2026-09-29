<?php
/**
 * Leitor de Server-Sent Events.
 *
 * A API de streaming devolve eventos separados por linha em branco, cada um
 * numa linha "data: {json}". O cURL entrega bytes em pedaços arbitrários, que
 * cortam linha no meio sem cerimônia — então este acumulador guarda o resto e
 * só devolve evento inteiro.
 *
 * Sem estado do WordPress: é o que permite testá-lo com bytes de mentira.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Sse {

	private $resto = '';

	/**
	 * Consome bytes e devolve os eventos que ficaram completos.
	 *
	 * @return array lista de arrays decodificados
	 */
	public function receber( $bytes ) {
		$this->resto .= $bytes;
		$eventos      = array();

		// Só processa até a última quebra de linha: o que vier depois está
		// incompleto e espera o próximo pedaço.
		$corte = strrpos( $this->resto, "\n" );
		if ( false === $corte ) {
			return $eventos;
		}

		$pronto      = substr( $this->resto, 0, $corte + 1 );
		$this->resto = substr( $this->resto, $corte + 1 );

		foreach ( preg_split( '/\R/', $pronto ) as $linha ) {
			$linha = trim( $linha );
			if ( '' === $linha || 0 !== strpos( $linha, 'data:' ) ) {
				continue;
			}

			$carga = trim( substr( $linha, 5 ) );
			if ( '' === $carga || '[DONE]' === $carga ) {
				continue;
			}

			$dados = json_decode( $carga, true );
			if ( is_array( $dados ) ) {
				$eventos[] = $dados;
			}
		}

		return $eventos;
	}

	/** O texto que veio neste evento. */
	public static function texto_do_evento( array $evento ) {
		if ( ! isset( $evento['candidates'][0]['content']['parts'] ) ) {
			return '';
		}
		$texto = '';
		foreach ( $evento['candidates'][0]['content']['parts'] as $parte ) {
			if ( isset( $parte['text'] ) ) {
				$texto .= $parte['text'];
			}
		}
		return $texto;
	}

	public static function finish_do_evento( array $evento ) {
		return isset( $evento['candidates'][0]['finishReason'] ) ? $evento['candidates'][0]['finishReason'] : '';
	}

	public static function uso_do_evento( array $evento ) {
		if ( ! isset( $evento['usageMetadata'] ) ) {
			return null;
		}
		$u = $evento['usageMetadata'];
		return array(
			'entrada' => isset( $u['promptTokenCount'] ) ? (int) $u['promptTokenCount'] : 0,
			'saida'   => isset( $u['candidatesTokenCount'] ) ? (int) $u['candidatesTokenCount'] : 0,
			'total'   => isset( $u['totalTokenCount'] ) ? (int) $u['totalTokenCount'] : 0,
		);
	}

	/** A pergunta foi barrada pela API antes de gerar qualquer coisa? */
	public static function bloqueio_do_evento( array $evento ) {
		if ( isset( $evento['promptFeedback']['blockReason'] ) ) {
			return (string) $evento['promptFeedback']['blockReason'];
		}
		$finish = self::finish_do_evento( $evento );
		if ( 'SAFETY' === $finish || 'PROHIBITED_CONTENT' === $finish ) {
			return $finish;
		}
		return '';
	}
}
