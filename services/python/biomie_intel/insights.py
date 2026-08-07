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
from math import erf, sqrt
from typing import Any

import pymysql

from .db import fetch_all
from .forecast_store import ForecastLinha
from .target_ladder import evaluate as evaluate_target_ladder, recommend as recommend_target_ladder

#: abaixo disto, variação percentual é ruído — não vira frase
MIN_NOTAS_PARA_TENDENCIA = 8
#: share do maior cliente a partir do qual a concentração é risco real
LIMIAR_CONCENTRACAO = 0.35
#: por quantas horas a leitura continua válida na tela
VALIDADE_HORAS = 8
#: por quantos dias a leitura vencida fica guardada antes do expurgo
RETENCAO_DIAS = 30
MIN_DIAS_FORECAST = 5

#: MAPE abaixo disto é projeção utilizável.
ERRO_CONFIANCA_ALTA = 0.25
#: MAPE a partir daqui significa que o modelo não ordena nada para aquela
#: entidade — a faixa cobre qualquer desfecho. A linha continua sendo gravada
#: (para o erro poder ser cobrado depois), mas não pode virar alerta: número sem
#: lastro apresentado como conclusão é pior que ausência de número.
ERRO_CONFIANCA_MINIMA = 0.50

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
    conn: pymysql.connections.Connection, company_id: int, desde: date, hoje: date
) -> Insight | None:
    """Quem carrega e quem sumiu, comparando duas janelas iguais.

    `hoje` vem de quem chama, nunca de `date.today()`: o resto do módulo
    trabalha na data UTC que `compute` fixou, e o servidor roda em -03. Ler o
    relógio local aqui deslocava o corte de 28 dias em um dia entre 21h e 00h,
    e tornava a função impossível de testar contra uma data escolhida.
    """
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

    corte = pd.Timestamp(hoje) - pd.Timedelta(days=28)
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


def _erro_historico(totais: list[float]) -> float:
    """MAPE leave-one-out simples; não mede o mês usando o próprio mês."""
    if len(totais) < 3:
        return 0.30
    erros: list[float] = []
    for i, real in enumerate(totais):
        treino = totais[:i] + totais[i + 1:]
        esperado = sum(treino) / len(treino)
        if real > 0:
            erros.append(abs(real - esperado) / real)
    return min(1.0, max(0.15, sum(erros) / len(erros) if erros else 0.30))


def _risco_nao_bater(meta: float, projetado: float, erro: float) -> float | None:
    if meta <= 0:
        return None
    sigma = max(projetado * erro / 1.645, 1.0)
    z = (meta - projetado) / sigma
    return min(0.99, max(0.01, 0.5 * (1.0 + erf(z / sqrt(2.0)))))


#: quanto histórico o forecast enxerga para trás a partir da data de referência
JANELA_FORECAST_DIAS = 190


def _forecast_rows(conn, company_id: int, dimensao: str, hoje: date) -> list[dict[str, Any]]:
    """Séries mensais/dia por empresa, canal ou vendedor; dimensão é whitelist.

    A JANELA É ANCORADA EM `hoje`, COM TETO — e as duas coisas importam.

    Antes, o piso saía de `date.today()` e não havia teto nenhum. Com isso,
    `calcular_forecasts(conn, id, hoje=<data passada>)` — que é o que
    `forecast-mensal --as-of` faz — lia notas emitidas DEPOIS da data de
    referência. Como `historicos` é montado com os meses diferentes da
    competência corrente, meses posteriores ao `as_of` entravam como se fossem
    passado, e a "média histórica" passava a conter o futuro.

    Isso não aparece na rodada diária do cron (lá `as_of` é hoje), só no
    replay. E o replay é justamente quando o número mais precisa ser honesto:
    `intelligence_forecasts` existe para o erro da previsão ser COBRADO depois,
    e uma previsão que viu o futuro seria cobrada e aprovada com louvor.
    """
    campos = {
        "empresa": ("'empresa'", "'Empresa'"),
        "canal": ("COALESCE(NULLIF(business_channel,''),'sem_canal')", "COALESCE(NULLIF(business_channel,''),'Sem canal')"),
        "vendedor": ("COALESCE(NULLIF(codigo_vendedor_omie,''),NULLIF(nome_vendedor,''),'sem_vendedor')", "COALESCE(NULLIF(nome_vendedor,''),'Sem vendedor')"),
    }
    codigo, nome = campos[dimensao]
    return fetch_all(
        conn,
        f"""SELECT {codigo} AS codigo, {nome} AS nome, data_emissao AS dia,
                   SUM(valor_total) AS receita, COUNT(*) AS notas
              FROM biomie_notas_fiscais_canonicas
             WHERE company_id = %s AND {UNIVERSO_COMERCIAL}
               AND data_emissao >= %s
               AND data_emissao <= %s
             GROUP BY codigo, nome, data_emissao
             ORDER BY codigo, dia""",
        (company_id, hoje - timedelta(days=JANELA_FORECAST_DIAS), hoje),
    )


