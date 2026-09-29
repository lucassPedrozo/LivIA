<?php
/**
 * A trava anti-invenção — a terceira camada, e a única que é garantia.
 *
 * As duas primeiras (a instrução da base e a temperatura baixa) são pedido: o
 * modelo pode não atender. Esta roda fora do modelo, antes de a resposta chegar
 * ao cliente, e cobre os erros mais caros: passar um contato, um preço ou um
 * link que não existem — e despejar a própria base quando alguém pede.
 *
 * Porte de permitidos() e verificar_resposta() do livia.py, com regras a mais.
 * Tudo aqui é função pura: entra string, sai string. Não conhece o WordPress,
 * e é por isso que a suíte roda em milissegundos.
 */

defined( 'ABSPATH' ) || exit;

class Livia_Trava {

	/**
	 * Telefone. As âncoras (?<!\d) e (?!\d) impedem que uma corrida longa de
	 * dígitos — um protocolo de 15 números, por exemplo — seja fatiada num
	 * "telefone" que ninguém escreveu.
	 */
	const RE_TELEFONE = '/(?<!\d)\+?\d{0,3}[\s.\-]?\(?\d{2}\)?[\s.\-]?\d{4,5}[\s.\-]?\d{4}(?!\d)/';

	const RE_EMAIL = '/[\w.+\-]+@[\w\-]+\.[\w.\-]+/u';

	/**
	 * TLDs prováveis num texto em português. Extensão de arquivo não entra:
	 * "foto.jpg" e "arquivo.pdf" não podem ser confundidos com domínio.
	 */
	const TLDS = 'com\.br|org\.br|net\.br|gov\.br|edu\.br|com|net|org|app|dev|site|online|blog|shop|info';

	/** Numerais por extenso — "mil e quinhentos reais" é preço tanto quanto "R$ 1.500". */
	const NUMERAIS = 'zero|uma?|dois|duas|tres|quatro|cinco|seis|sete|oito|nove|dez|onze|doze|treze|quatorze|catorze|quinze|dezesseis|dezessete|dezoito|dezenove|vinte|trinta|quarenta|cinquenta|sessenta|setenta|oitenta|noventa|cem|cento|duzentos|trezentos|quatrocentos|quinhentos|seiscentos|setecentos|oitocentos|novecentos|mil|milhao|milhoes|bilhao|bilhoes';

	/**
	 * Quantas palavras seguidas, copiadas literalmente da parte de INSTRUÇÃO da
	 * base, denunciam despejo de prompt.
	 *
	 * Medido nas respostas reais do histórico contra a base em vigor: a maior
	 * cópia literal legítima ficou em 10 palavras, e o script mais longo que a
	 * base manda repetir encosta em 11. Um despejo de prompt copia centenas.
	 * 25 tem folga dos dois lados.
	 *
	 * Vale remedir sempre que a base mudar de tamanho — foi o que se fez quando
	 * ela passou de um formulário para cinco.
	 */
	const PALAVRAS_VAZAMENTO = 25;

	// ------------------------------------------------------------- utilitários

	private static function sem_acento( $texto ) {
		// Mapa explícito em vez de iconv(): iconv depende de locale e falha
		// diferente em cada servidor. Isto dá o mesmo resultado em toda parte.
		$de = array(
			'á', 'à', 'â', 'ã', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï',
			'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü', 'ç', 'ñ',
		);
		$para = array(
			'a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i',
			'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c', 'n',
		);
		return str_replace( $de, $para, $texto );
	}

	/** Minúsculas e sem acento: a forma em que dinheiro e vazamento são comparados. */
	private static function achatar( $texto ) {
		$baixo = function_exists( 'mb_strtolower' ) ? mb_strtolower( $texto, 'UTF-8' ) : strtolower( $texto );
		return self::sem_acento( $baixo );
	}

	/**
	 * A forma comparável de um e-mail.
	 *
	 * A regex termina em `[\w.\-]+`, que é guloso e engole a pontuação do fim
	 * da frase: "escreva para contato@exemplo.com.br." casa com o ponto final
	 * incluído. Com isso, o MESMO e-mail virava duas chaves diferentes conforme
	 * a posição na frase — e um endereço que estava na base era barrado por
	 * aparecer no fim de uma oração. Tirar a pontuação das pontas resolve os
	 * dois lados de uma vez, porque quem monta a lista e quem confere passam
	 * por aqui.
	 */
	private static function chave_email( $achado ) {
		return rtrim( self::achatar( $achado ), ".,;:!?)]}'\"" );
	}

