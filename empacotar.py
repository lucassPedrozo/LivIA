#!/usr/bin/env python3
"""
Gera o .zip do plugin para subir no WordPress.

    python empacotar.py

Sai em dist/livia-<versao>.zip, com a versão lida do cabeçalho de livia.php.

Por que existe: zipar a pasta na mão leva junto livia/tests/, que não tem o que
fazer num servidor público. Lá dentro está o stubs-wp.php, que redefine
get_option e update_option, e o livia-staging.php, que desliga os limites de
ritmo. Nenhum dos dois faz estrago sozinho, mas nenhum dos dois tem motivo para
estar acessível pela web.

Recusa empacotar se a suíte offline não estiver verde — pacote que sobe com
teste vermelho é pacote que volta.
"""

import re
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

BASE = Path(__file__).resolve().parent
PLUGIN = BASE / "livia"
DESTINO = BASE / "dist"

# O que NÃO vai para o servidor.
PASTAS_FORA = {"tests"}
ARQUIVOS_FORA = {".DS_Store", "Thumbs.db"}
SUFIXOS_FORA = {".rar", ".zip", ".log", ".pyc"}


def versao() -> str:
    cabecalho = (PLUGIN / "livia.php").read_text(encoding="utf-8")
    achado = re.search(r"^\s*\*\s*Version:\s*(.+)$", cabecalho, re.M)
    return achado.group(1).strip() if achado else "0.0.0"


def suite_verde() -> bool:
    print("rodando a suíte offline antes de empacotar...")
    r = subprocess.run(
        [shutil.which("php") or "php", str(PLUGIN / "tests" / "rodar.php")],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
    )
    print((r.stdout or "").strip().splitlines()[-1] if r.stdout else "(sem saída)")
    return r.returncode == 0


def incluir(caminho: Path) -> bool:
    relativo = caminho.relative_to(PLUGIN)
    if relativo.parts and relativo.parts[0] in PASTAS_FORA:
        return False
    if caminho.name in ARQUIVOS_FORA or caminho.suffix.lower() in SUFIXOS_FORA:
        return False
    return True


def main() -> int:
    if not PLUGIN.is_dir():
        print(f"não encontrei {PLUGIN}")
        return 1

    if not suite_verde():
        print("\nsuíte vermelha — não empacotei. Corrija antes de subir.")
        return 1

    DESTINO.mkdir(exist_ok=True)
    saida = DESTINO / f"livia-{versao()}.zip"

    entradas = sorted(c for c in PLUGIN.rglob("*") if c.is_file() and incluir(c))

    with zipfile.ZipFile(saida, "w", zipfile.ZIP_DEFLATED) as z:
        for caminho in entradas:
            # O WordPress espera a pasta do plugin na raiz do zip.
            z.write(caminho, Path("livia") / caminho.relative_to(PLUGIN))

    kb = saida.stat().st_size / 1024
    print(f"\n{saida.relative_to(BASE)} · {len(entradas)} arquivos · {kb:.0f} KB\n")
    for caminho in entradas:
        print(f"  livia/{caminho.relative_to(PLUGIN).as_posix()}")

    fora = sorted(c for c in PLUGIN.rglob("*") if c.is_file() and not incluir(c))
    if fora:
        print(f"\nficaram de fora ({len(fora)}):")
        for caminho in fora:
            print(f"  livia/{caminho.relative_to(PLUGIN).as_posix()}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
