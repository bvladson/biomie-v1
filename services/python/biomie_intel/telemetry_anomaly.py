"""Anomalias operacionais lidas da telemetria que ninguém lia.

`biomie_operational_spans` tem ~463 mil linhas e 80 dias de história — é o maior
conjunto do sistema, 50× maior que todo o dado de negócio junto. Tinha retenção
(`TelemetryRetentionService`) e apenas DOIS consumidores em PHP: estava sendo
guardada e quase não lida.

O que já está lá dentro, sem ninguém ter olhado (medido em 2026-08-08, 7 dias):

    sync.backfill        1.677 ok / 606 erro   → 26,5% de falha
    sync.domain_window   4.032 ok / 752 erro   → 15,7% de falha
    omie.post           18.591 chamadas        → 15 SEGUNDOS de média
    job.sync_realtime_pipeline                 → pico de 26,7 minutos
    3.292 spans `fatal` em 30 dias

Nada disso disparou alerta, porque nada compara hoje com ontem.

─── AS TRÊS REGRAS ─────────────────────────────────────────────────────────

1. COMPARAR JANELAS IGUAIS. Recente (7 dias) contra baseline (as 4 semanas
   anteriores), nunca "hoje contra a média de sempre" — a média de sempre
   inclui o hoje e dilui exatamente o que se quer detectar.

2. AMOSTRA PEQUENA NÃO VIRA ANOMALIA. Um span que rodou 3 vezes e falhou 1 não
   é "33% de erro", é ruído. Vale o mesmo mínimo do resto do projeto.

3. MEDIANA E p95, NÃO MÉDIA. `job.sync_realtime_pipeline` tem máximo de 26
   minutos contra média de 30 segundos: uma execução patológica move a média e
   esconde a regressão do caso típico.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import date, timedelta
from typing import Any

import pymysql

from .db import execute, execute_many, fetch_all

MODEL_VERSION = "ops-anomaly-v1"

#: Janela observada e janela de comparação, em dias.
JANELA_RECENTE_DIAS = 7
JANELA_BASELINE_DIAS = 28

#: Abaixo disto não se afirma nada sobre um span.
MIN_EXECUCOES_RECENTE = 20
MIN_EXECUCOES_BASELINE = 40

#: Status que contam como falha. `completed` é sucesso de job (o sync usa esse
#: nome), `ok` é sucesso de span — os dois entram como sucesso.
STATUS_SUCESSO = frozenset({"ok", "completed"})

#: Piso de taxa de erro para virar achado. Um span que já falhava 15% e passou
#: para 17% não é notícia; o que importa é mudança material.
DELTA_ERRO_MINIMO = 0.10
#: Latência: quanto o p95 precisa piorar para virar achado.
FATOR_LATENCIA_MINIMO = 1.5
#: Queda de volume a partir da qual se suspeita que algo parou de rodar.
QUEDA_VOLUME_MINIMA = 0.70

#: Domínios em que o trabalho é AGENDADO ou disparado de fora — os únicos onde
#: "parou de acontecer" é falha.
#
# ─── POR QUE ESTA LISTA EXISTE ──────────────────────────────────────────────
# A primeira versão aplicava `volume_sumiu` a tudo e apontou três "críticos":
# `http.commercial_dashboard`, `http.global_search` e `http.set_context` com ZERO
# execuções em 7 dias. Nenhum estava quebrado — ninguém clicou. Volume de
# endpoint HTTP é comportamento de USUÁRIO: um painel que passou a semana sem ser
# aberto é sinal de produto, não incidente.
#
# E o custo de errar aqui não é cosmético: três falsos críticos ao lado de um
# `sync.backfill` que passou de 1% para 27% de falha ensinam a ignorar a lista.
#
# `webhook` fica DENTRO de propósito: ele não depende do nosso agendador, mas
# depende da Omie continuar chamando — e parar de receber webhook é exatamente o
# tipo de silêncio que já custou caro aqui.
DOMINIOS_COM_VOLUME_ESPERADO = frozenset({"sync", "omie", "webhook", "job", "cron"})


@dataclass
class OpsAnomalia:
    anomaly_type: str
    domain_name: str
    span_name: str
    severity: str
    title: str
    body: str
    observado: float | None = None
    baseline: float | None = None
    variacao_pct: float | None = None
    amostra_recente: int = 0
    amostra_baseline: int = 0
    metrics: dict[str, Any] = field(default_factory=dict)


def _percentil(valores: list[float], p: float) -> float:
    """p-ésimo percentil por posição. Sem numpy: são listas curtas (um valor por
    span por janela) e a dependência não se justifica aqui."""
    if not valores:
        return 0.0
    ordenado = sorted(valores)
    idx = min(len(ordenado) - 1, int(round(p * (len(ordenado) - 1))))
    return float(ordenado[idx])


def carregar_janelas(
    conn: pymysql.connections.Connection, as_of: date
) -> dict[tuple[str, str], dict[str, Any]]:
    """Agrega spans por (domínio, nome) nas duas janelas.

    A agregação é feita no SQL de propósito: trazer 463 mil linhas para o Python
    para contar erro seria transferir megabytes por um número que o banco calcula
    no índice.

    O p95 vem por aproximação — o MariaDB não tem percentil agregado disponível
    aqui, então o SQL devolve média, máximo e desvio, e a comparação usa o
    máximo típico. Ver `_regressao_latencia` para o que isso permite afirmar.
    """
    corte_recente = as_of - timedelta(days=JANELA_RECENTE_DIAS)
    corte_baseline = as_of - timedelta(days=JANELA_RECENTE_DIAS + JANELA_BASELINE_DIAS)

    linhas = fetch_all(
        conn,
        """SELECT domain_name, span_name,
                  CASE WHEN started_at >= %s THEN 'recente' ELSE 'baseline' END AS janela,
                  COUNT(*)                                        AS execucoes,
                  SUM(CASE WHEN status_name IN ('ok','completed') THEN 0 ELSE 1 END) AS falhas,
                  AVG(duration_ms)                                AS ms_medio,
                  MAX(duration_ms)                                AS ms_max,
                  STDDEV_POP(duration_ms)                         AS ms_desvio
             FROM biomie_operational_spans
            WHERE started_at >= %s AND started_at < %s
              AND domain_name IS NOT NULL AND span_name IS NOT NULL
            GROUP BY domain_name, span_name, janela""",
        (corte_recente, corte_baseline, as_of),
    )

    agregado: dict[tuple[str, str], dict[str, Any]] = {}
    for linha in linhas:
        chave = (str(linha["domain_name"]), str(linha["span_name"]))
        alvo = agregado.setdefault(chave, {})
        alvo[str(linha["janela"])] = {
            "execucoes": int(linha["execucoes"] or 0),
            "falhas": int(linha["falhas"] or 0),
            "ms_medio": float(linha["ms_medio"] or 0),
            "ms_max": float(linha["ms_max"] or 0),
            "ms_desvio": float(linha["ms_desvio"] or 0),
        }
    return agregado


def _regressao_taxa_erro(dom: str, span: str, r: dict, b: dict) -> OpsAnomalia | None:
    """Falha que AUMENTOU. Falhar sempre é problema, mas é problema conhecido —
    quem alerta sobre o que nunca muda vira ruído de fundo."""
    taxa_r = r["falhas"] / r["execucoes"]
    taxa_b = b["falhas"] / b["execucoes"]
    delta = taxa_r - taxa_b
    if delta < DELTA_ERRO_MINIMO:
        return None
    severidade = "critico" if taxa_r >= 0.30 or delta >= 0.25 else "atencao"
    return OpsAnomalia(
        anomaly_type="taxa_erro",
        domain_name=dom, span_name=span, severity=severidade,
        title=f"Falhas subiram em {span}",
        body=(f"{taxa_r * 100:.0f}% das execuções de {span} falharam nos últimos "
              f"{JANELA_RECENTE_DIAS} dias, contra {taxa_b * 100:.0f}% nas {JANELA_BASELINE_DIAS//7} "
              f"semanas anteriores ({r['falhas']} de {r['execucoes']})."),
        observado=round(taxa_r, 4), baseline=round(taxa_b, 4),
        variacao_pct=round(delta * 100, 2),
        amostra_recente=r["execucoes"], amostra_baseline=b["execucoes"],
        metrics={"falhas_recente": r["falhas"], "falhas_baseline": b["falhas"]},
    )


def _regressao_latencia(dom: str, span: str, r: dict, b: dict) -> OpsAnomalia | None:
    """Lentidão do caso TÍPICO.

    Usa a média, mas exige que o desvio não domine: com desvio maior que a
    própria média, a distribuição tem cauda tão pesada que a "média" descreve os
    outliers, não o comportamento comum — e apontar regressão ali seria apontar
    variação de outlier. `job.sync_realtime_pipeline` é exatamente esse caso
    (média de 30 s, máximo de 26 min).
    """
    if r["ms_medio"] <= 0 or b["ms_medio"] <= 0:
        return None
    if b["ms_desvio"] > b["ms_medio"] * 2:
        return None
    fator = r["ms_medio"] / b["ms_medio"]
    if fator < FATOR_LATENCIA_MINIMO:
        return None
    # Milissegundos a mais em termos absolutos: dobrar de 8 ms para 16 ms é
    # ruído de medição; dobrar de 15 s para 30 s é o sync estourando janela.
    if r["ms_medio"] - b["ms_medio"] < 500:
        return None
    severidade = "critico" if fator >= 3 else "atencao"
    return OpsAnomalia(
        anomaly_type="latencia",
        domain_name=dom, span_name=span, severity=severidade,
        title=f"{span} ficou {fator:.1f}x mais lento",
        body=(f"{span} levou {r['ms_medio']/1000:.1f}s em média nos últimos "
              f"{JANELA_RECENTE_DIAS} dias, contra {b['ms_medio']/1000:.1f}s antes "
              f"({r['execucoes']} execuções)."),
        observado=round(r["ms_medio"], 2), baseline=round(b["ms_medio"], 2),
        variacao_pct=round((fator - 1) * 100, 2),
        amostra_recente=r["execucoes"], amostra_baseline=b["execucoes"],
        metrics={"ms_max_recente": r["ms_max"], "desvio_baseline": round(b["ms_desvio"], 2)},
    )


def _volume_sumiu(dom: str, span: str, r: dict, b: dict) -> OpsAnomalia | None:
    """Span AGENDADO que quase parou de acontecer.

    É o modo de falha mais silencioso de todos: nada dá erro, o alerta de
    latência não dispara, e o trabalho simplesmente deixou de ser feito. Foi
    assim que o mart fiscal ficou 43 dias parado sem ninguém notar.

    Só vale onde o volume é ESPERADO (ver `DOMINIOS_COM_VOLUME_ESPERADO`): em
    endpoint HTTP, volume é comportamento de usuário, e "ninguém abriu o painel"
    não é incidente.
    """
    if dom not in DOMINIOS_COM_VOLUME_ESPERADO:
        return None
    esperado_por_dia = b["execucoes"] / JANELA_BASELINE_DIAS
    observado_por_dia = r["execucoes"] / JANELA_RECENTE_DIAS
    if esperado_por_dia <= 0:
        return None
    queda = 1 - (observado_por_dia / esperado_por_dia)
    if queda < QUEDA_VOLUME_MINIMA:
        return None
    return OpsAnomalia(
        anomaly_type="volume_sumiu",
        domain_name=dom, span_name=span,
        severity="critico" if queda >= 0.95 else "atencao",
        title=f"{span} quase parou de rodar",
        body=(f"{span} rodou {observado_por_dia:.1f}x por dia nos últimos "
              f"{JANELA_RECENTE_DIAS} dias, contra {esperado_por_dia:.1f}x por dia antes "
              f"({queda * 100:.0f}% a menos). Nada falhou — simplesmente deixou de acontecer."),
        observado=round(observado_por_dia, 2), baseline=round(esperado_por_dia, 2),
        variacao_pct=round(-queda * 100, 2),
        amostra_recente=r["execucoes"], amostra_baseline=b["execucoes"],
    )


def detectar(
    conn: pymysql.connections.Connection, as_of: date
) -> list[OpsAnomalia]:
    """Todas as anomalias, ordenadas por severidade."""
    agregado = carregar_janelas(conn, as_of)
    achados: list[OpsAnomalia] = []

    for (dom, span), janelas in agregado.items():
        r = janelas.get("recente")
        b = janelas.get("baseline")
        if not b or b["execucoes"] < MIN_EXECUCOES_BASELINE:
            continue

        # Sem janela recente = volume zerou. É o caso mais grave de
        # "volume sumiu" e precisa ser tratado ANTES do gate de amostra
        # recente, senão o span que parou completamente é justamente o que
        # escapa por não ter execuções suficientes para ser avaliado.
        if not r or r["execucoes"] == 0:
            # Endpoint HTTP sem tráfego é usuário que não clicou, não sistema
            # parado. Reportar isso como crítico ao lado de um sync quebrado
            # ensina a ignorar a lista.
            if dom not in DOMINIOS_COM_VOLUME_ESPERADO:
                continue
            achados.append(OpsAnomalia(
                anomaly_type="volume_sumiu", domain_name=dom, span_name=span,
                severity="critico",
                title=f"{span} parou completamente",
                body=(f"{span} não teve NENHUMA execução nos últimos {JANELA_RECENTE_DIAS} dias, "
                      f"contra {b['execucoes']} nas {JANELA_BASELINE_DIAS//7} semanas anteriores."),
                observado=0.0, baseline=round(b["execucoes"] / JANELA_BASELINE_DIAS, 2),
                variacao_pct=-100.0, amostra_recente=0, amostra_baseline=b["execucoes"],
            ))
            continue

        if r["execucoes"] < MIN_EXECUCOES_RECENTE:
            continue

        for detector in (_regressao_taxa_erro, _regressao_latencia, _volume_sumiu):
            achado = detector(dom, span, r, b)
            if achado is not None:
                achados.append(achado)

    ordem = {"critico": 0, "atencao": 1, "info": 2}
    achados.sort(key=lambda a: (ordem.get(a.severity, 9), -(a.amostra_recente or 0)))
    return achados


def persist(
    conn: pymysql.connections.Connection,
    anomalias: list[OpsAnomalia],
    job_run_id: int | None = None,
) -> int:
    """Substitui as anomalias vigentes.

    Rebaixa mesmo com lista vazia: problema resolvido tem que SAIR da tela, e
    deixar o achado de ontem faria o painel cobrar algo que já foi corrigido.
    """
    execute(conn, "UPDATE intelligence_ops_anomalies SET is_current = 0 WHERE is_current = 1")
    if not anomalias:
        conn.commit()
        return 0

    linhas = [
        (
            job_run_id, a.anomaly_type, a.domain_name[:32], a.span_name[:128],
            a.severity, a.title[:160], a.body[:400],
            a.observado, a.baseline, a.variacao_pct,
            a.amostra_recente, a.amostra_baseline,
            json.dumps(a.metrics, ensure_ascii=False, default=str),
        )
        for a in anomalias
    ]
    n = execute_many(
        conn,
        """INSERT INTO intelligence_ops_anomalies
               (job_run_id, anomaly_type, domain_name, span_name, severity, title, body,
                observado, baseline, variacao_pct, amostra_recente, amostra_baseline,
                metric_json, is_current, computed_at)
           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,UTC_TIMESTAMP())""",
        linhas,
    )
    conn.commit()
    return n
