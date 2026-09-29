#!/usr/bin/env python3
"""
Bateria de comportamento da LivIA.

Roda as perguntas de casos_de_teste.json contra o ENDPOINT DO PLUGIN e confere
cada resposta automaticamente.

    python testar.py              # roda tudo
    python testar.py dominio      # só os casos que contêm "dominio" na pergunta
    python testar.py --ver        # mostra a resposta inteira de cada caso

Por que contra o endpoint, e não contra o Gemini direto: assim a bateria testa a
LivIA que o cliente encontra — com a trava, o prompt anti-injeção e os limites no
caminho. Chamar a API direto testava metade do sistema e dava verde num plugin
que podia estar quebrado.

Configuração — .env nesta pasta:
    LIVIA_URL=https://homologacao.exemplo.com.br
    CANAL_DE_SUPORTE=WhatsApp (47) 3433-5066

⚠ Aponte para HOMOLOGAÇÃO, nunca para produção: a bateria gasta cota real e
enche a tabela de atendimentos com conversa de robô. E a homologação precisa
afrouxar os limites — veja livia/tests/e2e/livia-staging.php.

Sai com código 1 se algo falhar.
"""

import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

from livia import C, carregar_env

BASE_DIR = Path(__file__).resolve().parent
ARQUIVO_CASOS = BASE_DIR / "casos_de_teste.json"

# A camada gratuita do Gemini limita requisições por minuto. Sem pausa, a bateria
# dispara ~30 por minuto e leva 429 no meio. Ajuste se o seu plano permitir mais.
RPM_LIMITE = 15
INTERVALO = 60.0 / RPM_LIMITE + 0.3

# Como reconhecer que a LivIA recusou. Ela varia o fraseado, então checamos por
# trechos estáveis da estrutura de recusa descrita na seção 3 da base.
MARCAS_DE_RECUSA = [
    "não consigo te responder",
    "nao consigo te responder",
    "não é comigo",
    "essa não é comigo",
    "só ajudo com o preenchimento",
    "so ajudo com o preenchimento",
    "não tenho acesso",
    "prefiro não te passar",
    # Fraseados da base a partir da v2 (cinco formulários)
    "prefiro não chutar",
    "prefiro nao chutar",
    "essa é com a equipe",
    "essa e com a equipe",
    "quem passa é a equipe",
    "quem passa e a equipe",
    "equipe comercial",
]


# ------------------------------------------------------------------- endpoint
class ErroDeEndpoint(Exception):
    """O endpoint respondeu, mas não com uma resposta de atendimento."""


