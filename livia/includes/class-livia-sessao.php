<?php
/**
 * A conversa de um cliente.
 *
 * Sessão nativa do PHP está fora de questão: ela não sobrevive a cache de
 * página nem a mais de um servidor, e prende um arquivo de lock por requisição.
 * Aqui o identificador é opaco, vem do cliente num cabeçalho, e o histórico
 * mora num transient com prazo de validade.
 *
 * Sem cookie, de propósito. O widget fica numa página que pode estar em cache,
 * e um site brasileiro que não precisa pedir consentimento de cookie é um
 * problema a menos.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Sessao {

	const PREFIXO = 'livia_s_';

	/** Duas horas sem falar nada e a conversa se apaga sozinha. */
	const VALIDADE = 7200;

	/**
	 * Quantos turnos anteriores vão junto no pedido. Suficiente para entender
	 * "e o outro?" sem encher o pedido — e a cota. Mesmo valor do livia.py.
	 */
	const TURNOS_DE_MEMORIA = 6;

	/** Teto de mensagens numa conversa só. 20 idas e voltas é muito para um formulário. */
	const TETO_DE_TURNOS = 40;

	public static function novo_id() {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function id_valido( $id ) {
		return is_string( $id ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $id );
	}

	private static function chave( $id ) {
		return self::PREFIXO . $id;
	}

	/**
	 * O que está guardado da conversa: histórico e contexto.
	 *
	 * O contexto é de onde ela começou — qual página, qual formulário. Fica na
	 * sessão porque é capturado uma vez, na abertura, e vale para todas as
	 * mensagens seguintes sem o widget precisar reenviar a cada pergunta.
	 */
	private static function tudo( $id ) {
		if ( ! self::id_valido( $id ) ) {
			return array( 'historico' => array(), 'contexto' => array(), 'marcas' => array() );
		}
		$dados = get_transient( self::chave( $id ) );
		if ( ! is_array( $dados ) || ! isset( $dados['historico'] ) ) {
			return array( 'historico' => array(), 'contexto' => array(), 'marcas' => array() );
		}
		return array(
			'historico' => is_array( $dados['historico'] ) ? $dados['historico'] : array(),
			'contexto'  => isset( $dados['contexto'] ) && is_array( $dados['contexto'] ) ? $dados['contexto'] : array(),
			'marcas'    => isset( $dados['marcas'] ) && is_array( $dados['marcas'] ) ? $dados['marcas'] : array(),
		);
	}

	public static function historico( $id ) {
		$dados = self::tudo( $id );
		return $dados['historico'];
	}

	/** De onde a conversa começou: página, formulário. */
	public static function contexto( $id ) {
		$dados = self::tudo( $id );
		return $dados['contexto'];
	}

	/**
	 * As marcas da conversa: o que já aconteceu nela e não está no histórico.
	 *
	 * Hoje há uma só — se o contato da equipe já foi passado. Fica aqui, e não
	 * numa varredura do histórico a cada turno, porque a resposta pode ter sido
	 * cortada pela trava depois de gravada: quem sabe o que o cliente de fato
	 * leu é o momento do envio, não o texto guardado.
	 */
	public static function marcas( $id ) {
		$dados = self::tudo( $id );
		return $dados['marcas'];
	}

	public static function marcar( $id, $marca ) {
		$marcas = self::marcas( $id );
		if ( ! empty( $marcas[ $marca ] ) ) {
			return true;
		}
		$marcas[ $marca ] = true;
		return self::gravar( $id, self::historico( $id ), null, $marcas );
	}

	public static function tem_marca( $id, $marca ) {
		$marcas = self::marcas( $id );
		return ! empty( $marcas[ $marca ] );
	}

	public static function gravar( $id, array $historico, array $contexto = null, array $marcas = null ) {
		if ( ! self::id_valido( $id ) ) {
			return false;
		}
		// Null quer dizer "não mexe no que já está lá". Sem isto, cada
		// acrescentar() apagaria as marcas da conversa.
		if ( null === $contexto || null === $marcas ) {
			$antes = self::tudo( $id );
			if ( null === $contexto ) {
				$contexto = $antes['contexto'];
			}
			if ( null === $marcas ) {
				$marcas = $antes['marcas'];
			}
		}
		return set_transient(
			self::chave( $id ),
			array( 'historico' => $historico, 'contexto' => $contexto, 'marcas' => $marcas ),
			self::VALIDADE
		);
	}

	public static function definir_contexto( $id, array $contexto ) {
		return self::gravar( $id, self::historico( $id ), $contexto );
	}

	public static function acrescentar( $id, $papel, $texto ) {
		$historico   = self::historico( $id );
		$historico[] = array(
			'role'  => $papel,
			'parts' => array( array( 'text' => $texto ) ),
		);
		self::gravar( $id, $historico );
		return $historico;
	}

	/** Desfaz o último turno — usado quando a API falhou e não houve resposta. */
	public static function remover_ultimo( $id ) {
		$historico = self::historico( $id );
		array_pop( $historico );
		self::gravar( $id, $historico );
		return $historico;
	}

	public static function encerrada( $id ) {
		return count( self::historico( $id ) ) >= self::TETO_DE_TURNOS;
	}

	/**
	 * Os últimos turnos, sempre começando por uma fala do cliente.
	 *
	 * A API recusa uma conversa que comece pela fala do modelo, então a fatia é
	 * ajustada até cair num turno "user". Porte de janela(), do livia.py.
	 */
	public static function janela( array $historico ) {
		$recorte = array_slice( $historico, -self::TURNOS_DE_MEMORIA );
		while ( $recorte && 'user' !== $recorte[0]['role'] ) {
			array_shift( $recorte );
		}
		return array_values( $recorte );
	}
}
