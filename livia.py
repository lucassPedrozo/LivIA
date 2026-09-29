#!/usr/bin/env python3
"""
LivIA — atendente virtual da Joinvix.

Tira dúvidas de quem está preenchendo o formulário "Site em 72h".
Responde SOMENTE com o que está em base_conhecimento.md; fora disso, encaminha
para o atendimento humano.

Uso:
    python livia.py          # atendimento
    python testar.py         # bateria de testes automática

Configuração — arquivo .env nesta pasta, ou variáveis de ambiente:
    GEMINI_API_KEY=sua-chave
    GEMINI_MODEL=gemini-3.5-flash-lite
    CANAL_DE_SUPORTE=WhatsApp (47) 3433-5066

As funções deste arquivo (carregar_base, chamar_gemini, verificar_resposta) são
o "miolo" da LivIA e não dependem do terminal — é o que será reaproveitado quando
o sistema virar um plugin de WordPress.
"""

import json
import os
import re
import sys
import urllib.error
import urllib.request
from datetime import datetime
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent
# A base mora dentro do plugin: uma fonte só, lida tanto pelo PHP quanto por aqui.
ARQUIVO_CONHECIMENTO = BASE_DIR / "livia" / "conhecimento" / "base_conhecimento.md"
PASTA_LOGS = BASE_DIR / "historico"
ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{modelo}:generateContent"

# Quantos turnos anteriores mandar junto. Suficiente para entender "e o outro?",
# sem encher o pedido (e o consumo da cota gratuita).
TURNOS_DE_MEMORIA = 6

MARCADOR_CANAL = "[CANAL_DE_SUPORTE]"

BOAS_VINDAS = """
Oi! Eu sou a LivIA, da Joinvix. 🙂

Estou aqui para te ajudar a preencher o formulário do seu site.
Pode perguntar do seu jeito mesmo, sem formalidade. Por exemplo:

  · o que é domínio?
  · não estou conseguindo anexar minhas fotos
  · o que eu escrevo no campo de serviços?
  · é obrigatório preencher tudo?

Para encerrar, escreva: sair
"""

FORA_DO_AR = """Não consegui responder agora — parece que a conexão falhou.
Tente perguntar de novo daqui a pouco."""

COTA_ESGOTADA = """Estou com muitas conversas ao mesmo tempo agora.
Espere um minutinho e pergunte de novo."""


# ---------------------------------------------------------------- cores ANSI
class C:
    RESET = "\033[0m"
    AZUL = "\033[38;5;33m"
    CINZA = "\033[38;5;245m"
    VERDE = "\033[38;5;35m"
    AMAR = "\033[38;5;179m"
    VERM = "\033[38;5;203m"
    NEGR = "\033[1m"


def carregar_env() -> None:
    """Lê o .env desta pasta e joga em os.environ (sem sobrescrever)."""
    caminho = BASE_DIR / ".env"
    if not caminho.exists():
        return
    for linha in caminho.read_text(encoding="utf-8").splitlines():
        linha = linha.strip()
        if not linha or linha.startswith("#") or "=" not in linha:
            continue
        chave, _, valor = linha.partition("=")
        os.environ.setdefault(chave.strip(), valor.strip().strip('"').strip("'"))


def carregar_base() -> str:
    """Lê a base de conhecimento com o canal de suporte já substituído.

    O contato fica no .env, não espalhado pelo texto. Quando isto virar plugin de
    WordPress, o canal vem de um campo de configuração e a base continua igual.
    """
    texto = ARQUIVO_CONHECIMENTO.read_text(encoding="utf-8")
    canal = os.environ.get("CANAL_DE_SUPORTE", "").strip()
    if canal:
        texto = texto.replace(MARCADOR_CANAL, canal)
    return texto


# ------------------------------------------------------- trava anti-invenção
#
# A instrução da base pede que a LivIA não invente nada, mas instrução é pedido,
# não garantia. Estas funções verificam a resposta ANTES de mostrar ao cliente e
# barram o erro mais caro: passar um contato ou um valor que não existe.

RE_TELEFONE = re.compile(r"\+?\d{0,3}[\s.-]?\(?\d{2}\)?[\s.-]?\d{4,5}[\s.-]?\d{4}")
RE_EMAIL = re.compile(r"[\w.+-]+@[\w-]+\.[\w.-]+")
RE_DINHEIRO = re.compile(r"R\$\s*[\d.,]+|\d+[\d.,]*\s*reais", re.IGNORECASE)


