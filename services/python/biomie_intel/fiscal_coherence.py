"""Coerencia fiscal mensuravel: fonte canonica x mart e anomalias cadastrais.

O job nunca corrige tributo nem cadastro sozinho. Ele persiste evidencia para
que defasagem e baixa qualidade deixem de parecer ausencia de movimento.
"""
from __future__ import annotations

import json
from datetime import datetime, timezone
from typing import Any

from .db import execute, fetch_all, fetch_one

MODEL_VERSION = "fiscal-coherence-v1"


def compute(conn, company_id: int) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    source = fetch_all(conn, """SELECT DATE_FORMAT(COALESCE(data_competencia,data_emissao),'%%Y-%%m') competencia,
        COUNT(DISTINCT nota_key) invoices, COALESCE(SUM(valor_total),0) revenue
      FROM biomie_notas_fiscais_canonicas
     WHERE company_id=%s AND status_fiscal='autorizada'
       AND COALESCE(data_competencia,data_emissao) >= DATE_SUB(UTC_DATE(), INTERVAL 15 MONTH)
     GROUP BY competencia""", (company_id,))
    # `valor_total_item` NAO serve para comparar com o total do documento.
    #
    # Ele e `valor_produto + valor_ipi`, sem subtrair o desconto — conferido em
    # producao: MAG_FILIAL 2026-05 deu 591.229,82 + 19.081,47 = 610.311,29,
    # exato. Comparado ao total da NF isso produzia divergencia ESTRUTURAL em
    # seis das oito competencias, todas com o mart "maior que a fonte", e
    # afogava fevereiro — a unica anomalia real, de R$ 41.195,88.
    #
    # `produto - desconto + ipi` e a composicao comparavel. Com ela, sete das
    # oito viram residuo de frete/despesa acessoria (R$ 600 a R$ 3.000) e
    # fevereiro fica sozinho.
    mart = fetch_all(conn, """SELECT competencia, COUNT(DISTINCT nota_key) invoices, COUNT(*) items,
        COALESCE(SUM(valor_produto - valor_desconto + valor_ipi),0) revenue,
        COALESCE(SUM(valor_total_item),0) revenue_bruto_itens,
        MAX(built_at) built_at
      FROM mart_fiscal_items WHERE company_id=%s AND status_fiscal='autorizada' AND direction='saida'
     GROUP BY competencia""", (company_id,))
    by_month = {str(r["competencia"]): r for r in mart if r.get("competencia")}
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    rows: list[dict[str, Any]] = []
    anomalies: list[dict[str, Any]] = []
    for src in source:
        comp = str(src["competencia"])
        m = by_month.get(comp, {})
        canonical_n = int(src["invoices"] or 0); mart_n = int(m.get("invoices") or 0)
        canonical_revenue = float(src["revenue"] or 0); mart_revenue = float(m.get("revenue") or 0)
        coverage = 100.0 if canonical_n == 0 else min(100.0, mart_n * 100.0 / canonical_n)
        built = m.get("built_at")
        freshness = round((now - built).total_seconds() / 3600, 2) if built else None
        reasons = []
        if coverage < 95: reasons.append("cobertura_abaixo_95")
        if freshness is None or freshness > 30: reasons.append("mart_desatualizado")
        revenue_gap_pct = 0 if canonical_revenue <= 0 else abs(canonical_revenue-mart_revenue) * 100 / canonical_revenue
        if revenue_gap_pct > 1: reasons.append("valor_mart_diverge_da_fonte")
        status = "critical" if coverage < 80 or freshness is None or freshness > 72 else ("warning" if reasons else "ok")
        rows.append({"competencia": comp, "canonical_invoices": canonical_n, "mart_invoices": mart_n,
            "mart_items": int(m.get("items") or 0), "canonical_revenue": canonical_revenue,
            "mart_revenue": mart_revenue,
            # DIFERENCA COM SINAL, nao "quanto falta".
            #
            # O campo antigo era `max(0.0, canonical - mart)`: quando o mart era
            # MAIOR que a fonte, a diferenca virava 0,00 — e a tela mostrava
            # "valor do mart diverge da fonte: R$ 0,00", que se contradiz. Foi o
            # que aconteceu em quatro dos cinco warnings da MAG_FILIAL.
            #
            # Positivo = falta valor no mart. Negativo = o mart tem a mais.
            # As duas direcoes sao problema e as duas precisam de numero.
            "revenue_delta": round(canonical_revenue - mart_revenue, 2),
            "coverage_pct": round(coverage,2), "mart_built_at": built, "freshness_hours": freshness,
            "status": status, "reasons": reasons})

    missing_ncm = fetch_all(conn, """SELECT competencia, COUNT(*) affected_rows, COALESCE(SUM(valor_total_item),0) affected_value
      FROM mart_fiscal_items WHERE company_id=%s AND status_fiscal='autorizada' AND direction='saida'
       AND (ncm IS NULL OR TRIM(ncm)='') GROUP BY competencia""", (company_id,))
    for r in missing_ncm:
        anomalies.append({"competencia": str(r["competencia"]), "type":"missing_ncm", "severity":"warning",
          "key":"sem_ncm", "title":"Itens fiscais sem NCM", "detail":"Cadastro incompleto impede validar aliquota e enquadramento.",
          "rows":int(r["affected_rows"]), "value":float(r["affected_value"] or 0), "evidence":{}})
    divergent = fetch_all(conn, """SELECT competencia,ncm,COUNT(*) affected_rows,MIN(aliq_icms) min_rate,MAX(aliq_icms) max_rate,
        COALESCE(SUM(valor_total_item),0) affected_value FROM mart_fiscal_items
       WHERE company_id=%s AND status_fiscal='autorizada' AND direction='saida' AND ncm<>'' AND aliq_icms>0
       GROUP BY competencia,ncm HAVING COUNT(*)>=2 AND MAX(aliq_icms)-MIN(aliq_icms)>=4""", (company_id,))
    for r in divergent:
        anomalies.append({"competencia":str(r["competencia"]), "type":"ncm_icms_divergent", "severity":"warning",
          "key":str(r["ncm"]), "title":f"Aliquota ICMS divergente no NCM {r['ncm']}",
          "detail":"A mesma classificacao apresenta diferenca relevante; revise UF, CST e regra fiscal antes de alterar cadastro.",
          "rows":int(r["affected_rows"]), "value":float(r["affected_value"] or 0),
          "evidence":{"min_rate":float(r["min_rate"]),"max_rate":float(r["max_rate"])}})
    return rows, anomalies


