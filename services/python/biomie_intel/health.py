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
import json

import pymysql

from .config import READABLE_TABLES, WRITABLE_TABLES
from .db import fetch_all, fetch_one
# Mesma definição que os insights usam para decidir o que é venda. Reimplementar
# aqui faria o health medir um universo e a home outro.
from .insights import UNIVERSO_COMERCIAL

#: acima disto sem execução bem-sucedida, o job é considerado parado
#
# insights roda de 6 em 6 horas (`50 */6` no cron da VPS); 14h dá folga para uma
# execução perdida sem virar alarme falso, e ainda pega o job que morreu. Ficar
# de fora daqui foi o que deixou o insights sem dead-man switch enquanto o
# quote_score tinha um.
# forecast roda 1x/dia (`10 5 * * *`); 30h cobre uma execução perdida.
MAX_IDLE_HORAS = {"quote_score": 26, "insights": 14, "forecast": 30, "geo_intelligence": 30, "fiscal_mart": 30}

#: tabelas sem as quais a camada não funciona — a checagem de existência
TABELAS_OBRIGATORIAS = (
    "intelligence_job_runs",
    "intelligence_quote_scores",
    "intelligence_quote_feedback",
    "intelligence_insights",
    "intelligence_forecasts",
    "intelligence_geo_scores",
    "intelligence_fiscal_coherence",
    "intelligence_fiscal_anomalies",
)

#: MySQL 1142 = comando negado para o usuário — falta de GRANT, não de tabela
ERRO_SEM_PERMISSAO = 1142
#: MySQL 1146 = tabela não existe
ERRO_TABELA_AUSENTE = 1146


def _prova_de_acesso(conn: pymysql.connections.Connection) -> tuple[dict[str, str], list[str]]:
    """Tenta LER, de fato, cada tabela declarada — não pergunta, faz.

    Existe porque `SHOW GRANTS` diz o que foi concedido e não o que funciona, e
    porque o modo de falha real aqui é silencioso: o forecast perdeu o acesso às
    tabelas de meta e passou a projetar sem meta nenhuma, o que na tela é
    idêntico a empresa que não cadastrou meta. Um SELECT de uma linha custa
    milissegundos e transforma esse silêncio em problema nomeado.
    """
    acesso: dict[str, str] = {}
    problemas: list[str] = []

    for tabela in READABLE_TABLES:
        try:
            fetch_one(conn, f"SELECT 1 AS ok FROM `{tabela}` LIMIT 1")  # noqa: S608 — lista fechada no código
            acesso[tabela] = "ok"
        except pymysql.err.MySQLError as exc:
            codigo = exc.args[0] if exc.args else None
            if codigo == ERRO_SEM_PERMISSAO:
                acesso[tabela] = "sem_permissao"
                problemas.append(
                    f"{tabela}: SELECT negado — rode provision_intelligence_db_user.sh"
                )
            elif codigo == ERRO_TABELA_AUSENTE:
                acesso[tabela] = "tabela_ausente"
                problemas.append(f"{tabela}: tabela não existe — rode a migration")
            else:
                acesso[tabela] = f"erro_{codigo}"
                problemas.append(f"{tabela}: erro {codigo} ao ler")

    return acesso, problemas


