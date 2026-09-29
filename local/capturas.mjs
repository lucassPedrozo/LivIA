// Gera as capturas do README a partir do ambiente local.
//
//   LIVIA_LABORATORIO=1 php -S localhost:8765 local/servidor.php   (num terminal)
//   php local/semear.php                                           (conversas fictícias)
//   node local/capturas.mjs                                        (as imagens)
//
// Usa o Chrome instalado, em modo headless, falando o protocolo do DevTools
// direto — sem Puppeteer nem Playwright, então não há nada para instalar. As
// imagens saem em assets/livia/, com dados fictícios e sem chave nenhuma.

import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, existsSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RAIZ = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const SAIDA = join( RAIZ, 'assets', 'livia' );
const BASE = process.env.LIVIA_LOCAL || 'http://localhost:8765';
const PORTA = 9333;

const CHROMES = [
	process.env.CHROME,
	'C:/Program Files/Google/Chrome/Application/chrome.exe',
	'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
	'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
	'/usr/bin/google-chrome',
	'/usr/bin/chromium',
].filter( Boolean );

const espera = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

async function abrirChrome() {
	const exe = CHROMES.find( ( c ) => existsSync( c ) );
	if ( ! exe ) {
		throw new Error( 'Chrome não encontrado. Defina CHROME=/caminho/do/chrome.' );
	}
	const perfil = mkdtempSync( join( tmpdir(), 'livia-capturas-' ) );
	const proc = spawn( exe, [
		'--headless=new',
		`--remote-debugging-port=${ PORTA }`,
		`--user-data-dir=${ perfil }`,
		'--hide-scrollbars',
		'--no-first-run',
		'--no-default-browser-check',
		'about:blank',
	], { stdio: 'ignore' } );

	for ( let i = 0; i < 50; i++ ) {
		try {
			const alvos = await ( await fetch( `http://127.0.0.1:${ PORTA }/json` ) ).json();
			const pagina = alvos.find( ( a ) => 'page' === a.type );
			if ( pagina ) {
				return { proc, ws: pagina.webSocketDebuggerUrl };
			}
		} catch ( e ) {}
		await espera( 200 );
	}
	throw new Error( 'O Chrome não abriu a porta de depuração.' );
}

function conectar( url ) {
	return new Promise( ( resolver ) => {
		const ws = new WebSocket( url );
		let id = 0;
		const pendentes = new Map();
		ws.onmessage = ( ev ) => {
			const m = JSON.parse( ev.data );
			if ( m.id && pendentes.has( m.id ) ) {
				const { ok, falha } = pendentes.get( m.id );
				pendentes.delete( m.id );
				m.error ? falha( new Error( m.error.message ) ) : ok( m.result );
			}
		};
		ws.onopen = () => resolver( {
			enviar( metodo, params = {} ) {
				return new Promise( ( ok, falha ) => {
					pendentes.set( ++id, { ok, falha } );
					ws.send( JSON.stringify( { id, method: metodo, params } ) );
				} );
			},
			fechar: () => ws.close(),
		} );
	} );
}

async function avaliar( cdp, js ) {
	const r = await cdp.enviar( 'Runtime.evaluate', { expression: js, awaitPromise: true, returnByValue: true } );
	if ( r.exceptionDetails ) {
		throw new Error( r.exceptionDetails.exception?.description || 'erro no JS da página' );
	}
	return r.result.value;
}

async function tela( cdp, largura, altura, escala, movel ) {
	await cdp.enviar( 'Emulation.setDeviceMetricsOverride', {
		width: largura, height: altura, deviceScaleFactor: escala, mobile: movel,
	} );
	await cdp.enviar( 'Emulation.setTouchEmulationEnabled', { enabled: movel } );
}

async function ir( cdp, caminho ) {
	await cdp.enviar( 'Page.navigate', { url: BASE + caminho } );
	await espera( 1200 );
}

// O widget lembra a conversa e se estava aberto no sessionStorage. Sem limpar
// antes de carregar, a captura seguinte herda a anterior — e o clique que
// deveria abrir o painel acaba fechando.
async function irLimpo( cdp, caminho ) {
	await ir( cdp, caminho );
	await avaliar( cdp, 'sessionStorage.clear()' );
	await ir( cdp, caminho );
}