#: erros do MySQL que significam "o schema aqui é mais antigo", não "sem acesso"
_ERROS_DE_SCHEMA = (
    1146,  # tabela não existe
    1054,  # coluna desconhecida
)


def _metas_com_escadas(conn, company_id: int, competencia: str) -> tuple[float, dict[str, float], dict[str, float], dict[str, list[float]]]:
    """Metas por canal e por vendedor.

    O `except` aqui é ESTREITO de propósito. Um `except Exception` genérico
    transformava falta de GRANT (erro 1142) em lista vazia, e o forecast passava
    a projetar sem meta — ou seja, `risco_nao_bater = None` — exatamente igual a
    uma empresa que nunca cadastrou meta. Erro de permissão precisa SUBIR: é
    configuração quebrada, não ausência de dado.
    """
    def _tolerando_schema_antigo(sql: str, params: tuple) -> list[dict[str, Any]]:
        try:
            return fetch_all(conn, sql, params)
        except pymysql.err.MySQLError as exc:
            codigo = exc.args[0] if exc.args else None
            if codigo in _ERROS_DE_SCHEMA:
                return []
            raise

    canais = _tolerando_schema_antigo(
        """SELECT canal, meta_valor FROM biomie_company_targets
            WHERE company_id = %s AND competencia = %s AND ativo = 1""",
        (company_id, competencia),
    )
    # `year_month` PRECISA de crase: YEAR_MONTH é palavra reservada do MariaDB
    # (unidade de intervalo). Sem as crases a query devolve 1064 e, enquanto o
    # `except` daqui era genérico, isso virava "empresa sem meta de vendedor" —
    # em silêncio, desde que o forecast por vendedor foi ao ar. Havia 4 metas de
    # agosto somando R$ 160 mil sendo descartadas.
    vendedores = _tolerando_schema_antigo(
        """SELECT vendor_id, target_amount, tiers_json FROM biomie_sales_targets_monthly
            WHERE company_id = %s AND `year_month` = %s""",
        (company_id, competencia),
    )
    niveis_normalizados = _tolerando_schema_antigo(
        """SELECT vendor_id, level_number, target_amount
             FROM biomie_sales_target_levels
            WHERE company_id = %s AND `year_month` = %s AND active = 1
            ORDER BY vendor_id, level_number""",
        (company_id, competencia),
    )
    por_canal = {str(r["canal"]): float(r["meta_valor"] or 0) for r in canais}
    por_vendedor = {str(r["vendor_id"]): float(r["target_amount"] or 0) for r in vendedores}
    escadas: dict[str, list[float]] = {}
    import json
    for row in vendedores:
        levels = [float(row["target_amount"] or 0)]
        try:
            extras = json.loads(row.get("tiers_json") or "[]")
        except (TypeError, ValueError):
            extras = []
        levels.extend(float(value) for value in extras if isinstance(value, (int, float)) and value > 0)
        escadas[str(row["vendor_id"])] = sorted(set(value for value in levels if value > 0))
    # A tabela normalizada é a fonte preferida; JSON permanece como fallback
    # durante a transição e para releases anteriores à migration.
    normalizadas: dict[str, list[float]] = {}
    for row in niveis_normalizados:
        normalizadas.setdefault(str(row["vendor_id"]), []).append(float(row["target_amount"] or 0))
    escadas.update(normalizadas)
    for vendor_id, levels in escadas.items():
        escadas[vendor_id] = sorted(set(value for value in levels if value > 0))
    return sum(por_canal.values()), por_canal, por_vendedor, escadas