def _digitos(texto: str) -> str:
    return re.sub(r"\D", "", texto)


def permitidos(base: str) -> dict:
    """Contatos e valores que a LivIA PODE dizer: os que existem na base.

    Inclui os exemplos didáticos da base — como (47) 99999-9999 — porque ela usa
    esses exemplos de propósito ao explicar os campos. Qualquer contato fora
    desta lista é invenção.
    """
    return {
        "telefones": {_digitos(m)[-10:] for m in RE_TELEFONE.findall(base) if len(_digitos(m)) >= 10},
        "emails": {m.lower() for m in RE_EMAIL.findall(base)},
        "dinheiro": {m.lower() for m in RE_DINHEIRO.findall(base)},
    }


def verificar_resposta(resposta: str, ok: dict) -> str | None:
    """Devolve o motivo do bloqueio, ou None se a resposta pode ser mostrada."""
    for achado in RE_TELEFONE.findall(resposta):
        d = _digitos(achado)
        if len(d) >= 10 and d[-10:] not in ok["telefones"]:
            return f"telefone que não está na base: {achado.strip()}"

    for achado in RE_EMAIL.findall(resposta):
        if achado.lower() not in ok["emails"]:
            return f"e-mail que não está na base: {achado}"

    for achado in RE_DINHEIRO.findall(resposta):
        if achado.lower() not in ok["dinheiro"]:
            return f"valor em dinheiro que não está na base: {achado.strip()}"

    return None


def resposta_segura(canal: str) -> str:
    """O que dizer quando a resposta do modelo foi barrada."""
    contato = canal or "a nossa equipe de atendimento"
    return (
        "Prefiro não te passar essa informação para não correr o risco de estar errada.\n"
        f"Quem confirma isso com segurança é {contato}.\n\n"
        "Se for dúvida sobre o que preencher no formulário, pode me perguntar que eu ajudo."
    )


# ---------------------------------------------------------------- API Gemini
class CotaEsgotada(Exception):
    """Estourou o limite de uso da API (429)."""


def chamar_gemini(api_key: str, modelo: str, instrucao: str, historico: list) -> str:
    """Envia a conversa para o Gemini e devolve o texto da resposta."""
    corpo = {
        "system_instruction": {"parts": [{"text": instrucao}]},
        "contents": historico,
        # Temperatura baixa: queremos a resposta da base, não criatividade.
        "generationConfig": {"temperature": 0.2, "topP": 0.8, "maxOutputTokens": 1024},
    }
    req = urllib.request.Request(
        ENDPOINT.format(modelo=modelo),
        data=json.dumps(corpo).encode("utf-8"),
        headers={"Content-Type": "application/json", "X-goog-api-key": api_key},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=90) as resp:
            dados = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        if e.code == 429:
            raise CotaEsgotada() from None
        detalhe = e.read().decode("utf-8", "replace")[:600]
        raise RuntimeError(f"HTTP {e.code}: {detalhe}") from None
    except urllib.error.URLError as e:
        raise RuntimeError(f"conexão: {e.reason}") from None

    candidatos = dados.get("candidates") or []
    if not candidatos:
        motivo = dados.get("promptFeedback", {}).get("blockReason", "resposta vazia")
        raise RuntimeError(f"a API não retornou conteúdo ({motivo})")

    partes = candidatos[0].get("content", {}).get("parts") or []
    texto = "".join(p.get("text", "") for p in partes).strip()
    if not texto:
        raise RuntimeError(
            f"resposta sem texto (finishReason: {candidatos[0].get('finishReason', '?')})"
        )
    return texto


def janela(historico: list) -> list:
    """Últimos turnos da conversa, sempre começando por uma fala do cliente.

    A API recusa uma conversa que comece pela fala do modelo, então a fatia é
    ajustada para cair sempre num turno 'user'.
    """
    recorte = historico[-TURNOS_DE_MEMORIA:]
    while recorte and recorte[0]["role"] != "user":
        recorte.pop(0)
    return recorte


