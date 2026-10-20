#!/usr/bin/env python3
"""Cria o ZIP de homologacao sem fixtures, seeds nem documentacao interna."""

from __future__ import annotations

import io
import subprocess
import sys
import zipfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def git(*arguments: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(ROOT), *arguments])


def main() -> int:
    if git("status", "--porcelain", "--untracked-files=no").strip():
        raise SystemExit("O codigo rastreado tem alteracoes locais; crie o ZIP de um commit limpo.")
    commit = git("rev-parse", "HEAD").decode("ascii").strip()
    for required in (
        "Instalar-PC-Windows.cmd",
        "implantacao/windows/Instalar-TraceLocal.ps1",
        "implantacao/windows/auto-master-homologacao/Configurar.ps1",
    ):
        try:
            git("cat-file", "-e", f"{commit}:{required}")
        except subprocess.CalledProcessError as error:
            raise SystemExit(f"O commit {commit[:7]} nao contem {required}.") from error
    output = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else ROOT / f"DallogixTrace-PC-Windows-{commit[:7]}.zip"
    archive = git(
        "archive",
        "--format=zip",
        "--prefix=DallogixTrace/",
        commit,
        "--",
        ".",
        ":(exclude)testes/**",
        ":(exclude)documentacao/testes/**",
        ":(exclude)documentacao/auditorias/**",
        ":(exclude)banco-de-dados/seeds/**",
        ":(exclude)documentacao/operacao/teste-producao-local.md",
        ":(exclude)documentacao/operacao/simulador-clp.md",
        ":(exclude)documentacao/operacao/plano-entrega-producao.md",
        ":(exclude)documentacao/operacao/plano-execucao-consolidado.md",
        ":(exclude)documentacao/arquitetura/plano-ajuste-geral.md",
        ":(exclude)documentacao/historico-projeto-dallogix-trace.md",
        ":(exclude)documentacao/prompt-mestre-dallogix-trace.md",
        ":(exclude)documentacao/revisao-codigo-desempenho.md",
        ":(exclude)scripts/simulate_clp.sh",
    )
    with zipfile.ZipFile(io.BytesIO(archive)) as source, zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as target:
        for member in source.infolist():
            target.writestr(member, source.read(member))
        target.writestr("DallogixTrace/.trace-source-commit", commit + "\n")
    print(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