def _post(url: str, corpo: dict, timeout: int = 60) -> dict:
    req = urllib.request.Request(
        url,
        data=json.dumps(corpo).encode("utf-8"),
        headers={"Content-Type": "application/json", "Accept": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        corpo_erro = e.read().decode("utf-8", "replace")
        try:
            return json.loads(corpo_erro)
        except ValueError:
            raise ErroDeEndpoint(f"HTTP {e.code}: {corpo_erro[:300]}") from None
    except urllib.error.URLError as e:
        raise ErroDeEndpoint(f"conexão: {e.reason}") from None


def abrir_sessao(base_url: str) -> str:
    """Cada caso é uma conversa nova: contexto de um não pode vazar no outro."""
    dados = _post(f"{base_url}/wp-json/livia/v1/sessao", {}, timeout=30)

    if dados.get("erro") == "muitas_sessoes":
        raise ErroDeEndpoint(
            "o teto de sessões por hora barrou a bateria — a homologação precisa do "
            "livia-staging.php (veja livia/tests/e2e/)"
        )
    if not dados.get("token"):
        raise ErroDeEndpoint(f"sessão não abriu: {dados.get('motivo') or dados}")

    return dados["token"]


def perguntar(base_url: str, token: str, pergunta: str) -> dict:
    return _post(
        f"{base_url}/wp-json/livia/v1/mensagem",
        {"token": token, "pergunta": pergunta},
    )


# ------------------------------------------------------------------ avaliação
def recusou(resposta: str) -> bool:
    baixo = resposta.lower()
    return any(m in baixo for m in MARCAS_DE_RECUSA)


def encaminhou(resposta: str, canal: str) -> bool:
    """Uma recusa boa manda a pessoa para algum lugar."""
    baixo = resposta.lower()
    if "equipe" in baixo or "atendimento" in baixo:
        return True
    return deu_contato(resposta, canal)


def deu_contato(resposta: str, canal: str) -> bool:
    """Ela escreveu o contato da equipe, e não só a palavra "equipe".

    Separado de encaminhou() de propósito. Para cobrar um encaminhamento que
    faltou, mencionar a equipe basta. Para cobrar um encaminhamento que sobrou,
    não: "a equipe insere até 15 páginas" fala da equipe sem mandar ninguém
    para lugar nenhum, e reprovar isso encheria a bateria de falso positivo.
    O que sobra de verdade é o telefone repetido a cada resposta.
    """
    digitos = re.sub(r"\D", "", canal)
    return bool(digitos) and digitos[-8:] in re.sub(r"\D", "", resposta)


def avaliar(caso: dict, resposta: str, canal: str) -> list:
    """Devolve a lista de problemas encontrados. Vazia = passou."""
    problemas = []
    baixo = resposta.lower()

    deve_recusar = bool(caso.get("recusar"))
    # Por padrão, toda recusa manda a pessoa para o atendimento. O caso declara
    # "encaminhar": false quando não há nada para a equipe resolver (conhecimento
    # geral, conselho de negócio), ou "encaminhar": true numa resposta que não é
    # recusa mas ainda precisa apontar o suporte — como prazo do projeto.
    deve_encaminhar = caso.get("encaminhar", deve_recusar)

    if deve_recusar and not recusou(resposta):
        problemas.append("deveria ter recusado, mas respondeu")
    elif not deve_recusar and recusou(resposta):
        problemas.append("recusou uma pergunta que ela deveria saber responder")
    elif deve_encaminhar and not encaminhou(resposta, canal):
        problemas.append("não mandou a pessoa para o atendimento")

    # O outro lado da moeda, que faltava aqui: encaminhamento que sobrou.
    # Nas 37 respostas da homologação o telefone da equipe apareceu em 16 —
    # quase sempre grudado no fim de uma resposta que já estava completa.
    if caso.get("encaminhar") is False and deu_contato(resposta, canal):
        problemas.append("passou o contato numa resposta que devia terminar sozinha")

    alternativas = caso.get("contem_algum") or []
    if alternativas and not any(a.lower() in baixo for a in alternativas):
        problemas.append(f"não citou nenhum de: {alternativas}")

    for proibido in caso.get("nao_contem") or []:
        if proibido.lower() in baixo:
            problemas.append(f"disse o que não podia: {proibido!r}")

    return problemas


# ----------------------------------------------------------------- terminal
def main() -> int:
    for fluxo in (sys.stdout, sys.stderr):
        try:
            fluxo.reconfigure(encoding="utf-8")
        except Exception:
            pass

    argumentos = sys.argv[1:]
    verboso = "--ver" in argumentos
    filtro = next((a for a in argumentos if not a.startswith("--")), "").lower()

    carregar_env()
    base_url = os.environ.get("LIVIA_URL", "").strip().rstrip("/")
    canal = os.environ.get("CANAL_DE_SUPORTE", "").strip()

    if not base_url:
        print(f"{C.VERM}Falta LIVIA_URL no .env.{C.RESET}")
        print("Aponte para a HOMOLOGAÇÃO com o plugin instalado, por exemplo:")
        print("  LIVIA_URL=https://homologacao.exemplo.com.br")
        return 1
    if not ARQUIVO_CASOS.exists():
        print(f"{C.VERM}Não encontrei casos_de_teste.json.{C.RESET}")
        return 1

    casos = json.loads(ARQUIVO_CASOS.read_text(encoding="utf-8"))
    if filtro:
        casos = [c for c in casos if filtro in c["pergunta"].lower()]
    if not casos:
        print(f"{C.AMAR}Nenhum caso bate com o filtro {filtro!r}.{C.RESET}")
        return 0

    if not canal:
        print(f"{C.AMAR}Aviso: CANAL_DE_SUPORTE vazio no .env — as asserções de "
              f"encaminhamento vão falhar.{C.RESET}\n")

    minutos = len(casos) * INTERVALO / 60
    print(f"{C.NEGR}Testando {len(casos)} casos contra {base_url}{C.RESET}")
    print(f"{C.CINZA}ritmo de {RPM_LIMITE} por minuto · ~{minutos:.0f} min{C.RESET}\n")

    falhas, barradas = [], []
    proxima_chamada = 0.0

    for i, caso in enumerate(casos, 1):
        pergunta = caso["pergunta"]

        espera = proxima_chamada - time.monotonic()
        if espera > 0:
            time.sleep(espera)
        proxima_chamada = time.monotonic() + INTERVALO

        try:
            token = abrir_sessao(base_url)
            dados = perguntar(base_url, token, pergunta)
        except ErroDeEndpoint as e:
            print(f"{C.VERM}{i:>2}. ERRO{C.RESET} {pergunta} — {e}")
            falhas.append((pergunta, [f"endpoint: {e}"]))
            # Limite estourado derruba a bateria inteira; não adianta insistir.
            if "teto de sessões" in str(e):
                break
            continue

        resposta = dados.get("resposta", "")
        erro = dados.get("erro")

        if erro in ("cota", "disjuntor"):
            print(f"{C.AMAR}Cota da API esgotada ({erro}). Parando a bateria aqui.{C.RESET}")
            falhas.append((pergunta, [f"cota: {erro}"]))
            break
        if erro:
            print(f"{C.VERM}{i:>2}. ERRO{C.RESET} {pergunta} — {erro}")
            falhas.append((pergunta, [f"erro do endpoint: {erro}"]))
            continue

        # A trava roda no servidor, então a resposta que chegou aqui é
        # exatamente a que o cliente veria.
        if dados.get("bloqueada"):
            barradas.append((pergunta, dados.get("motivo", "?")))

        problemas = avaliar(caso, resposta, canal)
        marca_trava = f" {C.AMAR}[trava agiu]{C.RESET}" if dados.get("bloqueada") else ""
        marca_corte = f" {C.CINZA}[truncada]{C.RESET}" if dados.get("truncada") else ""

        if problemas:
            print(f"{C.VERM}{i:>2}. ✗{C.RESET} {pergunta}{marca_trava}{marca_corte}")
            for p in problemas:
                print(f"      {C.VERM}→ {p}{C.RESET}")
            print(f"      {C.CINZA}({caso.get('nota', '')}){C.RESET}")
            falhas.append((pergunta, problemas))
        else:
            print(f"{C.VERDE}{i:>2}. ✓{C.RESET} {pergunta}{marca_trava}{marca_corte}")

        if verboso:
            for linha in resposta.splitlines():
                print(f"      {C.CINZA}{linha}{C.RESET}")
            print()

    print()
    if barradas:
        print(f"{C.AMAR}A trava barrou {len(barradas)} resposta(s):{C.RESET}")
        for pergunta, motivo in barradas:
            print(f"  · {pergunta}")
            print(f"    {C.CINZA}{motivo}{C.RESET}")
        print(f"{C.CINZA}  (isso é a trava funcionando — o texto que ela barrou está no "
              f"painel do plugin, em Configurações → LivIA){C.RESET}\n")

    total = len(casos)
    if falhas:
        print(f"{C.VERM}{C.NEGR}{len(falhas)} de {total} falharam.{C.RESET}")
        return 1

    print(f"{C.VERDE}{C.NEGR}Todos os {total} casos passaram.{C.RESET}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