def _metas(conn, company_id: int, competencia: str) -> tuple[float, dict[str, float], dict[str, float]]:
    """Contrato legado: metas simples; a evolução usa `_metas_com_escadas`."""
    total, canais, vendedores, _ = _metas_com_escadas(conn, company_id, competencia)
    return total, canais, vendedores


def calcular_forecasts(conn, company_id: int, hoje: date) -> list[ForecastLinha]:
    """Os NÚMEROS do forecast mensal, por empresa, canal e vendedor.

    Devolve a forma persistível. A narrativa é derivada disto em
    `_forecasts_segmentados` — o job de forecast grava estas linhas e o job de
    insights escreve as frases, os dois a partir da mesma conta. Duplicar o
    cálculo faria a home e a tabela discordarem em algum momento.
    """
    import pandas as pd

    if hoje.day < MIN_DIAS_FORECAST:
        return []
    competencia = hoje.strftime("%Y-%m")
    meta_empresa, metas_canal, metas_vendedor, escadas_vendedor = _metas_com_escadas(conn, company_id, competencia)
    saida: list[ForecastLinha] = []

    for dimensao in ("empresa", "canal", "vendedor"):
        linhas = _forecast_rows(conn, company_id, dimensao, hoje)
        if not linhas:
            continue
        df = pd.DataFrame(linhas)
        df["dia"] = pd.to_datetime(df["dia"])
        df["receita"] = df["receita"].astype(float)
        df["mes"] = df["dia"].dt.strftime("%Y-%m")
        for (codigo, nome), grupo in df.groupby(["codigo", "nome"]):
            atual = grupo[grupo["mes"] == competencia]
            if atual.empty:
                continue
            realizado = float(atual["receita"].sum())
            historicos = [float(v) for m, v in grupo[grupo["mes"] != competencia].groupby("mes")["receita"].sum().tail(5).items()]
            if len(historicos) < 2 or realizado <= 0:
                continue
            dias_mes = (hoje.replace(day=28) + timedelta(days=4)).replace(day=1) - timedelta(days=1)
            ritmo = realizado / hoje.day * dias_mes.day
            media_hist = sum(historicos) / len(historicos)
            # Combina ritmo corrente e histórico; o peso do realizado cresce ao longo do mês.
            peso = min(0.90, max(0.25, hoje.day / dias_mes.day))
            projetado = ritmo * peso + media_hist * (1.0 - peso)
            erro = _erro_historico(historicos)
            minimo, maximo = max(realizado, projetado * (1 - erro)), projetado * (1 + erro)
            meta_bruta = meta_empresa if dimensao == "empresa" else (
                metas_canal.get(str(codigo), 0.0) if dimensao == "canal"
                else metas_vendedor.get(str(codigo), 0.0)
            )
            ladder = evaluate_target_ladder(escadas_vendedor.get(str(codigo), []), realizado) \
                if dimensao == "vendedor" else None
            level_probabilities: list[dict[str, Any]] = []
            if ladder:
                for level_number, level_value in enumerate(ladder["levels"], start=1):
                    risk = _risco_nao_bater(level_value, projetado, erro)
                    level_probabilities.append({
                        "level": level_number,
                        "target": round(level_value, 2),
                        "achieved": realizado >= level_value,
                        "probability": 1.0 if realizado >= level_value else (
                            round(1.0 - risk, 4) if risk is not None else None
                        ),
                        "actionable": erro < ERRO_CONFIANCA_MINIMA,
                    })
            if ladder and ladder["base_target"] is not None:
                # O compromisso continua nas métricas, mas risco/gap miram o
                # primeiro nível ainda não conquistado. Depois do máximo não se
                # inventa uma nova meta nem se gera alerta.
                meta_bruta = ladder["active_target"] or 0.0
            # 0.0 aqui significa "não achei meta", não "a meta é zero".
            meta = meta_bruta if meta_bruta > 0 else None
            ladder_advice = recommend_target_ladder(
                escadas_vendedor.get(str(codigo), []), realizado, projetado,
                max(dias_mes.day - hoje.day, 0), erro,
            ) if dimensao == "vendedor" else {}
            saida.append(ForecastLinha(
                dimensao=dimensao,
                entidade_codigo=str(codigo),
                entidade_nome=str(nome),
                competencia=competencia,
                realizado=realizado,
                projecao=projetado,
                valor_min=minimo,
                valor_max=maximo,
                meta_valor=meta,
                risco_nao_bater=_risco_nao_bater(meta or 0.0, projetado, erro),
                erro_historico=erro,
                meses_historicos=len(historicos),
                dados_usados=len(grupo),
                fatores=[
                    f"ritmo atual de {_brl(ritmo)}",
                    f"média de {len(historicos)} meses: {_brl(media_hist)}",
                    f"peso do mês corrente: {peso * 100:.0f}%",
                    *([f"escada: nível {ladder['achieved_level']} conquistado; próximo {ladder['active_level']}"] if ladder else []),
                    *([f"ritmo necessário: {_brl(ladder_advice['required_daily'])}/dia"] if ladder_advice.get("required_daily") else []),
                    *([f"próximo patamar sugerido: {_brl(ladder_advice['recommended_next_target'])} (requer aprovação)"] if ladder_advice.get("recommended_next_target") else []),
                ],
                base_target=ladder["base_target"] if ladder else meta,
                active_target=ladder["active_target"] if ladder else meta,
                maximum_target=ladder["maximum_target"] if ladder else meta,
                achieved_level=int(ladder["achieved_level"]) if ladder else 0,
                active_level=ladder["active_level"] if ladder else (1 if meta else None),
                levels_total=len(ladder["levels"]) if ladder else (1 if meta else 0),
                level_probabilities=level_probabilities,
                required_daily=ladder_advice.get("required_daily"),
                projected_level=int(ladder_advice.get("projected_level", 0)),
                recommended_next_target=ladder_advice.get("recommended_next_target"),
                ladder_confidence=str(ladder_advice.get("confidence", "sem_dados")),
            ))

    return saida


