"""Dataset histórico para treino — construído SEM vazamento temporal.

O erro clássico neste tipo de problema é treinar com colunas que só existem
depois do desfecho (etapa=60, data_faturamento, tem_nfe, status='faturado').
O modelo fica com 99% de acurácia no papel e é inútil na tela, porque em
produção ele nunca vai ver essas colunas preenchidas no momento da decisão.

A disciplina aqui:

  · Um DATA DE CORTE T por linha. Tudo que entra como feature tem timestamp <= T.
  · O RÓTULO é observado estritamente em (T, T + horizonte].
  · Pedidos que JÁ tinham NF em T saem da amostra — prever o que já aconteceu
    não é previsão.
  · Colunas de desfecho são proibidas por construção; a lista está em
    _COLUNAS_PROIBIDAS e o builder falha se alguma escapar para as features.

Rótulo: o pedido virou NF. Vem de biomie_notas_fiscais_canonicas, não da coluna
`resolution` da agenda — essa está 100% NULL porque o portão de desativação
automática nunca foi ligado, e não da `data_faturamento` dos pedidos, que só
tem 3 preenchidas em 334.
"""

from __future__ import annotations

import csv
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path
from typing import Any

import pymysql

from .db import fetch_all

# Nunca podem virar feature: só ficam conhecidas depois do desfecho.
_COLUNAS_PROIBIDAS = frozenset({
    "data_faturamento", "data_cancelamento", "tem_nfe", "tem_titulo",
    "etapa_omie", "status_comercial", "resolution", "resolved_at",
    "status_pedido_omie", "bridge_status",
})


@dataclass(frozen=True)
class DatasetSpec:
    company_id: int
    horizon_days: int = 30
    #: quantas datas de corte gerar, andando para trás
    cuts: int = 12
    #: espaçamento entre cortes
    step_days: int = 7
    #: pedidos mais velhos que isso em T saem (orçamento morto não é decisão real)
    max_age_days: int = 120


def _cut_dates(spec: DatasetSpec, latest: date) -> list[date]:
    """Datas de corte, da mais antiga para a mais recente.

    O corte mais recente respeita o horizonte: não adianta cortar ontem se o
    rótulo precisa de 30 dias para se materializar — essas linhas nasceriam
    todas negativas por falta de tempo, não por não terem fechado.
    """
    newest = latest - timedelta(days=spec.horizon_days)
    return sorted(newest - timedelta(days=spec.step_days * i) for i in range(spec.cuts))


def build(
    conn: pymysql.connections.Connection,
    spec: DatasetSpec,
    latest: date | None = None,
) -> list[dict[str, Any]]:
    latest = latest or date.today()
    samples: list[dict[str, Any]] = []

    for cut in _cut_dates(spec, latest):
        samples.extend(_build_for_cut(conn, spec, cut))

    _assert_sem_vazamento(samples)
    return samples


