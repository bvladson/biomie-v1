"""CLI do serviço de inteligência.

    python -m biomie_intel health
    python -m biomie_intel score    --company=all [--as-of=YYYY-MM-DD] [--dry-run]
    python -m biomie_intel dataset  --company=MAG_MATRIZ [--horizon=30] [--cuts=12]
    python -m biomie_intel train    --company=MAG_MATRIZ
    python -m biomie_intel compare  --company=all

Toda saída é JSON: o chamador é cron ou o PHP, não um humano lendo texto solto.
"""

from __future__ import annotations

import argparse
import json
import sys
import uuid
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any

from . import dataset as dataset_mod
from . import health as health_mod
from . import forecast as forecast_mod
from . import insights as insights_mod
from . import model as model_mod
from . import quotes as quotes_mod
from .config import ConfigError, load_settings
from .db import connect, fetch_all
from .jobs import track
from .logging_setup import setup as setup_logging
from .tenancy import UnknownCompanyError, resolve


def _dispersao(scores) -> dict[str, Any]:
    """Quanto o score realmente separa a população.

    Existe porque a primeira calibração colocou 100% dos orçamentos em "alta" —
    um score que não separa é indistinguível de não ter score, e isso precisa
    aparecer na saída do job, não só numa inspeção manual.
    """
    if not scores:
        return {}
    valores = sorted(s.score for s in scores)
    n = len(valores)

    def pct(p: float) -> float:
        return round(valores[min(n - 1, int(p * n))], 1)

    return {
        "min": round(valores[0], 1),
        "p25": pct(0.25),
        "mediana": pct(0.50),
        "p75": pct(0.75),
        "max": round(valores[-1], 1),
        "amplitude": round(valores[-1] - valores[0], 1),
    }


def _saida(payload: dict[str, Any], code: int = 0) -> int:
    json.dump(payload, sys.stdout, ensure_ascii=False, indent=2, default=str)
    sys.stdout.write("\n")
    return code


def cmd_health(args, settings, logger) -> int:
    with connect(settings) as conn:
        resultado = health_mod.check(conn)
    return _saida(resultado, 0 if resultado["ok"] else 1)


def cmd_score(args, settings, logger) -> int:
    as_of = date.fromisoformat(args.as_of) if args.as_of else datetime.now(timezone.utc).date()
    resumo: list[dict[str, Any]] = []

    with connect(settings) as conn:
        empresas = resolve(conn, args.company)
        for empresa in empresas:
            linhas = quotes_mod.load_open_quotes(conn, empresa.id)
            scores = quotes_mod.score_company(linhas, as_of)

            # Movimento desde a rodada anterior — o gestor quer saber O QUE
            # MUDOU, não reler a mesma lista.
            anteriores = quotes_mod.load_previous_scores(
                conn, empresa.id, "deterministic", settings.run_mode
            )
            quotes_mod.apply_movement(scores, anteriores)

            bandas: dict[str, int] = {}
            movimentos: dict[str, int] = {}
            for s in scores:
                bandas[s.band] = bandas.get(s.band, 0) + 1
                mv = str(s.features.get("movimento", "?"))
                movimentos[mv] = movimentos.get(mv, 0) + 1
            dispersao = _dispersao(scores)

            if args.dry_run:
                resumo.append({
                    "empresa": empresa.slug, "orcamentos": len(scores),
                    "bandas": bandas, "dispersao": dispersao, "movimento": movimentos, "gravado": False,
                })
                continue

            with track(
                conn, "quote_score",
                company_id=empresa.id,
                run_mode=settings.run_mode,
                correlation_id=args.correlation_id,
            ) as run:
                run.rows_in = len(linhas)
                gravados = quotes_mod.persist_scores(
                    conn, scores,
                    company_id=empresa.id,
                    job_run_id=run.id,
                    scorer="deterministic",
                    run_mode=settings.run_mode,
                )
                run.rows_out = gravados
                run.metrics = {"bandas": bandas, "dispersao": dispersao,
                               "movimento": movimentos, "as_of": as_of.isoformat()}

            logger.info(
                "scores gravados",
                extra={"context": {"empresa": empresa.slug, "n": gravados, "bandas": bandas}},
            )
            resumo.append({
                "empresa": empresa.slug, "orcamentos": len(scores),
                "bandas": bandas, "dispersao": dispersao, "movimento": movimentos, "gravado": True,
            })

    return _saida({"ok": True, "comando": "score", "as_of": as_of.isoformat(), "empresas": resumo})


