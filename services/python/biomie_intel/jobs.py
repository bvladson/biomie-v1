"""Trilha de execução em intelligence_job_runs.

Um job que morre calado é indistinguível de um job que nunca foi agendado — foi
exatamente isso que manteve compute_forecasts marcado como "nunca executou" por
meses neste projeto. Aqui todo job abre uma linha ANTES de trabalhar e a fecha
como completed ou failed, inclusive quando a exceção sobe.
"""

from __future__ import annotations

import contextlib
import socket
import time
from collections.abc import Iterator
from dataclasses import dataclass, field
from typing import Any

import pymysql

from .db import execute, fetch_one


@dataclass
class JobRun:
    id: int
    job_name: str
    company_id: int | None
    run_mode: str
    rows_in: int = 0
    rows_out: int = 0
    metrics: dict[str, Any] = field(default_factory=dict)
    model_version: str | None = None


@contextlib.contextmanager
def track(
    conn: pymysql.connections.Connection,
    job_name: str,
    *,
    company_id: int | None,
    run_mode: str,
    correlation_id: str,
    model_version: str | None = None,
) -> Iterator[JobRun]:
    started = time.monotonic()

    execute(
        conn,
        """INSERT INTO intelligence_job_runs
               (job_name, company_id, run_mode, status, model_version,
                started_at, hostname, correlation_id)
           VALUES (%s, %s, %s, 'running', %s, UTC_TIMESTAMP(), %s, %s)""",
        (job_name, company_id, run_mode, model_version, socket.gethostname()[:64], correlation_id),
    )
    row = fetch_one(conn, "SELECT LAST_INSERT_ID() AS id")
    run_id = int(row["id"]) if row else 0
    conn.commit()

    run = JobRun(
        id=run_id,
        job_name=job_name,
        company_id=company_id,
        run_mode=run_mode,
        model_version=model_version,
    )

    try:
        yield run
    except BaseException as exc:  # noqa: BLE001 — precisa marcar failed até em KeyboardInterrupt
        conn.rollback()
        _finish(conn, run, "failed", started, error=f"{type(exc).__name__}: {exc}")
        raise
    else:
        _finish(conn, run, "completed", started)


def _finish(
    conn: pymysql.connections.Connection,
    run: JobRun,
    status: str,
    started_monotonic: float,
    error: str | None = None,
) -> None:
    import json

    duration_ms = int((time.monotonic() - started_monotonic) * 1000)
    execute(
        conn,
        """UPDATE intelligence_job_runs
              SET status = %s, finished_at = UTC_TIMESTAMP(), duration_ms = %s,
                  rows_in = %s, rows_out = %s, metrics_json = %s,
                  error_message = %s, model_version = COALESCE(%s, model_version)
            WHERE id = %s""",
        (
            status,
            duration_ms,
            run.rows_in,
            run.rows_out,
            json.dumps(run.metrics, ensure_ascii=False, default=str) if run.metrics else None,
            error[:2000] if error else None,
            run.model_version,
            run.id,
        ),
    )
    conn.commit()
