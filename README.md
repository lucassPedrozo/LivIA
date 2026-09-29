# LivIA

Plugin de WordPress que coloca uma atendente virtual ao lado dos formulários de briefing: o cliente abre o painel, pergunta do jeito dele — "o que é domínio?", "não consigo anexar minhas imagens", "qual formulário eu uso?" — e recebe a resposta em linguagem de gente leiga, em streaming, com o texto escrito pelo Gemini a partir de uma base de conhecimento em Markdown. Fora do escopo, ela não responde: encaminha para o atendimento humano em vez de inventar. Entra em qualquer página pelo shortcode `[livia]`.

## Visão geral

O produto atende quem está preenchendo um briefing pela primeira vez, e a premissa que organiza o código todo é que **uma resposta errada custa mais caro do que resposta nenhuma**. Por isso o modelo nunca fala direto com o cliente: tudo o que ele escreve passa por uma trava no servidor que varre o texto atrás de telefone, e-mail, valor, percentual ou endereço de site que não exista na base — e de sinais de que ela está recitando o documento interno. Se achar, a resposta é descartada e o cliente recebe um texto seguro que encaminha para a equipe.

A trava continua valendo durante o streaming. O servidor segura uma janela de 120 bytes na ponta e só libera o texto que já foi verificado, então um contato inventado nunca chega inteiro à tela — e isso é testado byte a byte, com a resposta entregue em pedaços de um byte. O ritmo humano (pausa de leitura, revelação palavra por palavra, indicador de digitação) mora no navegador, para não prender um processo do PHP-FPM pelo tempo da encenação.

Cada pergunta leva só o pedaço da base que ela pede: identidade, guardrails e escalonamento vão sempre; o resto é escolhido por assunto, dentro de um orçamento fixo. Isso cortou pela metade os tokens por mensagem sem tirar nenhuma regra do caminho. A suíte offline tem 185 casos e roda sem WordPress, sem banco, sem rede e sem PHPUnit.

## Funcionalidades

- Painel lateral aberto por um botão discreto, com convite temporizado, página ao fundo escurecida e tela cheia no celular.
- Respostas em streaming (SSE) com ritmo humano, e três redes de proteção quando o servidor ou o navegador não sustentam streaming.
- Trava anti-alucinação no servidor, que barra contato, preço, percentual e link fora da base — inclusive por extenso e durante o streaming.
- Seleção de trechos da base por assunto, com orçamento fixo por pergunta e botão para voltar a mandar a base inteira.
- Resposta imediata, sem modelo e sem cota, para cortesia pura ("oi", "obrigado", "tchau").
- Modelo principal e modelo reserva: cota esgotada ou modelo descontinuado troca para a reserva na mesma pergunta.
- Cache de contexto automático, ligado só quando o movimento compensa o custo de armazenamento.
- Disjuntor de custo: teto diário por modelo, limite por conversa, por IP e por sessão nova.
- Proteção contra injeção de prompt e token de sessão assinado em todas as rotas.
- Botão "Tentar de novo" quando a conexão falha, sem obrigar o cliente a digitar tudo outra vez.
- Avaliação de cada resposta com polegar, ligada à sessão para ninguém votar na conversa dos outros.
- Painel administrativo com a faixa de atenção, o que não funcionou, movimento de 14 dias, busca por conversa e exportação em CSV.
- Rota de saúde para monitor de uptime e alertas por e-mail que só disparam quando exigem decisão.
- LGPD: redação de e-mail, telefone, CPF, CNPJ e CEP antes de gravar, retenção de 90 dias e expurgo automático.

## Estrutura do projeto