	private static function digitos( $texto ) {
		return preg_replace( '/\D/', '', $texto );
	}

	/** Palavras normalizadas, para comparar cópia literal. */
	private static function palavras( $texto ) {
		preg_match_all( '/[a-z0-9]+/', self::achatar( $texto ), $m );
		return $m[0];
	}

	/**
	 * Chave de comparação de um valor: os dígitos, quando existem; senão o
	 * próprio texto achatado.
	 *
	 * É o que faz "R$ 1.500", "R$1.500,00" e "1.500,00" caírem na mesma chave.
	 * O livia.py comparava string literal e deixava as variações passarem.
	 */
	private static function chave_valor( $achado ) {
		$digitos = self::digitos( $achado );
		if ( '' !== $digitos ) {
			return $digitos;
		}
		return trim( preg_replace( '/\s+/', ' ', self::achatar( $achado ) ) );
	}

	private static function normalizar_url( $achado ) {
		$u = self::achatar( trim( $achado ) );
		$u = preg_replace( '#^https?://#', '', $u );
		$u = preg_replace( '#^www\.#', '', $u );
		return rtrim( $u, "/.,;:!?)*_" . chr( 96 ) . chr( 34 ) . chr( 39 ) );
	}

	// ------------------------------------------------------------- expressões

	/**
	 * Dinheiro e percentual rodam sobre o texto achatado (minúsculo e sem
	 * acento), então precisam do modificador "i": senão "R$" nunca casa, porque
	 * chega como "r$". Foi assim que "R$ 1.500" passou pela primeira versão.
	 */
	private static function re_dinheiro() {
		$n = self::NUMERAIS;
		return '/R\$\s*[\d.,]+'
			. '|(?<![\d.,])\d{1,3}(?:\.\d{3})*,\d{2}(?!\d)'
			. '|\b\d[\d.,]*\s*(?:reais|real)\b'
			. '|\b(?:' . $n . ')(?:\s+(?:e\s+)?(?:' . $n . '))*\s+(?:reais|real)\b'
			. '/i';
	}

	private static function re_percentual() {
		$n = self::NUMERAIS;
		return '/\b\d{1,3}\s*%'
			. '|\b\d{1,3}\s*por\s+cento\b'
			. '|\b(?:' . $n . ')(?:\s+e\s+(?:' . $n . '))*\s+por\s+cento\b'
			. '/i';
	}

	private static function re_url() {
		// Crase, aspas e asterisco nao fazem parte do endereco: sao a marcacao
		// que a base poe em volta dele.
		$fim = '/[^\\s<>()\\[\\]"`*]*';
		return '#(?:https?://)?(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+(?:' . self::TLDS . ')(?![a-z])(?:' . $fim . ')?#i';
	}

	// ------------------------------------------------------------- permitidos

	/**
	 * O que a LivIA PODE dizer: o que existe na base.
	 *
	 * Inclui os exemplos didáticos — (47) 99999-9999, contato@minhaempresa.com.br,
	 * padariadobairro.com.br — porque a base usa esses exemplos de propósito ao
	 * explicar os campos. Contato fora desta lista é invenção, por definição.
	 */
	public static function permitidos( $base ) {
		$achatada = self::achatar( $base );

		$telefones = array();
		if ( preg_match_all( self::RE_TELEFONE, $base, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$d = self::digitos( $achado );
				if ( strlen( $d ) >= 10 ) {
					$telefones[ substr( $d, -10 ) ] = true;
				}
			}
		}

		$emails = array();
		if ( preg_match_all( self::RE_EMAIL, $base, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$emails[ self::chave_email( $achado ) ] = true;
			}
		}

		$dinheiro = array();
		if ( preg_match_all( self::re_dinheiro(), $achatada, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$dinheiro[ self::chave_valor( $achado ) ] = true;
			}
		}

		$percentuais = array();
		if ( preg_match_all( self::re_percentual(), $achatada, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$percentuais[ self::chave_valor( $achado ) ] = true;
			}
		}

		$urls = array();
		if ( preg_match_all( self::re_url(), $base, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$urls[ self::normalizar_url( $achado ) ] = true;
			}
		}

		return array(
			'telefones'   => $telefones,
			'emails'      => $emails,
			'dinheiro'    => $dinheiro,
			'percentuais' => $percentuais,
			'urls'        => $urls,
			'titulos'     => self::titulos( $base ),
			'corpus'      => self::corpus_instrucao( $base ),
		);
	}

