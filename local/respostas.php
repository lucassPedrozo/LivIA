<?php
/**
 * Respostas de laboratório, no lugar do Gemini.
 *
 * Entram pelos mesmos filtros "pre_" que a suíte usa, então tudo o que vem
 * depois do modelo é o código de produção: a trava, a janela retida do
 * streaming, o registro. Os textos seguem a base — se um deles citasse um
 * contato que a base não tem, a trava cortaria aqui também.
 */

function livia_local_resposta( array $historico ) {
	$ultima = end( $historico );
	$texto  = '';
	if ( is_array( $ultima ) && isset( $ultima['parts'][0]['text'] ) ) {
		$texto = $ultima['parts'][0]['text'];
	}
	// O turno leva os trechos da base junto; a pergunta é só o que está entre
	// os marcadores do prompt.
	$abre = strpos( $texto, Livia_Prompt::ABRE );
	$fecha = strpos( $texto, Livia_Prompt::FECHA );
	if ( false !== $abre && false !== $fecha ) {
		$texto = substr( $texto, $abre + strlen( Livia_Prompt::ABRE ), $fecha - $abre - strlen( Livia_Prompt::ABRE ) );
	}
	$texto = strtr( mb_strtolower( $texto ), array( 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c' ) );

	$respostas = array(
		'dominio'    => "Domínio é o endereço do seu site na internet — o que a pessoa digita no navegador pra te achar.\n\nSe você ainda não tem um, tudo bem: escreva o nome que gostaria de usar e a palavra \"sugestão\" do lado. A equipe confere se ele está livre.",
		'tempo'      => "No Site em 72h são até 72 horas — mas o relógio começa quando o material completo chega até a equipe.\n\nO que mais atrasa não é a produção, é ficar esperando conteúdo. Então quanto mais completo você mandar de primeira, mais rápido sai.",
		'demora'     => "No Site em 72h são até 72 horas — mas o relógio começa quando o material completo chega até a equipe.\n\nO que mais atrasa não é a produção, é ficar esperando conteúdo. Então quanto mais completo você mandar de primeira, mais rápido sai.",
		'enviar'     => "O essencial é: a logo, as fotos que você quer no site e os textos de cada página.\n\nSe não tiver fotos boas, pode marcar que autoriza o uso de imagens de banco. E se faltar texto, escreva do seu jeito mesmo — a equipe revisa antes de publicar.",
		'foto'       => "Pode anexar direto no campo de arquivos, até 15 itens. Se forem muitas fotos, junte tudo numa pasta compactada (.zip).\n\nFoto tirada do celular serve, sim — só evite print de tela e imagem cortada.",
		'logo'       => "Pode mandar a logo no campo de arquivos, de preferência em PNG com fundo transparente.\n\nSe você ainda não tem logo, dá pra fazer o site sem: é só avisar isso no campo de observações.",
	);

	foreach ( $respostas as $gatilho => $resposta ) {
		if ( false !== strpos( $texto, $gatilho ) ) {
			return $resposta;
		}
	}

	return "Boa pergunta! Pra essa eu não tenho a resposta aqui comigo, então prefiro não chutar.\n\nA equipe consegue te ajudar direitinho — é só chamar no [CANAL].";
}

/** O canal vem da configuração, igual ao que a base faz com [CANAL_DE_SUPORTE]. */
function livia_local_com_canal( $texto ) {
	return str_replace( '[CANAL]', Livia_Config::canal(), $texto );
}

add_filter(
	'livia_pre_gerar',
	function ( $curto, $instrucao, $historico ) {
		$texto = livia_local_com_canal( livia_local_resposta( $historico ) );
		return array(
			'texto'    => $texto,
			'uso'      => array( 'entrada' => 6800 + wp_rand( 0, 900 ), 'saida' => 60 + wp_rand( 0, 40 ), 'total' => 0 ),
			'finish'   => 'STOP',
			'truncado' => false,
		);
	},
	10,
	3
);

add_filter(
	'livia_pre_gerar_stream',
	function ( $curto, $instrucao, $historico, $ao_pedaco ) {
		$texto = livia_local_com_canal( livia_local_resposta( $historico ) );

		// Pedaços de tamanho irregular, como a API manda: é o que exercita a
		// janela retida de verdade.
		$i = 0;
		while ( $i < strlen( $texto ) ) {
			$n = wp_rand( 12, 40 );
			if ( false === call_user_func( $ao_pedaco, substr( $texto, $i, $n ) ) ) {
				break;
			}
			$i += $n;
			usleep( 60000 );
		}

		return array(
			'texto'    => $texto,
			'uso'      => array( 'entrada' => 6800 + wp_rand( 0, 900 ), 'saida' => 60 + wp_rand( 0, 40 ), 'total' => 0 ),
			'finish'   => 'STOP',
			'truncado' => false,
			'abortado' => false,
		);
	},
	10,
	4
);
