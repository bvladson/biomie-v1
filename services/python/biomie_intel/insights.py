"""Leituras narrativas da operação — o que os números QUEREM DIZER.

A home tem muito número e pouca conclusão. "R$ 70.607 faturados" não diz se
está bom; "34% abaixo das 4 semanas anteriores, e 2 vendedores respondem pela
queda" diz — e leva a uma ação.

REGRAS QUE ESTE MÓDULO SEGUE

1. Nenhuma frase sem os números que a sustentam (`metric_json`). Insight que não
   dá para conferir é opinião, e opinião não pertence a um painel.
2. Amostra pequena não vira conclusão. Comparar duas semanas com 3 notas cada e
   anunciar "queda de 40%" é ruído estatístico apresentado como fato.
3. Toda leitura tem validade. Insight vencido é pior que insight nenhum: some
   sozinho se o job parar, em vez de mostrar conclusão velha como se fosse de hoje.

Usa pandas porque o trabalho aqui é agrupar/janela/comparar séries — SQL faria,
mas ficaria ilegível, e a leitura precisa continuar auditável por quem for mexer.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, datetime, timedelta, timezone
from typing import Any

import pymysql

from .db import fetch_all

#: abaixo disto, variação percentual é ruído — não vira frase
MIN_NOTAS_PARA_TENDENCIA = 8
#: share do maior cliente a partir do qual a concentração é risco real
LIMIAR_CONCENTRACAO = 0.35
#: por quantas horas a leitura continua válida na tela
VALIDADE_HORAS = 8

# UNIVERSO COMERCIAL — espelha app/Support/CommercialNfUniverse.php.
#
# Sem isto a leitura soma remessa, uso interno e transferência, e a home mostra
# "R$ 1,16 mi nas últimas 4 semanas" logo ao lado de um KPI de R$ 70 mil. Dois
# números contraditórios na mesma tela destroem a confiança no painel inteiro —
# o usuário passa a não acreditar em nenhum dos dois.
#
# Regra: autorizada + escopo comercial + (licitação SEMPRE preservada OU
# varejo visível). Se o PHP mudar a regra, esta constante tem que acompanhar.
UNIVERSO_COMERCIAL = (
    "status_fiscal = 'autorizada' "
    "AND operation_scope = 'commercial' "
    "AND (business_channel = 'licitacao' "
    "     OR COALESCE(is_biomie_commercial_visible, 1) = 1)"
)


@dataclass
class Insight:
    kind: str
    severity: str
    title: str
    body: str
    rank_weight: int = 0
    metrics: dict[str, Any] = field(default_factory=dict)
    link_url: str | None = None


def _brl(v: float) -> str:
    return f"R$ {v:,.0f}".replace(",", "X").replace(".", ",").replace("X", ".")


def _faturamento_diario(
    conn: pymysql.connections.Connection, company_id: int, desde: date
) -> list[dict[str, Any]]:
    return fetch_all(
        conn,
        """SELECT data_emissao AS dia,
                  COUNT(*)            AS notas,
                  SUM(valor_total)    AS receita
             FROM biomie_notas_fiscais_canonicas
            WHERE company_id = %s
              AND """ + UNIVERSO_COMERCIAL + """
              AND data_emissao >= %s
            GROUP BY data_emissao
            ORDER BY data_emissao""",
        (company_id, desde),
    )


def _tendencia(linhas: list[dict[str, Any]], hoje: date) -> Insight | None:
    """Compara as últimas 4 semanas com as 4 anteriores.

    Janelas iguais de propósito: comparar "mês corrente" (parcial) com "mês
    passado" (completo) inventa queda todo dia 2 — erro clássico de dashboard.
    """
    import pandas as pd

    if not linhas:
        return None

    df = pd.DataFrame(linhas)
    df["dia"] = pd.to_datetime(df["dia"])
    df["receita"] = df["receita"].astype(float)

    corte = pd.Timestamp(hoje - timedelta(days=28))
    inicio = pd.Timestamp(hoje - timedelta(days=56))

    recente = df[df["dia"] >= corte]
    anterior = df[(df["dia"] >= inicio) & (df["dia"] < corte)]

    notas_recente = int(recente["notas"].sum()) if not recente.empty else 0
    notas_anterior = int(anterior["notas"].sum()) if not anterior.empty else 0
    if notas_recente < MIN_NOTAS_PARA_TENDENCIA or notas_anterior < MIN_NOTAS_PARA_TENDENCIA:
        return None  # amostra pequena não vira conclusão

    r1 = float(recente["receita"].sum())
    r0 = float(anterior["receita"].sum())
    if r0 <= 0:
        return None

    variacao = (r1 - r0) / r0
    if abs(variacao) < 0.10:
        return Insight(
            kind="tendencia_receita",
            severity="neutro",
            title="Faturamento estável",
            body=(f"As últimas 4 semanas somam {_brl(r1)}, praticamente o mesmo das "
                  f"4 anteriores ({_brl(r0)})."),
            rank_weight=10,
            metrics={"receita_recente": r1, "receita_anterior": r0, "variacao": round(variacao, 4),
                     "notas_recente": notas_recente, "notas_anterior": notas_anterior},
        )

    subiu = variacao > 0
    return Insight(
        kind="tendencia_receita",
        severity="positivo" if subiu else "atencao",
        title=("Faturamento em alta" if subiu else "Faturamento em queda"),
        body=(f"As últimas 4 semanas somam {_brl(r1)}, "
              f"{abs(variacao) * 100:.0f}% {'acima' if subiu else 'abaixo'} das 4 anteriores "
              f"({_brl(r0)}). Base: {notas_recente} notas contra {notas_anterior}."),
        rank_weight=80 if not subiu else 40,
        metrics={"receita_recente": r1, "receita_anterior": r0, "variacao": round(variacao, 4),
                 "notas_recente": notas_recente, "notas_anterior": notas_anterior},
    )


def _concentracao(
    conn: pymysql.connections.Connection, company_id: int, desde: date
) -> Insight | None:
    """Quanto do faturamento depende de um cliente só."""
    import pandas as pd

    linhas = fetch_all(
        conn,
        """SELECT COALESCE(NULLIF(nome_cliente,''), 'Sem cliente') AS cliente,
                  SUM(valor_total) AS receita
             FROM biomie_notas_fiscais_canonicas
            WHERE company_id = %s AND """ + UNIVERSO_COMERCIAL + """
              AND data_emissao >= %s
            GROUP BY cliente
            ORDER BY receita DESC""",
        (company_id, desde),
    )
    if len(linhas) < 3:
        return None

    df = pd.DataFrame(linhas)
    df["receita"] = df["receita"].astype(float)
    total = float(df["receita"].sum())
    if total <= 0:
        return None

    topo = df.iloc[0]
    share = float(topo["receita"]) / total
    if share < LIMIAR_CONCENTRACAO:
        return None

    return Insight(
        kind="concentracao_cliente",
        severity="critico" if share >= 0.6 else "atencao",
        title="Receita concentrada em um cliente",
        body=(f"{topo['cliente']} responde por {share * 100:.0f}% do faturamento dos "
              f"últimos 90 dias ({_brl(float(topo['receita']))} de {_brl(total)}). "
              f"Perder esse cliente derruba o mês."),
        rank_weight=90 if share >= 0.6 else 60,
        metrics={"cliente": str(topo["cliente"]), "share": round(share, 4),
                 "receita_cliente": float(topo["receita"]), "receita_total": total,
                 "clientes_no_periodo": int(len(df))},
    )


def _ritmo_do_mes(linhas: list[dict[str, Any]], hoje: date) -> Insight | None:
    """Projeção do mês pelo ritmo diário já realizado.

    Só faz sentido depois de alguns dias: projetar o mês no dia 2 multiplica
    ruído por 30.
    """
    import pandas as pd

    if not linhas or hoje.day < 5:
        return None

    df = pd.DataFrame(linhas)
    df["dia"] = pd.to_datetime(df["dia"])
    df["receita"] = df["receita"].astype(float)

    inicio_mes = pd.Timestamp(hoje.replace(day=1))
    mes = df[df["dia"] >= inicio_mes]
    if mes.empty:
        return None

    realizado = float(mes["receita"].sum())
    dias_corridos = hoje.day
    proximo_mes = (hoje.replace(day=28) + timedelta(days=4)).replace(day=1)
    dias_no_mes = (proximo_mes - timedelta(days=1)).day

    projecao = realizado / dias_corridos * dias_no_mes

    return Insight(
        kind="ritmo_mes",
        severity="neutro",
        title="Ritmo do mês",
        body=(f"{_brl(realizado)} em {dias_corridos} dias. Mantido o ritmo, o mês "
              f"fecha perto de {_brl(projecao)}."),
        rank_weight=30,
        metrics={"realizado": realizado, "dias_corridos": dias_corridos,
                 "dias_no_mes": dias_no_mes, "projecao": round(projecao, 2)},
    )


def _vendedor_destaque(
    conn: pymysql.connections.Connection, company_id: int, desde: date
) -> Insight | None:
    """Quem carrega e quem sumiu, comparando duas janelas iguais."""
    import pandas as pd

    linhas = fetch_all(
        conn,
        """SELECT COALESCE(NULLIF(nome_vendedor,''), 'Sem vendedor') AS vendedor,
                  data_emissao AS dia,
                  SUM(valor_total) AS receita
             FROM biomie_notas_fiscais_canonicas
            WHERE company_id = %s AND """ + UNIVERSO_COMERCIAL + """
              AND data_emissao >= %s
            GROUP BY vendedor, data_emissao""",
        (company_id, desde),
    )
    if len(linhas) < 6:
        return None

    df = pd.DataFrame(linhas)
    df["dia"] = pd.to_datetime(df["dia"])
    df["receita"] = df["receita"].astype(float)

    hoje = pd.Timestamp(date.today())
    corte = hoje - pd.Timedelta(days=28)
    recente = df[df["dia"] >= corte].groupby("vendedor")["receita"].sum()
    anterior = df[df["dia"] < corte].groupby("vendedor")["receita"].sum()
    if recente.empty or anterior.empty:
        return None

    comp = pd.concat([anterior.rename("antes"), recente.rename("depois")], axis=1).fillna(0.0)
    # Só vendedores com histórico relevante — quem vendeu R$ 500 e parou não é notícia.
    comp = comp[comp["antes"] >= 1000]
    if comp.empty:
        return None

    comp["queda"] = comp["antes"] - comp["depois"]
    pior = comp.sort_values("queda", ascending=False).iloc[0]
    if float(pior["queda"]) <= 0:
        return None

    perda_rel = float(pior["queda"]) / float(pior["antes"])
    if perda_rel < 0.5:
        return None

    return Insight(
        kind="vendedor_queda",
        severity="atencao",
        title="Vendedor perdendo ritmo",
        body=(f"{pior.name} faturou {_brl(float(pior['depois']))} nas últimas 4 semanas "
              f"contra {_brl(float(pior['antes']))} nas 4 anteriores "
              f"({perda_rel * 100:.0f}% a menos)."),
        rank_weight=70,
        metrics={"vendedor": str(pior.name), "antes": float(pior["antes"]),
                 "depois": float(pior["depois"]), "queda_relativa": round(perda_rel, 4)},
        link_url="vendedores.php",
    )


def compute(conn: pymysql.connections.Connection, company_id: int) -> list[Insight]:
    """Todas as leituras de uma empresa, já ordenadas por relevância."""
    hoje = datetime.now(timezone.utc).date()
    desde_90 = hoje - timedelta(days=90)

    diario = _faturamento_diario(conn, company_id, desde_90)

    candidatos = [
        _tendencia(diario, hoje),
        _concentracao(conn, company_id, desde_90),
        _ritmo_do_mes(diario, hoje),
        _vendedor_destaque(conn, company_id, desde_90),
    ]
    achados = [i for i in candidatos if i is not None]
    achados.sort(key=lambda i: -i.rank_weight)
    return achados


def persist(
    conn: pymysql.connections.Connection,
    company_id: int,
    insights: list[Insight],
    job_run_id: int,
) -> int:
    """Substitui as leituras vigentes da empresa."""
    import json

    from .db import execute, execute_many

    execute(
        conn,
        "UPDATE intelligence_insights SET is_current = 0 WHERE company_id = %s AND is_current = 1",
        (company_id,),
    )
    if not insights:
        conn.commit()
        return 0

    validade = datetime.now(timezone.utc) + timedelta(hours=VALIDADE_HORAS)
    rows = [
        (
            company_id, job_run_id, i.kind, i.severity, i.rank_weight,
            i.title[:120], i.body[:400],
            json.dumps(i.metrics, ensure_ascii=False, default=str),
            i.link_url, validade.strftime("%Y-%m-%d %H:%M:%S"),
        )
        for i in insights
    ]
    n = execute_many(
        conn,
        """INSERT INTO intelligence_insights
               (company_id, job_run_id, kind, severity, rank_weight, title, body,
                metric_json, link_url, valid_until, is_current, computed_at)
           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,UTC_TIMESTAMP())""",
        rows,
    )
    conn.commit()
    return n