def _confianca(erro_historico: float) -> str:
    """Rótulo honesto sobre quanto a projeção merece ser levada a sério."""
    if erro_historico < ERRO_CONFIANCA_ALTA:
        return "alta"
    if erro_historico < ERRO_CONFIANCA_MINIMA:
        return "media"
    return "baixa"


def _forecasts_segmentados(conn, company_id: int, hoje: date) -> list[Insight]:
    """A NARRATIVA do forecast — derivada de `calcular_forecasts`, nunca recalculada."""
    saida: list[Insight] = []
    for linha in calcular_forecasts(conn, company_id, hoje):
        risco = linha.risco_nao_bater
        confianca = _confianca(linha.erro_historico)
        metricas = linha.to_metrics() | {
            "confianca": confianca,
            "ultima_atualizacao": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
        }
        titulo = "Previsão do mês" if linha.dimensao == "empresa" else f"Previsão — {linha.entidade_nome}"

        # PORTÃO DE CONFIANÇA. Com MAPE acima do limite o modelo não tem poder
        # preditivo para esta entidade: a faixa fica tão larga que cobre
        # qualquer desfecho, e um "risco de 66%" calculado sobre ela é um número
        # sem lastro. A linha continua sendo GRAVADA — a previsão precisa ficar
        # registrada para o erro poder ser cobrado depois —, mas NÃO vira alerta.
        # Vimos vendedores com erro histórico de 100% gerando notificação de
        # atenção no primeiro dia em que isto rodou.
        if confianca == "baixa":
            saida.append(Insight(
                kind=f"forecast_{linha.dimensao}_{linha.entidade_codigo[:20]}",
                severity="neutro",
                title=titulo,
                body=(f"Realizado {_brl(linha.realizado)}. Histórico irregular demais para projetar "
                      f"com confiança (erro de {linha.erro_historico * 100:.0f}%): "
                      f"a faixa iria de {_brl(linha.valor_min)} a {_brl(linha.valor_max)}."),
                rank_weight=10,
                metrics=metricas,
                link_url="plano_voo.php" if linha.dimensao != "canal" else "index.php",
            ))
            continue

        risco_txt = f" Risco de não bater a meta: {risco * 100:.0f}%." if risco is not None else ""
        confianca_txt = "" if confianca == "alta" else f" Confiança média (erro de {linha.erro_historico * 100:.0f}%)."
        ladder_txt = ""
        if linha.levels_total > 1:
            if linha.active_target is None:
                superacao = max(linha.realizado - (linha.maximum_target or linha.realizado), 0)
                ladder_txt = f" Todos os {linha.levels_total} níveis conquistados; superação de {_brl(superacao)}."
                if linha.recommended_next_target is not None:
                    ladder_txt += f" Próximo patamar sugerido pelo Python: {_brl(linha.recommended_next_target)}, sujeito a aprovação."
            else:
                chance = next((
                    p.get("probability") for p in linha.level_probabilities
                    if p.get("level") == linha.active_level
                ), None)
                chance_txt = f", chance estimada {chance * 100:.0f}%" if chance is not None else ""
                ladder_txt = (
                    f" Meta {linha.achieved_level} conquistada; desafio ativo Meta "
                    f"{linha.active_level} em {_brl(linha.active_target)}{chance_txt}."
                )
        saida.append(Insight(
            kind=f"forecast_{linha.dimensao}_{linha.entidade_codigo[:20]}",
            severity="critico" if risco is not None and risco >= .70 else "atencao" if risco is not None and risco >= .45 else "neutro",
            title=titulo,
            body=(f"Realizado {_brl(linha.realizado)}; projeção provável {_brl(linha.projecao)}, "
                  f"faixa {_brl(linha.valor_min)}–{_brl(linha.valor_max)}.{risco_txt}{confianca_txt}{ladder_txt}"),
            rank_weight=95 if linha.dimensao == "empresa" else (75 if risco is not None and risco >= .45 else 25),
            metrics=metricas,
            link_url="plano_voo.php" if linha.dimensao != "canal" else "index.php",
        ))
    # Empresa sempre; segmentos mais relevantes sem inundar a home.
    gerais = [i for i in saida if i.metrics["dimensao"] == "empresa"]
    segmentos = sorted((i for i in saida if i.metrics["dimensao"] != "empresa"), key=lambda i: -i.rank_weight)
    return gerais[:1] + segmentos[:3]


