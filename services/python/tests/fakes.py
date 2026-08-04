"""Duplo de conexão para testar a LÓGICA sem depender de um MariaDB.

Por que existe: a maior parte do que precisa de teste aqui é análise — série
curta, dia sem venda, largura da banda, anomalia, expurgo por validade. Amarrar
isso a um banco real tornaria os testes lentos, dependentes de ambiente e, o
pior, opcionais na prática: um teste que só roda "quando dá" não trava nada.

O que este duplo NÃO substitui: permissão, transação, rollback e isolamento
entre empresas dependem do banco de verdade e vivem em `dbfixture.py`, que pula
com aviso quando não há MariaDB configurado.

O duplo registra todo SQL executado, então dá para afirmar coisas sobre a
CONSULTA — por exemplo, que o expurgo filtra por company_id — e não só sobre o
retorno.
"""

from __future__ import annotations

import re
from typing import Any


class FakeCursor:
    def __init__(self, conn: "FakeConnection") -> None:
        self._conn = conn
        self._rows: list[dict[str, Any]] = []
        self.rowcount = 0

    def __enter__(self) -> "FakeCursor":
        return self

    def __exit__(self, *exc: object) -> None:
        return None

    def execute(self, sql: str, params: Any = None) -> None:
        self._conn.executed.append((sql, params))
        if self._conn.fail_on and self._conn.fail_on in _squash(sql):
            raise RuntimeError(self._conn.fail_message)
        self._rows = self._conn._next_result(sql)
        self.rowcount = self._conn.rowcount_for(sql, self._rows)

    def executemany(self, sql: str, rows: Any) -> None:
        self._conn.executed.append((sql, list(rows)))
        if self._conn.fail_on and self._conn.fail_on in _squash(sql):
            raise RuntimeError(self._conn.fail_message)
        self._rows = []
        self.rowcount = len(list(rows))

    def fetchall(self) -> list[dict[str, Any]]:
        return list(self._rows)

    def fetchone(self) -> dict[str, Any] | None:
        return self._rows[0] if self._rows else None


class FakeConnection:
    """Conexão programável.

    `results` mapeia um trecho de SQL (normalizado) para o resultado devolvido.
    A busca é por SUBSTRING para o teste não precisar repetir a query inteira —
    o que o tornaria refém de formatação.
    """

    def __init__(
        self,
        results: dict[str, list[dict[str, Any]]] | None = None,
        *,
        rowcounts: dict[str, int] | None = None,
    ) -> None:
        self.results = {_squash(k): v for k, v in (results or {}).items()}
        self.rowcounts = {_squash(k): v for k, v in (rowcounts or {}).items()}
        self.executed: list[tuple[str, Any]] = []
        self.commits = 0
        self.rollbacks = 0
        self.closed = False
        #: quando definido, qualquer SQL contendo este trecho levanta erro
        self.fail_on: str | None = None
        self.fail_message = "falha simulada no banco"

    # -- API usada por db.py ------------------------------------------------
    def cursor(self) -> FakeCursor:
        return FakeCursor(self)

    def commit(self) -> None:
        self.commits += 1

    def rollback(self) -> None:
        self.rollbacks += 1

    def close(self) -> None:
        self.closed = True

    # -- apoio aos testes ---------------------------------------------------
    def _next_result(self, sql: str) -> list[dict[str, Any]]:
        alvo = _squash(sql)
        for trecho, linhas in self.results.items():
            if trecho in alvo:
                return list(linhas)
        return []

    def rowcount_for(self, sql: str, rows: list[dict[str, Any]]) -> int:
        alvo = _squash(sql)
        for trecho, n in self.rowcounts.items():
            if trecho in alvo:
                return n
        return len(rows)

    def sql_executado(self) -> list[str]:
        return [_squash(s) for s, _ in self.executed]

    def params_de(self, trecho: str) -> Any:
        """Parâmetros da primeira consulta que contém `trecho`."""
        alvo = _squash(trecho)
        for sql, params in self.executed:
            if alvo in _squash(sql):
                return params
        raise AssertionError(f"nenhum SQL executado contém {trecho!r}")


def _squash(sql: str) -> str:
    """Normaliza espaços para a busca por substring não depender de formatação."""
    return re.sub(r"\s+", " ", str(sql)).strip()