	/**
	 * Acrescenta à lista o que o PRÓPRIO CLIENTE escreveu nesta conversa.
	 *
	 * Na homologação a trava cortou uma resposta no meio de `padariaaurora.com.br` —
	 * o domínio que o cliente tinha acabado de digitar, e que a LivIA estava
	 * repetindo para confirmar que anotou. Repetir o que a pessoa escreveu não é
	 * invenção, e é o movimento mais natural de um atendimento de briefing:
	 * "anotei aqui: contato@suaempresa.com.br, certo?".
	 *
	 * Três categorias entram, e duas ficam de fora, com motivo:
	 *
	 *   entram   telefone, e-mail, endereço de site — são exatamente os dados
	 *            que o briefing existe para coletar, e confirmá-los de volta é
	 *            o comportamento certo;
	 *
	 *   ficam    dinheiro e porcentagem. "Me cobraram 500" repetido de volta
	 *   fora     vira a LivIA falando de preço, que é o que ela nunca pode
	 *            fazer — nem repetindo. Um cliente que insista em ouvir um
	 *            valor da boca dela continua batendo na trava.
	 *
	 * Vale só para a conversa em que foi digitado: a lista é montada por pedido,
	 * a partir do histórico daquela sessão, e não sobrevive a ela.
	 *
	 * O corpus e os títulos NÃO são tocados. Eles são o detector de vazamento da
	 * instrução — somar a fala do cliente ali faria a LivIA repetir o cliente
	 * contar como se ela estivesse recitando o documento interno.
	 */
	public static function somar_do_cliente( array $permitidos, $falas ) {
		$falas = trim( (string) $falas );
		if ( '' === $falas ) {
			return $permitidos;
		}

		if ( preg_match_all( self::RE_TELEFONE, $falas, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$d = self::digitos( $achado );
				if ( strlen( $d ) >= 10 ) {
					$permitidos['telefones'][ substr( $d, -10 ) ] = true;
				}
			}
		}

		if ( preg_match_all( self::RE_EMAIL, $falas, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$permitidos['emails'][ self::chave_email( $achado ) ] = true;
			}
		}

		if ( preg_match_all( self::re_url(), $falas, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$permitidos['urls'][ self::normalizar_url( $achado ) ] = true;
			}
		}

		return $permitidos;
	}

	/**
	 * Títulos das seções da base, indexados pela forma normalizada.
	 *
	 * A comparação exige que a resposta reproduza a SINTAXE de título ("## ..."),
	 * não só as palavras. Sem isso a regra derrubaria resposta legítima: "os
	 * campos do formulário" é título de seção e também é uma frase que a LivIA
	 * diz o tempo todo. Ninguém escreve "##" dentro de um balão de chat — quem
	 * escreve está recitando o documento.
	 */
	private static function titulos( $base ) {
		$titulos = array();
		foreach ( preg_split( '/\R/', $base ) as $linha ) {
			if ( ! preg_match( '/^#{1,6}\s+(.+)$/', trim( $linha ), $m ) ) {
				continue;
			}
			$titulo = self::titulo_normalizado( $m[1] );
			if ( count( explode( ' ', $titulo ) ) >= 3 ) {
				$titulos[ $titulo ] = trim( $m[1] );
			}
		}
		return $titulos;
	}

	/** "## 2. REGRA MAIS IMPORTANTE — não invente" e "REGRA MAIS IMPORTANTE..." batem. */
	private static function titulo_normalizado( $texto ) {
		$texto = preg_replace( '/^\s*\d+\.\s*/', '', trim( $texto ) );
		return implode( ' ', self::palavras( $texto ) );
	}

