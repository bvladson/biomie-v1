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
#
# CALIBRADO CONTRA A POPULAÇÃO REAL (218 orçamentos abertos, 2026-08-03), não
# contra intuição. A medição mostrou que 93% estão com follow-up atrasado e 91%
# com previsão vencida: tratados como binário, esses dois davam 60/100 para
# quase todo mundo e a agenda inteira virava "alta" — que é o mesmo que não ter
# prioridade nenhuma.
#
# Por isso os dois viraram MAGNITUDE (quanto atrasado), e o peso migrou para o
# que realmente varia nesta base: o valor, que vai de R$ 0 a R$ 523 mil.
W_FOLLOWUP_OVERDUE = 30   # QUANTO a cadência estourou, não SE estourou
W_FORECAST_PRESSURE = 20  # janela de fechamento — pico perto da data, decai depois
W_VALUE = 30              # tamanho do negócio, relativo à própria empresa
W_SILENCE = 12            # tempo parado sem avançar de etapa
W_CHARGE_FATIGUE = 8      # cobranças acumuladas sem desfecho

#: atraso a partir do qual não faz diferença estar "mais atrasado"
SATURACAO_ATRASO_DIAS = 30
#: previsão estourada além disto sugere negócio à deriva, não urgência
DERIVA_PREVISAO_DIAS = 45

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
    """Follow-up atrasado, por MAGNITUDE.

    Satura em 30 dias, não em 7: com 61% da base entre 4 e 15 dias de atraso,
    saturar cedo empilharia a maioria no teto e o score deixaria de ordenar.
    """
    if days_late is None or days_late <= 0:
        return 0.0, None
    points = W_FOLLOWUP_OVERDUE * min(days_late, SATURACAO_ATRASO_DIAS) / SATURACAO_ATRASO_DIAS
    plural = "s" if days_late > 1 else ""
    # Só vira motivo visível quando o atraso é material.
    reason = f"Follow-up atrasado há {days_late} dia{plural}" if days_late >= 7 else None
    return points, reason


def _forecast_pressure(days_to_forecast: int | None) -> tuple[float, str | None]:
    """Janela de fechamento — curva em U invertido.

    Pico quando a data está chegando ou acabou de passar: é aí que a ação do
    vendedor muda o resultado. Previsão estourada há muito tempo NÃO é urgência,
    é negócio à deriva — tratá-la como máxima urgência (o que 91% da base faria)
    afogaria os orçamentos realmente quentes.
    """
    if days_to_forecast is None:
        return 0.0, None

    if days_to_forecast >= 0:
        if days_to_forecast <= 3:
            return W_FORECAST_PRESSURE, f"Fecha em {days_to_forecast} dia(s) pela previsão"
        if days_to_forecast <= 7:
            return W_FORECAST_PRESSURE * 0.7, "Previsão de fechamento nesta semana"
        if days_to_forecast <= 15:
            return W_FORECAST_PRESSURE * 0.35, None
        return 0.0, None

    atraso = abs(days_to_forecast)
    if atraso <= 15:
        return W_FORECAST_PRESSURE * 0.9, f"Previsão venceu há {atraso} dia(s)"
    if atraso <= DERIVA_PREVISAO_DIAS:
        # Decai linearmente de 0,9 até 0,3 entre 15 e 45 dias.
        fator = 0.9 - 0.6 * (atraso - 15) / (DERIVA_PREVISAO_DIAS - 15)
        return W_FORECAST_PRESSURE * fator, f"Previsão venceu há {atraso} dias"
    return W_FORECAST_PRESSURE * 0.2, f"À deriva: previsão venceu há {atraso} dias"


def _value_weight(percentile: float | None, valor: float | None) -> tuple[float, str | None]:
    """Valor RELATIVO à própria empresa.

    Percentil em vez de faixa fixa em reais de propósito: R$ 50 mil é um negócio
    grande para uma das empresas do grupo e rotina para outra. Faixa fixa faria
    o score de uma empresa dominar o da outra.
    """
    if percentile is None:
        # 78 dos 218 orçamentos não casam com pedido canônico e ficam sem valor.
        # Zerar o componente os empurraria todos para "baixa" por FALTA DE DADO,
        # não por mérito — um viés silencioso contra orçamentos mal ligados.
        # Trata como mediano e marca a lacuna nas features, que ficam auditáveis.
        return W_VALUE * 0.35, None
    if percentile >= 0.90:
        return W_VALUE, f"Entre os 10% maiores da empresa (R$ {valor:,.0f})".replace(",", ".")
    if percentile >= 0.75:
        return W_VALUE * 0.75, f"Entre os 25% maiores da empresa (R$ {valor:,.0f})".replace(",", ".")
    if percentile >= 0.50:
        return W_VALUE * 0.50, None
    if percentile >= 0.25:
        return W_VALUE * 0.22, None
    return W_VALUE * 0.08, None


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
        "valor_desconhecido": valor is None,
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
