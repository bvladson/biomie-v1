"""Log estruturado em JSON, um arquivo por dia.

Sai em JSON porque o resto do BIomie já loga assim (storage/logs/biomie.log) e
porque um log de job precisa ser filtrável por correlation_id quando algo dá
errado às 3 da manhã.
"""

from __future__ import annotations

import json
import logging
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

#: por quantos dias o log diário fica antes de ser apagado.
#
# Estes arquivos NÃO servem para o logrotate: o nome já carrega a data
# (`intelligence-2026-08-07.log`), então rotacionar produziria
# `intelligence-2026-08-04.log.1`, que não quer dizer nada. O que falta é
# DELEÇÃO, e quem sabe a política é quem cria os arquivos.
#
# 90 dias cobre com folga a pergunta que esses logs respondem ("por que o job
# falhou na semana passada?") e trava o diretório em ~1,4 MB, contra os ~5,5 MB
# por ano que ele acumulava para sempre.
RETENCAO_DIAS_LOG = 90


class JsonFormatter(logging.Formatter):
    def __init__(self, correlation_id: str) -> None:
        super().__init__()
        self.correlation_id = correlation_id

    def format(self, record: logging.LogRecord) -> str:
        payload = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname,
            "message": record.getMessage(),
            "correlation_id": self.correlation_id,
            "logger": record.name,
        }
        extra = getattr(record, "context", None)
        if isinstance(extra, dict):
            payload["context"] = extra
        if record.exc_info:
            payload["exception"] = self.formatException(record.exc_info)
        return json.dumps(payload, ensure_ascii=False, default=str)


def expurgar_logs_antigos(log_dir: Path, dias: int = RETENCAO_DIAS_LOG) -> int:
    """Apaga os diários fora da janela. NUNCA levanta.

    Roda junto do setup pelo mesmo motivo do expurgo de insights e de scores: um
    agendamento separado só para faxina é mais um job para parar calado. E, como
    lá, a limpeza não pode derrubar a missão — só que aqui é ainda mais forte,
    porque isto roda ANTES do logger existir: uma exceção deixaria o job inteiro
    sem sequer conseguir reportar por que morreu.

    O glob é restrito ao padrão que este módulo cria. Varrer `*.log` alcançaria
    arquivo de outro processo que por acaso divida o diretório.
    """
    limite = time.time() - dias * 86400
    apagados = 0
    try:
        for arquivo in log_dir.glob("intelligence-*.log"):
            try:
                if arquivo.is_file() and arquivo.stat().st_mtime < limite:
                    arquivo.unlink()
                    apagados += 1
            except OSError:
                continue
    except OSError:
        return apagados
    return apagados


def setup(log_dir: Path, correlation_id: str, verbose: bool = False) -> logging.Logger:
    logger = logging.getLogger("biomie_intel")
    logger.setLevel(logging.DEBUG if verbose else logging.INFO)
    logger.handlers.clear()
    logger.propagate = False

    formatter = JsonFormatter(correlation_id)

    try:
        log_dir.mkdir(parents=True, exist_ok=True)
        expurgar_logs_antigos(log_dir)
        file_handler = logging.FileHandler(
            log_dir / f"intelligence-{datetime.now(timezone.utc):%Y-%m-%d}.log",
            encoding="utf-8",
        )
        file_handler.setFormatter(formatter)
        logger.addHandler(file_handler)
    except OSError:
        # Sem permissão de escrita não é motivo para o job morrer — mas o stderr
        # tem que dizer que o rastro em arquivo se perdeu.
        print("AVISO: não consegui escrever o log em arquivo.", file=sys.stderr)

    stream = logging.StreamHandler(sys.stderr)
    stream.setFormatter(formatter)
    logger.addHandler(stream)
    return logger
