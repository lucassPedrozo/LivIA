# Validar as afirmações de processo da base

**Por que este arquivo existe.** Quase tudo na `base_conhecimento.md` veio do próprio
formulário: os 13 campos, os obrigatórios, o texto exato das opções, os tipos de arquivo.
Isso é verificável abrindo o formulário.

As afirmações abaixo são de outra natureza — elas descrevem **o processo da Joinvix**, e
foram escritas a partir de suposições razoáveis, não de fonte confirmada.

Nenhuma das três camadas anti-alucinação protege contra isso: a trava só compara contato e
valor, e o teste só confere que a LivIA repetiu o que a base manda repetir. Se uma destas
linhas estiver errada, a LivIA a repete com toda a confiança, para todo cliente, até alguém
perceber.

**Como usar:** leve a alguém que toca o Site em 72h. Para cada linha: confirma, corrige ou
manda recusar. Marque a coluna. Deve levar uns vinte minutos.

---

## Risco alto — promessa que o cliente pode cobrar

Estas viram expectativa contratual na cabeça de quem lê.

| # | A LivIA hoje afirma | Onde | ✔ / corrigir / recusar |
|---|---|---|---|
| 1 | "Ajustes fazem parte do processo." | seção 7, "Vou poder mudar depois?" | |
| 2 | Marcando "podem usar imagens da internet ou geradas por IA", **a equipe procura fotos profissionais** para o site. | seção 7, "Não tenho fotos" | |
| 3 | "A equipe revisa e ajusta os textos antes de colocar no site." (aparece duas vezes) | seções 6 e 7 | |
| 4 | O prazo de **72 horas começa a contar quando a equipe recebe todo o material**. | seção 7, prazo | |
| 5 | Faltando material, o prazo **só começa quando estiver completo**. | seção 7, prazo | |

## Risco alto — lacuna, não afirmação

A base não diz nada sobre isto, e a pergunta vai chegar. Hoje a LivIA improvisa ou recusa
sem motivo.

| # | Pergunta que vai chegar | Situação hoje | Decisão |
|---|---|---|---|
| 6 | "72 horas **corridas ou úteis**?" Quem envia sexta 18h vai cobrar na segunda. | Não está na base. Ela diz "72 horas" sem qualificar. | |
| 7 | "**Quem registra e paga o domínio**, eu ou vocês?" | Não está na base. Cai em recusa por ser confundido com preço. | |

## Risco médio — processo operacional

Se estiver errado, o cliente faz uma coisa que não funciona e a equipe descobre depois.

| # | A LivIA hoje afirma | Onde | ✔ / corrigir / recusar |
|---|---|---|---|
| 8 | Dá para **mandar as fotos por [CANAL_DE_SUPORTE]** e terminar o resto do formulário normalmente. A equipe consegue casar o material com o formulário depois? | seção 6, "Se nada funcionar" | |
| 9 | Link de vídeo (YouTube, Drive) **escrito dentro do campo 9** é lido e usado pela equipe. | seção 7, "Posso mandar vídeo?" | |
| 10 | "Enviei errado / faltou uma coisa" → a equipe resolve por [CANAL_DE_SUPORTE]. | seção 6 | |
| 11 | Dá para fazer o site **sem logo**; basta escrever isso no campo 12 ou 8. | seção 7, "Não tenho logo" | |
| 12 | Sem domínio: escrever o nome desejado + a palavra **"sugestão"** no campo 4. | seção 5 campo 4, seção 8 | |

## Verificável sem perguntar a ninguém

Abra o formulário e teste. Cinco minutos.

| # | A base afirma | Como conferir |
|---|---|---|
| 13 | O formulário **não guarda** o preenchimento; sair da página perde tudo. | Preencher metade, sair, voltar. |
| 14 | O espaço de arquivos **não aceita vídeo**. | Tentar anexar um .mp4. |
| 15 | Limite de **1.000 MB por arquivo** — e o conselho de que "foto de celular quase nunca chega perto disso". Se o limite real for menor (1.000 KB, 10 MB), o conselho está invertido e trava o cliente. | Conferir a configuração do campo. |
| 16 | O aviso do que falta aparece **em vermelho no começo da página**. | Enviar com um obrigatório em branco. |

---

## Depois de validar

1. Corrija a `base_conhecimento.md` no que divergir.
2. Para cada correção, **acrescente um caso em `casos_de_teste.json`** — é assim que a
   correção de hoje não volta como defeito daqui a três meses.
3. Rode `python testar.py`.
4. Apague a seção "⚠ Pendência" do `README.md`, que deixa de valer.
5. O que a Joinvix não puder confirmar, **tire da base**. Sem a informação, a LivIA recusa
   e encaminha — que é o comportamento certo. Uma lacuna é segura; uma afirmação errada não.
