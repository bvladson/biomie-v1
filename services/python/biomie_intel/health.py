"""Health check do serviço de inteligência.

Responde três perguntas que um alerta precisa distinguir:
  · consigo conectar no banco com o usuário restrito?
  · as tabelas da camada existem?
  · os jobs estão rodando, ou pararam calados?

O último item é o que pega o modo de falha real deste projeto: job agendado que
morre em silêncio parece idêntico a job que nunca foi agendado.
"""

from __future__ import annotations

from datetime import datetime, timezone
from typing import Any

import pymysql

from .config import WRITABLE_TABLES
from .db import fetch_all, fetch_one

#: acima disto sem execução bem-sucedida, o job é considerado parado
#
# insights roda de 6 em 6 horas (`50 */6` no cron da VPS); 14h dá folga para uma
# execução perdida sem virar alarme falso, e ainda pega o job que morreu. Ficar
# de fora daqui foi o que deixou o insights sem dead-man switch enquanto o
# quote_score tinha um.
MAX_IDLE_HORAS = {"quote_score": 26, "insights": 14}

#: tabelas sem as quais a camada não funciona — a checagem de existência
TABELAS_OBRIGATORIAS = (
    "intelligence_job_runs",
    "intelligence_quote_scores",
    "intelligence_quote_feedback",
    "intelligence_insights",
)


def check(conn: pymysql.connections.Connection) -> dict[str, Any]:
    problemas: list[str] = []

    tabelas = {r["t"] for r in fetch_all(
        conn,
        """SELECT table_name AS t FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name IN (%s)"""
        % ", ".join(["%s"] * len(TABELAS_OBRIGATORIAS)),
        TABELAS_OBRIGATORIAS,
    )}
    faltando = set(TABELAS_OBRIGATORIAS) - tabelas
    if faltando:
        problemas.append(f"tabelas ausentes: {sorted(faltando)} — rode a migration")

    jobs: dict[str, Any] = {}
    for job_name, limite in MAX_IDLE_HORAS.items():
        row = fetch_one(
            conn,
            """SELECT MAX(finished_at) AS ultimo
                 FROM intelligence_job_runs
                WHERE job_name = %s AND status = 'completed'""",
            (job_name,),
        ) if not faltando else None

        ultimo = row["ultimo"] if row else None
        if ultimo is None:
            jobs[job_name] = {"ultimo_sucesso": None, "situacao": "nunca executou"}
            problemas.append(f"{job_name}: nenhuma execução bem-sucedida registrada")
            continue

        idade_h = (datetime.now(timezone.utc).replace(tzinfo=None) - ultimo).total_seconds() / 3600
        situacao = "ok" if idade_h <= limite else "parado"
        jobs[job_name] = {
            "ultimo_sucesso": ultimo.isoformat(),
            "horas_desde": round(idade_h, 1),
            "situacao": situacao,
        }
        if situacao == "parado":
            problemas.append(f"{job_name}: sem sucesso há {idade_h:.1f}h (limite {limite}h)")

    scores = fetch_one(
        conn,
        """SELECT COUNT(*) AS vigentes, MAX(scored_at) AS ultimo
             FROM intelligence_quote_scores WHERE is_current = 1""",
    ) if not faltando else {"vigentes": 0, "ultimo": None}

    # Insights VIGENTES — e vigente significa não vencido, não apenas
    # `is_current = 1`. A tela filtra por `valid_until`, então contar sem esse
    # filtro faria o health dizer "12 leituras" enquanto a home mostra zero.
    insights = fetch_one(
        conn,
        """SELECT COUNT(*) AS vigentes,
                  COUNT(DISTINCT company_id) AS empresas,
                  MAX(computed_at) AS ultimo
             FROM intelligence_insights
            WHERE is_current = 1 AND valid_until > UTC_TIMESTAMP()""",
    ) if not faltando else {"vigentes": 0, "empresas": 0, "ultimo": None}

    # Job "completed" com zero leitura vigente é falha silenciosa: a execução
    # gravou e as linhas venceram sem ninguém substituir. O status do job sozinho
    # não distingue isso de "rodou agora".
    if not faltando and jobs.get("insights", {}).get("situacao") == "ok" \
            and not int(insights["vigentes"] or 0):
        problemas.append("insights: job em dia mas nenhuma leitura vigente na tela")

    return {
        "ok": not problemas,
        "verificado_em": datetime.now(timezone.utc).isoformat(),
        "banco": "conectado",
        "tabelas_gravaveis": list(WRITABLE_TABLES),
        "jobs": jobs,
        "scores_vigentes": int(scores["vigentes"] or 0) if scores else 0,
        "ultimo_score_em": scores["ultimo"].isoformat() if scores and scores.get("ultimo") else None,
        "insights_vigentes": int(insights["vigentes"] or 0) if insights else 0,
        "insights_empresas": int(insights["empresas"] or 0) if insights else 0,
        "ultimo_insight_em": (
            insights["ultimo"].isoformat() if insights and insights.get("ultimo") else None
        ),
        "problemas": problemas,
    }
