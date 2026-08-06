"""Regra canônica da escada de metas, espelho do domínio PHP.

Este módulo e `app/Commercial/TargetLadder.php` calculam a MESMA coisa em dois
idiomas. `config/target-ladder-contract.json` guarda os cenários que os dois
precisam responder igual, e as duas suítes o executam — sem isso a tela mostra
um nível e o forecast calcula outro, sem ninguém perceber.

Duas coisas que este arquivo aprendeu a fazer depois de divergirem do PHP:

  · NÃO EXPLODIR com valor não numérico. `float('abc')` levanta ValueError, e
    como o forecast roda as cinco empresas no mesmo processo, um único degrau
    mal cadastrado derrubava a previsão inteira daquela empresa. O PHP sempre
    ignorou o valor inválido; aqui era exceção.
  · DEVOLVER SEMPRE AS MESMAS CHAVES. O resultado vazio tinha 7 chaves e o
    preenchido 12: quem lesse `resultado["active_gap"]` depois de uma escada
    vazia recebia KeyError em vez de zero.
"""

from __future__ import annotations

from typing import Any, Iterable
from statistics import median


def _niveis_validos(levels: Iterable[Any]) -> list[float]:
    """Só números positivos, sem repetição, em ordem crescente.

    Espelha o filtro do PHP (`is_numeric` + `> 0`): valor inválido é IGNORADO,
    nunca derruba o cálculo. Meta mal cadastrada é erro de cadastro, e o preço
    dele não pode ser a empresa inteira ficar sem previsão.
    """
    limpos: set[float] = set()
    for valor in levels:
        try:
            numero = round(float(valor), 2)
        except (TypeError, ValueError):
            continue
        if numero > 0:
            limpos.add(numero)

    return sorted(limpos)


def _vazio() -> dict[str, Any]:
    """Escada inexistente — MESMAS chaves do resultado preenchido.

    `base_target` e `maximum_target` são `None`, nunca 0.0: "não há meta" e
    "a meta é zero" levam a conclusões opostas, e é 0.0 que faz o consumidor
    perder o fallback (`row.base_target ?? target_period` não dispara com zero).
    """
    return {
        "levels": [],
        "base_target": None,
        "active_target": None,
        "maximum_target": None,
        "achieved_level": 0,
        "active_level": None,
        "active_gap": 0.0,
        "base_attainment_pct": 0.0,
        "active_attainment_pct": 0.0,
        "step_progress_pct": 0.0,
        "all_levels_achieved": False,
        "over_maximum_amount": 0.0,
    }


def evaluate(levels: Iterable[Any], realized: float) -> dict[str, Any]:
    clean = _niveis_validos(levels)
    if not clean:
        return _vazio()

    try:
        realizado = float(realized)
    except (TypeError, ValueError):
        realizado = 0.0

    achieved = sum(1 for value in clean if realizado >= value)
    active_index = achieved if achieved < len(clean) else None
    active = clean[active_index] if active_index is not None else None
    previous = clean[active_index - 1] if active_index not in (None, 0) else 0.0
    span = (active - previous) if active is not None else 0.0
    step = 100.0 if active is None else (
        max(0.0, min(100.0, (realizado - previous) / span * 100)) if span > 0 else 100.0
    )

    # Arredondamento em 2 casas espelha o `round(..., 2)` do PHP. Sem isto os
    # dois motores divergiriam na segunda casa em qualquer dízima, e o contrato
    # compartilhado passaria a falhar por ruído em vez de por regra.
    return {
        "levels": clean,
        "base_target": clean[0],
        "active_target": active,
        "maximum_target": clean[-1],
        "achieved_level": achieved,
        "active_level": active_index + 1 if active_index is not None else None,
        "active_gap": round(max(active - realizado, 0.0), 2) if active is not None else 0.0,
        "base_attainment_pct": round(realizado / clean[0] * 100, 2),
        "active_attainment_pct": round(realizado / active * 100, 2) if active else 100.0,
        "step_progress_pct": round(step, 2),
        "all_levels_achieved": active is None,
        "over_maximum_amount": round(max(realizado - clean[-1], 0.0), 2),
    }


def recommend(
    levels: Iterable[Any],
    realized: float,
    projected: float,
    remaining_days: int,
    historical_error: float,
) -> dict[str, Any]:
    """Camada prescritiva da escada, sem alterar a meta oficial.

    A recomendacao depois do ultimo nivel e deliberadamente uma PROPOSTA. Metas
    podem afetar comissao e gestao de pessoas; o modelo informa um proximo
    patamar defensavel, mas a aprovacao continua humana.
    """
    ladder = evaluate(levels, realized)
    clean = ladder["levels"]
    if not clean:
        return {
            "required_daily": None, "projected_level": 0,
            "recommended_next_target": None, "confidence": "sem_dados",
        }

    try:
        proj = max(float(projected), float(realized))
    except (TypeError, ValueError):
        proj = max(float(realized), 0.0)
    days = max(int(remaining_days), 0)
    active = ladder["active_target"]
    required_daily = round(max(active - float(realized), 0.0) / days, 2) if active and days > 0 else 0.0
    projected_level = sum(1 for value in clean if proj >= value)

    suggested = None
    if ladder["all_levels_achieved"]:
        steps = [clean[i] - clean[i - 1] for i in range(1, len(clean))]
        typical_step = median(steps) if steps else clean[-1] * 0.25
        raw = max(clean[-1] + typical_step, proj * 1.05, float(realized) * 1.10)
        # Valores de gestao legiveis: arredonda para mil sem reduzir o desafio.
        suggested = float(int((raw + 999.99) // 1000) * 1000)

    error = max(0.0, float(historical_error))
    confidence = "alta" if error < 0.25 else "media" if error < 0.50 else "baixa"
    return {
        "required_daily": required_daily,
        "projected_level": projected_level,
        "recommended_next_target": suggested,
        "confidence": confidence,
    }