	/**
	 * A parte da base que é INSTRUÇÃO, normalizada em palavras.
	 *
	 * As linhas de citação (começando com ">") ficam de fora de propósito: são
	 * exatamente os exemplos que a base manda a LivIA repetir. Copiar aquilo é
	 * o comportamento desejado; copiar a instrução é despejo de prompt.
	 */
	private static function corpus_instrucao( $base ) {
		$linhas = array();
		foreach ( preg_split( '/\R/', $base ) as $linha ) {
			$limpa = trim( $linha );
			if ( '' === $limpa || 0 === strpos( $limpa, '>' ) ) {
				continue;
			}
			$linhas[] = $linha;
		}
		return ' ' . implode( ' ', self::palavras( implode( "\n", $linhas ) ) ) . ' ';
	}

	// ------------------------------------------------------------- verificação

	/**
	 * Devolve o motivo do bloqueio, ou null se a resposta pode ser mostrada.
	 *
	 * @return string|null
	 */
	public static function verificar( $resposta, array $ok ) {
		$achatada = self::achatar( $resposta );

		if ( preg_match_all( self::RE_TELEFONE, $resposta, $m ) ) {
			foreach ( $m[0] as $achado ) {
				$d = self::digitos( $achado );
				if ( strlen( $d ) >= 10 && ! isset( $ok['telefones'][ substr( $d, -10 ) ] ) ) {
					return 'telefone que não está na base: ' . trim( $achado );
				}
			}
		}

		if ( preg_match_all( self::RE_EMAIL, $resposta, $m ) ) {
			foreach ( $m[0] as $achado ) {
				if ( ! isset( $ok['emails'][ self::chave_email( $achado ) ] ) ) {
					return 'e-mail que não está na base: ' . $achado;
				}
			}
		}

		if ( preg_match_all( self::re_dinheiro(), $achatada, $m ) ) {
			foreach ( $m[0] as $achado ) {
				if ( ! isset( $ok['dinheiro'][ self::chave_valor( $achado ) ] ) ) {
					return 'valor em dinheiro que não está na base: ' . trim( $achado );
				}
			}
		}

		if ( preg_match_all( self::re_percentual(), $achatada, $m ) ) {
			foreach ( $m[0] as $achado ) {
				if ( ! isset( $ok['percentuais'][ self::chave_valor( $achado ) ] ) ) {
					return 'percentual que não está na base: ' . trim( $achado );
				}
			}
		}

		if ( preg_match_all( self::re_url(), $resposta, $m ) ) {
			foreach ( $m[0] as $achado ) {
				if ( ! isset( $ok['urls'][ self::normalizar_url( $achado ) ] ) ) {
					return 'endereço de site que não está na base: ' . trim( $achado );
				}
			}
		}

		return self::detectar_vazamento( $resposta, $ok );
	}

	/** A resposta está recitando a base em vez de responder ao cliente? */
	private static function detectar_vazamento( $resposta, array $ok ) {
		// 1. Reproduziu uma linha de título do documento, com "##" e tudo.
		foreach ( preg_split( '/\R/', $resposta ) as $linha ) {
			if ( ! preg_match( '/^#{1,6}\s+(.+)$/', trim( $linha ), $m ) ) {
				continue;
			}
			$titulo = self::titulo_normalizado( $m[1] );
			if ( isset( $ok['titulos'][ $titulo ] ) ) {
				return 'recitou um título da base: ' . $ok['titulos'][ $titulo ];
			}
		}

		// 2. Copiou um bloco longo da instrução, com ou sem formatação.
		$palavras = self::palavras( $resposta );
		$k        = self::PALAVRAS_VAZAMENTO;
		$n = count( $palavras );
		if ( $n < $k || '' === trim( $ok['corpus'] ) ) {
			return null;
		}

		for ( $i = 0; $i + $k <= $n; $i++ ) {
			$trecho = ' ' . implode( ' ', array_slice( $palavras, $i, $k ) ) . ' ';
			if ( false !== strpos( $ok['corpus'], $trecho ) ) {
				return sprintf( 'copiou %d palavras seguidas da instrução da base', $k );
			}
		}

		return null;
	}

	/** O que dizer quando a resposta do modelo foi barrada. */
	public static function resposta_segura( $canal ) {
		$canal   = trim( (string) $canal );
		$contato = '' !== $canal ? $canal : 'a nossa equipe de atendimento';

		return "Prefiro não te passar essa informação para não correr o risco de estar errada.\n"
			. "Quem confirma isso com segurança é {$contato}.\n\n"
			. 'Se for dúvida sobre o que preencher no formulário, pode me perguntar que eu ajudo.';
	}
}