async function fotografar( cdp, nome, clip = null ) {
	const params = { format: 'png' };
	if ( clip ) {
		params.clip = { ...clip, scale: 1 };
		params.captureBeyondViewport = true;
	}
	const { data } = await cdp.enviar( 'Page.captureScreenshot', params );
	writeFileSync( join( SAIDA, nome ), Buffer.from( data, 'base64' ) );
	console.log( '  ' + nome );
}

// Recorte da altura de uma tela, começando no título que contém o texto.
async function fotografarDesde( cdp, nome, titulo, largura, altura ) {
	const y = await avaliar( cdp, `(() => {
		const h = [ ...document.querySelectorAll( 'h2, h3' ) ].find( ( e ) => e.textContent.includes( ${ JSON.stringify( titulo ) } ) );
		return h ? Math.max( 0, h.getBoundingClientRect().top + scrollY - 24 ) : 0;
	})()` );
	await fotografar( cdp, nome, { x: 0, y, width: largura, height: altura } );
}

// Abre o widget e faz as perguntas como uma pessoa faria: digita, envia e
// espera a resposta terminar de aparecer (o polegar só surge no fim).
const CONVERSAR = ( perguntas ) => `(async () => {
	const q = ( s ) => document.querySelector( s );
	const dorme = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );
	q( '.livia-bolha' ).click();
	// A saudação é digitada com ritmo, e as sugestões só aparecem quando ela
	// termina. Perguntar antes disso cria uma ordem que ninguém real produz.
	for ( let i = 0; i < 60 && ! q( '.livia-sugestao' ); i++ ) {
		await dorme( 150 );
	}
	await dorme( 400 );
	for ( const p of ${ JSON.stringify( perguntas ) } ) {
		const antes = document.querySelectorAll( '.livia-opiniao' ).length;
		const campo = q( '.livia-campo' );
		campo.value = p;
		campo.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		q( '.livia-form' ).requestSubmit();
		for ( let i = 0; i < 120 && document.querySelectorAll( '.livia-opiniao' ).length <= antes; i++ ) {
			await dorme( 250 );
		}
		await dorme( 700 );
	}
	document.activeElement && document.activeElement.blur();
	return document.querySelectorAll( '.livia-opiniao' ).length;
})()`;

async function principal() {
	mkdirSync( SAIDA, { recursive: true } );

	const { proc, ws } = await abrirChrome();
	const cdp = await conectar( ws );
	await cdp.enviar( 'Page.enable' );
	await cdp.enviar( 'Runtime.enable' );

	try {
		console.log( 'capturas em ' + SAIDA );

		// 1. Desktop: a conversa aberta sobre o formulário.
		await tela( cdp, 1280, 800, 2, false );
		await irLimpo( cdp, '/' );
		await avaliar( cdp, CONVERSAR( [ 'o que é domínio?', 'quanto tempo demora?' ] ) );
		await fotografar( cdp, 'img1.png' );

		// 2. Celular: tela cheia, que é onde a maioria preenche o briefing.
		await tela( cdp, 390, 844, 3, true );
		await irLimpo( cdp, '/' );
		await avaliar( cdp, CONVERSAR( [ 'não consigo anexar minhas fotos' ] ) );
		await fotografar( cdp, 'img2.png' );

		// 3. O painel: as conversas, com o selo de cada problema.
		await tela( cdp, 1440, 900, 2, false );
		await ir( cdp, '/wp-admin/options-general.php?page=livia-relatorios' );
		await fotografar( cdp, 'img3.png' );

		// 4. O que não funcionou e o movimento dos últimos 14 dias.
		await fotografarDesde( cdp, 'img4.png', 'não funcionou', 1440, 900 );

		// 5. A configuração: situação, cota, modelos e aparência do widget.
		await ir( cdp, '/wp-admin/options-general.php?page=livia' );
		await fotografar( cdp, 'img5.png' );
	} finally {
		cdp.fechar();
		proc.kill();
	}
}

principal().catch( ( e ) => {
	console.error( e.message );
	process.exit( 1 );
} );
