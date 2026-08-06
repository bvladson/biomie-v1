"""Forma persistível do forecast mensal e sua escrita.

Separado de `insights.py` de propósito: lá vive a NARRATIVA (a frase que a home
mostra), aqui vive o NÚMERO. São coisas com ciclos de vida diferentes — a frase
vence em 8 horas, a previsão precisa sobreviver para ser confrontada com o
realizado meses depois.

Por que persistir importa: um forecast que ninguém guarda não pode ter o erro
cobrado. "Erro histórico de 18%" sai do mesmo cálculo que produziu a previsão —
é autodeclaração. Guardando a previsão com a data em que foi feita, o backtest
passa a comparar o que foi dito na época com o que aconteceu depois.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from typing import Any

import pymysql

from .db import execute


@dataclass
class ForecastLinha:
    """Uma previsão, para uma entidade, para uma competência."""

    dimensao: str
    entidade_codigo: str
    entidade_nome: str
    competencia: str
    realizado: float
    projecao: float
    valor_min: float
    valor_max: float
    #: None = sem meta cadastrada. NUNCA 0.0 — meta zero faria o risco de não
    #: bater dar sempre 0%, o que é uma afirmação, não uma ausência.
    meta_valor: float | None
    risco_nao_bater: float | None
    erro_historico: float
    meses_historicos: int
    dados_usados: int
    fatores: list[str] = field(default_factory=list)
    base_target: float | None = None
    active_target: float | None = None
    maximum_target: float | None = None
    achieved_level: int = 0
    active_level: int | None = None
    levels_total: int = 0
    level_probabilities: list[dict[str, Any]] = field(default_factory=list)
    required_daily: float | None = None
    projected_level: int = 0
    recommended_next_target: float | None = None
    ladder_confidence: str = "sem_dados"

    def to_metrics(self) -> dict[str, Any]:
        """Espelho para o `metric_json` do insight — mesma verdade, um formato."""
        return {
            "dimensao": self.dimensao,
            "entidade": self.entidade_nome,
            "meta": round(self.meta_valor, 2) if self.meta_valor is not None else None,
            "base_target": round(self.base_target, 2) if self.base_target is not None else None,
            "active_target": round(self.active_target, 2) if self.active_target is not None else None,
            "maximum_target": round(self.maximum_target, 2) if self.maximum_target is not None else None,
            "achieved_level": self.achieved_level,
            "active_level": self.active_level,
            "levels_total": self.levels_total,
            "level_probabilities": self.level_probabilities,
            "required_daily": round(self.required_daily, 2) if self.required_daily is not None else None,
            "projected_level": self.projected_level,
            "recommended_next_target": round(self.recommended_next_target, 2) if self.recommended_next_target is not None else None,
            "ladder_confidence": self.ladder_confidence,
            "realizado": round(self.realizado, 2),
            "projecao": round(self.projecao, 2),
            "faixa_min": round(self.valor_min, 2),
            "faixa_max": round(self.valor_max, 2),
            "risco_nao_bater": round(self.risco_nao_bater, 4) if self.risco_nao_bater is not None else None,
            "erro_historico": round(self.erro_historico, 4),
            "dados_usados": self.dados_usados,
            "meses_historicos": self.meses_historicos,
            "fatores": self.fatores,
        }


def persist(
    conn: pymysql.connections.Connection,
    company_id: int,
    competencia: str,
    linhas: list[ForecastLinha],
    job_run_id: int | None = None,
) -> int:
    """Rebaixa as previsões vigentes da competência e grava as novas.

    INSERE em vez de sobrescrever: apagar a previsão anterior destruiria
    justamente a série que torna o erro auditável. O rebaixamento é restrito à
    COMPETÊNCIA sendo gravada — rebaixar tudo apagaria a marcação de vigente das
    previsões de meses anteriores, que ainda são a última palavra sobre eles.

    Escopo por `company_id` em toda operação: este job roda para todas as
    empresas no mesmo processo, e um UPDATE sem filtro de empresa aqui seria um
    vazamento cross-empresa silencioso.
    """
    if not linhas:
        return 0

    execute(
        conn,
        """UPDATE intelligence_forecasts
              SET is_current = 0
            WHERE company_id = %s AND competencia = %s AND is_current = 1""",
        (company_id, competencia),
    )

    for linha in linhas:
        execute(
            conn,
            """INSERT INTO intelligence_forecasts
                   (company_id, job_run_id, dimensao, entidade_codigo, entidade_nome,
                    competencia, realizado, projecao, valor_min, valor_max,
                    meta_valor, base_target, active_target, maximum_target,
                    achieved_level, active_level, levels_total, level_probabilities_json,
                    risco_nao_bater, erro_historico, meses_historicos,
                    dados_usados, fatores_json, is_current, computed_at)
               VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 1,
                       UTC_TIMESTAMP())""",
            (
                company_id,
                job_run_id,
                linha.dimensao,
                linha.entidade_codigo[:64],
                linha.entidade_nome[:160],
                linha.competencia,
                round(linha.realizado, 2),
                round(linha.projecao, 2),
                round(linha.valor_min, 2),
                round(linha.valor_max, 2),
                round(linha.meta_valor, 2) if linha.meta_valor is not None else None,
                round(linha.base_target, 2) if linha.base_target is not None else None,
                round(linha.active_target, 2) if linha.active_target is not None else None,
                round(linha.maximum_target, 2) if linha.maximum_target is not None else None,
                linha.achieved_level,
                linha.active_level,
                linha.levels_total,
                json.dumps(linha.level_probabilities, ensure_ascii=False) if linha.level_probabilities else None,
                round(linha.risco_nao_bater, 4) if linha.risco_nao_bater is not None else None,
                round(linha.erro_historico, 4),
                linha.meses_historicos,
                linha.dados_usados,
                json.dumps(linha.fatores, ensure_ascii=False) if linha.fatores else None,
            ),
        )

    conn.commit()
    return len(linhas)