def _anomalia_canal(conn, company_id: int, hoje: date) -> Insight | None:
    """Canal muito abaixo de duas janelas equivalentes anteriores."""
    linhas = fetch_all(conn, """SELECT COALESCE(NULLIF(business_channel,''),'sem_canal') canal,
               SUM(CASE WHEN data_emissao >= %s THEN valor_total ELSE 0 END) atual,
               SUM(CASE WHEN data_emissao >= %s AND data_emissao < %s THEN valor_total ELSE 0 END) anterior,
               COUNT(CASE WHEN data_emissao >= %s THEN 1 END) notas
          FROM biomie_notas_fiscais_canonicas
         WHERE company_id = %s AND """ + UNIVERSO_COMERCIAL + """ AND data_emissao >= %s
         GROUP BY canal""", (
        hoje - timedelta(days=28), hoje - timedelta(days=56), hoje - timedelta(days=28),
        hoje - timedelta(days=28), company_id, hoje - timedelta(days=56)))
    piores = []
    for r in linhas:
        atual, anterior = float(r["atual"] or 0), float(r["anterior"] or 0)
        if anterior >= 1000 and int(r["notas"] or 0) >= 4:
            queda = (anterior - atual) / anterior
            if queda >= .20:
                piores.append((queda, r, atual, anterior))
    if not piores:
        return None
    queda, r, atual, anterior = max(piores, key=lambda x: x[0])
    return Insight(kind="anomalia_queda_canal", severity="critico" if queda >= .40 else "atencao",
        title="Canal abaixo do comportamento normal",
        body=(f"As vendas do canal {r['canal']} estão {queda * 100:.0f}% abaixo das 4 semanas anteriores "
              f"({_brl(atual)} contra {_brl(anterior)})."), rank_weight=100,
        metrics={"canal": str(r["canal"]), "atual": atual, "anterior": anterior,
                 "queda_relativa": round(queda, 4), "notas": int(r["notas"] or 0)}, link_url="index.php")


