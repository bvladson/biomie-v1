"""Score determinístico de prioridade de orçamentos.

O que ele responde: "de todos os orçamentos abertos, quais o vendedor deve tocar
HOJE?" — não "quais vão fechar". Prever fechamento exige rótulo histórico, que
hoje não existe (ver dataset.py). Urgência de follow-up, por outro lado, é
calculável com o que já está no banco, e é o que a Agenda de Orçamentos pede.

DETERMINÍSTICO no sentido forte: mesmo snapshot + mesmo `as_of` = mesmo score,
sem aleatoriedade, sem estado escondido. Isso é o que permite usá-lo como
BASELINE honesto na comparação contra o modelo depois.

Os cinco componentes somam 100. Cada um que dispara devolve o motivo em texto,
porque um número sozinho numa tela de vendedor não muda comportamento — o
vendedor precisa saber POR QUE aquele orçamento subiu.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, datetime
from typing import Any

# Pesos máximos por componente. Somam 100.
W_FOLLOWUP_OVERDUE = 35   # cadência de follow-up estourada — o sinal mais forte
W_FORECAST_PRESSURE = 25  # previsão de fechamento chegando ou vencida
W_VALUE = 20              # tamanho do negócio, relativo à própria empresa
W_SILENCE = 12            # tempo parado sem avançar de etapa
W_CHARGE_FATIGUE = 8      # cobranças acumuladas sem desfecho

BAND_HIGH = 70.0
BAND_MEDIUM = 40.0


@dataclass
class QuoteScore:
    company_id: int
    omie_order_id: int
    score: float
    band: str
    features: dict[str, Any]
    reasons: list[str] = field(default_factory=list)


def _days_between(later: date | None, earlier: date | None) -> int | None:
    if later is None or earlier is None:
        return None
    return (later - earlier).days


def _as_date(value: Any) -> date | None:
    if value is None:
        return None
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    return None


def _followup_overdue(days_late: int | None) -> tuple[float, str | None]:
    """Follow-up atrasado. Satura em 7 dias: passou disso, já está ruim igual."""
    if days_late is None:
        return 0.0, None
    if days_late <= 0:
        return 0.0, None
    points = W_FOLLOWUP_OVERDUE * min(days_late, 7) / 7
    plural = "s" if days_late > 1 else ""
    return points, f"Follow-up atrasado há {days_late} dia{plural}"


def _forecast_pressure(days_to_forecast: int | None) -> tuple[float, str | None]:
    """Previsão de faturamento. Vencida pesa o máximo; longe não pesa nada."""
    if days_to_forecast is None:
        return 0.0, None
    if days_to_forecast < 0:
        return W_FORECAST_PRESSURE, f"Previsão de fechamento venceu há {abs(days_to_forecast)} dia(s)"
    if days_to_forecast <= 3:
        return W_FORECAST_PRESSURE * 0.8, f"Previsão de fechamento em {days_to_forecast} dia(s)"
    if days_to_forecast <= 7:
        return W_FORECAST_PRESSURE * 0.48, "Previsão de fechamento nesta semana"
    if days_to_forecast <= 15:
        return W_FORECAST_PRESSURE * 0.24, "Previsão de fechamento em até 15 dias"
    return 0.0, None


def _value_weight(percentile: float | None, valor: float | None) -> tuple[float, str | None]:
    """Valor RELATIVO à própria empresa.

    Percentil em vez de faixa fixa em reais de propósito: R$ 50 mil é um negócio
    grande para uma das empresas do grupo e rotina para outra. Faixa fixa faria
    o score de uma empresa dominar o da outra.
    """
    if percentile is None:
        return 0.0, None
    if percentile >= 0.90:
        return W_VALUE, f"Entre os 10% maiores da empresa (R$ {valor:,.0f})".replace(",", ".")
    if percentile >= 0.75:
        return W_VALUE * 0.75, f"Entre os 25% maiores da empresa (R$ {valor:,.0f})".replace(",", ".")
    if percentile >= 0.50:
        return W_VALUE * 0.50, None
    if percentile >= 0.25:
        return W_VALUE * 0.25, None
    return W_VALUE * 0.10, None


def _silence(days_open: int | None, etapa: str | None) -> tuple[float, str | None]:
    """Tempo aberto sem sair das etapas iniciais (10 = orçamento, 20 = aprovado)."""
    if days_open is None or days_open <= 0:
        return 0.0, None
    if etapa not in (None, "", "10", "20"):
        return 0.0, None
    if days_open >= 30:
        return W_SILENCE, f"Parado há {days_open} dias sem avançar de etapa"
    if days_open >= 15:
        return W_SILENCE * 0.67, f"Parado há {days_open} dias sem avançar de etapa"
    if days_open >= 7:
        return W_SILENCE * 0.33, None
    return 0.0, None


def _charge_fatigue(charge_count: int) -> tuple[float, str | None]:
    """Cobranças do gestor que não viraram desfecho — sinal de escalar."""
    if charge_count >= 3:
        return W_CHARGE_FATIGUE, f"Já cobrado {charge_count}x sem desfecho"
    if charge_count == 2:
        return W_CHARGE_FATIGUE * 0.62, "Já cobrado 2x sem desfecho"
    if charge_count == 1:
        return W_CHARGE_FATIGUE * 0.25, None
    return 0.0, None


def band_for(score: float) -> str:
    if score >= BAND_HIGH:
        return "alta"
    if score >= BAND_MEDIUM:
        return "media"
    return "baixa"


def score_quote(row: dict[str, Any], as_of: date, value_percentile: float | None) -> QuoteScore:
    """Pontua UM orçamento. Função pura: sem I/O, testável isoladamente."""
    created = _as_date(row.get("quote_created_at"))
    forecast = _as_date(row.get("quote_forecast_on"))
    next_followup = _as_date(row.get("next_followup_on"))

    days_late = _days_between(as_of, next_followup)
    days_to_forecast = _days_between(forecast, as_of)
    days_open = _days_between(as_of, created)
    charge_count = int(row.get("charge_count") or 0)
    etapa = str(row["etapa_omie"]) if row.get("etapa_omie") is not None else None
    valor = float(row["valor_total"]) if row.get("valor_total") is not None else None

    parts = [
        ("followup_overdue", *_followup_overdue(days_late)),
        ("forecast_pressure", *_forecast_pressure(days_to_forecast)),
        ("value_weight", *_value_weight(value_percentile, valor)),
        ("silence", *_silence(days_open, etapa)),
        ("charge_fatigue", *_charge_fatigue(charge_count)),
    ]

    total = round(sum(p[1] for p in parts), 2)
    reasons = [p[2] for p in parts if p[2]]

    features = {
        "as_of": as_of.isoformat(),
        "dias_followup_atrasado": days_late,
        "dias_ate_previsao": days_to_forecast,
        "dias_aberto": days_open,
        "charge_count": charge_count,
        "followup_step": int(row.get("followup_step") or 0),
        "etapa_omie": etapa,
        "valor_total": valor,
        "valor_percentil": round(value_percentile, 4) if value_percentile is not None else None,
        "business_channel": row.get("business_channel"),
        "componentes": {name: round(points, 2) for name, points, _ in parts},
    }

    return QuoteScore(
        company_id=int(row["company_id"]),
        omie_order_id=int(row["omie_order_id"]),
        score=total,
        band=band_for(total),
        features=features,
        reasons=reasons,
    )


def percentile_ranks(values: list[float | None]) -> list[float | None]:
    """Percentil de cada valor dentro da lista (fração de itens <= ele).

    Empates recebem o mesmo percentil — sem isso, dois orçamentos de valor
    idêntico poderiam cair em faixas diferentes só pela ordem da consulta, e o
    score deixaria de ser determinístico.
    """
    present = sorted(v for v in values if v is not None)
    if not present:
        return [None] * len(values)

    ranks: list[float | None] = []
    for v in values:
        if v is None:
            ranks.append(None)
            continue
        count_le = sum(1 for p in present if p <= v)
        ranks.append(count_le / len(present))
    return ranks
