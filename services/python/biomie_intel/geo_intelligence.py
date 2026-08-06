"""Inteligencia territorial auditavel.

O navegador nao calcula oportunidade: este modulo usa serie mensal, registra a
confianca e persiste a explicacao que chegou ao usuario. O algoritmo e
deliberadamente deterministico; so deve virar modelo estatistico depois de um
backtest provar ganho sobre este baseline.
"""

from __future__ import annotations

import json
import math
import re
import unicodedata
from dataclasses import dataclass, field
from datetime import date
from statistics import mean, median, pstdev
from typing import Any

import pymysql

from .db import execute, execute_many, fetch_all

MODEL_VERSION = "geo-baseline-v1"


def normalize_key(value: Any) -> str:
    text = unicodedata.normalize("NFD", str(value or "").strip().lower())
    text = "".join(char for char in text if unicodedata.category(char) != "Mn")
    return re.sub(r"_+", "_", re.sub(r"[^a-z0-9]+", "_", text)).strip("_")


@dataclass
class GeoScore:
    company_id: int
    competencia: str
    layer_id: str
    territory_key: str
    territory_label: str
    opportunity_score: float
    confidence: str
    months_used: int
    current_revenue: float
    previous_revenue: float
    growth_pct: float | None
    customers: int
    invoices: int
    average_ticket: float
    potential_revenue: float
    forecast_revenue: float | None
    forecast_low: float | None
    forecast_high: float | None
    main_reason: str
    recommended_action: str
    features: dict[str, Any] = field(default_factory=dict)


def _confidence(values: list[float]) -> tuple[str, float]:
    if len(values) < 3:
        return "baixa", 1.0
    avg = mean(values)
    cv = pstdev(values) / avg if avg > 0 else 1.0
    error = min(1.0, max(0.15, cv))
    if len(values) >= 6 and error < 0.25:
        return "alta", error
    if len(values) >= 3 and error < 0.50:
        return "media", error
    return "baixa", error


def _forecast(values: list[float], as_of: date, competencia: str) -> tuple[float | None, float | None, float | None, float]:
    if not values:
        return None, None, None, 1.0
    adjusted = list(values)
    if competencia == as_of.strftime("%Y-%m"):
        days_in_month = 31
        if as_of.month in (4, 6, 9, 11):
            days_in_month = 30
        elif as_of.month == 2:
            days_in_month = 29 if as_of.year % 4 == 0 else 28
        elapsed = max(1, as_of.day)
        adjusted[-1] = adjusted[-1] / elapsed * days_in_month
    recent = adjusted[-3:]
    weights = list(range(1, len(recent) + 1))
    prediction = sum(v * w for v, w in zip(recent, weights)) / sum(weights)
    _, error = _confidence(adjusted)
    spread = prediction * max(0.15, min(1.0, error))
    return prediction, max(0.0, prediction - spread), prediction + spread, error


def _label(key: str) -> str:
    return " ".join(part.capitalize() for part in key.split("_") if part) or "Nao identificado"


