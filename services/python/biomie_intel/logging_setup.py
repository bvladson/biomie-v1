"""Log estruturado em JSON, um arquivo por dia.

Sai em JSON porque o resto do BIomie já loga assim (storage/logs/biomie.log) e
porque um log de job precisa ser filtrável por correlation_id quando algo dá
errado às 3 da manhã.
"""

from __future__ import annotations

import json
import logging
import sys
from datetime import datetime, timezone
from pathlib import Path


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


def setup(log_dir: Path, correlation_id: str, verbose: bool = False) -> logging.Logger:
    logger = logging.getLogger("biomie_intel")
    logger.setLevel(logging.DEBUG if verbose else logging.INFO)
    logger.handlers.clear()
    logger.propagate = False

    formatter = JsonFormatter(correlation_id)

    try:
        log_dir.mkdir(parents=True, exist_ok=True)
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
