<?php
/**
 * A janela retida — a trava funcionando durante o streaming.
 *
 * O conflito: a trava foi desenhada para examinar a resposta INTEIRA antes de
 * mostrar qualquer coisa; streaming entrega pedaço por pedaço. Ingenuamente
 * combinados, o telefone inventado aparece no balão do cliente e só depois é
 * retirado — exatamente o erro que a trava existe para impedir.
 *
 * A solução: só é liberado para a tela o texto que já está a RETENCAO bytes da
 * ponta, e a cada pedaço que chega a trava varre o acumulado inteiro.
 *
 * Por que isso basta: telefone, e-mail, valor, percentual e URL são padrões
 * curtos — nenhum chega perto de 120 bytes. Uma ocorrência qualquer, no momento
 * em que fica completa no acumulado, termina necessariamente depois do ponto
 * que já foi emitido. Ou seja: um contato inventado NUNCA sai inteiro para o
 * cliente.
 *
 * A regra de vazamento (25 palavras seguidas da instrução) é mais longa que a
 * janela e por isso é o único caso de melhor esforço: quando ela dispara, umas
 * poucas palavras já podem ter aparecido. O evento de bloqueio manda o widget
 * descartar o balão inteiro, então o que sobra na tela é nada — mas vale
 * registrar que a garantia forte é para contato, valor e endereço.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Stream {

	/**
	 * Quanto do fim fica retido, em bytes.
	 *
	 * Folgado sobre o maior padrão que a trava procura: telefone ~20, valor ~30,
	 * e-mail ~60, URL com caminho ~120.
	 */
	const RETENCAO = 120;

	/** @var array */
	private $permitidos;

	/** @var string tudo que a API mandou até agora */
	private $buffer = '';

	/** @var int quantos bytes já foram para a tela */
	private $emitido = 0;

	/** @var string|null motivo do bloqueio, quando houve */
	private $bloqueio = null;

	public function __construct( array $permitidos ) {
		$this->permitidos = $permitidos;
	}

	/**
	 * Recebe um pedaço da API e devolve o que pode ir para a tela agora.
	 *
	 * @return string|false false quando a trava barrou — a partir daí não sai
	 *                      mais nada e o widget descarta o que já mostrou.
	 */
	public function receber( $pedaco ) {
		if ( null !== $this->bloqueio ) {
			return false;
		}

		$this->buffer .= (string) $pedaco;

		$motivo = Livia_Trava::verificar( $this->buffer, $this->permitidos );
		if ( null !== $motivo ) {
			$this->bloqueio = $motivo;
			return false;
		}

		return $this->liberar( strlen( $this->buffer ) - self::RETENCAO );
	}

	/**
	 * Fim do stream: varre uma última vez e solta o que ficou retido.
	 *
	 * @return string|false
	 */
	public function finalizar() {
		if ( null !== $this->bloqueio ) {
			return false;
		}

		$motivo = Livia_Trava::verificar( $this->buffer, $this->permitidos );
		if ( null !== $motivo ) {
			$this->bloqueio = $motivo;
			return false;
		}

		return $this->liberar( strlen( $this->buffer ), true );
	}

	/**
	 * Corta em espaço em branco: nunca parte uma palavra no meio, e — como
	 * espaço é sempre um byte ASCII — nunca parte um caractere UTF-8 ao meio,
	 * que sairia como losango na tela.
	 */
	private function liberar( $limite, $ate_o_fim = false ) {
		$limite = min( $limite, strlen( $this->buffer ) );
		if ( $limite <= $this->emitido ) {
			return '';
		}

		if ( ! $ate_o_fim ) {
			$corte = $limite;
			while ( $corte > $this->emitido && ! ctype_space( $this->buffer[ $corte - 1 ] ) ) {
				--$corte;
			}
			if ( $corte <= $this->emitido ) {
				return ''; // nenhuma palavra inteira nova ainda
			}
			$limite = $corte;
		}

		$trecho        = substr( $this->buffer, $this->emitido, $limite - $this->emitido );
		$this->emitido = $limite;
		return $trecho;
	}

	public function bloqueio() {
		return $this->bloqueio;
	}

	public function texto_completo() {
		return $this->buffer;
	}
}