def _anomalia_cancelamentos(conn, company_id: int, hoje: date) -> Insight | None:
    r = fetch_all(conn, """SELECT
        SUM(data_emissao >= %s AND status_fiscal = 'cancelada') canc_atual,
        SUM(data_emissao >= %s AND data_emissao < %s AND status_fiscal = 'cancelada') canc_anterior,
        SUM(data_emissao >= %s) total_atual
        FROM biomie_notas_fiscais_canonicas WHERE company_id = %s AND data_emissao >= %s""",
        (hoje - timedelta(days=28), hoje - timedelta(days=56), hoje - timedelta(days=28),
         hoje - timedelta(days=28), company_id, hoje - timedelta(days=56)))
    if not r:
        return None
    x = r[0]; atual, anterior, total = int(x["canc_atual"] or 0), int(x["canc_anterior"] or 0), int(x["total_atual"] or 0)
    if atual < 3 or atual < max(3, anterior * 1.5):
        return None
    return Insight(kind="anomalia_cancelamentos", severity="atencao", title="Cancelamentos acima do padrão",
        body=f"Foram {atual} notas canceladas nas últimas 4 semanas, contra {anterior} nas 4 anteriores.",
        rank_weight=85, metrics={"cancelamentos_atual": atual, "cancelamentos_anterior": anterior,
                                "notas_periodo": total, "crescimento": round((atual-anterior)/max(anterior,1),4)},
        link_url="fiscal.php")


def compute(conn: pymysql.connections.Connection, company_id: int) -> list[Insight]:
    """Todas as leituras de uma empresa, já ordenadas por relevância."""
    hoje = datetime.now(timezone.utc).date()
    desde_90 = hoje - timedelta(days=90)

    diario = _faturamento_diario(conn, company_id, desde_90)

    candidatos = [
        *_forecasts_segmentados(conn, company_id, hoje),
        _anomalia_canal(conn, company_id, hoje),
        _anomalia_cancelamentos(conn, company_id, hoje),
        _tendencia(diario, hoje),
        _concentracao(conn, company_id, desde_90),
        _ritmo_do_mes(diario, hoje),
        _vendedor_destaque(conn, company_id, desde_90, hoje),
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


def purge_expired(
    conn: pymysql.connections.Connection,
    company_id: int,
    retencao_dias: int = RETENCAO_DIAS,
) -> int:
    """Apaga leituras VENCIDAS e já substituídas, de UMA empresa.

    Sem isto a tabela cresce para sempre: cada execução marca as vigentes como
    `is_current = 0` e insere novas, e nada nunca sai. São 4 execuções por dia ×
    5 empresas — pequeno por linha, ilimitado no tempo.

    Três travas de propósito:

    1. `is_current = 0` — nunca apaga o que está na tela. Uma leitura vigente é
       apagada só quando a execução seguinte a substitui.
    2. `valid_until` fora da janela de retenção — o histórico recente continua
       disponível para conferir o que o painel dizia semana passada. Expurgo
       imediato transformaria "por que apareceu esse insight?" em pergunta sem
       resposta.
    3. `company_id` explícito — mesma regra do resto desta camada: não existe
       escrita "para todas as empresas" escondida num WHERE.

    O DELETE exige a permissão correspondente no usuário restrito, concedida
    APENAS nesta tabela (ver provision_intelligence_db_user.sh).
    """
    from .db import execute

    n = execute(
        conn,
        """DELETE FROM intelligence_insights
            WHERE company_id = %s
              AND is_current = 0
              AND valid_until < (UTC_TIMESTAMP() - INTERVAL %s DAY)""",
        (company_id, int(retencao_dias)),
    )
    conn.commit()
    return n
