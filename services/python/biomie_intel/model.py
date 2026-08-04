"""Treino e avaliação do modelo supervisionado.

REGRAS QUE ESTE MÓDULO NÃO NEGOCIA:

1. Divisão TEMPORAL, nunca aleatória. O mesmo pedido aparece em várias datas de
   corte; um split aleatório colocaria o mesmo pedido no treino e no teste e a
   métrica sairia inflada. Treina nos cortes antigos, testa nos mais novos.

2. O modelo tem que GANHAR do baseline determinístico para ser considerado útil.
   Não basta ter AUC alto: se o score simples ordena igual ou melhor, o modelo
   só adiciona custo e opacidade.

3. Amostra insuficiente REPROVA. Com poucas dezenas de linhas ou uma classe só,
   qualquer métrica é ruído. O treino devolve `apto=False` e diz por quê, em vez
   de entregar um número bonito que não se sustenta.

Regressão logística de propósito: com centenas (não milhões) de linhas, um
modelo linear regularizado é mais honesto que um ensemble — e os coeficientes
são audítáveis, o que importa numa tela que cobra vendedor.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from .dataset import FEATURE_COLUMNS, LABEL_COLUMN

# Abaixo disto, qualquer métrica é ruído.
MIN_LINHAS = 150
MIN_POSITIVOS = 30
MIN_NEGATIVOS = 30
#: ganho mínimo de AUC sobre o baseline para valer a pena trocar
GANHO_MINIMO_AUC = 0.03


@dataclass
class TrainResult:
    apto: bool
    motivo: str
    model_version: str | None = None
    metricas: dict[str, Any] = field(default_factory=dict)
    coeficientes: dict[str, float] = field(default_factory=dict)
    artifact_path: str | None = None


def _auc(y_true: list[int], scores: list[float]) -> float | None:
    """AUC por contagem de pares concordantes (Mann-Whitney).

    Implementado à mão para não depender de sklearn só para isto — e porque
    empates precisam contar 0,5, detalhe que implementações ingênuas erram.
    """
    pos = [s for y, s in zip(y_true, scores) if y == 1]
    neg = [s for y, s in zip(y_true, scores) if y == 0]
    if not pos or not neg:
        return None
    concordantes = 0.0
    for p in pos:
        for n in neg:
            if p > n:
                concordantes += 1.0
            elif p == n:
                concordantes += 0.5
    return concordantes / (len(pos) * len(neg))


def _split_temporal(samples: list[dict[str, Any]], test_fraction: float = 0.3):
    cortes = sorted({s["cut_date"] for s in samples})
    if len(cortes) < 3:
        return samples, []
    n_teste = max(1, int(len(cortes) * test_fraction))
    cortes_teste = set(cortes[-n_teste:])
    treino = [s for s in samples if s["cut_date"] not in cortes_teste]
    teste = [s for s in samples if s["cut_date"] in cortes_teste]
    return treino, teste


def _viabilidade(samples: list[dict[str, Any]]) -> str | None:
    total = len(samples)
    pos = sum(s[LABEL_COLUMN] for s in samples)
    neg = total - pos
    if total < MIN_LINHAS:
        return f"amostra pequena demais: {total} linhas (mínimo {MIN_LINHAS})"
    if pos < MIN_POSITIVOS:
        return f"poucos positivos: {pos} (mínimo {MIN_POSITIVOS})"
    if neg < MIN_NEGATIVOS:
        return f"poucos negativos: {neg} (mínimo {MIN_NEGATIVOS})"
    return None


def train(
    samples: list[dict[str, Any]],
    baseline_scores: dict[tuple[int, int], float] | None,
    model_dir: Path,
) -> TrainResult:
    motivo = _viabilidade(samples)
    if motivo:
        return TrainResult(apto=False, motivo=motivo)

    try:
        import numpy as np
        from sklearn.linear_model import LogisticRegression
        from sklearn.pipeline import Pipeline
        from sklearn.preprocessing import StandardScaler
    except ImportError:
        return TrainResult(apto=False, motivo="scikit-learn não instalado no venv do serviço")

    treino, teste = _split_temporal(samples)
    if not teste:
        return TrainResult(apto=False, motivo="cortes insuficientes para separar treino e teste no tempo")

    def matriz(rows: list[dict[str, Any]]):
        X = np.array(
            [[float(r[c]) if r[c] is not None else 0.0 for c in FEATURE_COLUMNS] for r in rows],
            dtype=float,
        )
        y = np.array([int(r[LABEL_COLUMN]) for r in rows], dtype=int)
        return X, y

    X_tr, y_tr = matriz(treino)
    X_te, y_te = matriz(teste)

    if len(set(y_tr.tolist())) < 2 or len(set(y_te.tolist())) < 2:
        return TrainResult(apto=False, motivo="treino ou teste ficou com uma única classe após o corte temporal")

    pipe = Pipeline([
        ("scaler", StandardScaler()),
        ("clf", LogisticRegression(max_iter=1000, class_weight="balanced", C=1.0)),
    ])
    pipe.fit(X_tr, y_tr)

    probs = pipe.predict_proba(X_te)[:, 1].tolist()
    y_teste = y_te.tolist()
    auc_modelo = _auc(y_teste, probs)

    # Baseline: o score determinístico ordenando os MESMOS registros de teste.
    auc_baseline = None
    if baseline_scores:
        pares = [
            (y, baseline_scores.get((r["company_id"], r["pedido_id_omie"])))
            for y, r in zip(y_teste, teste)
        ]
        pares = [(y, s) for y, s in pares if s is not None]
        if len(pares) >= 10:
            auc_baseline = _auc([y for y, _ in pares], [s for _, s in pares])

    brier = float(sum((p - y) ** 2 for p, y in zip(probs, y_teste)) / len(y_teste))

    metricas = {
        "linhas_treino": len(treino),
        "linhas_teste": len(teste),
        "positivos_teste": int(sum(y_teste)),
        "auc_modelo": round(auc_modelo, 4) if auc_modelo is not None else None,
        "auc_baseline": round(auc_baseline, 4) if auc_baseline is not None else None,
        "brier": round(brier, 4),
        "cortes_treino": sorted({s["cut_date"] for s in treino}),
        "cortes_teste": sorted({s["cut_date"] for s in teste}),
    }

    coefs = dict(zip(FEATURE_COLUMNS, (round(float(c), 4) for c in pipe.named_steps["clf"].coef_[0])))

    if auc_modelo is None:
        return TrainResult(apto=False, motivo="não foi possível calcular AUC no teste", metricas=metricas)

    if auc_baseline is not None and auc_modelo < auc_baseline + GANHO_MINIMO_AUC:
        return TrainResult(
            apto=False,
            motivo=(
                f"o modelo não supera o baseline: AUC {auc_modelo:.3f} vs "
                f"{auc_baseline:.3f} (exigido +{GANHO_MINIMO_AUC})"
            ),
            metricas=metricas,
            coeficientes=coefs,
        )

    version = f"logreg-{datetime.now(timezone.utc):%Y%m%d%H%M%S}"
    artifact = _salvar(pipe, model_dir, version, metricas, coefs)

    return TrainResult(
        apto=True,
        motivo="modelo aprovado no corte temporal",
        model_version=version,
        metricas=metricas,
        coeficientes=coefs,
        artifact_path=str(artifact),
    )


def _salvar(pipe, model_dir: Path, version: str, metricas: dict, coefs: dict) -> Path:
    import pickle

    model_dir.mkdir(parents=True, exist_ok=True)
    artifact = model_dir / f"{version}.pkl"
    with artifact.open("wb") as fh:
        pickle.dump({"pipeline": pipe, "features": list(FEATURE_COLUMNS)}, fh)
    (model_dir / f"{version}.json").write_text(
        json.dumps(
            {"version": version, "metricas": metricas, "coeficientes": coefs},
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )
    return artifact