def _build_for_cut(
    conn: pymysql.connections.Connection, spec: DatasetSpec, cut: date
) -> list[dict[str, Any]]:
    horizon_end = cut + timedelta(days=spec.horizon_days)
    oldest = cut - timedelta(days=spec.max_age_days)

    rows = fetch_all(
        conn,
        """
        SELECT p.pedido_id_omie,
               p.company_id,
               p.data_emissao,
               p.valor_total,
               p.quantidade_itens,
               p.business_channel,
               p.codigo_vendedor_omie,
               p.codigo_cliente_omie,

               -- RÓTULO: existe NF autorizada para este pedido dentro da janela?
               EXISTS (
                   SELECT 1 FROM biomie_notas_fiscais_canonicas n
                    WHERE n.company_id = p.company_id
                      AND n.pedido_id_omie = p.pedido_id_omie
                      AND n.status_fiscal = 'autorizada'
                      AND n.data_emissao > %(cut)s
                      AND n.data_emissao <= %(horizon_end)s
               ) AS convertido,

               -- Histórico do VENDEDOR estritamente ANTES do corte.
               (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nv
                 WHERE nv.company_id = p.company_id
                   AND nv.codigo_vendedor_omie = p.codigo_vendedor_omie
                   AND nv.status_fiscal = 'autorizada'
                   AND nv.data_emissao <= %(cut)s) AS vendedor_nfs_ate_corte,

               -- Cliente já comprou antes do corte?
               (SELECT COUNT(*) FROM biomie_notas_fiscais_canonicas nc
                 WHERE nc.company_id = p.company_id
                   AND nc.codigo_cliente_omie = p.codigo_cliente_omie
                   AND nc.status_fiscal = 'autorizada'
                   AND nc.data_emissao <= %(cut)s) AS cliente_nfs_ate_corte
          FROM biomie_pedidos_canonicos p
         WHERE p.company_id = %(company)s
           AND p.data_emissao IS NOT NULL
           AND p.data_emissao <= %(cut)s
           AND p.data_emissao >= %(oldest)s
           -- Aberto EM T: nenhuma NF anterior ou igual ao corte.
           AND NOT EXISTS (
                   SELECT 1 FROM biomie_notas_fiscais_canonicas n0
                    WHERE n0.company_id = p.company_id
                      AND n0.pedido_id_omie = p.pedido_id_omie
                      AND n0.status_fiscal = 'autorizada'
                      AND n0.data_emissao <= %(cut)s
               )
        """,
        {
            "company": spec.company_id,
            "cut": cut,
            "horizon_end": horizon_end,
            "oldest": oldest,
        },
    )

    valores = [float(r["valor_total"]) for r in rows if r.get("valor_total") is not None]
    valores_ord = sorted(valores)

    samples = []
    for r in rows:
        valor = float(r["valor_total"]) if r.get("valor_total") is not None else None
        percentil = None
        if valor is not None and valores_ord:
            percentil = sum(1 for v in valores_ord if v <= valor) / len(valores_ord)

        emissao: date = r["data_emissao"]
        samples.append({
            "company_id": int(r["company_id"]),
            "pedido_id_omie": int(r["pedido_id_omie"]),
            "cut_date": cut.isoformat(),
            # ── features (tudo conhecido em T) ──
            "dias_aberto": (cut - emissao).days,
            "valor_total": valor,
            "valor_percentil": round(percentil, 4) if percentil is not None else None,
            "quantidade_itens": int(r["quantidade_itens"] or 0),
            "canal_licitacao": 1 if (r.get("business_channel") or "") == "licitacao" else 0,
            "vendedor_nfs_ate_corte": int(r["vendedor_nfs_ate_corte"] or 0),
            "cliente_nfs_ate_corte": int(r["cliente_nfs_ate_corte"] or 0),
            "cliente_recorrente": 1 if int(r["cliente_nfs_ate_corte"] or 0) > 0 else 0,
            "mes_emissao": emissao.month,
            "dia_semana_emissao": emissao.weekday(),
            # ── rótulo (observado só depois de T) ──
            "convertido": int(r["convertido"] or 0),
        })
    return samples


FEATURE_COLUMNS = (
    "dias_aberto",
    "valor_total",
    "valor_percentil",
    "quantidade_itens",
    "canal_licitacao",
    "vendedor_nfs_ate_corte",
    "cliente_nfs_ate_corte",
    "cliente_recorrente",
    "mes_emissao",
    "dia_semana_emissao",
)
LABEL_COLUMN = "convertido"


def _assert_sem_vazamento(samples: list[dict[str, Any]]) -> None:
    """Trava explícita: nenhuma coluna de desfecho pode ter entrado nas features."""
    if not samples:
        return
    vazadas = _COLUNAS_PROIBIDAS.intersection(FEATURE_COLUMNS)
    if vazadas:
        raise RuntimeError(f"Vazamento temporal: {sorted(vazadas)} não pode ser feature.")
    faltando = set(FEATURE_COLUMNS) - set(samples[0])
    if faltando:
        raise RuntimeError(f"Dataset sem as features declaradas: {sorted(faltando)}")


def to_csv(samples: list[dict[str, Any]], path: Path) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    if not samples:
        path.write_text("", encoding="utf-8")
        return path
    with path.open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=list(samples[0].keys()))
        writer.writeheader()
        writer.writerows(samples)
    return path


def summarize(samples: list[dict[str, Any]]) -> dict[str, Any]:
    total = len(samples)
    positivos = sum(s[LABEL_COLUMN] for s in samples)
    cortes = sorted({s["cut_date"] for s in samples})
    return {
        "linhas": total,
        "positivos": positivos,
        "negativos": total - positivos,
        "taxa_conversao": round(positivos / total, 4) if total else None,
        "cortes": len(cortes),
        "primeiro_corte": cortes[0] if cortes else None,
        "ultimo_corte": cortes[-1] if cortes else None,
    }
