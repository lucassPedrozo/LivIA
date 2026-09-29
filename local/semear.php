<?php
/**
 * Enche o registro local com duas semanas de conversas fictícias.
 *
 *   php local/semear.php
 *
 * Serve para ver o painel com movimento sem esperar cliente nenhum: as
 * perguntas são inventadas, as pessoas não existem e nenhum dado sai daqui.
 * Apaga o que havia antes, então rodar de novo dá sempre o mesmo painel.
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 'Rode por linha de comando.' );
}

$_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/wp-falso.php';
require __DIR__ . '/configurar.php';
require dirname( __DIR__ ) . '/livia/livia.php';

global $wpdb;
$tabela = $wpdb->prefix . 'livia_mensagens';
$wpdb->query( "DELETE FROM {$tabela}" );

mt_srand( 20260929 );

$paginas = array(
	42 => array( '/site-em-72h/', 'Briefing — Site em 72h', 'site-em-72h' ),
	43 => array( '/landing-page/', 'Briefing — Landing Page', 'landing-page' ),
	44 => array( '/site-gerenciavel/', 'Briefing — Site Gerenciável', 'site-gerenciavel' ),
);

// pergunta, resposta, origem, bloqueio, util
$falas = array(
	array( 'oi', 'Oi! Em que parte do briefing você travou?', 'atalho', null, null ),
	array( 'o que é domínio?', 'Domínio é o endereço do seu site na internet — o que a pessoa digita no navegador pra te achar.', 'modelo', null, 1 ),
	array( 'o que é dominio', 'Domínio é o endereço do seu site na internet — o que a pessoa digita no navegador pra te achar.', 'modelo', null, null ),
	array( 'quanto tempo demora?', 'No Site em 72h são até 72 horas a partir do material completo.', 'modelo', null, 1 ),
	array( 'não consigo anexar minhas fotos', 'Se forem muitas, junte tudo numa pasta compactada (.zip) e anexe de uma vez.', 'modelo', null, 1 ),
	array( 'posso mandar a logo depois?', 'Pode, sim. Avise no campo de observações que a logo vai depois.', 'modelo', null, null ),
	array( 'qual formulário eu uso?', 'Depende do que você contratou. Você já tem um site com a gente, ou é um site novo?', 'modelo', null, 1 ),
	array( 'o que preciso enviar?', 'O essencial é a logo, as fotos e os textos de cada página.', 'modelo', null, null ),
	array( 'quanto custa um site?', 'Valores quem passa é a equipe comercial — eles te respondem rapidinho.', 'modelo', null, null ),
	array( 'o que é hospedagem?', 'É o espaço onde os arquivos do site ficam guardados e funcionando.', 'modelo', null, null ),
	array( 'preciso ter as fotos prontas?', 'Não precisa. Você pode autorizar o uso de imagens de banco.', 'modelo', null, 0 ),
	array( 'vocês fazem loja virtual?', 'Pra essa eu não tenho a resposta aqui comigo, então prefiro não chutar.', 'modelo', 'valor fora da base', null ),
	array( 'obrigado', 'Por nada! Qualquer coisa, é só chamar.', 'atalho', null, null ),
	array( 'dá pra mudar o site depois de pronto?', 'Ajustes fazem parte do processo — a equipe te explica como funciona.', 'modelo', null, 0 ),
	array( 'o que colocar em sites de referência?', 'Sites que você gosta, mesmo de outro ramo, e o que te agrada em cada um.', 'modelo', null, 1 ),
);

$agora = time();
$n     = 0;

for ( $dia = 13; $dia >= 0; $dia-- ) {
	// Semana com ritmo de verdade: fim de semana cai, segunda sobe.
	$semana    = (int) gmdate( 'N', $agora - $dia * DAY_IN_SECONDS );
	$conversas = $semana >= 6 ? mt_rand( 1, 3 ) : mt_rand( 4, 9 );
	if ( 0 === $dia ) {
		$conversas = 5;
	}

	for ( $c = 0; $c < $conversas; $c++ ) {
		$sessao = bin2hex( random_bytes( 16 ) );
		$pid    = array( 42, 42, 43, 44 )[ mt_rand( 0, 3 ) ]; // o Site em 72h pesa o dobro
		$pag    = $paginas[ $pid ];
		$hora   = $agora - $dia * DAY_IN_SECONDS - mt_rand( 600, 9 * HOUR_IN_SECONDS );
		$turnos = mt_rand( 1, 4 );

		for ( $t = 0; $t < $turnos; $t++ ) {
			$f      = $falas[ mt_rand( 0, count( $falas ) - 1 ) ];
			$modelo = 'modelo' === $f[2];
			$wpdb->insert(
				$tabela,
				array(
					'sessao'         => $sessao,
					'criado_em'      => gmdate( 'Y-m-d H:i:s', $hora + $t * mt_rand( 30, 120 ) ),
					'pergunta'       => $f[0],
					'resposta'       => $f[1],
					'bloqueio'       => $f[3],
					'erro'           => null,
					'tokens_entrada' => $modelo ? mt_rand( 6400, 7900 ) : 0,
					'tokens_saida'   => $modelo ? mt_rand( 50, 110 ) : 0,
					'latencia_ms'    => $modelo ? mt_rand( 1800, 7200 ) : mt_rand( 3, 12 ),
					'modelo'         => $modelo ? 'gemini-3.5-flash-lite' : '',
					'truncada'       => 0,
					'streaming'      => $modelo ? 1 : 0,
					'origem'         => $f[2],
					'pagina_id'      => $pid,
					'pagina_url'     => $pag[0],
					'pagina_titulo'  => $pag[1],
					'formulario'     => $pag[2],
					'util'           => $f[4],
				)
			);
			++$n;
		}
	}
}

echo "{$n} mensagens fictícias em " . LIVIA_LOCAL_BANCO . PHP_EOL;
