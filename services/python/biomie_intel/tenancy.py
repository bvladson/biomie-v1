"""Resolução de empresa.

O BIomie é multi-empresa e já teve vazamento entre empresas por consulta a mart_
sem company_id. Esta camada resolve o alvo UMA vez, no começo do job, e devolve
uma lista explícita de empresas — nunca um "todas" implícito no meio de um SQL.
"""

from __future__ import annotations

from dataclasses import dataclass

import pymysql

from .db import fetch_all


@dataclass(frozen=True)
class Company:
    id: int
    slug: str
    name: str


class UnknownCompanyError(RuntimeError):
    pass


def resolve(conn: pymysql.connections.Connection, selector: str = "all") -> list[Company]:
    """Resolve 'all', um slug ou um id numérico para empresas concretas.

    Só empresas ativas entram. Um seletor que não casa com nada é ERRO, não
    lista vazia — um job que roda "para nenhuma empresa" e reporta sucesso é
    pior que um job que falha.
    """
    rows = fetch_all(
        conn,
        """SELECT id, slug, COALESCE(NULLIF(nome_fantasia,''), razao_social) AS nome
             FROM biomie_companies
            WHERE ativo = 1
            ORDER BY id""",
    )
    companies = [Company(int(r["id"]), str(r["slug"]), str(r["nome"])) for r in rows]

    if selector in ("all", "", None):
        if not companies:
            raise UnknownCompanyError("Nenhuma empresa ativa cadastrada.")
        return companies

    needle = str(selector).strip().lower()
    matched = [c for c in companies if c.slug.lower() == needle or str(c.id) == needle]
    if not matched:
        known = ", ".join(c.slug for c in companies)
        raise UnknownCompanyError(f"Empresa {selector!r} não encontrada. Ativas: {known}")
    return matched
