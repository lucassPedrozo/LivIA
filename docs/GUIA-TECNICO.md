# LivIA — guia técnico

Tira-dúvidas para clientes que estão preenchendo os formulários de
https://formularios.joinvix.com.br — **os cinco**: Site Gerenciável, Ajustes, Alteração de
Layout, Landing Page e Site em 72h.

Responde perguntas simples e diretas ("o que é domínio?", "não consigo anexar minhas
imagens", "qual formulário eu uso?") em linguagem de gente leiga, e faz a triagem de quem
entrou no formulário errado. **Fora do escopo, ela não responde** — encaminha para o
atendimento humano em vez de inventar.

---

## O plugin de WordPress

O terminal foi a fase de calibragem. O produto é o plugin, em `livia/`: instala no
WordPress, aparece na página por shortcode, e responde em streaming com a trava rodando no
servidor.

| Peça | Estado |
|---|---|
| `base_conhecimento.md` | ✅ Vai inteira. Já mora dentro do plugin. |
| `.env` | ✅ Virou tela de configuração (`Configurações → LivIA`). |
| `verificar_resposta` / `permitidos` | ✅ `Livia_Trava`, com cinco regras a mais que o Python. |
| `chamar_gemini` | ✅ `Livia_Gemini`, com retry, prazo curto e streaming. |
| Endpoint, sessão e defesa de cota | ✅ `Livia_Rest`, `Livia_Sessao`, `Livia_Limites`, `Livia_Prompt`. |
| Streaming com ritmo humano | ✅ `Livia_Sse`, `Livia_Stream`, widget `[livia]`. |
| Registro, métricas e LGPD | ✅ `Livia_Registro` + painéis em *Configurações → LivIA*. |
| `casos_de_teste.json` | ✅ Intocado. O `testar.py` passou a apontar para o endpoint. |

### Instalar para desenvolver

Aponte a pasta `livia/` para dentro do WordPress — link simbólico é o mais prático, porque
você continua editando aqui:

```
mklink /D C:\caminho\wp-content\plugins\livia "C:\...\LivIA\livia"
```

Depois ative em *Plugins* e configure em *Configurações → LivIA*.

**A chave da API pode ficar fora do banco.** Defina no `wp-config.php` e ela nunca é
gravada em `wp_options` — dump de banco e backup deixam de vazá-la:

```php
define( 'LIVIA_GEMINI_API_KEY', 'sua-chave' );
```

A constante sempre ganha do campo da tela de configuração.

---

### Empacotar para subir no servidor

```bash
python empacotar.py
```

Sai em `dist/livia-<versao>.zip`, pronto para *Plugins → Adicionar novo → Enviar plugin*.

O script **recusa empacotar com a suíte vermelha**, e deixa `livia/tests/` de fora. Zipar a
pasta na mão leva os testes junto, e lá dentro estão o `stubs-wp.php` — que redefine
`get_option` e `update_option` — e o `livia-staging.php`, que desliga os limites de ritmo.
Nenhum dos dois faz estrago sozinho, e nenhum dos dois tem motivo para ficar acessível pela
web.

### As rotas

| Rota | O que faz |
|---|---|
| `POST /wp-json/livia/v1/sessao` | Abre a conversa. Devolve id, token assinado e se a LivIA está disponível hoje. |
| `POST /wp-json/livia/v1/conversa` | Pergunta e resposta em streaming (SSE). É a que o widget usa. |
| `POST /wp-json/livia/v1/mensagem` | A mesma coisa, resposta inteira em JSON. É o plano B do widget. |
| `POST /wp-json/livia/v1/opiniao` | O cliente diz se a resposta ajudou. Mesmo token da conversa; só marca linha da própria sessão. |
| `GET /wp-json/livia/v1/saude` | Estado, para monitoramento externo. **503** quando não atende, **200** nos demais casos. Com `?chave=<token>` traz cota, erros, latência e expurgo — o token está na tela de configuração. |

Os dois caminhos de resposta passam pela mesma função de guardas — para não acontecer de
uma defesa existir num e faltar no outro.

### Pôr o widget numa página

```
[livia formulario="site-em-72h"]
```

Os arquivos só carregam na página que tem o shortcode, e um shortcode por página: o segundo
é ignorado, senão virariam dois widgets sobrepostos no mesmo canto.

Ele começa como um **botão no canto inferior esquerdo** — ícone mais o texto que estiver em
*Texto do botão* ("Dúvidas?" de fábrica). Balãozinho sozinho significa "chat" para quem já
usa chat, e quem está preenchendo um briefing pela primeira vez não é essa pessoa; com o
campo em branco o botão volta a ser o círculo só com o ícone. Depois de vinte segundos na
página aparece um convite ao lado, que some sozinho se ninguém clicar — e não volta.

Aberto, vira um **painel colado na borda esquerda**, do topo ao rodapé, com a página atrás
escurecida e levemente desfocada. Escurecer não é enfeite: separa a conversa do formulário e
dá à pessoa um lugar óbvio para clicar quando quer voltar — **clicou fora, fechou** (`Esc`
também). No celular o painel ocupa a tela inteira, respeita o entalhe, e o desfoque sai
(atrás dele não há nada à mostra, e o efeito só gastaria GPU).

O widget se muda para filho direto do `<body>` assim que carrega. Ele entra por shortcode,
então nasce dentro de um container do tema — e lá herda o que aquele container impuser. No
Elementor, uma regra de carregamento preguiçoso apaga `background-image` com `!important` em
todo descendente, e isso deixava o cabeçalho do painel transparente; pior, basta um ancestral
com `transform` para `position: fixed` passar a se medir por ele.

A conversa fica no `sessionStorage` da aba: quem navega para outra página e volta continua de
onde parou. É `sessionStorage` e não `localStorage` de propósito — num computador
compartilhado, a conversa não deve aparecer para a próxima pessoa.

Nome, texto do botão, cor, primeira fala e convite saem de *Configurações → LivIA*. Quem
quiser ir além redefine as variáveis CSS (`--livia-cor`, `--livia-balao-dela`, …) no tema,
sem tocar no arquivo do plugin.

**Toda regra de `livia-widget.css` começa com `.livia-raiz`**, e há um caso de teste que
cobra isso. Não é estilo de escrita: sem o prefixo a regra vale 0-1-0 de especificidade e
perde para qualquer `.classe-do-tema button` — foi o que aconteceu no site de vocês, onde o
kit do Elementor (`padding: 20px 36px; border-radius: 30px`) transformou o X do cabeçalho num
retângulo azul de 72x40 e o botão de enviar numa pílula cinza. Regra nova nasce com o
prefixo. Botão e campo ainda passam por um reset de território no topo do arquivo, que
devolve a fonte e as medidas do widget para o que o componente não declara.

Logo acima do campo aparecem até **três perguntas de partida**, configuráveis. Uma caixa de
texto vazia com "pergunte do seu jeito mesmo" não diz a ninguém o que dá para perguntar; três
exemplos dizem — e somem no primeiro envio, para não virar mobília. Ficam coladas no campo, e
não dentro da rolagem: num painel de altura inteira elas ficariam grudadas no topo, longe da
mão, e sairiam de vista na primeira resposta.

As respostas são montadas em **parágrafos de verdade** — uma linha em branco no texto vira um
`<p>` com espaçamento. Sem isso, uma resposta de seis linhas chegava como um bloco corrido:
tecnicamente certa e ilegível num balão de celular. A base e o lembrete final do prompt pedem
no máximo três parágrafos de até três linhas, com a resposta direta sozinha no primeiro.

O que **não é cosmético** e não deveria ser mexido sem pensar: o widget é quem faz o ritmo
humano (pausa de leitura, revelação palavra por palavra com variação, indicador de
digitação, nota de "ainda estou verificando" depois de oito segundos). Isso mora no
navegador porque um `sleep()` no PHP seguraria um processo do PHP-FPM pelo tempo inteiro da
encenação, e processo preso é o gargalo desta arquitetura.

Sobre o que ela é: a LivIA **não esconde** ser uma IA — perguntada, responde. Mas também não
abre a conversa com um rótulo que ninguém pediu. O cabeçalho diz o nome e "responde na hora";
o filtro `livia_estado_visivel` troca essa linha para quem preferir ser explícito desde o
primeiro segundo.

### Resposta imediata para o que não precisa de modelo

Cortesia pura — "oi", "bom dia", "obrigado", "tchau", "ok" — é respondida pelo próprio PHP,
em milissegundos, sem rede e sem cota. Era daí que vinha a travada em mensagens simples: a
base inteira, uns doze mil tokens, ia à API para devolver uma saudação que já sabíamos
escrever.

O corte é estreito de propósito. Basta o cliente colar uma pergunta junto — "oi, quanto
custa?" — para o atalho não valer. Errar para o lado de mandar ao modelo custa tempo; errar
para o outro lado custa uma resposta errada.

As respostas variam dentro da conversa (repetir a mesma frase é o que mais denuncia que do
outro lado não tem gente) e nenhuma contém contato, preço ou link — há caso de teste passando
cada uma delas pela trava. Elas contam para o limite de ritmo, mas não para a cota do dia, e
continuam funcionando com o disjuntor aberto.

Para desligar tudo: `add_filter( 'livia_atalhos', '__return_false' );`

### Quando um modelo acaba, outro assume

*Configurações → LivIA* tem **modelo principal** e **modelo reserva**, escolhidos de uma
lista que vem da própria API (botão *Atualizar lista de modelos*; a lista fica guardada meio
dia, e a tela nunca vai à rede sozinha para não pendurar o wp-admin).

Cota esgotada não é falha transitória, é um estado. Ao primeiro 429 — ou 404, de um ID que o
Google descontinuou — a LivIA anota isso, termina **a mesma pergunta** já na reserva, e as
seguintes vão direto para lá. Dez minutos depois ela tenta o principal de novo, sozinha.

Antes disso, um 429 custava até trinta segundos por pergunta: três tentativas com recuo
contra um modelo que não ia voltar. Chave recusada (403) **não** rebaixa — a reserva usaria a
mesma chave, e trocar só mascararia o problema.

A troca manda um e-mail, uma vez por hora no máximo. O painel mostra quem está atendendo e
tem um botão para voltar ao principal antes da hora.

### O interruptor

*LivIA ligada*, no topo da configuração. Desligada, o widget não é impresso em página nenhuma
e as rotas recusam com uma mensagem clara — sem desativar o plugin, sem perder o registro nem
a configuração. Para o monitor, `desligada` é um estado próprio: sai `200` com `ok:false`,
porque um alerta que dispara por causa de um interruptor é um alerta que se aprende a
ignorar.

### A trava durante o streaming

Streaming entrega pedaço por pedaço; a trava foi feita para ver a resposta inteira. Sem
cuidado, o telefone inventado aparece no balão e só depois é retirado.

`Livia_Stream` resolve com uma **janela retida**: só vai para a tela o texto que já está a
120 bytes da ponta, e a cada pedaço a trava varre o acumulado inteiro. Telefone, e-mail,
valor, percentual e URL são padrões curtos — nenhum chega perto de 120 bytes — então uma
ocorrência, no instante em que fica completa, termina sempre depois do ponto já emitido.
**Um contato inventado nunca sai inteiro.**

Isso é testado byte a byte: `casos-fase4.php` passa a resposta em pedaços de 1 byte, o pior
caso possível, e confere que a sequência proibida jamais aparece no que foi para a tela.

### Quando o streaming não funciona

Há três redes de proteção, nesta ordem:

1. **O endpoint desliga o que bufferiza.** `zlib.output_compression`, `mod_deflate` via
   `no-gzip`, `X-Accel-Buffering: no` para nginx e LiteSpeed, e 2 KB de comentário SSE no
   começo para estourar buffer de proxy que só repassa depois de encher.
2. **Sem cURL, o servidor serve a resposta inteira pelo mesmo protocolo SSE.** O widget não
   percebe diferença além do texto chegar de uma vez — e ninguém paga cota duas vezes.
3. **O widget cai para `/mensagem`** se a resposta não vier como `text/event-stream`, se o
   navegador não tiver `ReadableStream`, ou se a conexão abrir e não entregar um evento
   sequer. A partir daí ele nem tenta streaming de novo na mesma página.

O texto aparece palavra por palavra nos três caminhos: o ritmo é do navegador.

### Monitoramento e alertas

| | O que é |
|---|---|
| `GET /livia/v1/saude` | Para o monitor de uptime. 503 = não atende. |
| Disjuntor abriu | E-mail imediato, **uma vez** — não a cada requisição barrada. |
| Chave recusada | E-mail depois de 3 falhas seguidas. Uma resposta boa no meio zera a contagem. |
| Resumo de ontem | Só quando há o que dizer: erro acima de 20% ou alguma resposta barrada. Um e-mail diário de "está tudo bem" vira regra de caixa de entrada em duas semanas. |

Destino em `livia_email_alerta`; o padrão é o e-mail do administrador.

### Cache de contexto

A instrução é byte a byte idêntica em toda chamada — é o caso de uso exato do cache. O que
complica é o preço: o cache cobra **armazenamento por hora**, tenha movimento ou não. Com
pouca conversa, guardar a instrução custa mais do que os tokens que ela economiza.

Por isso o padrão é **automático**: liga sozinho a partir de seis perguntas na janela recente
e se apaga quando o movimento cai. *Configurações → LivIA* permite forçar `sempre` ou
`nunca`, e mostra o movimento atual ao lado do limiar.

Há um tamanho mínimo para o conteúdo poder ser cacheado, e ele varia por modelo. Em vez de
supor, o plugin tenta, guarda o motivo exato da recusa e continua funcionando sem cache — o
pior caso é gastar os tokens de sempre. Mudar a base esquece automaticamente o que estava
guardado.

Exceção honesta: a regra de vazamento (25 palavras seguidas) é mais longa que a janela, então
quando ela dispara umas poucas palavras já podem ter aparecido. O evento de bloqueio manda o
widget descartar o balão inteiro, mas a garantia forte é para contato, valor e endereço.

Rota pública, sem login. A defesa não é autenticação — é o teto de entrada, o limite de
ritmo e o disjuntor.

### Os limites, e por que cada um tem o número que tem

| Limite | Valor | Motivo |
|---|---|---|
| Tamanho da pergunta | 500 caracteres | Dúvida sobre campo de formulário cabe folgado. Barra antes de virar token. |
| Mensagens por conversa | 12 / 5 min | Um atendimento real tem 3 a 6 perguntas. |
| Mensagens por IP | 40 / 5 min | **Alto de propósito.** Operadora de celular põe muita gente atrás do mesmo IP, e a base toda orienta a preencher pelo celular. |
| Sessões novas por IP | 30 / hora | Mesmo motivo. |
| Chamadas à API por dia | 200 | O disjuntor. Filtro `livia_teto_diario` para mudar. |

Separar o teto da conversa do teto do IP é o que permite apertar um sem punir quem divide
IP com meio bairro. Trocar de sessão a cada pedido não contorna nada: o teto do IP continua
valendo, e abrir sessão também é limitado.

**Atrás de Cloudflare ou proxy reverso**, diga qual cabeçalho vale — senão o limite por IP
não existe, porque o cliente escreve o próprio `X-Forwarded-For`:

```php
define( 'LIVIA_HEADER_IP', 'HTTP_CF_CONNECTING_IP' );
```

> `livia.py` continua existindo como protótipo de terminal, para calibrar uma resposta
> rápido sem subir nada. A trava dele é a versão antiga e mais fraca — o que vale para
> produção é a do plugin.

O que é 100% descartável: o loop de terminal, as cores ANSI e `input()`.

---

## Configurar

Tudo no `.env`:

```
GEMINI_API_KEY=sua-chave
GEMINI_MODEL=gemini-3.5-flash-lite
CANAL_DE_SUPORTE=WhatsApp (47) 3433-5066
```

O `CANAL_DE_SUPORTE` fica **só aqui**. A base usa o marcador `[CANAL_DE_SUPORTE]`, que é
trocado pelo valor real na hora de carregar. Para mudar o contato, muda uma linha — e a
base não precisa ser tocada.

Escreva o contato em texto puro. Nada de link markdown ou HTML: a LivIA repete exatamente
o que receber, e o texto tem que funcionar no terminal e, depois, dentro do site.

---

## Testar

São duas suítes, com papéis diferentes. **Não são alternativas — a segunda não substitui a
primeira.**

### 1. Offline — a cada mudança

```bash
php livia/tests/rodar.php
```

75 casos, 50 milissegundos, nenhuma dependência: sem composer, sem `vendor/`, sem banco,
sem HTTP e sem gastar cota. Cobre a trava, a leitura do streaming, a janela retida, os
limites, a sessão, a camada anti-injeção e a redação de dado pessoal.

Roda sozinha no CI a cada push (`.github/workflows/testes.yml`).

As classes de núcleo — `Livia_Trava`, `Livia_Gemini`, `Livia_Base`, `Livia_Stream` — recebem
strings e devolvem strings; só encostam no WordPress para ler configuração e cache.
`livia/tests/stubs-wp.php` finge essa parte, e é o que permite a suíte rodar sem nada
instalado.

| Arquivo | O que cobre |
|---|---|
| `tests/casos-trava.php` | A trava: contato, valor, percentual, URL e vazamento da base. |
| `tests/casos-gemini.php` | A leitura da resposta da API: truncamento, bloqueio de segurança, tokens, JSON quebrado. |
| `tests/casos-fase3.php` | Token, sessão, limites de ritmo, disjuntor e anti-injeção — o fluxo do endpoint inteiro, com a API substituída por um filtro. |
| `tests/casos-fase4.php` | Leitura de SSE e a janela retida, byte a byte. |
| `tests/casos-fase5.php` | Redação de dado pessoal e cálculo de p95. |
| `tests/casos-config.php` | Configuração e invalidação do cache da base. |
| `tests/paridade-base.php` | A base do PHP é byte a byte igual à do Python? |

O teste de paridade precisa do Python para gerar a referência:

```bash
python -c "import livia; livia.carregar_env(); open('ref.txt','w',encoding='utf-8',newline='').write(livia.carregar_base())"
php livia/tests/paridade-base.php ref.txt "WhatsApp (47) 3433-5066"
```

Se divergir, as duas implementações deixaram de ser comparáveis e os casos de teste não
valem mais para as duas ao mesmo tempo.

### 2. Comportamento — ao mexer na base

```bash
python testar.py
python testar.py dominio    # só os casos com "dominio" na pergunta
python testar.py --ver      # mostra a resposta inteira de cada caso
```

Roda os casos de `casos_de_teste.json` **contra o endpoint do plugin**, com o modelo real.
É a LivIA que o cliente encontra: com a trava, o prompt anti-injeção e os limites no
caminho. Gasta cota e leva alguns minutos.

**Rode toda vez que editar a base de conhecimento.**

Precisa de `LIVIA_URL` no `.env`, apontando para a **homologação** — nunca para produção,
que gastaria cota real e encheria a tabela de atendimentos com conversa de robô.

E a homologação precisa afrouxar os limites, senão a bateria esbarra neles: ela abre uma
conversa nova por caso (contexto de um caso não pode vazar no outro), são 34 conversas em
poucos minutos, e o teto de produção é 30 por hora. Copie
`livia/tests/e2e/livia-staging.php` para `wp-content/mu-plugins/` da homologação.

O certo não é baixar a defesa em produção para o teste passar.

**Uma falha isolada nem sempre é defeito.** A LivIA varia o fraseado entre uma execução e
outra, então uma asserção presa a uma palavra exata pode falhar hoje e passar amanhã com a
resposta igualmente correta. Antes de mexer na base, rode aquele caso sozinho com `--ver` e
leia o texto:

- resposta **errada** → o defeito é da base, corrija lá;
- resposta **certa** com outra palavra → o defeito é da asserção, adicione a alternativa em
  `contem_algum`.

Prenda o teste ao que a resposta precisa **dizer**, não às palavras que ela usa para dizer.
Teste que falha à toa é teste que você para de rodar.

---

## As três camadas contra alucinação

**1. A base inteira vai junto de cada pergunta.**
Ela cabe no pedido, então não existe busca nem recorte — a LivIA sempre enxerga tudo que
pode dizer. Sem lacuna, sem invenção para preencher lacuna.

**2. Instrução explícita + temperatura 0,2.**
A seção 2 da base manda responder só o que está escrito e, na dúvida, recusar. A
temperatura baixa faz ela repetir a base em vez de criar variação.

**3. Trava fora do modelo** (`Livia_Trava`, no plugin).
Antes de mostrar qualquer resposta, o servidor varre o texto atrás de **telefone, e-mail,
valor, percentual ou endereço de site que não exista na base** — e de sinais de que a LivIA
está recitando a própria base em vez de responder. Se achar, a resposta é descartada e o
cliente recebe um texto seguro que encaminha para o atendimento.

Durante o streaming ela continua valendo, com a janela retida descrita mais abaixo.

Os "permitidos" saem da própria base — inclusive os exemplos didáticos como
`(47) 99999-9999`, que ela usa de propósito ao explicar os campos. Qualquer contato fora
dessa lista é invenção, por definição.

Essa camada existe porque as duas primeiras são pedido, não garantia. Ela cobre o erro
mais caro: passar para o cliente um contato, um preço ou um link que não existe.

### O que a trava do plugin pega a mais que a do protótipo

| Regra | `livia.py` | `Livia_Trava` |
|---|---|---|
| Telefone fora da base | ✅ | ✅ com âncora, sem fatiar corrida longa de dígitos |
| E-mail fora da base | ✅ | ✅ |
| `R$ 1.500` | ✅ | ✅ |
| `1.500,00` sem `R$` | ❌ passava | ✅ compara por dígitos |
| `mil e quinhentos reais` | ❌ passava | ✅ numerais por extenso |
| `50%` / `cinquenta por cento` | ❌ passava | ✅ |
| `joinvix.com.br/painel` | ❌ passava | ✅ allowlist de domínio **e caminho** |
| Recitar a base | ❌ passava | ✅ título markdown, ou 25 palavras seguidas da instrução |

Duas coisas que não são detalhe:

- **A chave da API fica no servidor**, nunca no navegador. O JavaScript do widget fala com
  o PHP do plugin; o PHP é que fala com o Gemini.
- **A trava roda no servidor**, junto da chamada. Validação no navegador é enfeite —
  qualquer um contorna.

---

## Limpar o registro

*Configurações → LivIA — conversas*, no fim da página. Três escopos: só as de hoje, tudo que é
anterior a hoje, ou o registro inteiro. Exige uma confirmação marcada à mão **e** um
`confirm()` no clique, porque não tem desfazer.

Existe por um motivo prático: a primeira coisa que se faz com a LivIA no ar é conversar com
ela para ver se presta, e essas conversas de teste ficam misturadas com as de cliente para
sempre, estragando toda média que o painel mostrar.

Uma salvaguarda no código: `Livia_Registro::apagar()` com um escopo que não reconhece apaga
**nada**. Um `else` que apagasse a tabela inteira transformaria qualquer erro de digitação,
hoje ou daqui a um ano, em perda total do registro.

## O painel

Um item no menu (*Configurações → LivIA*), duas abas: **Configuração** e **Conversas**. A URL
antiga da tela de conversas continua valendo — ela é registrada e escondida do menu, em vez
de não existir, para quem a tiver nos favoritos não cair num 404.

### A faixa de atenção

Aparece no topo das duas abas, e só mostra o que **exige uma decisão**: falta de chave, o
interruptor desligado, a cota que vai acabar hoje, a reserva assumindo, o cache que não pegou,
conversas com problema hoje (com link para lê-las). Sem nada a dizer, uma linha discreta.

A regra que mantém isso útil é não avisar de rotina. Um painel que avisa de tudo é um painel
que ninguém lê — e aí o aviso que importava passa junto com os outros.

### O que não funcionou

A fila do que arrumar na base: perguntas em que o cliente marcou 👎 ou em que a trava cortou a
resposta, agrupadas por pergunta — a mesma dúvida escrita de dez jeitos é **uma** lacuna, e
vendo as dez separadas ninguém percebe isso.

**Encaminhamento de propósito não entra.** Preço e prazo de contrato *têm* que ir para a
equipe; contá-los como falha encheria a lista do comportamento correto e esconderia o que
precisa de conserto. Erro de API também fica de fora: é rede, não é a base.

### Movimento dos últimos 14 dias

Barras em CSS, sem biblioteca nenhuma — são catorze números, e um pacote de gráfico custaria
mais bytes que a página inteira, vindo de um CDN que veria o IP de quem abre o painel.

A parte azul de cada barra é o que foi ao modelo; o resto foi respondido na hora pelos
atalhos, sem cota. Dia sem conversa aparece vazio e não some: um gráfico que pula os buracos
faz três dias parados virarem duas barras coladas.

Vinte e quatro horas dizem como está agora; não dizem se está piorando.

### Busca

Procura no que foi perguntado e no que foi respondido, e traz a **conversa inteira** em que a
palavra apareceu — não a linha solta. Quem procura "logotipo" quer ler o atendimento, não ver
um fragmento.

## Quando dá errado, o cliente não fica sem saída

Na homologação houve **quatro falhas de conexão em exatamente 25,02 s** — o prazo do cURL
batendo, não o modelo demorando. A conversa simplesmente parava: um balão de desculpa e nada
para clicar. Perguntar de novo exigia digitar tudo outra vez.

| | Era | É |
|---|---|---|
| Prazo de uma tentativa | 25 s | **15 s** |
| Tentativas que cabem no prazo total de 32 s | uma e meia (a segunda nascia com 7 s) | **duas inteiras** |
| Depois da falha | nada | **botão "Tentar de novo"**, que reenvia sem repetir o balão do cliente |

Quinze e não doze porque a p90 medida foi 12,3 s — e isso com a base inteira, que era o dobro
do que vai hoje. Cortar para doze mataria resposta boa. Há caso de teste guardando a
invariante: duas tentativas precisam caber no prazo total, e o prazo de uma precisa ficar
acima da latência medida.

O botão não aparece em limite de ritmo nem com o disjuntor aberto. Ali repetir só piora, e um
botão que não resolve nada ensina a pessoa a não confiar nele.

## O polegar

Embaixo de cada resposta dela, dois ícones discretos. É o sinal mais barato que existe — não
custa token nem chamada de API — e responde a única pergunta que latência e contagem de
mensagem não respondem: **ela acertou?**

Na tela de conversas o voto aparece de dois jeitos: como selo no resumo (`2 ajudou`,
`1 não ajudou`) e colado no turno, dentro do diálogo. Um polegar para baixo conta como
problema tanto quanto um erro — a resposta chegou inteira e mesmo assim não serviu.

A rota `/opiniao` exige o mesmo token assinado da conversa, e a linha só é marcada se
pertencer àquela sessão. Sem isso, quem descobrisse a rota marcaria opinião nas conversas dos
outros e a métrica viraria ruído plantado.

## Repetir o que o cliente digitou não é invenção

A trava cortou uma resposta no meio de `padariaaurora.com.br` — o domínio que o cliente tinha
acabado de escrever, e que a LivIA estava repetindo para confirmar que anotou.

Agora `Livia_Trava::somar_do_cliente()` acrescenta à lista de permitidos o que o próprio
cliente escreveu **naquela conversa**. Três categorias entram e duas ficam de fora:

| | |
|---|---|
| **Entram** | telefone, e-mail, endereço de site — são os dados que o briefing existe para coletar, e confirmá-los de volta é o comportamento certo |
| **Ficam fora** | dinheiro e porcentagem. "Me cobraram 500" repetido de volta vira a LivIA falando de preço, que é o que ela nunca pode fazer — nem ecoando |

Vale só para a conversa em que foi digitado, e o corpus de detecção de vazamento não é
tocado: somar a fala do cliente ali faria a LivIA repetir o cliente contar como se ela
estivesse recitando o documento interno.

Um defeito antigo apareceu junto: a expressão de e-mail é gulosa e engolia o ponto final da
frase, então `contato@exemplo.com.br` e `contato@exemplo.com.br.` viravam chaves diferentes —
e um endereço que estava na base era barrado só por aparecer no fim de uma oração.

## Por que ela não repete o contato da equipe

Na primeira rodada de homologação, o telefone da equipe apareceu em 16 das 37 respostas, e a
construção "chama a equipe no [canal] informando o domínio do seu site" em 9 delas — palavra
por palavra.

A culpa não era do modelo. Estava escrita na base: a Seção 11 dava uma **fórmula** de
encaminhamento e mandava usá-la "sempre", e a Seção 9 se chamava "FAQ com respostas
prontas". Com temperatura 0.2, um documento que se anuncia como texto pronto é um documento
para recitar. Ela estava obedecendo.

São três defesas, e a terceira é a única que não depende do modelo colaborar:

1. **A base mudou.** Seção 11 virou critério, não fórmula — com uma lista explícita de quando
   **não** encaminhar. Seção 9 avisa que dali não se copia.
2. **O lembrete final** repete a regra colado no último turno, que é o que o modelo lê por
   último.
3. **A conversa guarda uma marca.** Quando uma resposta que chegou inteira ao cliente contém
   o contato, `Livia_Sessao::marcar()` anota. No turno seguinte, o pedido leva uma linha
   dizendo que o contato já foi dado.

Dois detalhes que parecem pequenos e não são:

- **O aviso vai no turno, não na instrução.** A instrução é o que o cache de contexto da API
  guarda, e ela precisa ser byte a byte idêntica em toda chamada. Duas variantes fariam o
  cache ser refeito a cada alternância, gastando exatamente o que ele economiza.
- **Só conta resposta que chegou inteira.** Uma barrada pela trava foi trocada pelo texto de
  recusa; uma que deu erro não chegou. Marcar nesses casos faria a LivIA calar um contato que
  ninguém leu.

Na bateria de comportamento, `"encaminhar": false` agora **reprova** a resposta que passa o
contato sem precisar — antes o avaliador só sabia cobrar o encaminhamento que faltou, e era
cego justamente para o que sobrava. Dois casos de contrapeso (`quanto custa o plano`,
`meu site ta fora do ar`) garantem que a correção não virou o defeito oposto.

## O ciclo de melhoria

No protótipo de terminal, cada pergunta e resposta vai para `historico/AAAA-MM-DD.log`.
**No plugin, vai para a tabela `wp_livia_mensagens`** e aparece em
*Configurações → LivIA*, em dois painéis:

**Toda mensagem é registrada** — inclusive as que nunca chegam ao modelo: pergunta comprida
demais, ritmo estourado, disjuntor aberto, sessão encerrada. Elas são justamente o que
alguém precisa ver para entender o que os clientes estão tentando perguntar e não
conseguindo. A coluna `origem` separa `guarda` de `modelo`, para a taxa de erro da API não
virar ficção.

A única exceção é a enxurrada: quando o limite de ritmo dispara, só a **primeira** tentativa
da janela é gravada. Registrar todas daria a um script uma escrita de banco por requisição —
exatamente o que o limite existe para impedir.

Há dois lugares para ler:

| Onde | Responde |
|---|---|
| *Configurações → LivIA* | Conversas e perguntas de hoje e de ontem, perguntas por conversa, barradas, erros, tokens e p95. Mais a cota do dia e o estado do disjuntor. |
| *Configurações → LivIA — conversas* | **De onde vêm as perguntas** (por página e por formulário), **o que mais perguntam** (agrupado por pergunta normalizada), e **todas as mensagens** na ordem em que aconteceram. Com filtro para ver só o que deu problema, e download de tudo em CSV. |

### Métricas por página

Para o relatório por página funcionar, o shortcode precisa dizer de qual briefing se trata:

```
[livia formulario="site-em-72h"]
```

O `formulario` é só um rótulo para a leitura ficar legível. A **página** é identificada pelo
próprio WordPress: o widget manda o ID do post, e o servidor deriva título e endereço a
partir dele. Aceitar o título que o navegador mandasse seria deixar qualquer um escrever no
relatório — e gravar texto arbitrário no banco.

Página com muita pergunta é página que não está se explicando sozinha. Muita coisa barrada
na guarda costuma ser gente escrevendo textão no chat; muita barrada pela trava é a base
induzindo a LivIA a inventar.

As linhas barradas são as mais valiosas do sistema inteiro: **o cliente não viu nenhum
daqueles textos**, mas cada um é uma pergunta que induziu a LivIA a inventar. Ou falta a
informação na base, ou a base induziu o erro.

### LGPD

- **Redação de dado pessoal na gravação.** E-mail, telefone, CPF, CNPJ e CEP saem da
  pergunta do cliente antes de ela chegar ao banco: `meu telefone é [telefone], onde
  coloco?`. Desligue com o filtro `livia_redigir_pii` se assumir o risco.
- **A resposta da LivIA fica intacta**, de propósito. Se ela contém um telefone, ou veio da
  base ou foi inventado — e nos dois casos é exatamente o que alguém precisa ver para
  corrigir a base. Redigir a resposta apagaria a evidência junto com o dado.
- **Retenção de 90 dias**, com expurgo diário automático. Filtro `livia_retencao_dias`.
- **Aviso no widget**, dizendo que a conversa é registrada. Filtro `livia_aviso_privacidade`.
- Apagar o plugin apaga a tabela, as opções e o agendamento.

### O que procurar

- **Recusas que não deveriam ser recusas** → falta essa informação na base. Adicione.
- **Respostas barradas** → veja o que induziu a invenção e corrija a base.
- **Perguntas que se repetem** → viram candidatas à seção 7 (dúvidas gerais).
- **Tokens por dia subindo** → é o aviso antecipado de que o teto diário vai apertar,
  em vez de descobrir no susto às onze da manhã.

Ao adicionar algo na base, **adicione também um caso em `casos_de_teste.json`** e rode
`testar.py`. É assim que a correção de hoje não volta como defeito daqui a três meses.

---

## Custo

O programa não custa nada. O custo é a API do Gemini, que tem camada gratuita no Google AI
Studio, com limite por minuto e por dia.

- `gemini-3.5-flash-lite` (o configurado) é o mais leve da família.
- Estourando o limite, a LivIA responde *"estou com muitas conversas ao mesmo tempo"* e
  volta a funcionar quando a cota renova. Nada quebra, e nada é cobrado.

São três limites, e cada um aperta de um jeito:

| Limite | O que restringe | Quem sente |
|---|---|---|
| **RPM** — requisições por minuto | quantas perguntas por minuto, somando todos os clientes | picos simultâneos |
| **TPM** — tokens por minuto | tamanho da base × número de perguntas | base muito grande |
| **RPD** — requisições por dia | total de perguntas no dia | volume de atendimento |

O **RPD é o que dita a capacidade real**. Uma conversa costuma ter 3 a 6 perguntas, então
o teto diário equivale a algo em torno de 80 a 160 atendimentos por dia — de sobra para
começar, e o número a acompanhar se a LivIA for para dentro dos sites.

O **TPM apertou de verdade.** O primeiro CSV de homologação mediu **527.565 tokens de
entrada para 2.817 de saída** — 187 para 1, ou 14.258 tokens por mensagem para respostas de
76. A base inteira viajava em toda pergunta, inclusive em "o que é domínio".

### Só vai o que a pergunta pede

A base é partida em trechos pelos títulos do próprio documento, e cada pergunta leva o que
precisa. Ver `Livia_Trechos`.

| | |
|---|---|
| **Vai sempre** | identidade e tom, guardrails, escalonamento, o que ela não sabe, mapa de URLs, resumo de uma página |
| **Vai por assunto** | de 8 a 12 trechos escolhidos, dentro de um orçamento de 8 KB |
| **Medido** | 15.544 → **7.193 tokens por mensagem** nas 41 perguntas reais da homologação, 54% a menos |
| **Custo** | 1,1 ms por pergunta, sem rede e sem embeddings |

O que vai sempre não é "o mais importante": é **o que muda toda resposta, ou o que a impede
de inventar**. Nenhum guardrail depende da escolha — se a seleção errar, ela perde acesso a
um trecho e diz que não sabe, que é o lado certo de errar.

Duas decisões de arquitetura que sustentam isso:

- **Regras na instrução, fatos no turno.** A instrução não muda nunca e por isso cabe no
  cache de contexto da API; os trechos mudam a cada pergunta e nunca cacheariam. Separá-los
  é o que deixa as duas economias conviverem.
- **A trava enxerga os dois pedaços.** A lista de permitidos é montada de instrução + trechos.
  Montá-la só da instrução faria a trava cortar a resposta no meio de um endereço legítimo.

Se ela começar a dizer que não sabe coisas que sabia, *Configurações → LivIA* tem o botão
de volta: **"O que vai na pergunta" → "a base inteira, sempre"**.

### O teto do dia é por modelo

A cota do plano free é por modelo, então uma reserva configurada são duas cotas. O contador
é por modelo, o disjuntor só abre quando **todos** acabaram, e o principal esgotado troca
para a reserva antes de gastar a viagem até a API para tomar um 429.

O número fica em *Configurações → LivIA* porque o valor certo é o do modelo que você
escolheu — ele muda por modelo e muda com o tempo. Confira em
https://ai.google.dev/gemini-api/docs/rate-limits.

Acompanhe os tokens no painel (*Configurações → LivIA*).

`testar.py` gasta uma chamada por caso e se auto-limita a 15 por minuto (`RPM_LIMITE`, no
topo do arquivo) para não bater no teto. Uma bateria completa leva alguns minutos — é o
preço de não tomar 429 no meio.

Limites atuais e o que muda no plano pago: https://ai.google.dev/pricing

---

## Arquivos

| Caminho | O que é |
|---|---|
| `livia/` | **O produto.** O plugin de WordPress. |
| `livia/conhecimento/base_conhecimento.md` | **O cérebro.** Tudo que ela sabe e tudo que ela pode dizer. É aqui que você mexe. |
| `livia/includes/` | O núcleo: trava, cliente da API, sessão, limites, rotas, registro, atalhos, seleção de trechos e cadeia de modelos. |
| `livia/admin/` | As duas telas do wp-admin: configuração e conversas. |
| `livia/public/` | O widget: protocolo do streaming, ritmo humano e a janela flutuante. |
| `livia/tests/` | A suíte offline e os stubs do WordPress. |
| `casos_de_teste.json` | Os casos de comportamento, com o que cada resposta precisa (ou não pode) conter. |
| `testar.py` | Roda a bateria de comportamento contra o endpoint. |
| `empacotar.py` | Gera o `.zip` do plugin, sem os testes. |
| `dist/` | O pacote gerado. Não versionado. |
| `livia.py` | O protótipo de terminal. Continua útil para calibrar rápido. |
| `.env` / `.env.example` | Chave, modelo, canal e URL de homologação. O `.env` não é versionado. |
| `historico/` | Log do protótipo de terminal, um arquivo por dia. Não versionado. |
| `VALIDAR-SECAO-7.md` | Checklist das afirmações de processo que precisam ser confirmadas. |

A base mora **dentro do plugin**, e o `livia.py` lê ela de lá. Uma fonte só: o dia em que
existirem duas cópias, elas divergem, e os `casos_de_teste.json` param de valer para as duas.

---

## Antes do primeiro cliente real

A seção 7 da base ("Outras dúvidas sobre o formulário") foi escrita a partir de **suposições
razoáveis sobre o processo da Joinvix**, não de fonte confirmada: como o prazo de 72 horas
conta, o que acontece quando falta material, o que a equipe ajusta depois.

**Nenhuma das três camadas protege contra isso.** A trava só compara contato, valor e
endereço; o teste só confere que a LivIA disse o que a base manda dizer. Se uma regra da
seção 7 estiver errada, ela é repetida com toda a confiança, para todo cliente.

O checklist está em **[VALIDAR-SECAO-7.md](../VALIDAR-SECAO-7.md)** — 16 afirmações ranqueadas
por risco, para levar a quem toca o Site em 72h. Deve levar vinte minutos.

---

## Checklist de go-live

Nenhum item aqui é opcional. Os que dependem de você estão marcados.

| | Item | Como conferir |
|---|---|---|
| 👤 | Chave rotacionada, restrita à API Generative Language | Botão **Testar chave e modelo** na tela de configuração |
| 👤 | Chave fora do banco | `define( 'LIVIA_GEMINI_API_KEY', ... )` no `wp-config.php` |
| 👤 | Seção 7 da base confirmada com quem toca o Site em 72h | [VALIDAR-SECAO-7.md](../VALIDAR-SECAO-7.md) |
| ✅ | Suíte offline verde | `php livia/tests/rodar.php` |
| ✅ | Base do PHP idêntica à do Python | `php livia/tests/paridade-base.php ref.txt "<canal>"` |
| 👤 | Bateria de comportamento verde em homologação | `python testar.py` |
| 👤 | Cabeçalho de IP declarado, se houver proxy | `define( 'LIVIA_HEADER_IP', 'HTTP_CF_CONNECTING_IP' )` |
| 👤 | `pm.max_children` dimensionado para o streaming | Ver *Riscos* abaixo |
| 👤 | Streaming chegando aos poucos, não de uma vez | Abrir o widget e mandar uma pergunta |
| 👤 | Expurgo diário agendado | Ativar o plugin agenda; conferir com WP Crontrol |
| 👤 | Alguém da equipe sabe onde ver as respostas barradas | *Configurações → LivIA* |

### Riscos que continuam de pé

**Processos do PHP-FPM.** Cada resposta em streaming segura um processo pelos 10 a 25
segundos inteiros. Numa hospedagem compartilhada com poucos processos, uma dúzia de
conversas simultâneas trava o site do formulário junto. Dimensione `pm.max_children`
conscientemente antes de abrir para clientes. É o principal custo técnico de rodar isto
dentro do WordPress — gerenciável, desde que medido antes e não descoberto depois.

**Conversa abandonada some das métricas.** Se o cliente fecha a aba no meio do streaming, o
processo é derrubado na próxima escrita (que é o certo: libera o worker na hora) e o
atendimento não chega a ser registrado.

**Contadores não são atômicos.** Duas requisições no mesmo milissegundo podem contar como
uma nos limites de ritmo. Erra por um, não por mil, e não vale um lock por mensagem.

**A base cresce, o TPM aperta.** Ela vai inteira em toda pergunta: dobrar o tamanho dobra o
gasto por resposta. Acompanhe os tokens no painel e ative o cache de contexto do Gemini
quando o volume justificar — o conteúdo é idêntico em toda chamada, que é exatamente o caso
de uso do cache.
