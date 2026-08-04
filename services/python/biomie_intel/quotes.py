"""Leitura dos orçamentos abertos e gravação dos scores."""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import pymysql

from .db import execute, execute_many, fetch_all
from .scoring import QuoteScore, percentile_ranks, score_quote

# "Aberto" = ainda não teve desfecho. A agenda marca desfecho em resolved_at;
# enquanto isso for NULL o orçamento continua cobrável.
OPEN_QUOTES_SQL = """
    SELECT s.id                AS schedule_id,
           s.company_id,
           s.omie_order_id,
           s.seller_id,
           s.quote_created_at,
           s.quote_forecast_on,
           s.next_followup_on,
           s.followup_step,
           s.charge_count,
           s.last_manager_charge_at,
           p.valor_total,
           p.etapa_omie,
           p.status_comercial,
           p.business_channel,
           p.nome_cliente,
           p.nome_vendedor
      FROM biomie_sales_quote_followup_schedule s
      LEFT JOIN biomie_pedidos_canonicos p
             ON p.pedido_id_omie = s.omie_order_id
            AND p.company_id     = s.company_id
     WHERE s.company_id = %s
       AND s.resolved_at IS NULL
"""


def load_open_quotes(
    conn: pymysql.connections.Connection, company_id: int
) -> list[dict[str, Any]]:
    return fetch_all(conn, OPEN_QUOTES_SQL, (company_id,))


def score_company(
    rows: list[dict[str, Any]], as_of: date
) -> list[QuoteScore]:
    """Pontua todos os orçamentos de UMA empresa.

    O percentil de valor é calculado dentro da empresa — por isso a função
    recebe o lote inteiro em vez de linha a linha.
    """
    values = [float(r["valor_total"]) if r.get("valor_total") is not None else None for r in rows]
    ranks = percentile_ranks(values)
    # Quantos orçamentos DE FATO têm valor: é essa a amostra que dá (ou não)
    # direito de afirmar "entre os 10% maiores".
    amostra = sum(1 for v in values if v is not None)
    return [score_quote(row, as_of, rank, amostra) for row, rank in zip(rows, ranks)]


def persist_scores(
    conn: pymysql.connections.Connection,
    scores: list[QuoteScore],
    *,
    company_id: int,
    job_run_id: int,
    scorer: str,
    run_mode: str,
    model_version: str | None = None,
) -> int:
    """Grava os scores marcando os anteriores como não-vigentes.

    Mantém histórico (não faz UPDATE no lugar) porque comparar modelo contra
    baseline exige as duas séries ao longo do tempo, não só a foto de agora.
    """
    if not scores:
        return 0

    # Só o par (scorer, run_mode) desta rodada perde vigência — o baseline não
    # pode ser desmarcado por uma rodada do shadow, senão a tela fica sem score.
    execute(
        conn,
        """UPDATE intelligence_quote_scores
              SET is_current = 0
            WHERE company_id = %s AND scorer = %s AND run_mode = %s AND is_current = 1""",
        (company_id, scorer, run_mode),
    )

    rows = [
        (
            s.company_id,
            s.omie_order_id,
            job_run_id,
            scorer,
            run_mode,
            model_version,
            s.score,
            s.band,
            s.features.get("probabilidade"),
            json.dumps(s.features, ensure_ascii=False, default=str),
            json.dumps(s.reasons, ensure_ascii=False),
        )
        for s in scores
    ]

    inserted = execute_many(
        conn,
        """INSERT INTO intelligence_quote_scores
               (company_id, omie_order_id, job_run_id, scorer, run_mode, model_version,
                score, priority_band, probability, features_json, reasons_json,
                is_current, scored_at)
           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1, UTC_TIMESTAMP())""",
        rows,
    )
    conn.commit()
    return inserted
