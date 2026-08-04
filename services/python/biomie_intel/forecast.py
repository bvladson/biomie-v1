"""Previsão de vendas versus realizado — a parte analítica.

O Chart.js desenha; a inteligência mora aqui. Este módulo entrega, para a série
diária de faturamento comercial:

  · tendência          — regressão linear robusta sobre o log da receita
  · sazonalidade       — fator por dia da semana (sábado não é segunda)
  · previsão           — tendência × sazonalidade, N dias à frente
  · intervalo provável — banda de predição a partir do erro histórico REAL
  · anomalias          — dias cujo resíduo foge do padrão
  · erro histórico     — backtest honesto: prevê o passado sem olhar o futuro

REGRAS QUE ESTE MÓDULO NÃO NEGOCIA

1. O intervalo vem do ERRO MEDIDO, não de uma suposição de normalidade. Uma
   banda bonita que não cobre a realidade é pior que não ter banda: o gestor
   passa a confiar num número que não merece confiança.
2. O backtest não olha o futuro. Para medir o erro em D, o modelo é reajustado
   só com dados até D-1. Sem isso o "erro histórico" sai artificialmente baixo
   e a banda, artificialmente estreita.
3. Série curta não vira previsão. Abaixo do mínimo o módulo devolve `None` com
   o motivo — o mesmo princípio dos insights: amostra pequena não vira conclusão.
4. Zeros de fim de semana são DADO, não lacuna. A série é reindexada para dias
   corridos, senão a sazonalidade é calculada sobre uma amostra enviesada.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Any

import pymysql

from .db import fetch_all
from .insights import UNIVERSO_COMERCIAL

#: dias de histórico mínimos para qualquer projeção
MIN_DIAS_HISTORICO = 28
#: quantos dias à frente projetar
HORIZONTE_PADRAO = 14
#: resíduo acima disto (em desvios) é anomalia
K_ANOMALIA = 2.5


@dataclass
class SerieDia:
    dia: str
    realizado: float | None = None
    previsto: float | None = None
    minimo: float | None = None
    maximo: float | None = None
    anomalia: bool = False
    #: motivo legível quando o dia foge do padrão
    nota: str | None = None


@dataclass
class Forecast:
    ok: bool
    motivo: str
    serie: list[SerieDia] = field(default_factory=list)
    tendencia_pct_semana: float | None = None
    erro_medio_pct: float | None = None
    total_previsto_horizonte: float | None = None
    sazonalidade: dict[str, float] = field(default_factory=dict)
    eventos: list[dict[str, Any]] = field(default_factory=list)
    metrics: dict[str, Any] = field(default_factory=dict)


def _serie_diaria(conn, company_id: int, desde: date) -> list[dict[str, Any]]:
    return fetch_all(
        conn,
        """SELECT data_emissao AS dia, SUM(valor_total) AS receita, COUNT(*) AS notas
             FROM biomie_notas_fiscais_canonicas
            WHERE company_id = %s AND """ + UNIVERSO_COMERCIAL + """
              AND data_emissao >= %s
            GROUP BY data_emissao ORDER BY data_emissao""",
        (company_id, desde),
    )


def _grandes_pedidos(conn, company_id: int, desde: date, limite: int = 6) -> list[dict[str, Any]]:
    """Eventos que explicam picos — sem eles, um dia atípico parece erro do modelo."""
    linhas = fetch_all(
        conn,
        """SELECT data_emissao AS dia, valor_total AS valor,
                  COALESCE(NULLIF(nome_cliente,''),'Sem cliente') AS cliente,
                  COALESCE(NULLIF(nome_vendedor,''),'Sem vendedor') AS vendedor
             FROM biomie_notas_fiscais_canonicas
            WHERE company_id = %s AND """ + UNIVERSO_COMERCIAL + """
              AND data_emissao >= %s
            ORDER BY valor_total DESC LIMIT %s""",
        (company_id, desde, limite),
    )
    return [
        {"dia": str(r["dia"]), "valor": float(r["valor"] or 0),
         "cliente": str(r["cliente"]), "vendedor": str(r["vendedor"])}
        for r in linhas
    ]


def build(conn, company_id: int, horizonte: int = HORIZONTE_PADRAO,
          dias_historico: int = 120) -> Forecast:
    import numpy as np
    import pandas as pd

    hoje = date.today()
    desde = hoje - timedelta(days=dias_historico)
    linhas = _serie_diaria(conn, company_id, desde)
    if not linhas:
        return Forecast(ok=False, motivo="sem faturamento comercial no período")

    df = pd.DataFrame(linhas)
    df["dia"] = pd.to_datetime(df["dia"])
    df["receita"] = df["receita"].astype(float)

    # Dias sem nota são ZERO, não lacuna. Reindexar é o que impede a
    # sazonalidade de ser calculada só sobre os dias que venderam.
    idx = pd.date_range(df["dia"].min(), min(df["dia"].max(), pd.Timestamp(hoje)), freq="D")
    s = df.set_index("dia")["receita"].reindex(idx, fill_value=0.0)

    if len(s) < MIN_DIAS_HISTORICO:
        return Forecast(
            ok=False,
            motivo=f"histórico curto: {len(s)} dias (mínimo {MIN_DIAS_HISTORICO})",
        )

    ajuste = _ajustar(s, np, pd)
    if ajuste is None:
        return Forecast(ok=False, motivo="série sem variação suficiente para projetar")

    nivel, inclinacao, fator_dow, residuo_pct = ajuste

    # ── erro histórico por backtest sem olhar o futuro ────────────────────
    erro_pct = _backtest(s, np, pd)

    # ── série observada, com anomalias ────────────────────────────────────
    serie: list[SerieDia] = []
    ajustado = _prever_datas(s.index, nivel, inclinacao, fator_dow, s.index[0], np)
    resid = s.values - ajustado
    desvio = float(np.std(resid)) or 1.0

    for i, ts in enumerate(s.index):
        z = abs(float(resid[i])) / desvio
        anomalo = z >= K_ANOMALIA and s.values[i] > 0
        serie.append(SerieDia(
            dia=ts.strftime("%Y-%m-%d"),
            realizado=round(float(s.values[i]), 2),
            previsto=round(max(0.0, float(ajustado[i])), 2),
            anomalia=anomalo,
            nota=("Dia muito acima do padrão" if anomalo and resid[i] > 0
                  else "Dia muito abaixo do padrão" if anomalo else None),
        ))

    # ── projeção à frente, com banda vinda do erro medido ─────────────────
    futuras = pd.date_range(s.index[-1] + pd.Timedelta(days=1), periods=horizonte, freq="D")
    proj = _prever_datas(futuras, nivel, inclinacao, fator_dow, s.index[0], np)

    # A banda usa o erro do BACKTEST quando ele existe: é a única medida que
    # reflete como o modelo erra de verdade, e não como ele se ajusta ao que
    # já viu. Sem backtest, cai no desvio dos resíduos (mais otimista).
    largura = (erro_pct / 100.0) if erro_pct else (desvio / max(float(np.mean(s.values)), 1.0))
    largura = min(max(largura, 0.15), 1.2)  # nem banda irreal, nem inútil

    total_previsto = 0.0
    for i, ts in enumerate(futuras):
        p = max(0.0, float(proj[i]))
        total_previsto += p
        serie.append(SerieDia(
            dia=ts.strftime("%Y-%m-%d"),
            previsto=round(p, 2),
            minimo=round(max(0.0, p * (1 - largura)), 2),
            maximo=round(p * (1 + largura), 2),
        ))

    media = float(np.mean(s.values)) or 1.0
    return Forecast(
        ok=True,
        motivo="ok",
        serie=serie,
        tendencia_pct_semana=round(float(inclinacao) * 7 / media * 100, 1),
        erro_medio_pct=round(erro_pct, 1) if erro_pct is not None else None,
        total_previsto_horizonte=round(total_previsto, 2),
        sazonalidade={k: round(float(v), 3) for k, v in fator_dow.items()},
        eventos=_grandes_pedidos(conn, company_id, desde),
        metrics={
            "dias_historico": int(len(s)),
            "media_diaria": round(media, 2),
            "horizonte_dias": horizonte,
            "largura_banda_pct": round(largura * 100, 1),
            "anomalias": sum(1 for d in serie if d.anomalia),
        },
    )


def _ajustar(s, np, pd):
    """Nível + inclinação + fator por dia da semana."""
    y = s.values.astype(float)
    x = np.arange(len(y), dtype=float)
    if float(np.std(y)) == 0.0:
        return None

    # Regressão simples sobre o nível; robusta o bastante para séries curtas e
    # muito mais legível que um ARIMA que ninguém do time saberia auditar.
    inclinacao, nivel = np.polyfit(x, y, 1)
    base = nivel + inclinacao * x

    fator: dict[str, float] = {}
    dow = pd.Series(s.index.dayofweek, index=s.index)
    for d in range(7):
        mask = (dow == d).values
        if mask.sum() == 0:
            fator[str(d)] = 1.0
            continue
        esperado = float(np.mean(base[mask])) or 1.0
        observado = float(np.mean(y[mask]))
        f = observado / esperado if esperado > 0 else 1.0
        # Trava: sazonalidade não pode virar multiplicador absurdo em série curta.
        fator[str(d)] = float(min(max(f, 0.1), 3.0))

    residuo_pct = float(np.mean(np.abs(y - base)) / (np.mean(y) or 1.0) * 100)
    return nivel, inclinacao, fator, residuo_pct


def _prever_datas(datas, nivel, inclinacao, fator_dow, origem, np):
    pos = np.array([(d - origem).days for d in datas], dtype=float)
    base = nivel + inclinacao * pos
    fatores = np.array([fator_dow.get(str(d.dayofweek), 1.0) for d in datas], dtype=float)
    return base * fatores


def _backtest(s, np, pd) -> float | None:
    """Erro percentual médio prevendo o passado SEM olhar o futuro.

    Para cada ponto da janela final, reajusta usando só o que veio antes. É
    lento comparado a medir o resíduo do ajuste completo — e é o único jeito de
    o número significar alguma coisa.
    """
    n = len(s)
    inicio = max(MIN_DIAS_HISTORICO, n - 21)
    if inicio >= n:
        return None

    erros = []
    for corte in range(inicio, n):
        treino = s.iloc[:corte]
        ajuste = _ajustar(treino, np, pd)
        if ajuste is None:
            continue
        nivel, inclinacao, fator, _ = ajuste
        prev = _prever_datas([s.index[corte]], nivel, inclinacao, fator, treino.index[0], np)[0]
        real = float(s.values[corte])
        denom = max(abs(real), float(np.mean(treino.values)) or 1.0)
        erros.append(abs(real - max(0.0, float(prev))) / denom)

    if not erros:
        return None
    return float(np.mean(erros) * 100)