```text
.
|-- .github/
|   `-- workflows/
|       `-- testes.yml
|-- docs/
|   `-- GUIA-TECNICO.md
|-- livia/
|   |-- admin/
|   |   |-- class-livia-admin.php
|   |   |-- class-livia-relatorios.php
|   |   `-- livia-admin.css
|   |-- conhecimento/
|   |   `-- base_conhecimento.md
|   |-- includes/
|   |   |-- class-livia-alerta.php
|   |   |-- class-livia-atalhos.php
|   |   |-- class-livia-base.php
|   |   |-- class-livia-cache.php
|   |   |-- class-livia-config.php
|   |   |-- class-livia-gemini.php
|   |   |-- class-livia-limites.php
|   |   |-- class-livia-modelos.php
|   |   |-- class-livia-prompt.php
|   |   |-- class-livia-registro.php
|   |   |-- class-livia-rest.php
|   |   |-- class-livia-saude.php
|   |   |-- class-livia-sessao.php
|   |   |-- class-livia-sse.php
|   |   |-- class-livia-stream.php
|   |   |-- class-livia-trava.php
|   |   |-- class-livia-trechos.php
|   |   `-- class-livia-widget.php
|   |-- public/
|   |   |-- livia-widget.css
|   |   `-- livia-widget.js
|   |-- tests/
|   |   |-- e2e/
|   |   |   `-- livia-staging.php
|   |   |-- casos-*.php
|   |   |-- paridade-base.php
|   |   |-- rodar.php
|   |   `-- stubs-wp.php
|   |-- livia.php
|   `-- uninstall.php
|-- .env.example
|-- casos_de_teste.json
|-- CURL - Gemini.txt
|-- empacotar.py
|-- livia.py
|-- testar.py
|-- VALIDAR-SECAO-7.md
`-- README.md
```

## Como executar

Requisitos: PHP 7.4 ou superior para rodar e testar, Python 3 para empacotar e para a bateria de comportamento, e uma chave da API do Gemini para ver a LivIA respondendo de verdade. A chave sai do `.env`, criado a partir do `.env.example`, que nunca é versionado.

Para rodar a suíte offline, que não precisa de nada instalado:

```bash
php livia/tests/rodar.php
```

Para instalar no WordPress durante o desenvolvimento, aponte a pasta `livia/` para `wp-content/plugins/` (um link simbólico é o mais prático), ative em *Plugins* e configure em *Configurações → LivIA*. Depois, coloque o widget numa página:

```text
[livia formulario="site-em-72h"]
```

A chave da API pode ficar fora do banco, definida no `wp-config.php` — ela sempre ganha do campo da tela de configuração:

```php
define( 'LIVIA_GEMINI_API_KEY', 'sua-chave' );
```

Para rodar a bateria de comportamento contra uma homologação com o plugin instalado (gasta cota; nunca aponte para produção):

```bash
python testar.py
```

Para calibrar uma resposta rápido no terminal, sem subir WordPress:

```bash
python livia.py
```

Para gerar o `.zip` que se instala no WordPress:

```bash
python empacotar.py
```

O empacotamento recusa a suíte vermelha e deixa `livia/tests/` de fora. O pacote sai em `dist/`, que não é versionado.

A arquitetura, as rotas, os limites e o motivo de cada número, o monitoramento, o painel, o custo da API e o checklist de go-live estão em [`docs/GUIA-TECNICO.md`](docs/GUIA-TECNICO.md).

## Stacks

- PHP 7.4
- WordPress (plugin, REST API, shortcode, WP-Cron)
- JavaScript (sem framework)
- CSS (sem framework)
- API do Gemini (streaming SSE e cache de contexto)
- Python 3
- GitHub Actions
- Markdown como base de conhecimento

## Hook para portfólio

**Categoria do projeto:** Produto interno / IA aplicada

**Breve descrição:** Plugin de WordPress com uma atendente virtual que tira dúvidas de quem preenche os briefings, responde em streaming a partir de uma base de conhecimento e tem uma trava no servidor que impede o modelo de inventar contato, preço ou link.

**Contexto:** Os formulários de briefing são preenchidos por clientes leigos, que travam em perguntas simples — o que é domínio, como anexar fotos, qual formulário usar — e acabam abandonando o preenchimento ou chamando a equipe. O projeto nasceu para responder essas dúvidas na hora, dentro da própria página, sem que a IA jamais prometa algo que a empresa não oferece.

**Resultado:** Um plugin que responde em linguagem de gente, com ritmo humano, só com o que está escrito na base; encaminha para a equipe o que é preço, prazo de contrato ou fora do escopo; e registra cada conversa em um painel que mostra exatamente o que falta na base.

**Destaques:**

- Trava anti-alucinação fora do modelo, que compara contato, valor, percentual e endereço contra a base e vale também durante o streaming.
- Janela retida de 120 bytes no streaming, testada byte a byte, para que um contato inventado nunca apareça inteiro na tela.
- Seleção de trechos da base por assunto, que reduziu em 54% os tokens por mensagem sem tirar nenhuma regra de segurança do caminho.
- Regras na instrução e fatos no turno, para que o cache de contexto da API e a seleção de trechos convivam.
- Cadeia de modelos com reserva automática e disjuntor de custo por modelo, por IP e por conversa.
- Registro de toda mensagem — inclusive as barradas antes do modelo — com redação de dado pessoal e retenção limitada.
- Painel que lista o que não funcionou agrupado por pergunta, para a base ser corrigida com dado e não com palpite.
- 185 testes offline que rodam com um comando e no CI a cada push, e uma bateria de comportamento com 54 casos contra o modelo real.

**Stacks:**

- PHP
- WordPress
- JavaScript
- CSS
- API do Gemini
- Python
