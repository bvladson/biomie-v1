"""Faixa NORMAL de faturamento por empresa e dia da semana.

O QUE ISTO RESPONDE: "R$ 12.575 num sábado é bom ou ruim?" — pergunta que a
tabela de evolução diária não conseguia responder, porque um número sozinho não
se compara com nada.

─── POR QUE QUANTIL, E NÃO MÉDIA ± DESVIO ──────────────────────────────────

Medido na MAG_FILIAL (161 dias com faturamento):

    média    R$  30.519
    mediana  ver abaixo (bem menor)
    máximo   R$ 671.082
    mínimo   R$     299
    desvio   R$  78.162   ← 2,6× a MÉDIA

Com desvio maior que o dobro da média, "média ± 1 desvio" produz faixa de
−R$ 47 mil a R$ 108 mil. Ela cobre praticamente qualquer dia possível, e faixa
que nunca erra também nunca informa. É o mesmo motivo pelo qual
`telemetry_anomaly` pula spans de cauda pesada.

Quantil empírico não supõe distribuição nenhuma: p25–p75 é a faixa típica, e
fora de p10/p90 é dia atípico. Com ~27 amostras por dia da semana, é estimável.

─── POR QUE POR DIA DA SEMANA ──────────────────────────────────────────────

Sábado não se compara com terça. A MAG_FILIAL fatura em 6 dias por semana (zero
domingos em 161 dias); comparar sábado com a média geral acusaria queda toda
semana — o erro clássico de dashboard que `insights._tendencia` já evita usando
janelas iguais.

─── E POR QUE ELE SE RECUSA NA MAIOR PARTE DAS EMPRESAS ────────────────────

Dias com faturamento por dia da semana, medido:

    MAG_FILIAL   26 27 28 26 27 27   (161 dias)
    ERGOOFFICE   24 12 11 12  8      ( 67 dias)
    EXPERT       12  9  6 11  2      ( 40 dias)
    UNI_DESIGN    6  3  4  1  5      ( 20 dias)

p10 e p90 sobre 2 sextas-feiras não são percentis, são os dois valores. O portão
de amostra devolve `sem_amostra`, que é resposta — não falha.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import date, timedelta
from typing import Any

import pymysql

from .db import execute_many, fetch_all

MODEL_VERSION = "daily-baseline-v1"

#: Janela de histórico. 180 dias ≈ 26 semanas, o que dá ~26 amostras por dia da
#: semana — suficiente para p10/p90 e curto o bastante para o baseline
#: acompanhar mudança de patamar do negócio em vez de perseguir o ano passado.
JANELA_DIAS = 180

#: Abaixo disto não se publica faixa. Com 10 amostras, p10 e p90 já são o
#: primeiro e o último valor — é o piso do que se pode chamar de percentil.
MIN_AMOSTRA = 10
#: A partir daqui a faixa é confiável o bastante para virar veredito na tela.
AMOSTRA_CONFIANCA_ALTA = 20


def _quantil(ordenado: list[float], p: float) -> float:
    """Quantil por interpolação linear entre posições.

    Implementado à mão em vez de usar numpy: são listas de dezenas de elementos,
    e o serviço já carrega numpy só para o treino — puxá-lo para o caminho do
    cron por causa de uma interpolação seria custo sem contrapartida.
    """
    if not ordenado:
        return 0.0
    if len(ordenado) == 1:
        return float(ordenado[0])
    pos = p * (len(ordenado) - 1)
    baixo = int(pos)
    alto = min(baixo + 1, len(ordenado) - 1)
    peso = pos - baixo
    return float(ordenado[baixo] * (1 - peso) + ordenado[alto] * peso)


@dataclass
class BaselineDia:
    company_id: int
    weekday: int
    amostra: int
    janela_dias: int
    mediana_revenue: float | None
    p10_revenue: float | None
    p25_revenue: float | None
    p75_revenue: float | None
    p90_revenue: float | None
    mediana_invoices: float | None
    confianca: str


def carregar_dias(
    conn: pymysql.connections.Connection, company_id: int, as_of: date
) -> list[dict[str, Any]]:
    """Um registro por DIA com faturamento, dentro da janela.

    `data_competencia < as_of` exclui o dia corrente: ele ainda está em curso e
    entraria como um dia fraco, puxando a faixa para baixo justamente contra o
    dia que se quer avaliar.
    """
    return fetch_all(
        conn,
        """SELECT DAYOFWEEK(n.data_competencia) AS weekday,
                  SUM(n.valor_total)            AS revenue,
                  COUNT(*)                      AS invoices
             FROM biomie_notas_fiscais_canonicas n
            WHERE n.company_id = %s
              AND n.status_fiscal = 'autorizada'
              AND n.operation_scope = 'commercial'
              AND (n.business_channel = 'licitacao'
                   OR COALESCE(n.is_biomie_commercial_visible, 1) = 1)
              AND n.data_competencia >= %s
              AND n.data_competencia <  %s
            GROUP BY n.data_competencia, weekday""",
        (company_id, as_of - timedelta(days=JANELA_DIAS), as_of),
    )


def calcular(
    conn: pymysql.connections.Connection, company_id: int, as_of: date
) -> list[BaselineDia]:
    """Uma faixa por dia da semana que TENHA amostra."""
    por_dia: dict[int, list[tuple[float, float]]] = {}
    for linha in carregar_dias(conn, company_id, as_of):
        weekday = int(linha["weekday"])
        por_dia.setdefault(weekday, []).append(
            (float(linha["revenue"] or 0), float(linha["invoices"] or 0))
        )

    saida: list[BaselineDia] = []
    for weekday in range(1, 8):
        amostras = por_dia.get(weekday, [])
        n = len(amostras)

        # Dia da semana em que a empresa nunca fatura (domingo, na MAG_FILIAL)
        # não gera linha nenhuma: publicar "faixa R$ 0–0" faria qualquer venda
        # de domingo parecer anomalia gigante.
        if n == 0:
            continue

        if n < MIN_AMOSTRA:
            saida.append(BaselineDia(
                company_id=company_id, weekday=weekday, amostra=n,
                janela_dias=JANELA_DIAS,
                mediana_revenue=None, p10_revenue=None, p25_revenue=None,
                p75_revenue=None, p90_revenue=None, mediana_invoices=None,
                confianca="sem_amostra",
            ))
            continue

        receitas = sorted(v for v, _ in amostras)
        notas = sorted(q for _, q in amostras)
        saida.append(BaselineDia(
            company_id=company_id, weekday=weekday, amostra=n,
            janela_dias=JANELA_DIAS,
            mediana_revenue=round(_quantil(receitas, 0.50), 2),
            p10_revenue=round(_quantil(receitas, 0.10), 2),
            p25_revenue=round(_quantil(receitas, 0.25), 2),
            p75_revenue=round(_quantil(receitas, 0.75), 2),
            p90_revenue=round(_quantil(receitas, 0.90), 2),
            mediana_invoices=round(_quantil(notas, 0.50), 2),
            confianca="alta" if n >= AMOSTRA_CONFIANCA_ALTA else "media",
        ))
    return saida


def classificar_dia(receita: float, baseline: BaselineDia | None) -> str:
    """Veredito de um dia contra a faixa. `sem_referencia` é resposta legítima.

    Existe aqui, e não no PHP, para que a regra viva ao lado dos números que a
    sustentam — se o limiar mudar, muda num lugar só.
    """
    if baseline is None or baseline.confianca == "sem_amostra":
        return "sem_referencia"
    if baseline.p90_revenue is not None and receita > baseline.p90_revenue:
        return "acima"
    if baseline.p10_revenue is not None and receita < baseline.p10_revenue:
        return "abaixo"
    return "dentro"


def resumo(baselines: list[BaselineDia]) -> dict[str, Any]:
    por_confianca: dict[str, int] = {}
    for b in baselines:
        por_confianca[b.confianca] = por_confianca.get(b.confianca, 0) + 1
    publicaveis = [b for b in baselines if b.confianca != "sem_amostra"]
    return {
        "dias_da_semana": len(baselines),
        "com_faixa": len(publicaveis),
        "por_confianca": por_confianca,
        "amostra_min": min((b.amostra for b in baselines), default=0),
        "amostra_max": max((b.amostra for b in baselines), default=0),
        "janela_dias": JANELA_DIAS,
    }


def persist(
    conn: pymysql.connections.Connection,
    baselines: list[BaselineDia],
    job_run_id: int | None = None,
) -> int:
    """Atualiza no lugar (upsert), sem versionar.

    O baseline é janela móvel recalculada todo dia: não existe competência que
    faça a versão anterior continuar sendo a última palavra sobre algo. Manter
    histórico daria 7 linhas por empresa por dia sem ninguém para lê-las.
    """
    if not baselines:
        return 0
    linhas = [
        (b.company_id, job_run_id, b.weekday, b.amostra, b.janela_dias,
         b.mediana_revenue, b.p10_revenue, b.p25_revenue, b.p75_revenue,
         b.p90_revenue, b.mediana_invoices, b.confianca)
        for b in baselines
    ]
    n = execute_many(
        conn,
        """INSERT INTO intelligence_daily_baseline
               (company_id, job_run_id, weekday, amostra, janela_dias,
                mediana_revenue, p10_revenue, p25_revenue, p75_revenue,
                p90_revenue, mediana_invoices, confianca, computed_at)
           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,UTC_TIMESTAMP())
           ON DUPLICATE KEY UPDATE
               job_run_id = VALUES(job_run_id), amostra = VALUES(amostra),
               janela_dias = VALUES(janela_dias),
               mediana_revenue = VALUES(mediana_revenue),
               p10_revenue = VALUES(p10_revenue), p25_revenue = VALUES(p25_revenue),
               p75_revenue = VALUES(p75_revenue), p90_revenue = VALUES(p90_revenue),
               mediana_invoices = VALUES(mediana_invoices),
               confianca = VALUES(confianca), computed_at = UTC_TIMESTAMP()""",
        linhas,
    )
    conn.commit()
    return n