def cmd_dataset(args, settings, logger) -> int:
    saidas = []
    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            spec = dataset_mod.DatasetSpec(
                company_id=empresa.id,
                horizon_days=args.horizon,
                cuts=args.cuts,
            )
            amostras = dataset_mod.build(conn, spec)
            resumo = dataset_mod.summarize(amostras)

            destino = None
            if amostras and not args.dry_run:
                destino = dataset_mod.to_csv(
                    amostras,
                    settings.model_dir / "datasets" / f"{empresa.slug.lower()}-h{args.horizon}.csv",
                )
            saidas.append({"empresa": empresa.slug, "resumo": resumo, "arquivo": str(destino) if destino else None})

    return _saida({"ok": True, "comando": "dataset", "empresas": saidas})


def cmd_train(args, settings, logger) -> int:
    resultados = []
    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            spec = dataset_mod.DatasetSpec(
                company_id=empresa.id, horizon_days=args.horizon, cuts=args.cuts
            )
            amostras = dataset_mod.build(conn, spec)

            # Baseline para comparação: score determinístico vigente por pedido.
            baseline = {
                (int(r["company_id"]), int(r["omie_order_id"])): float(r["score"])
                for r in fetch_all(
                    conn,
                    """SELECT company_id, omie_order_id, score
                         FROM intelligence_quote_scores
                        WHERE company_id = %s AND scorer = 'deterministic' AND is_current = 1""",
                    (empresa.id,),
                )
            }

            resultado = model_mod.train(amostras, baseline, settings.model_dir)
            resultados.append({
                "empresa": empresa.slug,
                "apto": resultado.apto,
                "apto_para_ativacao": resultado.apto_para_ativacao,
                "comparado_ao_baseline": resultado.comparado_ao_baseline,
                "motivo": resultado.motivo,
                "model_version": resultado.model_version,
                "metricas": resultado.metricas,
                "coeficientes": resultado.coeficientes,
                "dataset": dataset_mod.summarize(amostras),
                "features_sem_variancia": dataset_mod.features_sem_variancia(amostras),
            })

    algum_apto = any(r["apto"] for r in resultados)
    return _saida(
        {
            "ok": True,
            "comando": "train",
            "algum_modelo_apto": algum_apto,
            "algum_apto_para_ativacao": any(r["apto_para_ativacao"] for r in resultados),
            "empresas": resultados,
        },
        0 if algum_apto else 3,
    )


def cmd_insights(args, settings, logger) -> int:
    """Calcula as leituras narrativas que a home exibe."""
    saidas = []
    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            achados = insights_mod.compute(conn, empresa.id)

            if args.dry_run:
                saidas.append({
                    "empresa": empresa.slug,
                    "insights": [{"kind": i.kind, "severity": i.severity,
                                  "title": i.title, "body": i.body} for i in achados],
                    "gravado": False,
                })
                continue

            with track(
                conn, "insights",
                company_id=empresa.id,
                run_mode=settings.run_mode,
                correlation_id=args.correlation_id,
            ) as run:
                gravados = insights_mod.persist(conn, empresa.id, achados, run.id)
                # Expurgo junto da gravação: é o único momento em que já se sabe
                # o que virou histórico. Job separado só para isso seria mais um
                # agendamento para parar calado.
                expurgados = insights_mod.purge_expired(conn, empresa.id)
                run.rows_out = gravados
                run.metrics = {"tipos": [i.kind for i in achados], "expurgados": expurgados}

            saidas.append({
                "empresa": empresa.slug,
                "insights": [{"kind": i.kind, "severity": i.severity,
                              "title": i.title, "body": i.body} for i in achados],
                "gravado": True,
            })

    return _saida({"ok": True, "comando": "insights", "empresas": saidas})