def persist(conn, company_id: int, rows: list[dict[str, Any]], anomalies: list[dict[str, Any]]) -> int:
    execute(conn, "UPDATE intelligence_fiscal_coherence SET is_current=0 WHERE company_id=%s AND is_current=1", (company_id,))
    execute(conn, "UPDATE intelligence_fiscal_anomalies SET is_current=0 WHERE company_id=%s AND is_current=1", (company_id,))
    for r in rows:
        execute(conn, """INSERT INTO intelligence_fiscal_coherence
          (company_id,competencia,canonical_invoices,mart_invoices,mart_items,canonical_revenue,mart_revenue,
           revenue_delta,coverage_pct,mart_built_at,freshness_hours,status,reasons_json,is_current,computed_at)
          VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,UTC_TIMESTAMP())""",
          (company_id,r["competencia"],r["canonical_invoices"],r["mart_invoices"],r["mart_items"],r["canonical_revenue"],
           r["mart_revenue"],r["revenue_delta"],r["coverage_pct"],r["mart_built_at"],r["freshness_hours"],r["status"],json.dumps(r["reasons"])))
    for a in anomalies:
        execute(conn, """INSERT INTO intelligence_fiscal_anomalies
          (company_id,competencia,anomaly_type,severity,entity_key,title,detail,affected_rows,affected_value,evidence_json,is_current,computed_at)
          VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,UTC_TIMESTAMP())""",
          (company_id,a["competencia"],a["type"],a["severity"],a["key"],a["title"],a["detail"],a["rows"],a["value"],json.dumps(a["evidence"])))
    conn.commit()
    return len(rows)+len(anomalies)