def compute_from_rows(rows: list[dict[str, Any]], company_id: int, as_of: date) -> list[GeoScore]:
    series: dict[tuple[str, str], dict[str, dict[str, float]]] = {}
    labels: dict[tuple[str, str], str] = {}
    for row in rows:
        comp = str(row.get("competencia") or "")
        city = normalize_key(row.get("cidade_norm"))
        bairro = normalize_key(row.get("bairro_norm"))
        region = normalize_key(row.get("regiao_nome"))
        revenue = float(row.get("revenue") or 0)
        customers = int(row.get("customers") or 0)
        invoices = int(row.get("invoices") or 0)
        candidates = [("ceara_municipios", city, _label(city))]
        if region:
            candidates.append(("ceara_regioes", region, _label(region)))
        if city and bairro:
            candidates.append(("grande_fortaleza_bairros", f"{city}:{bairro}", f"{_label(bairro)} — {_label(city)}"))
        if city == "fortaleza" and bairro:
            candidates.append(("fortaleza_bairros", bairro, _label(bairro)))
        for layer, key, label in candidates:
            if not key or key in {"sem_cidade", "nao_informado"}:
                continue
            bucket = series.setdefault((layer, key), {}).setdefault(
                comp, {"revenue": 0.0, "customers": 0.0, "invoices": 0.0}
            )
            bucket["revenue"] += revenue
            bucket["customers"] += customers
            bucket["invoices"] += invoices
            labels[(layer, key)] = label

    latest_comp = as_of.strftime("%Y-%m")
    tickets: dict[str, list[float]] = {}
    prelim: list[dict[str, Any]] = []
    for (layer, key), monthly in series.items():
        comps = sorted(c for c in monthly if c <= latest_comp)[-12:]
        if not comps:
            continue
        current = monthly[comps[-1]]
        values = [float(monthly[c]["revenue"]) for c in comps]
        prev_values = values[-4:-1]
        previous = mean(prev_values) if prev_values else 0.0
        customers = int(current["customers"])
        ticket = values[-1] / customers if customers else 0.0
        tickets.setdefault(layer, []).append(ticket)
        prelim.append({"layer": layer, "key": key, "comps": comps, "values": values,
                       "current": current, "previous": previous, "ticket": ticket})

    max_raw = 1.0
    for item in prelim:
        # O guard precisa testar a lista FILTRADA, nao a crua: uma camada em que
        # todos os tickets sao zero (nenhum territorio com cliente identificado
        # no mes) passava pelo `if tickets[...]` e estourava
        # `StatisticsError: no median for empty data`, derrubando o job inteiro
        # daquela empresa. Sem ticket comparavel nao ha benchmark — e zero, e o
        # potencial daquele territorio fica sendo so a recuperacao de queda.
        positivos = [x for x in tickets[item["layer"]] if x > 0]
        benchmark = median(positivos) if positivos else 0.0
        gap = max(0.0, benchmark - item["ticket"]) * int(item["current"]["customers"])
        recovery = max(0.0, item["previous"] - item["values"][-1]) * 0.5
        item["potential"] = gap + recovery
        item["raw"] = math.log1p(item["potential"]) * (1 + min(1.0, int(item["current"]["customers"]) / 20))
        max_raw = max(max_raw, item["raw"])

    output: list[GeoScore] = []
    for item in prelim:
        values = item["values"]
        current = float(values[-1])
        previous = float(item["previous"])
        growth = ((current / previous) - 1) * 100 if previous > 0 else None
        confidence, hist_error = _confidence(values)
        forecast, low, high, forecast_error = _forecast(values, as_of, item["comps"][-1])
        score = round(100 * item["raw"] / max_raw, 2)
        potential = round(float(item["potential"]), 2)
        if confidence == "baixa":
            reason = "Historico ainda insuficiente ou instavel para priorizacao automatica."
            action = "Acompanhar o territorio e ampliar o historico antes de assumir potencial."
        elif growth is not None and growth < -10:
            reason = f"Receita caiu {abs(growth):.1f}% contra a media recente."
            action = "Reativar clientes recorrentes e revisar perdas recentes neste territorio."
        elif potential > 0:
            reason = "Ticket abaixo de territorios comparaveis, com espaco de monetizacao."
            action = "Priorizar recompra e cross-sell nos clientes ativos deste territorio."
        else:
            reason = "Territorio estavel, sem potencial adicional comprovado neste recorte."
            action = "Manter cobertura e monitorar mudancas de frequencia e mix."
        output.append(GeoScore(
            company_id=company_id, competencia=item["comps"][-1], layer_id=item["layer"],
            territory_key=item["key"], territory_label=labels[(item["layer"], item["key"])],
            opportunity_score=score, confidence=confidence, months_used=len(values),
            current_revenue=round(current, 2), previous_revenue=round(previous, 2),
            growth_pct=round(growth, 2) if growth is not None else None,
            customers=int(item["current"]["customers"]), invoices=int(item["current"]["invoices"]),
            average_ticket=round(float(item["ticket"]), 2), potential_revenue=potential,
            forecast_revenue=round(forecast, 2) if forecast is not None else None,
            forecast_low=round(low, 2) if low is not None else None,
            forecast_high=round(high, 2) if high is not None else None,
            main_reason=reason, recommended_action=action,
            features={"historical_error": round(hist_error, 4), "forecast_error": round(forecast_error, 4),
                      "series": [{"competencia": c, "revenue": round(values[i], 2)} for i, c in enumerate(item["comps"])]},
        ))
    return output


def compute(conn: pymysql.connections.Connection, company_id: int, as_of: date) -> list[GeoScore]:
    rows = fetch_all(conn, """
        SELECT competencia, cidade_norm, bairro_norm, regiao_nome,
               SUM(faturamento) AS revenue,
               SUM(qtd_notas) AS invoices,
               COUNT(DISTINCT COALESCE(CAST(codigo_cliente_omie AS CHAR), documento, cliente_nome)) AS customers
          FROM mart_compradores_por_geo
         WHERE company_id = %s AND competencia >= %s AND competencia <= %s
         GROUP BY competencia, cidade_norm, bairro_norm, regiao_nome
    """, (company_id, f"{as_of.year - 1:04d}-{as_of.month:02d}", as_of.strftime("%Y-%m")))
    return compute_from_rows(rows, company_id, as_of)


def persist(conn: pymysql.connections.Connection, company_id: int, scores: list[GeoScore]) -> int:
    execute(conn, "UPDATE intelligence_geo_scores SET is_current=0 WHERE company_id=%s AND is_current=1", (company_id,))
    sql = """INSERT INTO intelligence_geo_scores
      (company_id, competencia, layer_id, territory_key, territory_label, opportunity_score,
       confidence, months_used, current_revenue, previous_revenue, growth_pct, customers,
       invoices, average_ticket, potential_revenue, forecast_revenue, forecast_low, forecast_high,
       main_reason, recommended_action, features_json, model_version, is_current, computed_at)
      VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE territory_label=VALUES(territory_label), opportunity_score=VALUES(opportunity_score),
       confidence=VALUES(confidence), months_used=VALUES(months_used), current_revenue=VALUES(current_revenue),
       previous_revenue=VALUES(previous_revenue), growth_pct=VALUES(growth_pct), customers=VALUES(customers),
       invoices=VALUES(invoices), average_ticket=VALUES(average_ticket), potential_revenue=VALUES(potential_revenue),
       forecast_revenue=VALUES(forecast_revenue), forecast_low=VALUES(forecast_low), forecast_high=VALUES(forecast_high),
       main_reason=VALUES(main_reason), recommended_action=VALUES(recommended_action), features_json=VALUES(features_json),
       is_current=1, computed_at=UTC_TIMESTAMP()"""
    rows = [(
        s.company_id, s.competencia, s.layer_id, s.territory_key, s.territory_label, s.opportunity_score,
        s.confidence, s.months_used, s.current_revenue, s.previous_revenue, s.growth_pct, s.customers,
        s.invoices, s.average_ticket, s.potential_revenue, s.forecast_revenue, s.forecast_low, s.forecast_high,
        s.main_reason, s.recommended_action, json.dumps(s.features, ensure_ascii=False), MODEL_VERSION,
    ) for s in scores]
    count = execute_many(conn, sql, rows)
    conn.commit()
    return count