def cmd_forecast(args, settings, logger) -> int:
    """Previsao vs realizado. Saida pronta para o grafico consumir."""
    from dataclasses import asdict
    saidas = []
    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            f = forecast_mod.build(conn, empresa.id, horizonte=args.horizonte)
            saidas.append({
                "empresa": empresa.slug,
                "ok": f.ok,
                "motivo": f.motivo,
                "tendencia_pct_semana": f.tendencia_pct_semana,
                "erro_medio_pct": f.erro_medio_pct,
                "total_previsto_horizonte": f.total_previsto_horizonte,
                "metrics": f.metrics,
                "eventos": f.eventos,
                "serie": [asdict(d) for d in f.serie] if args.completo else len(f.serie),
            })
    return _saida({"ok": True, "comando": "forecast", "empresas": saidas})


def cmd_compare(args, settings, logger) -> int:
    """Compara baseline e shadow lado a lado — a evidência para decidir ativar."""
    with connect(settings) as conn:
        linhas = fetch_all(
            conn,
            """SELECT company_id, scorer, run_mode, model_version,
                      COUNT(*) AS n, AVG(score) AS score_medio,
                      SUM(priority_band = 'alta') AS alta,
                      MAX(scored_at) AS ultimo
                 FROM intelligence_quote_scores
                WHERE is_current = 1
                GROUP BY company_id, scorer, run_mode, model_version
                ORDER BY company_id, scorer""",
        )
    return _saida({"ok": True, "comando": "compare", "series": linhas})


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(prog="biomie_intel", description="Camada de inteligência do BIomie")
    p.add_argument("--verbose", action="store_true")
    sub = p.add_subparsers(dest="comando", required=True)

    sub.add_parser("health", help="checa banco, tabelas e frescor dos jobs")

    s = sub.add_parser("score", help="pontua os orçamentos abertos")
    s.add_argument("--company", default="all")
    s.add_argument("--as-of", dest="as_of", default=None, help="YYYY-MM-DD (padrão: hoje UTC)")
    s.add_argument("--dry-run", action="store_true")

    d = sub.add_parser("dataset", help="monta o dataset histórico sem vazamento")
    d.add_argument("--company", default="all")
    d.add_argument("--horizon", type=int, default=30)
    d.add_argument("--cuts", type=int, default=12)
    d.add_argument("--dry-run", action="store_true")

    t = sub.add_parser("train", help="treina e AVALIA contra o baseline")
    t.add_argument("--company", default="all")
    t.add_argument("--horizon", type=int, default=30)
    t.add_argument("--cuts", type=int, default=12)

    i = sub.add_parser("insights", help="leituras narrativas para a home")
    i.add_argument("--company", default="all")
    i.add_argument("--dry-run", action="store_true")

    fc = sub.add_parser("forecast", help="previsao vs realizado com banda e anomalias")
    fc.add_argument("--company", default="all")
    fc.add_argument("--horizonte", type=int, default=14)
    fc.add_argument("--completo", action="store_true", help="devolve a serie inteira")

    sub.add_parser("compare", help="baseline vs shadow lado a lado")
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    args.correlation_id = uuid.uuid4().hex[:24]

    try:
        settings = load_settings()
    except ConfigError as exc:
        return _saida({"ok": False, "erro": str(exc)}, 2)

    logger = setup_logging(Path(settings.log_dir), args.correlation_id, args.verbose)

    handlers = {
        "health": cmd_health,
        "score": cmd_score,
        "dataset": cmd_dataset,
        "train": cmd_train,
        "insights": cmd_insights,
        "forecast": cmd_forecast,
        "compare": cmd_compare,
    }
    try:
        return handlers[args.comando](args, settings, logger)
    except UnknownCompanyError as exc:
        return _saida({"ok": False, "erro": str(exc)}, 2)
    except Exception as exc:  # noqa: BLE001 — a saída precisa ser JSON para o cron
        logger.exception("falha no comando %s", args.comando)
        return _saida({"ok": False, "comando": args.comando, "erro": f"{type(exc).__name__}: {exc}"}, 1)


if __name__ == "__main__":
    raise SystemExit(main())
