/**
 * Widget da LivIA.
 *
 * Três trabalhos: falar o protocolo do endpoint, encenar o ritmo de quem
 * digita, e não atrapalhar quem só quer preencher o formulário.
 *
 * O ritmo é daqui, não do servidor. Um sleep() no PHP seguraria um processo do
 * PHP-FPM pelo tempo inteiro da encenação, e processo preso é o gargalo de
 * rodar isto dentro do WordPress. O servidor entrega no ritmo da API; quem
 * espera, pausa e revela palavra por palavra é o navegador — de graça.
 *
 * A conversa fica no sessionStorage da aba. Quem passa da página do briefing
 * para a de exemplos e volta não recomeça do zero, que é o que uma pessoa
 * esperaria de uma conversa.
 */
( function () {
	'use strict';

	var cfg = window.LiviaCfg || {};

	// Uma vez que o streaming se mostrou inviável nesta stack, não adianta
	// tentar de novo a cada pergunta: o cliente pagaria a espera da falha.
	var semStreaming = false;

	var semAnimacao = window.matchMedia
		&& window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	// Cadência perfeita é a assinatura mais robótica que existe: tudo aqui tem
	// um tico de aleatório.
	var RITMO = {
		leituraBase: 420,
		leituraPorLetra: 6,
		leituraMax: 1100,
		palavraBase: 42,
		palavraJitter: 58,
		aposFrase: 190,
		aposLinha: 130,

		// Segundos de silêncio antes de admitir que está demorando. Abaixo
		// disso, o "digitando" já conta a história sozinho.
		avisoDemora: 8000,

		// Quanto tempo na página antes de a LivIA se oferecer.
		convite: 20000
	};

	var MAX_GUARDADAS = 60;

	function esperar( ms ) {
		return new Promise( function ( r ) {
			setTimeout( r, ms );
		} );
	}

	function aleatorio( base, jitter ) {
		return base + Math.random() * jitter;
	}

	function agora() {
		var d = new Date();
		return ( '0' + d.getHours() ).slice( -2 ) + ':' + ( '0' + d.getMinutes() ).slice( -2 );
	}

	// ------------------------------------------------------------ lembrança

	/**
	 * O estado da conversa, por aba.
	 *
	 * sessionStorage e não localStorage de propósito: a conversa morre junto com
	 * a aba. Um briefing preenchido num computador compartilhado não deve deixar
	 * a conversa anterior aparecendo para a próxima pessoa.
	 *
	 * Toda leitura e escrita protegida: em aba anônima, ou com dados de site
	 * bloqueados, o acesso lança em vez de devolver vazio.
	 */
	function Memoria( chave ) {
		function ler() {
			try {
				return JSON.parse( sessionStorage.getItem( chave ) || '{}' ) || {};
			} catch ( e ) {
				return {};
			}
		}

		function gravar( d ) {
			try {
				sessionStorage.setItem( chave, JSON.stringify( d ) );
			} catch ( e ) {
				// Sem lugar para guardar, a conversa simplesmente não sobrevive
				// ao recarregamento. Não é motivo para quebrar o widget.
			}
		}

		return {
			ler: ler,
			campo: function ( nome, valor ) {
				var d = ler();
				if ( arguments.length === 1 ) {
					return d[ nome ];
				}
				d[ nome ] = valor;
				gravar( d );
			},
			anotar: function ( quem, texto, hora ) {
				var d = ler();
				d.falas = ( d.falas || [] ).concat( [ { q: quem, t: texto, h: hora } ] ).slice( -MAX_GUARDADAS );
				gravar( d );
			},
			esquecerToken: function () {
				var d = ler();
				delete d.token;
				delete d.sessao;
				gravar( d );
			}
		};
	}

	// ------------------------------------------------------------- montagem

	var ICONE_CHAT = '<svg class="livia-icone-chat" viewBox="0 0 24 24" aria-hidden="true">'
		+ '<path d="M12 3C6.9 3 2.8 6.5 2.8 10.8c0 2.4 1.3 4.6 3.4 6-.2.8-.7 2-1.5 3 0 0 2.6-.4 4.4-1.7 .9.2 1.9.4 2.9.4 5.1 0 9.2-3.5 9.2-7.7S17.1 3 12 3z"/></svg>';

	var ICONE_X = '<svg class="livia-icone-fechar" viewBox="0 0 24 24" aria-hidden="true">'
		+ '<path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7l1.4-1.4L10.6 10.6l6.3-6.3z"/></svg>';

	var ICONE_ENVIAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 20.5 22 12 3 3.5 3 10l13 2-13 2z"/></svg>';

	// O mesmo X do cabeçalho, em SVG: o caractere &times; muda de desenho e de
	// peso conforme a fonte do tema, e era um dos detalhes que destoavam.
	var ICONE_FECHAR = '<svg viewBox="0 0 24 24" aria-hidden="true">'
		+ '<path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7l1.4-1.4L10.6 10.6l6.3-6.3z"/></svg>';

	function montar( raiz, dados ) {
		var nome = dados.nome || 'LivIA';
		var org = dados.org || '';
		var inicial = nome.trim().charAt( 0 ).toUpperCase() || 'L';
		var rotulo = ( dados.rotulo || '' ).trim();

		raiz.className = 'livia-raiz';
		raiz.innerHTML =
			// O véu vem ANTES da janela: mesma pilha, e quem vem depois fica por
			// cima. É ele que escurece a página e recebe o clique de quem quer
			// sair. aria-hidden porque para quem não enxerga ele não existe —
			// quem usa leitor de tela fecha com Esc, não clicando no escuro.
			'<div class="livia-veu" hidden aria-hidden="true"></div>'
			+ '<div class="livia-janela" hidden role="dialog" aria-modal="false" aria-label="Conversa com ' + esc( nome ) + '">'
				+ '<div class="livia-topo">'
					+ '<div class="livia-avatar" aria-hidden="true">' + esc( inicial ) + '</div>'
					+ '<div class="livia-quem">'
						+ '<span class="livia-nome">' + esc( nome ) + ( org ? ' · ' + esc( org ) : '' ) + '</span>'
						+ '<span class="livia-estado"><i></i>' + esc( dados.estado || 'responde na hora' ) + '</span>'
					+ '</div>'
					+ '<button type="button" class="livia-fechar" aria-label="Fechar a conversa">' + ICONE_FECHAR + '</button>'
				+ '</div>'
				+ '<div class="livia-conversa" role="log" aria-live="polite"></div>'
				+ '<div class="livia-pe">'
					// As perguntas de partida vivem AQUI, fora da rolagem: numa
					// janela de altura inteira elas ficariam grudadas no topo,
					// longe do campo, e sairiam de vista na primeira resposta.
					// Coladas no campo, estão onde a mão já está.
					+ '<div class="livia-sugestoes-area"></div>'
					+ '<form class="livia-form">'
						+ '<textarea class="livia-campo" rows="1" autocomplete="off" '
							+ 'placeholder="Pergunte do seu jeito mesmo…" aria-label="Sua dúvida"></textarea>'
						+ '<button type="submit" class="livia-enviar" aria-label="Enviar" disabled>' + ICONE_ENVIAR + '</button>'
					+ '</form>'
					+ ( dados.aviso ? '<p class="livia-rodape">' + esc( dados.aviso ) + '</p>' : '' )
				+ '</div>'
			+ '</div>'
			+ '<button type="button" class="livia-bolha" aria-expanded="false" aria-label="Falar com ' + esc( nome ) + '">'
				+ ICONE_CHAT + ICONE_X
				// Um botão redondo só com ícone depende do desenho falar sozinho,
				// e balão significa "chat" para quem já usa chat — não para quem
				// está preenchendo um formulário pela primeira vez.
				+ ( rotulo ? '<span class="livia-rotulo">' + esc( rotulo ) + '</span>' : '' )
				+ '<span class="livia-presenca" aria-hidden="true"></span>'
			+ '</button>';

		return {
			raiz: raiz,
			veu: raiz.querySelector( '.livia-veu' ),
			janela: raiz.querySelector( '.livia-janela' ),
			conversa: raiz.querySelector( '.livia-conversa' ),
			form: raiz.querySelector( '.livia-form' ),
			campo: raiz.querySelector( '.livia-campo' ),
			enviar: raiz.querySelector( '.livia-enviar' ),
			bolha: raiz.querySelector( '.livia-bolha' ),
			areaSugestoes: raiz.querySelector( '.livia-sugestoes-area' ),
			fechar: raiz.querySelector( '.livia-fechar' ),
			inicial: inicial
		};
	}

	/** Nada aqui vem do cliente, mas texto de configuração vai para o HTML. */
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// --------------------------------------------------------------- balões

	/**
	 * Escreve texto num balão, quebrando em parágrafos de verdade.
	 *
	 * Uma linha em branco no texto vira um <p> novo. Sem isto, uma resposta de
	 * seis linhas chegava como um bloco corrido — tecnicamente certa e
	 * ilegível num balão de celular.
	 *
	 * Devolve um cursor, porque o streaming precisa continuar escrevendo no
	 * mesmo parágrafo até encontrar a próxima linha em branco.
	 */
	function Escritor( balao ) {
		var p = null;
		var texto = '';

		function novoParagrafo() {
			p = document.createElement( 'p' );
			texto = '';
			balao.appendChild( p );
		}

		return {
			acrescentar: function ( pedaco ) {
				var partes = String( pedaco ).split( /\n{2,}/ );
				for ( var i = 0; i < partes.length; i++ ) {
					if ( i > 0 ) {
						novoParagrafo();
					}
					if ( ! p ) {
						novoParagrafo();
					}
					// O espaço que sobrou da quebra não abre o parágrafo novo.
					texto += i > 0 ? partes[ i ].replace( /^[ \t]+/, '' ) : partes[ i ];
					p.textContent = texto;
				}
			},
			limpar: function () {
				balao.textContent = '';
				p = null;
				texto = '';
			}
		};
	}

	function Tela( ui ) {
		var ultimoQuem = null;
		var ultimaCol = null;

		function rolar() {
			ui.conversa.scrollTop = ui.conversa.scrollHeight;
		}

		/**
		 * Uma fala: avatar (só dela) + coluna com o balão e o horário.
		 *
		 * O avatar alinha pelo TOPO. Alinhado embaixo, como em mensageiro de
		 * mensagem curta, ele fica pendurado no fim de uma resposta de dez
		 * linhas, longe do nome de quem falou.
		 */
		function linha( quem ) {
			var el = document.createElement( 'div' );
			var vira = quem !== ultimoQuem;
			el.className = 'livia-linha livia-de-' + quem + ( vira ? ' livia-vira' : '' );

			if ( quem === 'livia' ) {
				var mini = document.createElement( 'div' );
				// Some quando ela fala duas vezes seguidas, mas continua
				// ocupando o lugar: sem isso os balões dançam na horizontal.
				mini.className = 'livia-mini' + ( vira ? '' : ' livia-oculto' );
				mini.setAttribute( 'aria-hidden', 'true' );
				mini.textContent = ui.inicial;
				el.appendChild( mini );
			}

			// Só a última fala de um grupo mostra o horário. Um relógio embaixo
			// de cada balão é ruído: ninguém precisa saber que as três falas
			// saíram no mesmo minuto.
			if ( ! vira && ultimaCol ) {
				var antiga = ultimaCol.querySelector( '.livia-hora' );
				if ( antiga ) {
					antiga.remove();
				}
			}

			var col = document.createElement( 'div' );
			col.className = 'livia-col';
			el.appendChild( col );

			ultimoQuem = quem;
			ultimaCol = col;
			ui.conversa.appendChild( el );
			return col;
		}

		function marcarHora( col, hora ) {
			var h = document.createElement( 'span' );
			h.className = 'livia-hora';
			h.textContent = hora || agora();
			col.appendChild( h );
		}

		return {
			rolar: rolar,

			/** Balão pronto, com o texto todo. */
			balao: function ( quem, texto, hora ) {
				var col = linha( quem );
				var b = document.createElement( 'div' );
				b.className = 'livia-balao';
				col.appendChild( b );

				Escritor( b ).acrescentar( texto || '' );

				if ( hora !== false ) {
					marcarHora( col, hora );
				}
				rolar();
				return b;
			},

			/** Balão vazio, para ser preenchido aos poucos pelo Revelador. */
			balaoVazio: function ( quem, hora ) {
				var col = linha( quem );
				var b = document.createElement( 'div' );
				b.className = 'livia-balao';
				col.appendChild( b );
				rolar();

				return {
					balao: b,
					col: col,
					concluir: function () {
						if ( hora !== false ) {
							marcarHora( col, hora );
							rolar();
						}
					},
					remover: function () {
						col.parentNode.remove();
						ultimoQuem = null;
						ultimaCol = null;
					}
				};
			},

			digitando: function () {
				var col = linha( 'livia' );
				var b = document.createElement( 'div' );
				b.className = 'livia-balao livia-digitando';
				b.innerHTML = '<span></span><span></span><span></span>';
				b.setAttribute( 'aria-label', 'digitando' );
				col.appendChild( b );
				rolar();

				return {
					remover: function () {
						// Remove a LINHA: só o balão deixaria o avatar órfão.
						col.parentNode.remove();
						ultimoQuem = null;
						ultimaCol = null;
					}
				};
			},

			/**
			 * As perguntas de partida.
			 *
			 * Uma caixa vazia não diz a ninguém o que dá para perguntar. Três
			 * exemplos dizem — e somem no primeiro envio, para não virar
			 * mobília no meio da conversa.
			 */
			sugestoes: function ( lista, aoEscolher ) {
				if ( ! lista || ! lista.length ) {
					return null;
				}

				var caixa = document.createElement( 'div' );
				caixa.className = 'livia-sugestoes';

				lista.forEach( function ( texto ) {
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'livia-sugestao';
					b.textContent = texto;
					b.addEventListener( 'click', function () {
						caixa.remove();
						aoEscolher( texto );
					} );
					caixa.appendChild( b );
				} );

				ui.areaSugestoes.appendChild( caixa );
				rolar();
				return caixa;
			},

			/**
			 * "Isso ajudou?" embaixo de uma resposta dela.
			 *
			 * O sinal mais barato que existe: não custa token nem chamada de
			 * API, e responde a única pergunta que latência e contagem de
			 * mensagem não respondem — se ela acertou.
			 *
			 * Discreto de propósito. Dois ícones pequenos, sem texto até o
			 * mouse chegar perto: um bloco de avaliação embaixo de cada
			 * resposta transforma conversa em formulário de pesquisa.
			 */
			opiniao: function ( col, aoVotar ) {
				var caixa = document.createElement( 'div' );
				caixa.className = 'livia-opiniao';

				var obrigado = document.createElement( 'span' );
				obrigado.className = 'livia-obrigado';
				obrigado.setAttribute( 'role', 'status' );

				[
					{ v: 1, r: 'Essa resposta ajudou', i: 'M2 7h2.2v6H2zm3.4 0 2.6-4.6c.2-.3.6-.5 1-.4.7.2 1.1.9 1 1.6L9.6 6H13c.6 0 1 .5.9 1.1l-.8 4.6c-.1.7-.7 1.3-1.5 1.3H5.4z' },
					{ v: 0, r: 'Essa resposta não ajudou', i: 'M2 3h2.2v6H2zm3.4 6 2.6 4.6c.2.3.6.5 1 .4.7-.2 1.1-.9 1-1.6L9.6 10H13c.6 0 1-.5.9-1.1l-.8-4.6C13 3.6 12.4 3 11.6 3H5.4z' }
				].forEach( function ( op ) {
					var b = document.createElement( 'button' );
					b.type = 'button';
					b.className = 'livia-polegar';
					b.setAttribute( 'aria-label', op.r );
					b.setAttribute( 'title', op.r );
					b.innerHTML = '<svg viewBox="0 0 15 15" aria-hidden="true" focusable="false">'
						+ '<path d="' + op.i + '"/></svg>';

					b.addEventListener( 'click', function () {
						if ( caixa.classList.contains( 'livia-votou' ) ) {
							return;
						}
						caixa.classList.add( 'livia-votou' );
						b.classList.add( 'livia-escolhido' );
						obrigado.textContent = op.v ? 'Que bom!' : 'Obrigada por avisar.';
						aoVotar( op.v );
					} );

					caixa.appendChild( b );
				} );

				caixa.appendChild( obrigado );
				col.appendChild( caixa );
				rolar();
				return caixa;
			},

			/**
			 * A resposta não veio — e a pessoa não fica sem saída.
			 *
			 * Na homologação houve quatro falhas de conexão, e em todas a
			 * conversa simplesmente parava: um balão de desculpa e nada para
			 * clicar. Perguntar de novo exigia digitar tudo outra vez.
			 */
			tentar: function ( col, aoTentar ) {
				var acao = document.createElement( 'button' );
				acao.type = 'button';
				acao.className = 'livia-tentar';
				acao.textContent = 'Tentar de novo';
				acao.addEventListener( 'click', function () {
					acao.disabled = true;
					acao.textContent = 'Tentando…';
					aoTentar();
				} );
				col.appendChild( acao );
				rolar();
				return acao;
			},

			/** Um balão de falha já com o botão dentro. */
			erro: function ( texto, aoTentar ) {
				var col = linha( 'livia' );
				var b = document.createElement( 'div' );
				b.className = 'livia-balao livia-balao-erro';
				Escritor( b ).acrescentar( texto );
				col.appendChild( b );
				rolar();
				return col;
			},

			nota: function ( texto ) {
				var el = document.createElement( 'div' );
				el.className = 'livia-nota';
				el.textContent = texto;
				ui.conversa.appendChild( el );
				rolar();
				return el;
			},

			esquecerAgrupamento: function () {
				ultimoQuem = null;
				ultimaCol = null;
			}
		};
	}

	// ---------------------------------------------------------------- ritmo

	/**
	 * Revela o texto palavra por palavra, no ritmo de quem digita.
	 *
	 * A fila é alimentada pelo stream e drenada por este laço, então o texto
	 * aparece no ritmo da encenação mesmo quando a API despeja tudo de uma vez
	 * — que é exatamente o caso das respostas de atalho, instantâneas.
	 */
	function Revelador( balao, tela ) {
		var fila = [];
		var rodando = false;
		var cancelado = false;
		var texto = '';
		var escritor = Escritor( balao );

		function pausaDe( pedaco ) {
			if ( semAnimacao ) {
				return 0;
			}
			var ms = aleatorio( RITMO.palavraBase, RITMO.palavraJitter );
			if ( /[.!?…]\s*$/.test( pedaco ) ) {
				ms += RITMO.aposFrase;
			}
			if ( /\n/.test( pedaco ) ) {
				ms += RITMO.aposLinha;
			}
			return ms;
		}

		async function drenar() {
			if ( rodando ) {
				return;
			}
			rodando = true;
			// O cursor piscando no fim do texto é o que diz "ainda estou
			// escrevendo" sem ocupar espaço nenhum na tela.
			balao.classList.add( 'livia-escrevendo' );
			while ( fila.length && ! cancelado ) {
				var pedaco = fila.shift();
				texto += pedaco;
				escritor.acrescentar( pedaco );
				tela.rolar();
				await esperar( pausaDe( pedaco ) );
			}
			rodando = false;
			balao.classList.remove( 'livia-escrevendo' );
		}

		return {
			empurrar: function ( novo ) {
				// Mantém o espaço junto da palavra que ele precede.
				var partes = novo.match( /\s*\S+|\s+/g ) || [];
				fila.push.apply( fila, partes );
				drenar();
			},
			descartar: function () {
				cancelado = true;
				fila.length = 0;
				texto = '';
				escritor.limpar();
			},
			trocarPor: function ( outro ) {
				cancelado = true;
				fila.length = 0;
				texto = outro;
				escritor.limpar();
				escritor.acrescentar( outro );
				tela.rolar();
			},
			texto: function () {
				return texto;
			},
			esvaziar: async function () {
				while ( ( fila.length || rodando ) && ! cancelado ) {
					await esperar( 30 );
				}
			}
		};
	}

	// ------------------------------------------------------------ protocolo

	function Conversa( ui, tela, memoria, pagina ) {
		var token = memoria.campo( 'token' ) || null;

		async function abrirSessao() {
			// O servidor deriva título e endereço a partir do ID — daqui vai só
			// o ID e o rótulo do formulário, os dois postos pelo shortcode.
			var r = await fetch( cfg.api + 'sessao', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { pagina_id: pagina.id, formulario: pagina.formulario } )
			} );
			var d = await r.json();
			if ( ! d.token ) {
				throw new Error( d.motivo || 'indisponivel' );
			}
			token = d.token;
			memoria.campo( 'token', d.token );
			memoria.campo( 'sessao', d.sessao );
			return d;
		}

		function derrubarToken( erro ) {
			if ( 'token_expirado' === erro || 'token_invalido' === erro ) {
				token = null;
				memoria.esquecerToken();
			}
		}

		/** Quebra o corpo SSE em quadros, sem supor que um pedaço traz um quadro. */
		function quadros( buffer, aoQuadro ) {
			var i;
			while ( ( i = buffer.indexOf( '\n\n' ) ) >= 0 ) {
				aoQuadro( buffer.slice( 0, i ) );
				buffer = buffer.slice( i + 2 );
			}
			return buffer;
		}

		function lerQuadro( bruto ) {
			var evento = 'mensagem';
			var dados = '';
			bruto.split( /\r?\n/ ).forEach( function ( linha ) {
				if ( linha.indexOf( 'event:' ) === 0 ) {
					evento = linha.slice( 6 ).trim();
				} else if ( linha.indexOf( 'data:' ) === 0 ) {
					dados += linha.slice( 5 ).trim();
				}
			} );
			try {
				return { evento: evento, dados: dados ? JSON.parse( dados ) : {} };
			} catch ( e ) {
				return null;
			}
		}

		/**
		 * A espera, encenada.
		 *
		 * Além dos pontinhos, uma nota depois de oito segundos. Silêncio longo
		 * num chat é indistinguível de travamento — e foi assim que a demora
		 * apareceu no relato de homologação.
		 */
		function Espera() {
			var aviso = tela.digitando();
			var nota = null;
			var relogio = setTimeout( function () {
				nota = tela.nota( 'Só um instante, ainda estou verificando isso…' );
			}, RITMO.avisoDemora );

			return {
				encerrar: function () {
					clearTimeout( relogio );
					aviso.remover();
					if ( nota ) {
						nota.remove();
					}
				}
			};
		}

		/** A pausa de quem termina de ler antes de começar a responder. */
		function leituraDe( pergunta ) {
			return Math.min(
				RITMO.leituraMax,
				RITMO.leituraBase + pergunta.length * RITMO.leituraPorLetra
			);
		}

		async function comRitmo( espera, leitura, comecou ) {
			var passou = Date.now() - comecou;
			if ( passou < leitura && ! semAnimacao ) {
				await esperar( leitura - passou );
			}
			espera.encerrar();
		}

		/**
		 * Falhas em que repetir a mesma pergunta faz sentido.
		 *
		 * Limite de ritmo e disjuntor ficam de fora: ali repetir só piora, e um
		 * botão de "tentar de novo" que não resolve nada é pior do que não ter
		 * botão — ensina a pessoa a não confiar nele.
		 */
		function valeRepetir( codigo ) {
			return [ 'conexao', 'sem_tempo', 'http', 'resposta_vazia' ].indexOf( codigo ) >= 0;
		}

		/** Um balão que se escreve sozinho, e que grava no fim. */
		async function escrever( texto, marcas ) {
			marcas = marcas || {};
			var vaga = tela.balaoVazio( 'livia', agora() );
			var rev = Revelador( vaga.balao, tela );
			rev.empurrar( texto );
			await rev.esvaziar();
			vaga.concluir();
			memoria.anotar( 'livia', texto, agora() );
			fechar( vaga.col, marcas );
		}

		/**
		 * O que vai embaixo de uma resposta: o polegar, ou o botão de repetir.
		 *
		 * Nunca os dois. Pedir opinião sobre uma falha de conexão é pedir
		 * opinião sobre o nosso próprio erro.
		 */
		function fechar( col, marcas ) {
			if ( marcas.erro ) {
				if ( valeRepetir( marcas.erro ) && marcas.repetir ) {
					tela.tentar( col, marcas.repetir );
				}
				return;
			}
			if ( marcas.turno ) {
				tela.opiniao( col, function ( util ) {
					votar( marcas.turno, util );
				} );
			}
		}

		/** Fogo e esquece: a opinião não pode atrapalhar a conversa. */
		function votar( turno, util ) {
			try {
				fetch( cfg.api + 'opiniao', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { token: token, turno: turno, util: util } ),
					keepalive: true
				} ).catch( function () {} );
			} catch ( e ) {
				// A pessoa já viu o "obrigada". Falhar isso na cara dela seria
				// trocar a conversa pelo nosso relatório.
			}
		}

		/** Caminho inteiro: uma requisição, uma resposta, sem streaming. */
		async function inteiro( pergunta, espera, leitura, comecou, repetir ) {
			var r = await fetch( cfg.api + 'mensagem', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { token: token, pergunta: pergunta } )
			} );
			var d = await r.json();

			await comRitmo( espera, leitura, comecou );
			derrubarToken( d.erro );
			await escrever(
				d.resposta || 'Não consegui responder agora.',
				{ turno: d.turno, erro: d.erro, repetir: repetir }
			);
		}

		async function perguntar( pergunta, repetir ) {
			if ( ! token ) {
				await abrirSessao();
			}

			var espera = Espera();
			var leitura = leituraDe( pergunta );
			var comecou = Date.now();

			if ( semStreaming ) {
				return inteiro( pergunta, espera, leitura, comecou, repetir );
			}

			var resp;
			try {
				resp = await fetch( cfg.api + 'conversa', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify( { token: token, pergunta: pergunta } )
				} );
			} catch ( e ) {
				// Nem chegou a responder: nada foi gerado, repetir não custa cota.
				semStreaming = true;
				return inteiro( pergunta, espera, leitura, comecou, repetir );
			}

			var tipo = ( resp.headers.get( 'content-type' ) || '' ).toLowerCase();

			// Saída antecipada legítima — atalho, token vencido, limite,
			// disjuntor, desligada: vem JSON, e é revelado no mesmo ritmo.
			if ( tipo.indexOf( 'application/json' ) >= 0 ) {
				var d = await resp.json();
				await comRitmo( espera, leitura, comecou );
				derrubarToken( d.erro );
				return escrever(
					d.resposta || 'Não consegui responder agora.',
					{ turno: d.turno, erro: d.erro, repetir: repetir }
				);
			}

			// Não veio SSE nem JSON: algum proxy ou o próprio servidor respondeu
			// outra coisa. O handler do WordPress provavelmente nem rodou.
			if ( tipo.indexOf( 'text/event-stream' ) < 0 || ! resp.body || ! resp.body.getReader ) {
				semStreaming = true;
				return inteiro( pergunta, espera, leitura, comecou, repetir );
			}

			await comRitmo( espera, leitura, comecou );

			var vaga = tela.balaoVazio( 'livia', agora() );
			var rev = Revelador( vaga.balao, tela );

			var leitor = resp.body.getReader();
			// stream:true monta caractere UTF-8 partido entre dois pedaços da rede.
			var dec = new TextDecoder( 'utf-8' );
			var buffer = '';
			var encerrado = false;
			var recebeu = false;
			var marcas = { repetir: repetir };

			try {
				while ( ! encerrado ) {
					var passo = await leitor.read();
					if ( passo.done ) {
						break;
					}
					buffer += dec.decode( passo.value, { stream: true } );
					buffer = quadros( buffer, function ( bruto ) {
						var q = lerQuadro( bruto );
						if ( ! q ) {
							return;
						}
						if ( q.evento === 'pedaco' ) {
							recebeu = true;
							rev.empurrar( q.dados.t || '' );
						} else if ( q.evento === 'fim' ) {
							recebeu = true;
							marcas.turno = q.dados.turno;
							encerrado = true;
						} else if ( q.evento === 'bloqueada' || q.evento === 'erro' ) {
							// Descarta o parcial e põe o texto seguro no lugar.
							recebeu = true;
							marcas.erro = q.dados.erro || 'bloqueada';
							rev.trocarPor( q.dados.resposta || '' );
							derrubarToken( q.dados.erro );
							encerrado = true;
						}
					} );
				}
			} catch ( e ) {
				// Conexão cortada no meio. Se já veio texto, fica o que veio.
			}

			await rev.esvaziar();

			// Abriu como SSE mas não entregou um evento sequer — proxy engolindo
			// o corpo. Vale uma tentativa pelo caminho inteiro, e daqui em diante
			// nem tentamos streaming de novo nesta página.
			if ( ! recebeu ) {
				semStreaming = true;
				vaga.remover();
				return inteiro( pergunta, Espera(), 0, Date.now(), repetir );
			}

			vaga.concluir();
			memoria.anotar( 'livia', rev.texto(), agora() );

			// A conexão caiu no meio: ficou o que chegou, mas a resposta está
			// pela metade. Quem está lendo merece o botão.
			if ( ! encerrado && ! marcas.erro ) {
				marcas.erro = 'conexao';
			}

			fechar( vaga.col, marcas );
		}

		return { perguntar: perguntar };
	}

	// ------------------------------------------------------------- abertura

	function iniciar( raiz ) {
		/**
		 * O widget muda de lugar no documento antes de qualquer outra coisa.
		 *
		 * Ele entra por shortcode, então nasce onde o editor o colocou: dentro
		 * de um container do tema. Lá ele herda o que aquele container impõe —
		 * no Elementor, uma regra de carregamento preguiçoso que apaga
		 * `background-image` com !important em TODO descendente, e que deixava
		 * o cabeçalho do painel transparente. Pior: basta um ancestral com
		 * `transform` ou `filter` para `position: fixed` passar a se medir por
		 * ele, e o painel de altura inteira desandar.
		 *
		 * Filho direto do <body>, nada disso o alcança.
		 */
		if ( raiz.parentNode !== document.body ) {
			document.body.appendChild( raiz );
		}

		var pagina = {
			id: parseInt( raiz.getAttribute( 'data-pagina' ), 10 ) || 0,
			formulario: raiz.getAttribute( 'data-formulario' ) || ''
		};

		var ui = montar( raiz, {
			nome: cfg.nome,
			org: cfg.org,
			estado: cfg.estado,
			rotulo: cfg.rotulo,
			aviso: cfg.aviso
		} );

		if ( cfg.cor ) {
			raiz.style.setProperty( '--livia-cor', cfg.cor );
		}

		var memoria = Memoria( 'livia:' + ( cfg.api || '' ) );
		var tela = Tela( ui );
		var conversa = Conversa( ui, tela, memoria, pagina );
		var ocupado = false;
		var convite = null;
		var relogioConvite = null;
		var sugestoes = null;
		var saudacao = null; // a primeira fala, enquanto ainda está sendo escrita
		var perguntasFeitas = 0;
		var propostasFeitas = false;
		var jaPerguntadas = {};

		// ----------------------------------------------------- abrir/fechar

		function abrir( focar ) {
			sumirConvite( true );
			ui.veu.hidden = false;
			ui.janela.hidden = false;
			raiz.classList.add( 'livia-aberta' );
			ui.bolha.setAttribute( 'aria-expanded', 'true' );
			memoria.campo( 'aberta', true );

			if ( ! memoria.campo( 'saudou' ) ) {
				memoria.campo( 'saudou', true );
				apresentar();
			}

			// Só agora o campo tem tamanho para ser medido: escondido, ele
			// devolve zero, e o zero virava uma linha de altura nenhuma que só
			// o primeiro caractere digitado consertava.
			ajustarAltura();
			tela.rolar();
			if ( focar !== false ) {
				ui.campo.focus();
			}
		}

		function fechar() {
			ui.veu.hidden = true;
			ui.janela.hidden = true;
			raiz.classList.remove( 'livia-aberta' );
			ui.bolha.setAttribute( 'aria-expanded', 'false' );
			memoria.campo( 'aberta', false );
			ui.bolha.focus();
		}

		function alternar() {
			if ( ui.janela.hidden ) {
				abrir();
			} else {
				fechar();
			}
		}

		/**
		 * A primeira fala.
		 *
		 * Escrita com o mesmo ritmo das outras, e não despejada pronta: é o
		 * primeiro sinal de que tem alguém do outro lado, e é barato.
		 */
		async function apresentar() {
			var texto = cfg.saudacao || 'Oi! Tô aqui pra ajudar com o briefing. Travou em alguma parte?';
			var espera = tela.digitando();
			var vaga = null;
			var rev = null;
			var pronta = false;

			// Idempotente: termina pelo caminho normal ou atropelada pelo
			// cliente, mas anota a fala uma vez só.
			function concluir() {
				if ( pronta ) {
					return;
				}
				pronta = true;
				saudacao = null;
				vaga.concluir();
				memoria.anotar( 'livia', texto, agora() );
			}

			// Quem escreve antes de a saudação terminar não espera por ela: a
			// fala termina na hora, no lugar dela. Sem isto, o balão do cliente
			// entrava ANTES do "Oi!" — o balão da saudação só nasce depois da
			// pausa de digitação —, e as sugestões apareciam depois da pergunta,
			// quando já não serviam para nada.
			saudacao = {
				atropelar: function () {
					if ( ! vaga ) {
						espera.remover();
						vaga = tela.balaoVazio( 'livia', agora() );
						rev = Revelador( vaga.balao, tela );
					}
					rev.trocarPor( texto );
					concluir();
				}
			};

			await esperar( semAnimacao ? 0 : aleatorio( 500, 500 ) );
			if ( pronta ) {
				return;
			}
			espera.remover();

			vaga = tela.balaoVazio( 'livia', agora() );
			rev = Revelador( vaga.balao, tela );
			rev.empurrar( texto );
			await rev.esvaziar();
			if ( pronta ) {
				return;
			}
			concluir();

			// Uma caixa de texto vazia não diz a ninguém o que dá para
			// perguntar. Três exemplos dizem — e somem no primeiro envio.
			sugestoes = tela.sugestoes( cfg.sugestoes, function ( escolhida ) {
				sugestoes = null;
				enviar( escolhida );
			} );
		}

		// -------------------------------------------------------- o convite

		function mostrarConvite() {
			if ( convite || ! ui.janela.hidden || memoria.campo( 'conviteVisto' ) ) {
				return;
			}

			convite = document.createElement( 'div' );
			convite.className = 'livia-convite';
			convite.setAttribute( 'role', 'status' );
			convite.innerHTML = '<button type="button" class="livia-convite-x" '
				+ 'aria-label="Dispensar">&times;</button>';
			convite.appendChild( document.createTextNode(
				cfg.convite || 'Travou em alguma parte? Me chama que eu te explico.'
			) );

			convite.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( '.livia-convite-x' ) ) {
					e.stopPropagation();
					sumirConvite( true );
					return;
				}
				abrir();
			} );

			raiz.appendChild( convite );

			// Ignorado, some sozinho — e não volta. Insistir é o que faz as
			// pessoas fecharem o chat antes de ler.
			setTimeout( function () {
				sumirConvite( true );
			}, 14000 );
		}

		function sumirConvite( definitivo ) {
			clearTimeout( relogioConvite );
			if ( convite ) {
				convite.remove();
				convite = null;
			}
			if ( definitivo ) {
				memoria.campo( 'conviteVisto', true );
			}
		}

		// ------------------------------------------------------ restauração

		function restaurar() {
			var falas = memoria.campo( 'falas' ) || [];
			falas.forEach( function ( f ) {
				tela.balao( f.q, f.t, f.h );
			} );
			if ( falas.length ) {
				tela.esquecerAgrupamento();
			}
			if ( memoria.campo( 'aberta' ) ) {
				abrir( false );
			}
		}

		// ------------------------------------------------------------ envio

		var ALTURA_MAX = 104;

		/**
		 * O campo cresce com o texto, até um teto.
		 *
		 * A rolagem só é ligada depois do teto. Com `overflow-y: auto` fixo, o
		 * navegador desenha as setinhas de rolagem dentro do campo de uma linha
		 * antes mesmo de existir o que rolar.
		 */
		function ajustarAltura() {
			// Campo escondido não tem o que medir. Sem esta saída, a chamada da
			// partida — que roda com a janela fechada — gravava `height: 0px`
			// no estilo do elemento e o campo abria achatado.
			if ( ui.janela.hidden ) {
				return;
			}
			ui.campo.style.height = 'auto';
			var alto = Math.min( ALTURA_MAX, ui.campo.scrollHeight );
			ui.campo.style.height = alto + 'px';
			ui.campo.style.overflowY = ui.campo.scrollHeight > ALTURA_MAX ? 'auto' : 'hidden';
		}

		/** O botão só fica aceso quando há o que mandar. */
		function ajustarBotao() {
			ui.enviar.disabled = ocupado || '' === ui.campo.value.trim();
		}

		/**
		 * Depois da primeira resposta, o que sobrou dos exemplos.
		 *
		 * Só uma vez, e só o que a pessoa ainda não perguntou. Repetir os
		 * mesmos botões embaixo de toda resposta os transformaria em mobília —
		 * e sugestão que vira mobília deixa de ser lida.
		 *
		 * Não são continuações do assunto: para isso seria preciso perguntar ao
		 * modelo o que sugerir, e uma chamada de API por resposta é exatamente
		 * o que a fase anterior passou o tempo inteiro cortando.
		 */
		function proporRestantes() {
			if ( propostasFeitas || perguntasFeitas !== 1 || sugestoes ) {
				return;
			}
			propostasFeitas = true;

			var sobraram = ( cfg.sugestoes || [] ).filter( function ( s ) {
				return ! jaPerguntadas[ s.toLowerCase().trim() ];
			} ).slice( 0, 2 );

			sugestoes = tela.sugestoes( sobraram, function ( escolhida ) {
				sugestoes = null;
				enviar( escolhida );
			} );
		}

		async function enviar( texto ) {
			var pergunta = ( texto || ui.campo.value ).trim();
			if ( ! pergunta || ocupado ) {
				return;
			}

			ocupado = true;

			if ( ! texto ) {
				ui.campo.value = '';
				ajustarAltura();
			}
			ajustarBotao();

			// A saudação vem antes da pergunta, sempre.
			if ( saudacao ) {
				saudacao.atropelar();
			}

			// A pessoa já sabe o que perguntar: os exemplos cumpriram o papel.
			if ( sugestoes ) {
				sugestoes.remove();
				sugestoes = null;
			}

			var hora = agora();
			tela.balao( 'cliente', pergunta, hora );
			memoria.anotar( 'cliente', pergunta, hora );
			jaPerguntadas[ pergunta.toLowerCase() ] = true;
			++perguntasFeitas;

			// Repetir é reenviar a MESMA pergunta, sem um segundo balão dela:
			// a primeira continua na tela, e um eco embaixo dela pareceria que
			// a pessoa perguntou duas vezes.
			function repetir() {
				ocupado = false;
				reenviar( pergunta );
			}

			try {
				await conversa.perguntar( pergunta, repetir );
			} catch ( err ) {
				tela.tentar( tela.erro( 'Não consegui responder agora.' ), repetir );
			}

			ocupado = false;
			ajustarBotao();
			ui.campo.focus();
			proporRestantes();
		}

		/** Mesma pergunta, sem repetir o balão do cliente. */
		async function reenviar( pergunta ) {
			if ( ocupado ) {
				return;
			}
			ocupado = true;
			ajustarBotao();

			try {
				await conversa.perguntar( pergunta, function () {
					ocupado = false;
					reenviar( pergunta );
				} );
			} catch ( err ) {
				tela.tentar( tela.erro( 'Não consegui responder agora.' ), function () {
					ocupado = false;
					reenviar( pergunta );
				} );
			}

			ocupado = false;
			ajustarBotao();
		}

		// ------------------------------------------------------------ laços

		ui.bolha.addEventListener( 'click', alternar );
		ui.fechar.addEventListener( 'click', fechar );

		// Clicou fora, fechou. O véu cobre a tela inteira menos o painel, então
		// "fora" é exatamente ele — não é preciso ouvir o documento todo nem
		// adivinhar se o clique foi dentro ou fora comparando coordenadas.
		ui.veu.addEventListener( 'click', fechar );

		ui.form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			enviar();
		} );

		ui.campo.addEventListener( 'input', function () {
			ajustarAltura();
			ajustarBotao();
		} );

		ui.campo.addEventListener( 'keydown', function ( e ) {
			// Enter manda, Shift+Enter pula linha — a convenção de todo chat.
			if ( 'Enter' === e.key && ! e.shiftKey ) {
				e.preventDefault();
				enviar();
			}
		} );

		raiz.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && ! ui.janela.hidden ) {
				fechar();
			}
		} );

		restaurar();
		ajustarAltura();
		ajustarBotao();

		if ( ui.janela.hidden && ! memoria.campo( 'conviteVisto' ) ) {
			relogioConvite = setTimeout( mostrarConvite, RITMO.convite );
		}
	}

	function ligar() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-livia]' ), iniciar );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ligar );
	} else {
		ligar();
	}
}() );