# ---------------------------------------------------------------- terminal
def registrar(pergunta: str, resposta: str, bloqueio: str | None = None) -> None:
    """Guarda cada pergunta e resposta em historico/, para você revisar depois.

    Serve para descobrir o que os clientes perguntam de verdade e ir melhorando a
    base_conhecimento.md. Respostas barradas ficam marcadas com BLOQUEADO — essas
    são as mais importantes de ler.
    """
    try:
        PASTA_LOGS.mkdir(exist_ok=True)
        destino = PASTA_LOGS / f"{datetime.now():%Y-%m-%d}.log"
        agora = f"{datetime.now():%H:%M:%S}"
        with destino.open("a", encoding="utf-8") as f:
            f.write(f"\n[{agora}] CLIENTE: {pergunta}\n")
            if bloqueio:
                f.write(f"[{agora}] BLOQUEADO ({bloqueio}): {resposta}\n")
            else:
                f.write(f"[{agora}] LIVIA: {resposta}\n")
    except OSError:
        pass  # log é conveniência; nunca derruba o atendimento


def imprimir_resposta(texto: str) -> None:
    print(f"\n{C.AZUL}{C.NEGR}LivIA{C.RESET}")
    for linha in texto.splitlines():
        print(f"  {linha}")
    print()


def main() -> int:
    # Windows: garante acento e emoji no console
    for fluxo in (sys.stdout, sys.stderr):
        try:
            fluxo.reconfigure(encoding="utf-8")
        except Exception:
            pass

    carregar_env()
    api_key = os.environ.get("GEMINI_API_KEY", "").strip()
    modelo = os.environ.get("GEMINI_MODEL", "gemini-3.5-flash-lite").strip()
    canal = os.environ.get("CANAL_DE_SUPORTE", "").strip()

    if not api_key:
        print(f"{C.VERM}Falta configurar a GEMINI_API_KEY.{C.RESET}")
        print("Crie um arquivo .env nesta pasta com a linha:  GEMINI_API_KEY=sua-chave")
        return 1
    if not ARQUIVO_CONHECIMENTO.exists():
        print(f"{C.VERM}Não encontrei a base de conhecimento:{C.RESET} {ARQUIVO_CONHECIMENTO}")
        return 1
    if not canal:
        print(
            f"{C.AMAR}Aviso: CANAL_DE_SUPORTE não está configurado no .env.{C.RESET}\n"
            f"{C.CINZA}A LivIA vai repetir {MARCADOR_CANAL} literalmente para o cliente.{C.RESET}"
        )

    instrucao = carregar_base()
    liberados = permitidos(instrucao)
    historico: list = []
    print(BOAS_VINDAS)

    while True:
        try:
            pergunta = input(f"{C.VERDE}Você:{C.RESET} ").strip()
        except (EOFError, KeyboardInterrupt):
            print()
            pergunta = "sair"

        if not pergunta:
            continue

        comando = pergunta.lower().lstrip("/")
        if comando in ("sair", "tchau", "encerrar", "exit", "quit"):
            print(f"\n{C.CINZA}  Foi bom te ajudar. Boa sorte com o site! 🙂{C.RESET}\n")
            return 0
        if comando in ("ajuda", "help", "?"):
            print(BOAS_VINDAS)
            continue
        if comando in ("limpar", "recomecar", "recomeçar"):
            historico = []
            print(f"{C.CINZA}  Pronto, comecei do zero. Pode perguntar.{C.RESET}\n")
            continue

        historico.append({"role": "user", "parts": [{"text": pergunta}]})

        try:
            resposta = chamar_gemini(api_key, modelo, instrucao, janela(historico))
        except CotaEsgotada:
            historico.pop()
            imprimir_resposta(COTA_ESGOTADA)
            continue
        except RuntimeError as e:
            historico.pop()
            imprimir_resposta(FORA_DO_AR)
            print(f"{C.CINZA}  (detalhe técnico: {e}){C.RESET}\n")
            continue

        bloqueio = verificar_resposta(resposta, liberados)
        if bloqueio:
            registrar(pergunta, resposta, bloqueio)
            historico.pop()  # não deixa a invenção contaminar os próximos turnos
            imprimir_resposta(resposta_segura(canal))
            print(f"{C.AMAR}  ⚠ resposta barrada — {bloqueio}{C.RESET}")
            print(f"{C.CINZA}  (registrada em historico/ para você revisar){C.RESET}\n")
            continue

        historico.append({"role": "model", "parts": [{"text": resposta}]})
        imprimir_resposta(resposta)
        registrar(pergunta, resposta)


if __name__ == "__main__":
    sys.exit(main())
