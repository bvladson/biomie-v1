"""Leitura dos orçamentos abertos e gravação dos scores."""

from __future__ import annotations

import json
from datetime import date
from typing import Any

import pymysql

from .db import execute, execute_many, fetch_all
from .scoring import QuoteScore, movimento, percentile_ranks, score_quote

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
           p.nome_vendedor,

           -- Quantas NFs autorizadas este cliente já tem. É o que separa
           -- prospecção de relacionamento em risco.
           (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas n
             WHERE n.company_id = s.company_id
               AND n.codigo_cliente_omie = p.codigo_cliente_omie
               AND n.status_fiscal = 'autorizada') AS cliente_nfs
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


def load_previous_scores(
    conn: pymysql.connections.Connection,
    company_id: int,
    scorer: str,
    run_mode: str,
) -> dict[int, float]:
    """Scores vigentes ANTES desta rodada, para calcular o movimento."""
    rows = fetch_all(
        conn,
        """SELECT omie_order_id, score
             FROM intelligence_quote_scores
            WHERE company_id = %s AND scorer = %s AND run_mode = %s AND is_current = 1""",
        (company_id, scorer, run_mode),
    )
    return {int(r["omie_order_id"]): float(r["score"]) for r in rows}


def apply_movement(scores: list[QuoteScore], anteriores: dict[int, float]) -> None:
    """Enriquece cada score com a variação desde a rodada anterior.

    Muta a lista de propósito: o movimento faz parte do MESMO score gravado, e
    separar em outra estrutura só criaria a chance de os dois divergirem.
    """
    for s in scores:
        direcao, delta, motivo = movimento(s.score, anteriores.get(s.omie_order_id))
        s.features["movimento"] = direcao
        s.features["delta"] = delta
        s.features["score_anterior"] = anteriores.get(s.omie_order_id)
        # "Novo na fila" só interessa se o orçamento já merece atenção — para um
        # score baixo, é ruído na tela.
        if motivo and (direcao != "novo" or s.band != "baixa"):
            s.reasons.insert(0, motivo)


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


#: dias de score já substituído que ficam guardados antes do expurgo.
#
# 90 dias = um trimestre das DUAS séries (determinístico e modelo), que é o que
# a comparação baseline × shadow precisa para decidir promoção. Medido em
# produção: 3.426 linhas/dia, ~880 bytes cada. Sem expurgo isso é 1,1 GB por ano
# numa VPS; com 90 dias a tabela estabiliza em torno de 270 MB.
RETENCAO_DIAS = 90
#: quantas linhas por DELETE. Apagar centenas de milhares numa transação só
#: travaria a tabela e estouraria o redo log — o job de score roda de 2 em 2
#: horas e não pode prender a Agenda enquanto faz faxina.
LOTE_EXPURGO = 5_000
#: teto por rodada: se houver um passivo enorme, ele sai em várias execuções em
#: vez de uma que demora demais e vira `failed` por timeout.
MAX_EXPURGO_POR_RODADA = 100_000


def purge_superseded(
    conn: pymysql.connections.Connection,
    company_id: int,
    retencao_dias: int = RETENCAO_DIAS,
) -> int:
    """Apaga scores JÁ SUBSTITUÍDOS e fora da janela de retenção, de UMA empresa.

    Sem isto a tabela não para de crescer: cada rodada marca as linhas vigentes
    como `is_current = 0` e insere novas, e nada nunca sai. São 12 execuções por
    dia × 5 empresas × ~250 orçamentos.

    QUATRO TRAVAS, e a terceira é a que não pode ser esquecida:

    1. `is_current = 0` — nunca apaga o que está na tela.
    2. `scored_at` fora da retenção — a comparação baseline × modelo exige as
       duas séries ao longo do tempo, não a foto de agora. Expurgo agressivo
       destruiria a evidência que decide a promoção do modelo.
    3. `NOT EXISTS` no feedback — `fk_iqf_score` é `ON DELETE SET NULL`, ou
       seja, o banco NÃO recusa: ele anula o `score_id` do feedback em silêncio.
       Esse campo existe justamente para saber qual score estava na tela quando
       o vendedor respondeu; comparar a resposta com o score vigente meses
       depois compararia com outro número. Perder o elo aqui não daria erro
       nenhum — só tornaria o rótulo inútil no dia em que houver volume para
       treinar.
    4. `company_id` explícito — não existe escrita "para todas as empresas"
       escondida num WHERE nesta camada.

    Em lotes, com commit entre eles, para não segurar lock da tabela que a
    Agenda lê.
    """
    total = 0
    while total < MAX_EXPURGO_POR_RODADA:
        apagadas = execute(
            conn,
            """DELETE FROM intelligence_quote_scores
                WHERE company_id = %s
                  AND is_current = 0
                  AND scored_at < (UTC_TIMESTAMP() - INTERVAL %s DAY)
                  AND NOT EXISTS (
                          SELECT 1 FROM intelligence_quote_feedback f
                           WHERE f.score_id = intelligence_quote_scores.id
                      )
                LIMIT %s""",
            (company_id, int(retencao_dias), int(LOTE_EXPURGO)),
        )
        conn.commit()
        total += apagadas
        if apagadas < LOTE_EXPURGO:
            break
    return total
