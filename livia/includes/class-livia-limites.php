<?php
/**
 * Defesa de cota.
 *
 * No terminal o único usuário era você. Na página do formulário o endpoint fica
 * aberto para a internet, e a cota diária vira recurso disputado: um único
 * abusador tira a LivIA do ar para todos os clientes reais.
 *
 * São três defesas, cada uma cobrindo o que a outra não cobre:
 *
 *   teto de entrada  — barra a pergunta gigante ANTES de virar token
 *   limite de ritmo  — dois tetos: um por conversa, um por IP
 *   disjuntor        — garante que a cota do dia acabe com aviso, não no susto
 */

defined( 'ABSPATH' ) || exit;

class Livia_Limites {

	/** Caracteres. Dúvida sobre campo de formulário cabe folgado. */
	const TETO_ENTRADA = 500;

	/**
	 * Mensagens de uma MESMA conversa dentro da janela.
	 *
	 * Este é o limite que pega o uso desgovernado: um atendimento de verdade
	 * tem 3 a 6 perguntas, então doze em cinco minutos já é muita folga.
	 */
	const POR_JANELA_SESSAO = 12;

	/**
	 * Mensagens do mesmo IP dentro da janela — a rede de segurança contra
	 * script, não contra cliente.
	 *
	 * Fica bem mais alto de propósito. Operadora de celular põe muita gente
	 * atrás do mesmo IP (CGNAT), e a base inteira orienta a preencher o
	 * formulário pelo celular: um teto de doze por IP derrubaria três clientes
	 * reais conversando ao mesmo tempo pela mesma operadora.
	 */
	const POR_JANELA_IP = 40;

	/** Tamanho da janela, em segundos. */
	const JANELA = 300;

	/** Sessões novas por IP por hora. Também generoso, pelo mesmo motivo. */
	const SESSOES_POR_HORA = 30;

	/**
	 * Chamadas à API por dia, por MODELO, antes de o disjuntor abrir.
	 *
	 * Duzentas é um padrão conservador, não um número da API. O plano free do
	 * Gemini tem um teto de requisições por dia que muda por modelo e muda com o
	 * tempo, então o valor certo é o que estiver na documentação do modelo que
	 * você escolheu — e é por isso que ele é editável na tela de configuração,
	 * em vez de viver aqui dentro.
	 *
	 * Por modelo, e não no total, porque a cota do plano free é por modelo:
	 * com uma reserva configurada são duas cotas, e um contador só somando as
	 * duas pararia a LivIA com metade da capacidade sobrando.
	 */
	const TETO_DIARIO = 200;

	/**
	 * O IP de quem está perguntando.
	 *
	 * Só REMOTE_ADDR por padrão. Confiar em X-Forwarded-For sem proxy na frente
	 * é o mesmo que não ter limite nenhum: o cabeçalho é escrito pelo cliente, e
	 * quem quiser burlar troca de "IP" a cada requisição.
	 *
	 * Atrás de Cloudflare ou de um proxy reverso, declare qual cabeçalho vale:
	 *   define( 'LIVIA_HEADER_IP', 'HTTP_CF_CONNECTING_IP' );
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		if ( defined( 'LIVIA_HEADER_IP' ) ) {
			$cabecalho = (string) constant( 'LIVIA_HEADER_IP' );
			if ( ! empty( $_SERVER[ $cabecalho ] ) ) {
				$partes = explode( ',', (string) $_SERVER[ $cabecalho ] );
				$ip     = trim( $partes[0] );
			}
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Os tetos passam por filtro para que a homologação possa afrouxá-los.
	 *
	 * A bateria de comportamento (testar.py) precisa de uma conversa nova por
	 * caso — são 34 — e bate no teto de 30 sessões por hora. O certo não é
	 * baixar a defesa em produção: é a homologação declarar que ali os números
	 * são outros. Veja livia/tests/e2e/livia-staging.php.
	 */
	private static function teto( $nome, $padrao ) {
		return (int) apply_filters( 'livia_teto_' . $nome, $padrao );
	}

	public static function teto_diario() {
		return max( 1, self::teto( 'diario', Livia_Config::teto_diario() ) );
	}

