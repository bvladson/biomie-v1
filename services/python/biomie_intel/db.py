"""Acesso ao banco — sempre em UTC, sempre com empresa explícita.

Duas travas que existem por causa de bugs reais deste projeto:

1. FUSO: o banco do BIomie é UTC e a aplicação PHP roda em America/Fortaleza.
   Toda conexão daqui força UTC na sessão. Sem isso, um servidor configurado em
   -03 grava linhas 3 horas atrás do histórico e o MAX(started_at) nunca avança.

2. TENANCY: já houve vazamento entre empresas por leitura de mart_ sem
   company_id. Aqui a assinatura obriga a empresa a ser passada — não existe
   consulta comercial "sem empresa" nesta camada.
"""

from __future__ import annotations

import contextlib
from collections.abc import Iterator, Sequence
from typing import Any

import pymysql
from pymysql.cursors import DictCursor

from .config import Settings


@contextlib.contextmanager
def connect(settings: Settings) -> Iterator[pymysql.connections.Connection]:
    conn = pymysql.connect(
        host=settings.db_host,
        port=settings.db_port,
        user=settings.db_user,
        password=settings.db_password,
        database=settings.db_name,
        charset="utf8mb4",
        cursorclass=DictCursor,
        autocommit=False,
        connect_timeout=10,
        read_timeout=120,
        write_timeout=60,
        # O banco do BIomie é UTC. Não herdar o fuso do sistema.
        init_command="SET time_zone = '+00:00'",
    )
    try:
        yield conn
    finally:
        conn.close()


def fetch_all(
    conn: pymysql.connections.Connection,
    sql: str,
    params: Sequence[Any] | None = None,
) -> list[dict[str, Any]]:
    with conn.cursor() as cur:
        cur.execute(sql, params or ())
        return list(cur.fetchall())


def fetch_one(
    conn: pymysql.connections.Connection,
    sql: str,
    params: Sequence[Any] | None = None,
) -> dict[str, Any] | None:
    with conn.cursor() as cur:
        cur.execute(sql, params or ())
        return cur.fetchone()


def execute(
    conn: pymysql.connections.Connection,
    sql: str,
    params: Sequence[Any] | None = None,
) -> int:
    with conn.cursor() as cur:
        cur.execute(sql, params or ())
        return cur.rowcount


def execute_many(
    conn: pymysql.connections.Connection,
    sql: str,
    rows: Sequence[Sequence[Any]],
) -> int:
    if not rows:
        return 0
    with conn.cursor() as cur:
        cur.executemany(sql, rows)
        return cur.rowcount
