"""O score territorial ordena alguma coisa? — backtest sem vazamento.

Hoje há 610 scores geográficos vigentes: 553 em confiança "baixa", 57 em
"media", nenhum em "alta". Um score que não separa é indistinguível de não ter
score, e esse erro já aconteceu aqui: a primeira calibração do score de
orçamentos colocou 100% da base na banda "alta" e só foi descoberta quando
alguém mediu a dispersão.

Este módulo transforma "priorize o território X" numa afirmação cobrável: os
territórios que o score colocou no topo faturaram mais no mês seguinte que os
demais, ou não?

─── AS TRÊS REGRAS QUE ELE NÃO NEGOCIA ─────────────────────────────────────

1. NÃO OLHA O FUTURO. O score de cada competência é recalculado usando APENAS
   as linhas até aquela competência, com `compute_from_rows` — a mesma função
   que roda em produção. Alimentar o backtest com a série inteira produziria um
   número bonito e falso, que foi exatamente o vício de sobrevivência que
   inflou um AUC para 0,94 neste projeto.

2. LIFT, NÃO PRECISÃO. "Acertei 70% do top" não significa nada se 70% de TODOS
   os territórios cresceram. A taxa da população inteira é medida junto, e o
   veredito olha a razão entre as duas.

3. AMOSTRA PEQUENA NÃO VIRA VEREDITO. Com poucas janelas o resultado é
   `amostra_insuficiente` — não "funciona" nem "não funciona". Silenciar a
   incerteza é como mentir com número.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import date
from typing import Any

import pymysql

from .db import execute, fetch_all
from .geo_intelligence import MODEL_VERSION as SCORE_VERSION
from .geo_intelligence import compute_from_rows, normalize_key

MODEL_VERSION = "geo-backtest-v1"

#: Abaixo disto não se declara nada sobre a qualidade da ordenação.
MIN_JANELAS = 3
#: Territórios de menos não permitem separar topo de resto.
MIN_TERRITORIOS = 8
#: Lift precisa superar isto para o score ser chamado de ordenador. 1.0 seria
#: "empatou com o acaso"; a folga evita declarar vitória em ruído.
LIFT_MINIMO = 1.20
#: Receita irrelevante entra como zero e distorce crescimento percentual.
RECEITA_MINIMA_BASE = 500.0


@dataclass
class BacktestResult:
    company_id: int
    layer_id: str
    competencia_referencia: str
    horizonte_meses: int
    territorios_avaliados: int
    top_n: int
    lift_top_n: float | None
    precisao_top_n: float | None
    precisao_base: float | None
    spearman: float | None
    mape: float | None
    cobertura_faixa_pct: float | None
    veredito: str
    motivo: str
    detalhe: dict[str, Any] = field(default_factory=dict)


def _proxima_competencia(comp: str, passos: int = 1) -> str:
    ano, mes = int(comp[:4]), int(comp[5:7])
    total = (ano * 12 + mes - 1) + passos
    return f"{total // 12:04d}-{total % 12 + 1:02d}"


def _ultimo_dia(comp: str) -> date:
    ano, mes = int(comp[:4]), int(comp[5:7])
    if mes == 12:
        return date(ano, 12, 31)
    proximo = date(ano, mes + 1, 1)
    return date.fromordinal(proximo.toordinal() - 1)


def _spearman(pares: list[tuple[float, float]]) -> float | None:
    """Correlação de ORDEM — o score não precisa acertar o valor, só a posição."""
    n = len(pares)
    if n < 3:
        return None

    def postos(valores: list[float]) -> list[float]:
        ordenado = sorted(range(n), key=lambda i: valores[i])
        r = [0.0] * n
        i = 0
        while i < n:
            j = i
            while j + 1 < n and valores[ordenado[j + 1]] == valores[ordenado[i]]:
                j += 1
            media_posto = (i + j) / 2 + 1
            for k in range(i, j + 1):
                r[ordenado[k]] = media_posto
            i = j + 1
        return r

    rx = postos([p[0] for p in pares])
    ry = postos([p[1] for p in pares])
    mx, my = sum(rx) / n, sum(ry) / n
    num = sum((a - mx) * (b - my) for a, b in zip(rx, ry))
    den = (sum((a - mx) ** 2 for a in rx) * sum((b - my) ** 2 for b in ry)) ** 0.5
    return round(num / den, 4) if den > 0 else None


def _observado(rows: list[dict[str, Any]], competencias: list[str]) -> dict[tuple[str, str], float]:
    """Receita por território nas competências observadas.

    Reproduz o mesmo desdobramento em camadas de `compute_from_rows` — se as
    duas divergirem, o backtest mede um território que o score nunca pontuou.
    """
    total: dict[tuple[str, str], float] = {}
    alvo = set(competencias)
    for row in rows:
        if str(row.get("competencia") or "") not in alvo:
            continue
        city = normalize_key(row.get("cidade_norm"))
        bairro = normalize_key(row.get("bairro_norm"))
        region = normalize_key(row.get("regiao_nome"))
        receita = float(row.get("revenue") or 0)
        chaves = [("ceara_municipios", city)]
        if region:
            chaves.append(("ceara_regioes", region))
        if city and bairro:
            chaves.append(("grande_fortaleza_bairros", f"{city}:{bairro}"))
        if city == "fortaleza" and bairro:
            chaves.append(("fortaleza_bairros", bairro))
        for layer, key in chaves:
            if not key or key in {"sem_cidade", "nao_informado"}:
                continue
            total[(layer, key)] = total.get((layer, key), 0.0) + receita
    return total


def _avaliar_janela(
    rows: list[dict[str, Any]],
    company_id: int,
    referencia: str,
    horizonte: int,
    top_n: int,
) -> list[BacktestResult]:
    """Uma competência de referência, todas as camadas."""
    treino = [r for r in rows if str(r.get("competencia") or "") <= referencia]
    if not treino:
        return []

    scores = compute_from_rows(treino, company_id, _ultimo_dia(referencia))
    futuras = [_proxima_competencia(referencia, p) for p in range(1, horizonte + 1)]
    observado = _observado(rows, futuras)

    por_camada: dict[str, list[Any]] = {}
    for s in scores:
        por_camada.setdefault(s.layer_id, []).append(s)

    resultados: list[BacktestResult] = []
    for layer, itens in por_camada.items():
        # Território sem base relevante distorce crescimento percentual: uma
        # venda de R$ 200 virando R$ 800 é "300% de crescimento" e domina a média.
        elegiveis = [s for s in itens if s.current_revenue >= RECEITA_MINIMA_BASE]
        if len(elegiveis) < MIN_TERRITORIOS:
            continue

        avaliados = []
        for s in elegiveis:
            futuro = observado.get((layer, s.territory_key), 0.0)
            crescimento = (futuro - s.current_revenue) / s.current_revenue
            dentro_da_faixa = (
                s.forecast_low is not None and s.forecast_high is not None
                and s.forecast_low <= futuro <= s.forecast_high
            )
            erro = (
                abs(s.forecast_revenue - futuro) / futuro
                if s.forecast_revenue is not None and futuro > 0 else None
            )
            avaliados.append({
                "key": s.territory_key, "label": s.territory_label,
                "score": s.opportunity_score, "base": s.current_revenue,
                "observado": round(futuro, 2), "crescimento": round(crescimento, 4),
                "previsto": s.forecast_revenue, "dentro_da_faixa": dentro_da_faixa,
                "erro": round(erro, 4) if erro is not None else None,
            })

        avaliados.sort(key=lambda a: -a["score"])
        n = min(top_n, max(1, len(avaliados) // 3))
        topo, resto = avaliados[:n], avaliados[n:]
        if not resto:
            continue

        cresc_topo = sum(a["crescimento"] for a in topo) / len(topo)
        cresc_resto = sum(a["crescimento"] for a in resto) / len(resto)
        # Deslocamento comum evita divisão por zero e o absurdo de lift negativo
        # quando os dois grupos encolhem: o que interessa é topo MELHOR que resto.
        base_comum = 1.0
        lift = (cresc_topo + base_comum) / (cresc_resto + base_comum) if (cresc_resto + base_comum) > 0 else None

        erros = [a["erro"] for a in avaliados if a["erro"] is not None]
        com_faixa = [a for a in avaliados if a["previsto"] is not None]

        resultados.append(BacktestResult(
            company_id=company_id, layer_id=layer, competencia_referencia=referencia,
            horizonte_meses=horizonte, territorios_avaliados=len(avaliados), top_n=n,
            lift_top_n=round(lift, 4) if lift is not None else None,
            precisao_top_n=round(sum(1 for a in topo if a["crescimento"] > 0) / len(topo), 4),
            precisao_base=round(sum(1 for a in avaliados if a["crescimento"] > 0) / len(avaliados), 4),
            spearman=_spearman([(a["score"], a["crescimento"]) for a in avaliados]),
            mape=round(sum(erros) / len(erros), 4) if erros else None,
            cobertura_faixa_pct=(
                round(100 * sum(1 for a in com_faixa if a["dentro_da_faixa"]) / len(com_faixa), 2)
                if com_faixa else None
            ),
            veredito="amostra_insuficiente", motivo="janela isolada — veredito só no consolidado",
            detalhe={"topo": topo[:10], "crescimento_topo": round(cresc_topo, 4),
                     "crescimento_resto": round(cresc_resto, 4)},
        ))
    return resultados


def _consolidar(janelas: list[BacktestResult]) -> tuple[str, str]:
    """O veredito olha o conjunto, nunca uma janela isolada."""
    if len(janelas) < MIN_JANELAS:
        return ("amostra_insuficiente",
                f"apenas {len(janelas)} janela(s) avaliada(s); minimo {MIN_JANELAS} para concluir")

    lifts = [j.lift_top_n for j in janelas if j.lift_top_n is not None]
    if not lifts:
        return "amostra_insuficiente", "nenhuma janela produziu lift calculavel"

    medio = sum(lifts) / len(lifts)
    venceram = sum(1 for x in lifts if x > 1.0)
    if medio >= LIFT_MINIMO and venceram > len(lifts) / 2:
        return ("ordena",
                f"lift medio {medio:.2f} em {len(lifts)} janelas; o topo cresceu mais em {venceram} delas")
    return ("nao_ordena",
            f"lift medio {medio:.2f} (minimo {LIFT_MINIMO}); o topo venceu em {venceram} de {len(lifts)} janelas")


def run(
    conn: pymysql.connections.Connection,
    company_id: int,
    as_of: date,
    horizonte: int = 1,
    janelas: int = 6,
    top_n: int = 10,
) -> list[BacktestResult]:
    rows = fetch_all(conn, """
        SELECT competencia, cidade_norm, bairro_norm, regiao_nome,
               SUM(faturamento) AS revenue
          FROM mart_compradores_por_geo
         WHERE company_id = %s AND competencia >= %s AND competencia <= %s
         GROUP BY competencia, cidade_norm, bairro_norm, regiao_nome
    """, (company_id, f"{as_of.year - 2:04d}-{as_of.month:02d}", as_of.strftime("%Y-%m")))
    if not rows:
        return []

    competencias = sorted({str(r["competencia"]) for r in rows if r.get("competencia")})
    # Só serve como referência a competência que TEM futuro observado — avaliar
    # o mês corrente contra um mês que ainda não terminou inventaria queda.
    referencias = [
        c for c in competencias
        if _proxima_competencia(c, horizonte) in competencias
    ][-janelas:]

    resultados: list[BacktestResult] = []
    for referencia in referencias:
        resultados.extend(_avaliar_janela(rows, company_id, referencia, horizonte, top_n))

    por_camada: dict[str, list[BacktestResult]] = {}
    for r in resultados:
        por_camada.setdefault(r.layer_id, []).append(r)
    for layer, janelas_da_camada in por_camada.items():
        veredito, motivo = _consolidar(janelas_da_camada)
        for r in janelas_da_camada:
            r.veredito = veredito
            r.motivo = motivo

    return resultados


def persist(
    conn: pymysql.connections.Connection,
    company_id: int,
    resultados: list[BacktestResult],
    job_run_id: int | None = None,
) -> int:
    if not resultados:
        return 0
    execute(conn, "UPDATE intelligence_geo_backtests SET is_current=0 WHERE company_id=%s AND is_current=1",
            (company_id,))
    for r in resultados:
        execute(conn, """INSERT INTO intelligence_geo_backtests
          (company_id, job_run_id, layer_id, competencia_referencia, horizonte_meses,
           territorios_avaliados, top_n, lift_top_n, precisao_top_n, precisao_base, spearman,
           mape, cobertura_faixa_pct, veredito, motivo, detalhe_json, model_version, is_current, computed_at)
          VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,UTC_TIMESTAMP())
          ON DUPLICATE KEY UPDATE job_run_id=VALUES(job_run_id),
           territorios_avaliados=VALUES(territorios_avaliados), top_n=VALUES(top_n),
           lift_top_n=VALUES(lift_top_n), precisao_top_n=VALUES(precisao_top_n),
           precisao_base=VALUES(precisao_base), spearman=VALUES(spearman), mape=VALUES(mape),
           cobertura_faixa_pct=VALUES(cobertura_faixa_pct), veredito=VALUES(veredito),
           motivo=VALUES(motivo), detalhe_json=VALUES(detalhe_json), is_current=1,
           computed_at=UTC_TIMESTAMP()""",
          (company_id, job_run_id, r.layer_id, r.competencia_referencia, r.horizonte_meses,
           r.territorios_avaliados, r.top_n, r.lift_top_n, r.precisao_top_n, r.precisao_base,
           r.spearman, r.mape, r.cobertura_faixa_pct, r.veredito, r.motivo[:240],
           json.dumps(r.detalhe, ensure_ascii=False, default=str), f"{MODEL_VERSION}+{SCORE_VERSION}"))
    conn.commit()
    return len(resultados)