	/**
	 * Contador de janela fixa. Duas requisições no mesmo milissegundo podem
	 * contar como uma — é aceitável: erra por um, não por mil, e não vale um
	 * lock por mensagem.
	 */
	private static function contar( $chave, $validade ) {
		$atual = (int) get_transient( $chave );
		++$atual;
		set_transient( $chave, $atual, $validade );
		return $atual;
	}

	private static function ler( $chave ) {
		return (int) get_transient( $chave );
	}

	// ----------------------------------------------------------- teto de entrada

	/**
	 * A pergunta tem tamanho de pergunta?
	 *
	 * @return true|WP_Error
	 */
	public static function pergunta_aceitavel( $texto ) {
		$texto = trim( (string) $texto );

		if ( '' === $texto ) {
			return new WP_Error( 'vazia', 'Escreva a sua dúvida que eu te ajudo.' );
		}

		$tamanho = function_exists( 'mb_strlen' ) ? mb_strlen( $texto, 'UTF-8' ) : strlen( $texto );
		if ( $tamanho > self::TETO_ENTRADA ) {
			return new WP_Error(
				'longa',
				sprintf(
					'Essa ficou comprida demais para eu ler de uma vez. Tenta resumir em até %d caracteres?',
					self::TETO_ENTRADA
				)
			);
		}

		return true;
	}

	// ----------------------------------------------------------- limite por IP

	private static function chave_ip( $prefixo, $bucket ) {
		return $prefixo . substr( md5( self::ip() ), 0, 12 ) . '_' . $bucket;
	}

	/**
	 * Dois tetos, cada um para uma coisa: o da sessão pega o uso desgovernado
	 * de uma pessoa; o do IP pega script. Separá-los é o que permite apertar um
	 * sem punir quem divide IP com meio bairro.
	 *
	 * @return true|WP_Error
	 */
	public static function pode_perguntar( $sessao = '' ) {
		$bucket = (int) floor( time() / self::JANELA );
		$respiro = new WP_Error(
			'muitas_perguntas',
			"Você mandou muitas perguntas seguidas e eu preciso de um respiro.\nEspere um minutinho e continue."
		);

		if ( '' !== (string) $sessao ) {
			$chave_sessao = 'livia_rs_' . substr( (string) $sessao, 0, 12 ) . '_' . $bucket;
			if ( self::ler( $chave_sessao ) >= self::teto( 'por_sessao', self::POR_JANELA_SESSAO ) ) {
				return $respiro;
			}
			self::contar( $chave_sessao, self::JANELA * 2 );
		}

		$chave_ip = self::chave_ip( 'livia_rl_', $bucket );
		if ( self::ler( $chave_ip ) >= self::teto( 'por_ip', self::POR_JANELA_IP ) ) {
			return $respiro;
		}
		self::contar( $chave_ip, self::JANELA * 2 );

		return true;
	}

	/** @return true|WP_Error */
	public static function pode_abrir_sessao() {
		$bucket = (int) floor( time() / HOUR_IN_SECONDS );
		$chave  = self::chave_ip( 'livia_ss_', $bucket );

		if ( self::ler( $chave ) >= self::teto( 'sessoes_por_hora', self::SESSOES_POR_HORA ) ) {
			return new WP_Error( 'muitas_sessoes', 'Muitas conversas abertas daqui. Tente de novo mais tarde.' );
		}

		self::contar( $chave, HOUR_IN_SECONDS * 2 );
		return true;
	}

	// ----------------------------------------------------------- disjuntor

	private static function chave_dia( $modelo = null ) {
		$modelo = null === $modelo ? Livia_Modelos::atual() : (string) $modelo;
		return 'livia_dia_' . gmdate( 'Ymd' ) . '_' . substr( md5( $modelo ), 0, 8 );
	}

	/** Chamadas de hoje contra um modelo. Sem argumento, o que está em uso. */
	public static function usadas_hoje( $modelo = null ) {
		return self::ler( self::chave_dia( $modelo ) );
	}

