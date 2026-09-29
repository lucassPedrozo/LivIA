<?php
/**
 * Qual modelo atende agora — e o que fazer quando ele para de atender.
 *
 * Sem isto, um 429 de cota esgotada custava até trinta segundos por pergunta:
 * o Livia_Gemini tentava três vezes, com recuo entre elas, contra um modelo que
 * não ia voltar tão cedo. O cliente via o balão de "digitando" parado. Era a
 * travada relatada em homologação.
 *
 * A ideia aqui é simples: cota esgotada não é falha transitória, é um estado.
 * Ao primeiro sinal de que o modelo principal caiu, a LivIA anota isso por uma
 * janela de tempo e passa a falar direto com a reserva. As perguntas seguintes
 * não pagam a espera da falha — vão certeiras para quem está de pé.
 *
 * A volta é automática: passada a janela, a próxima pergunta tenta o principal
 * de novo. Se ele voltou, a LivIA volta com ele; se não, rebaixa outra vez e
 * segue na reserva. Ninguém precisa mexer em nada.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Modelos {

	/** Enquanto durar, as perguntas vão direto para a reserva. */
	const REBAIXADO = 'livia_modelo_rebaixado';

	/**
	 * Quanto tempo na reserva antes de tentar o principal de novo.
	 *
	 * Dez minutos: cota por minuto se recupera bem antes disso, e cota por dia
	 * não se recupera de jeito nenhum — nos dois casos, tentar mais cedo só
	 * devolveria a espera ao cliente. Em compensação, nenhuma queda passageira
	 * prende a LivIA na reserva pelo resto do dia.
	 */
	const DESCANSO = 600;

	/** A lista de modelos que a API oferece para esta chave. Muda pouco. */
	const CATALOGO = 'livia_catalogo_modelos';
	const CATALOGO_VALIDADE = 43200;

	/**
	 * Erros que significam "este modelo não vai responder agora".
	 *
	 * 429 é cota. 404 é modelo que deixou de existir — acontece, o Google
	 * descontinua ID sem aviso, e é justamente o caso em que insistir é inútil.
	 * 403 fica de fora: chave sem permissão para o principal provavelmente
	 * também não tem para a reserva, e rebaixar só mascararia o problema real.
	 */
	public static function vale_rebaixar( $codigo_erro ) {
		return in_array( (string) $codigo_erro, array( 'cota', 'modelo_inexistente' ), true );
	}

	public static function principal() {
		return Livia_Config::modelo();
	}

	public static function reserva() {
		return Livia_Config::modelo_reserva();
	}

	/** Existe reserva configurada, e ela é diferente do principal? */
	public static function tem_reserva() {
		$r = self::reserva();
		return '' !== $r && $r !== self::principal();
	}

	/**
	 * O modelo que deve atender esta pergunta.
	 *
	 * @return string
	 */
	public static function atual() {
		if ( self::tem_reserva() && self::rebaixado() ) {
			return self::reserva();
		}
		return self::principal();
	}

	/** @return array|null dados do rebaixamento em curso */
	public static function rebaixado() {
		$r = get_transient( self::REBAIXADO );
		if ( ! is_array( $r ) || empty( $r['ate'] ) || $r['ate'] <= time() ) {
			return null;
		}
		return $r;
	}

	/**
	 * Marca o principal como fora do ar e passa a usar a reserva.
	 *
	 * Sem reserva configurada não há para onde ir — nesse caso não adianta
	 * anotar nada, e o erro segue para o cliente como sempre seguiu.
	 *
	 * @return string|null o modelo que assume, ou null se não há para onde ir
	 */
	public static function rebaixar( $motivo ) {
		if ( ! self::tem_reserva() ) {
			return null;
		}

		$ja = self::rebaixado();

		set_transient(
			self::REBAIXADO,
			array(
				'modelo' => self::principal(),
				'motivo' => (string) $motivo,
				'desde'  => $ja && ! empty( $ja['desde'] ) ? (int) $ja['desde'] : time(),
				'ate'    => time() + self::DESCANSO,
			),
			self::DESCANSO
		);

		// Só avisa na virada, não a cada pergunta da janela inteira: a equipe
		// precisa saber que trocou, não receber um e-mail por cliente.
		if ( ! $ja ) {
			do_action( 'livia_modelo_rebaixado', self::principal(), self::reserva(), $motivo );
		}

		return self::reserva();
	}

	/** O principal voltou (ou alguém quer forçar a volta). */
	public static function restaurar() {
		delete_transient( self::REBAIXADO );
	}

	/**
	 * Os modelos que esta chave pode usar, direto da API.
	 *
	 * É o que alimenta o seletor do painel. Digitar o ID à mão era a origem de
	 * um 404 em toda resposta, para todo cliente, descoberto só no atendimento.
	 *
	 * @param bool $forcar ignora o que está guardado e pergunta de novo
	 * @return array|WP_Error lista de arrays com id, nome e entrada
	 */
	public static function disponiveis( $forcar = false ) {
		if ( ! $forcar ) {
			$guardado = get_transient( self::CATALOGO );
			if ( is_array( $guardado ) ) {
				return $guardado;
			}
		}

		$curto = apply_filters( 'livia_pre_listar_modelos', null );
		if ( null !== $curto ) {
			return $curto;
		}

		$chave = Livia_Config::api_key();
		if ( '' === $chave ) {
			return new WP_Error( 'sem_chave', 'Falta a chave da API.' );
		}

		$resposta = wp_remote_get(
			Livia_Gemini::ENDPOINT . '/models?pageSize=200',
			array(
				'timeout' => 15,
				'headers' => array( 'X-goog-api-key' => $chave ),
			)
		);

		if ( is_wp_error( $resposta ) ) {
			return new WP_Error( 'conexao', 'Não consegui falar com a API: ' . $resposta->get_error_message() );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resposta );
		if ( 200 !== $codigo ) {
			return new WP_Error( 'http', sprintf( 'A API respondeu HTTP %d ao listar os modelos.', $codigo ) );
		}

		$dados = json_decode( wp_remote_retrieve_body( $resposta ), true );
		if ( ! isset( $dados['models'] ) || ! is_array( $dados['models'] ) ) {
			return new WP_Error( 'resposta_ilegivel', 'A API respondeu algo que não é uma lista de modelos.' );
		}

		$lista = array();
		foreach ( $dados['models'] as $m ) {
			if ( empty( $m['name'] ) ) {
				continue;
			}

			// Só serve modelo que gera texto. A lista traz também os de
			// embedding e os de imagem, que dariam erro se fossem escolhidos.
			$metodos = isset( $m['supportedGenerationMethods'] ) ? (array) $m['supportedGenerationMethods'] : array();
			if ( ! in_array( 'generateContent', $metodos, true ) ) {
				continue;
			}

			$id = Livia_Config::normalizar_modelo( $m['name'] );
			$lista[] = array(
				'id'        => $id,
				'nome'      => isset( $m['displayName'] ) ? (string) $m['displayName'] : $id,
				'entrada'   => isset( $m['inputTokenLimit'] ) ? (int) $m['inputTokenLimit'] : 0,
				'streaming' => in_array( 'streamGenerateContent', $metodos, true ),
			);
		}

		usort(
			$lista,
			function ( $a, $b ) {
				return strcmp( $a['id'], $b['id'] );
			}
		);

		set_transient( self::CATALOGO, $lista, self::CATALOGO_VALIDADE );
		return $lista;
	}

	/**
	 * Só o que já está guardado — nunca vai à rede.
	 *
	 * É o que a tela de configuração usa ao abrir. Buscar a lista no meio do
	 * carregamento da página deixaria o wp-admin pendurado até quinze segundos
	 * sempre que a API estivesse lenta, e para mostrar um <select> que ninguém
	 * pediu naquele instante. Quem quiser a lista fresca clica no botão.
	 *
	 * @return array|null null quando ainda não se perguntou à API
	 */
	public static function guardados() {
		$guardado = get_transient( self::CATALOGO );
		return is_array( $guardado ) ? $guardado : null;
	}

	public static function esquecer_catalogo() {
		delete_transient( self::CATALOGO );
	}

	/**
	 * Os modelos a tentar, na ordem, para UMA pergunta.
	 *
	 * Normalmente é [principal, reserva]. Se o principal já está rebaixado, é só
	 * [reserva] — não faz sentido pagar de novo a espera de quem já se sabe fora.
	 *
	 * @return string[]
	 */
	public static function cadeia() {
		$atual  = self::atual();
		$cadeia = array( $atual );

		if ( self::tem_reserva() && $atual !== self::reserva() ) {
			$cadeia[] = self::reserva();
		}

		return $cadeia;
	}

	/** Para o painel e a rota de saúde. */
	public static function estado() {
		$r = self::rebaixado();
		return array(
			'principal'   => self::principal(),
			'reserva'     => self::reserva(),
			'em_uso'      => self::atual(),
			'na_reserva'  => (bool) $r,
			'motivo'      => $r ? $r['motivo'] : null,
			'volta_em'    => $r ? max( 0, $r['ate'] - time() ) : 0,
		);
	}
}