def _target_ladder_consistency(conn: pymysql.connections.Connection) -> dict[str, Any]:
    """Compara fonte normalizada com o JSON legado sem esconder divergência."""
    monthly = fetch_all(
        conn,
        """SELECT company_id, vendor_id, `year_month`, target_amount, tiers_json
             FROM biomie_sales_targets_monthly WHERE target_amount > 0""",
    )
    normalized = fetch_all(
        conn,
        """SELECT company_id, vendor_id, `year_month`, level_number, target_amount
             FROM biomie_sales_target_levels WHERE active = 1
            ORDER BY company_id, vendor_id, `year_month`, level_number""",
    )
    actual: dict[tuple[int, str, str], list[float]] = {}
    for row in normalized:
        key = (int(row["company_id"]), str(row["vendor_id"]), str(row["year_month"]))
        actual.setdefault(key, []).append(round(float(row["target_amount"] or 0), 2))
    mismatches: list[dict[str, Any]] = []
    expected_keys: set[tuple[int, str, str]] = set()
    for row in monthly:
        key = (int(row["company_id"]), str(row["vendor_id"]), str(row["year_month"]))
        expected_keys.add(key)
        expected = [round(float(row["target_amount"] or 0), 2)]
        try:
            extras = json.loads(row.get("tiers_json") or "[]")
        except (TypeError, ValueError):
            extras = []
        expected.extend(round(float(value), 2) for value in extras if isinstance(value, (int, float)))
        expected = sorted(set(value for value in expected if value > 0))
        if actual.get(key, []) != expected:
            mismatches.append({
                "company_id": key[0], "vendor_id": key[1], "year_month": key[2],
                "legacy": expected, "normalized": actual.get(key, []),
            })
    for key in set(actual) - expected_keys:
        mismatches.append({
            "company_id": key[0], "vendor_id": key[1], "year_month": key[2],
            "legacy": [], "normalized": actual[key], "reason": "orphan_normalized_levels",
        })
    return {
        "ok": not mismatches,
        "targets_checked": len(monthly),
        "mismatches_count": len(mismatches),
        "mismatches": mismatches[:20],
    }


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

    geo_scores = fetch_one(
        conn,
        """SELECT COUNT(*) AS vigentes, COUNT(DISTINCT company_id) AS empresas,
                  MAX(computed_at) AS ultimo
             FROM intelligence_geo_scores WHERE is_current = 1""",
    ) if not faltando else {"vigentes": 0, "empresas": 0, "ultimo": None}

    # Job "completed" com zero leitura vigente é falha silenciosa: a execução
    # gravou e as linhas venceram sem ninguém substituir. O status do job sozinho
    # não distingue isso de "rodou agora".
    if not faltando and jobs.get("insights", {}).get("situacao") == "ok" \
            and not int(insights["vigentes"] or 0):
        problemas.append("insights: job em dia mas nenhuma leitura vigente na tela")

    acesso_leitura, problemas_de_acesso = _prova_de_acesso(conn)
    problemas.extend(problemas_de_acesso)
    target_ladder = {"ok": False, "status": "indisponivel"}
    if acesso_leitura.get("biomie_sales_targets_monthly") == "ok" \
            and acesso_leitura.get("biomie_sales_target_levels") == "ok":
        try:
            target_ladder = _target_ladder_consistency(conn)
            if not target_ladder["ok"]:
                problemas.append(
                    f"escada de metas: {target_ladder['mismatches_count']} divergencia(s) entre JSON e níveis normalizados"
                )
        except pymysql.err.MySQLError as exc:
            target_ladder = {"ok": False, "status": f"erro_{exc.args[0] if exc.args else 'sql'}"}
            problemas.append("escada de metas: não foi possível reconciliar as duas fontes")

    # Cobertura por empresa: um job "completed" que só atendeu 2 de 5 empresas
    # passa no dead-man switch e deixa três telas vazias. O total agregado
    # esconde exatamente isso.
    cobertura: dict[str, Any] = {}
    if not faltando:
        empresas_ativas = fetch_all(
            conn, "SELECT id, slug FROM biomie_companies WHERE ativo = 1 ORDER BY id"
        )
        com_leitura = {
            int(r["company_id"]) for r in fetch_all(
                conn,
                """SELECT DISTINCT company_id FROM intelligence_insights
                    WHERE is_current = 1 AND valid_until > UTC_TIMESTAMP()""",
            )
        }
        sem_leitura = [str(e["slug"]) for e in empresas_ativas if int(e["id"]) not in com_leitura]

        # Empresa SEM FATURAMENTO COMERCIAL não tem sobre o que concluir, e não
        # ter leitura é a resposta certa — não uma falha.
        #
        # Isto foi apurado, não suposto: a MAG_MATRIZ aparecia aqui todo dia. O
        # funil mostrou 1 NF em toda a história (R$ 199, remessa), fora do
        # universo comercial por definição. A empresa sincroniza normalmente.
        # Sem esta distinção o health apontaria um problema permanente que não
        # tem conserto — e alerta que toca sempre é alerta que ninguém lê.
        com_faturamento = {
            int(r["company_id"]) for r in fetch_all(
                conn,
                """SELECT DISTINCT company_id
                     FROM biomie_notas_fiscais_canonicas
                    WHERE data_emissao >= CURDATE() - INTERVAL 90 DAY
                      AND """ + UNIVERSO_COMERCIAL,
            )
        }
        sem_dado = [
            str(e["slug"]) for e in empresas_ativas
            if int(e["id"]) not in com_leitura and int(e["id"]) not in com_faturamento
        ]
        realmente_sem_leitura = [s for s in sem_leitura if s not in sem_dado]

        cobertura = {
            "empresas_ativas": len(empresas_ativas),
            "com_leitura_vigente": len(empresas_ativas) - len(sem_leitura),
            "sem_leitura_vigente": realmente_sem_leitura,
            # Separado de propósito: some do alerta, mas não some do relatório.
            # Engolir sem deixar rastro seria o outro extremo — se uma empresa
            # que faturava parar de faturar, ela aparece AQUI antes de virar
            # silêncio.
            "sem_faturamento_comercial_90d": sem_dado,
        }
        if realmente_sem_leitura and jobs.get("insights", {}).get("situacao") == "ok":
            problemas.append(
                "insights: job em dia mas sem leitura vigente em "
                + ", ".join(realmente_sem_leitura)
            )

    return {
        "ok": not problemas,
        "verificado_em": datetime.now(timezone.utc).isoformat(),
        "banco": "conectado",
        "tabelas_gravaveis": list(WRITABLE_TABLES),
        "acesso_leitura": acesso_leitura,
        "cobertura": cobertura,
        "target_ladder": target_ladder,
        "jobs": jobs,
        "scores_vigentes": int(scores["vigentes"] or 0) if scores else 0,
        "ultimo_score_em": scores["ultimo"].isoformat() if scores and scores.get("ultimo") else None,
        "insights_vigentes": int(insights["vigentes"] or 0) if insights else 0,
        "insights_empresas": int(insights["empresas"] or 0) if insights else 0,
        "ultimo_insight_em": (
            insights["ultimo"].isoformat() if insights and insights.get("ultimo") else None
        ),
        "geo_scores_vigentes": int(geo_scores["vigentes"] or 0) if geo_scores else 0,
        "geo_scores_empresas": int(geo_scores["empresas"] or 0) if geo_scores else 0,
        "ultimo_geo_score_em": (
            geo_scores["ultimo"].isoformat() if geo_scores and geo_scores.get("ultimo") else None
        ),
        "problemas": problemas,
    }
