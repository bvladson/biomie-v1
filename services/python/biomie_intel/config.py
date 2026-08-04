"""Configuração do serviço de inteligência.

As credenciais NUNCA vêm do repositório nem do .env.local.php da aplicação PHP:
este serviço usa um usuário de banco PRÓPRIO e RESTRITO, cujas credenciais moram
em /etc/biomie/intelligence.env (fora do deploy, 0640 root:biomie).

Motivo: o usuário da aplicação tem DDL e escrita em todas as tabelas. Um job de
análise que só precisa LER dados comerciais e ESCREVER em três tabelas não deve
carregar esse poder — se o processo for comprometido ou tiver um bug, o estrago
fica contido no que ele legitimamente usa.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

ENV_FILE = Path(os.environ.get("BIOMIE_INTEL_ENV", "/etc/biomie/intelligence.env"))

# Tabelas que este serviço escreve. Qualquer escrita fora daqui é bug — e o
# usuário do banco é configurado para recusar, não só por convenção.
WRITABLE_TABLES = (
    "intelligence_job_runs",
    "intelligence_quote_scores",
)


class ConfigError(RuntimeError):
    """Configuração ausente ou inválida — falha cedo, não em produção."""


def _load_env_file(path: Path) -> dict[str, str]:
    """Lê um arquivo KEY=value simples. Ignora comentários e linhas vazias."""
    if not path.is_file():
        return {}
    values: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        values[key.strip()] = value.strip().strip("'\"")
    return values


@dataclass(frozen=True)
class Settings:
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    log_dir: Path
    model_dir: Path
    #: 'baseline' (determinístico manda na tela) · 'shadow' (modelo roda calado)
    #: · 'active' (modelo manda na tela). Trocar para 'active' é decisão humana.
    run_mode: str

    @property
    def is_shadow(self) -> bool:
        return self.run_mode == "shadow"


def load_settings() -> Settings:
    env = {**_load_env_file(ENV_FILE), **os.environ}

    missing = [k for k in ("INTEL_DB_NAME", "INTEL_DB_USER", "INTEL_DB_PASSWORD") if not env.get(k)]
    if missing:
        raise ConfigError(
            f"Faltam variáveis {', '.join(missing)}. "
            f"Esperado em {ENV_FILE} ou no ambiente."
        )

    run_mode = env.get("INTEL_RUN_MODE", "baseline")
    if run_mode not in ("baseline", "shadow", "active"):
        raise ConfigError(f"INTEL_RUN_MODE inválido: {run_mode!r}")

    return Settings(
        db_host=env.get("INTEL_DB_HOST", "127.0.0.1"),
        db_port=int(env.get("INTEL_DB_PORT", "3306")),
        db_name=env["INTEL_DB_NAME"],
        db_user=env["INTEL_DB_USER"],
        db_password=env["INTEL_DB_PASSWORD"],
        log_dir=Path(env.get("INTEL_LOG_DIR", "/var/www/biomie/shared/storage/logs/intelligence")),
        model_dir=Path(env.get("INTEL_MODEL_DIR", "/var/www/biomie/shared/storage/intelligence/models")),
        run_mode=run_mode,
    )