	/** Somando a cadeia inteira: é a capacidade que ainda existe hoje. */
	public static function restantes_hoje() {
		$teto  = self::teto_diario();
		$sobra = 0;
		foreach ( Livia_Modelos::cadeia() as $modelo ) {
			$sobra += max( 0, $teto - self::usadas_hoje( $modelo ) );
		}
		return $sobra;
	}

	/**
	 * O modelo em uso ainda tem cota hoje?
	 *
	 * Separado do disjuntor porque as duas perguntas são diferentes: esta decide
	 * se vale trocar de modelo, aquela decide se acabou para todo mundo.
	 */
	public static function modelo_esgotado( $modelo = null ) {
		return self::usadas_hoje( $modelo ) >= self::teto_diario();
	}

	/**
	 * Já batemos o teto do dia?
	 *
	 * Com o disjuntor aberto a LivIA para de chamar a API e passa a encaminhar
	 * todo mundo para o atendimento. Degradar assim é o ponto: sem isso, o
	 * comportamento em cota cheia é repetir "estou com muitas conversas" até
	 * meia-noite, sem ninguém ficar sabendo.
	 */
	public static function disjuntor_aberto() {
		return 0 === self::restantes_hoje();
	}

	/**
	 * No ritmo de hoje, a cota do dia dá até quando?
	 *
	 * Uma regra de três sobre o dia em UTC, que é o fuso em que o contador
	 * zera. Deliberadamente ingênua: supõe que o resto do dia terá o mesmo
	 * movimento das horas já corridas. Serve para responder "vou bater no teto
	 * hoje?" com antecedência, não para prever o futuro — e por isso devolve
	 * também os números crus, para quem quiser discordar da conta.
	 *
	 * Antes das duas primeiras horas do dia não estima nada: com quinze minutos
	 * de amostra, uma rajada de três clientes projetaria um teto inexistente.
	 *
	 * @return array
	 */
	public static function previsao() {
		$teto   = self::teto_diario();
		$usadas = 0;
		foreach ( Livia_Modelos::cadeia() as $modelo ) {
			$usadas += self::usadas_hoje( $modelo );
		}
		$teto = $teto * count( Livia_Modelos::cadeia() );

		$decorrido = (int) gmdate( 'H' ) * HOUR_IN_SECONDS + (int) gmdate( 'i' ) * MINUTE_IN_SECONDS;
		$decorrido = max( 1, $decorrido );

		$base = array(
			'usadas'     => $usadas,
			'teto'       => $teto,
			'restantes'  => self::restantes_hoje(),
			'por_hora'   => $decorrido >= HOUR_IN_SECONDS ? round( $usadas / ( $decorrido / HOUR_IN_SECONDS ), 1 ) : null,
			'projetado'  => null,
			'estoura_em' => null,
			'confiavel'  => $decorrido >= 2 * HOUR_IN_SECONDS && $usadas > 0,
		);

		if ( ! $base['confiavel'] ) {
			return $base;
		}

		$ritmo = $usadas / $decorrido;
		$base['projetado'] = (int) round( $ritmo * DAY_IN_SECONDS );

		if ( $base['projetado'] > $teto ) {
			// Segundos até a conta bater no teto, contados de agora.
			$faltam = ( $teto - $usadas ) / $ritmo;
			$base['estoura_em'] = (int) max( 0, round( $faltam ) );
		}

		return $base;
	}

	/** Conta ANTES da chamada: se contasse depois, uma rajada passaria inteira. */
	public static function registrar_chamada() {
		$total = self::contar( self::chave_dia(), 2 * DAY_IN_SECONDS );

		// Avisa no instante exato em que o disjuntor fecha. Disparar a cada
		// requisição barrada depois disso encheria a caixa de entrada de quem
		// deveria estar resolvendo o problema.
		if ( $total === self::teto_diario() ) {
			do_action( 'livia_disjuntor_abriu', $total );
		}

		return $total;
	}

	public static function mensagem_do_disjuntor( $canal ) {
		$contato = '' !== trim( (string) $canal ) ? trim( $canal ) : 'a nossa equipe';
		return "Hoje já conversei com bastante gente e preciso parar por aqui.\n"
			. "Para não te deixar sem resposta, fale direto com {$contato} — "
			. 'eles resolvem qualquer dúvida sobre o formulário.';
	}
}
