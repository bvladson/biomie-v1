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
from . import forecast_store
from . import health as health_mod
from . import forecast as forecast_mod
from . import insights as insights_mod
from . import geo_backtest as geo_backtest_mod
from . import geo_intelligence as geo_intelligence_mod
from . import fiscal_coherence as fiscal_coherence_mod
from . import model as model_mod
from . import quotes as quotes_mod
from . import daily_baseline as baseline_mod
from . import receivables as receivables_mod
from . import telemetry_anomaly as telemetry_mod
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

                # Expurgo junto da gravação, pela mesma razão do insights: é o
                # único momento em que já se sabe o que virou histórico, e um
                # job separado só para faxina seria mais um agendamento para
                # parar calado.
                #
                # E, como lá, a limpeza NÃO derruba a missão. Os scores já estão
                # gravados e já estão na Agenda; deixar uma tarefa de manutenção
                # marcar a execução como `failed` faria o dead-man switch
                # disparar com a tela perfeitamente correta. O erro fica nas
                # métricas — engolir sem rastro seria o outro extremo.
                try:
                    run.metrics["expurgados"] = quotes_mod.purge_superseded(conn, empresa.id)
                except Exception as exc:  # noqa: BLE001 — faxina não derruba a missão
                    conn.rollback()
                    run.metrics["expurgo_falhou"] = f"{type(exc).__name__}: {exc}"
                    logger.warning("expurgo de scores falhou (empresa %s): %s", empresa.id, exc)

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
                run.rows_out = gravados
                run.metrics = {"tipos": [i.kind for i in achados]}

                # Expurgo junto da gravação: é o único momento em que já se sabe
                # o que virou histórico. Job separado só para isso seria mais um
                # agendamento para parar calado.
                #
                # MAS a limpeza NÃO pode derrubar o job. A missão aqui é produzir
                # as leituras — elas já foram gravadas e já estão na tela. Deixar
                # uma tarefa de manutenção marcar a execução como `failed` faria o
                # dead-man switch disparar com a home perfeitamente correta.
                # Aconteceu de verdade: o DELETE foi ao ar antes do GRANT
                # correspondente e o job inteiro passou a falhar por causa da
                # faxina. O erro fica REGISTRADO nas métricas — engolir sem
                # deixar rastro seria o outro extremo.
                try:
                    run.metrics["expurgados"] = insights_mod.purge_expired(conn, empresa.id)
                except Exception as exc:  # noqa: BLE001 — faxina não derruba a missão
                    conn.rollback()
                    run.metrics["expurgo_falhou"] = f"{type(exc).__name__}: {exc}"
                    logger.warning("expurgo de insights falhou (empresa %s): %s", empresa.id, exc)

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


def cmd_forecast_mensal(args, settings, logger) -> int:
    """Calcula e PERSISTE a previsao mensal por empresa, canal e vendedor.

    Este e o job que o cron agenda. Ele grava em `intelligence_forecasts` para
    que a previsao possa ser confrontada com o realizado depois — sem isso o
    "erro historico" e autodeclarado, porque sai do mesmo calculo que produziu
    o numero.

    Empresa que falha NAO derruba o restante: com cinco empresas no mesmo
    processo, abortar na primeira deixaria as outras quatro sem previsao por um
    problema que talvez seja so daquela. As falhas voltam no JSON e no exit code.
    """
    hoje = date.fromisoformat(args.as_of) if args.as_of else datetime.now(timezone.utc).date()
    saidas: list[dict[str, Any]] = []
    falhas: list[str] = []

    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            try:
                linhas = insights_mod.calcular_forecasts(conn, empresa.id, hoje)
            except Exception as exc:  # noqa: BLE001 — uma empresa nao derruba as outras
                conn.rollback()
                falhas.append(f"{empresa.slug}: {type(exc).__name__}: {exc}")
                logger.error("forecast falhou para %s: %s", empresa.slug, exc)
                saidas.append({"empresa": empresa.slug, "ok": False, "erro": str(exc)[:200]})
                continue

            if args.dry_run:
                saidas.append({
                    "empresa": empresa.slug,
                    "ok": True,
                    "linhas": [linha.to_metrics() for linha in linhas],
                    "gravado": False,
                })
                continue

            competencia = hoje.strftime("%Y-%m")
            with track(
                conn, "forecast",
                company_id=empresa.id,
                run_mode=settings.run_mode,
                correlation_id=args.correlation_id,
            ) as run:
                gravadas = forecast_store.persist(conn, empresa.id, competencia, linhas, run.id)
                run.rows_in = sum(linha.dados_usados for linha in linhas)
                run.rows_out = gravadas
                run.metrics = {
                    "competencia": competencia,
                    "por_dimensao": {
                        d: sum(1 for linha in linhas if linha.dimensao == d)
                        for d in ("empresa", "canal", "vendedor")
                    },
                    # Sem meta cadastrada nao ha risco a calcular — precisa
                    # aparecer, senao "risco nulo" parece "risco baixo".
                    "sem_meta": sum(1 for linha in linhas if linha.meta_valor is None),
                }

            saidas.append({
                "empresa": empresa.slug,
                "ok": True,
                "gravado": True,
                "linhas": gravadas,
                "sem_meta": sum(1 for linha in linhas if linha.meta_valor is None),
            })

    return _saida(
        {"ok": not falhas, "comando": "forecast-mensal", "empresas": saidas, "falhas": falhas},
        0 if not falhas else 1,
    )


def cmd_receivables(args, settings, logger) -> int:
    """Risco de atraso dos titulos em aberto.

    O resumo devolve `por_universo` de proposito: sem ele, "12 titulos de alto
    risco" parece cobertura completa quando pode ser 12 de 40 pontuaveis num
    universo de 554 — o resto sendo cartao, onde o vencimento nem e obrigacao do
    cliente.
    """
    hoje = datetime.now(timezone.utc).date()
    as_of = date.fromisoformat(args.as_of) if args.as_of else hoje

    # Recusa cedo, antes de tocar o banco: replay em data passada mistura
    # historico de uma data com a foto de hoje e produz uma fila que nunca
    # existiu. Ver `receivables.exigir_as_of_atual`.
    try:
        receivables_mod.exigir_as_of_atual(as_of, hoje)
    except receivables_mod.ReplayNaoSuportado as exc:
        return _saida({"ok": False, "comando": "receivables", "erro": str(exc)}, 2)

    saidas: list[dict[str, Any]] = []

    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            scores = receivables_mod.avaliar(conn, empresa.id, as_of)
            info = receivables_mod.resumo(scores)

            if args.dry_run:
                saidas.append({"empresa": empresa.slug, **info, "gravado": False})
                continue

            with track(conn, "receivable_risk", company_id=empresa.id,
                       run_mode=settings.run_mode, correlation_id=args.correlation_id,
                       model_version=receivables_mod.MODEL_VERSION) as run:
                gravados = receivables_mod.persist(conn, empresa.id, scores, run.id)
                run.rows_in = len(scores)
                run.rows_out = gravados
                run.metrics = info

            saidas.append({"empresa": empresa.slug, **info, "gravado": True})

    return _saida({"ok": True, "comando": "receivables",
                   "as_of": as_of.isoformat(), "empresas": saidas})


def cmd_ops_anomaly(args, settings, logger) -> int:
    """Anomalias operacionais na telemetria.

    Sem `--company`: span de sync e de HTTP e infraestrutura, nao dado de
    tenant. Forcar um recorte por empresa inventaria uma dimensao que o dado nao
    tem — e faria o mesmo achado aparecer cinco vezes.
    """
    as_of = date.fromisoformat(args.as_of) if args.as_of else datetime.now(timezone.utc).date()

    with connect(settings) as conn:
        achados = telemetry_mod.detectar(conn, as_of)
        por_tipo: dict[str, int] = {}
        por_severidade: dict[str, int] = {}
        for a in achados:
            por_tipo[a.anomaly_type] = por_tipo.get(a.anomaly_type, 0) + 1
            por_severidade[a.severity] = por_severidade.get(a.severity, 0) + 1

        if args.dry_run:
            return _saida({
                "ok": True, "comando": "ops-anomaly", "as_of": as_of.isoformat(),
                "achados": [{"tipo": a.anomaly_type, "span": a.span_name,
                             "severidade": a.severity, "titulo": a.title,
                             "corpo": a.body} for a in achados],
                "gravado": False,
            })

        with track(conn, "ops_anomaly", company_id=None,
                   run_mode=settings.run_mode, correlation_id=args.correlation_id,
                   model_version=telemetry_mod.MODEL_VERSION) as run:
            gravados = telemetry_mod.persist(conn, achados, run.id)
            run.rows_in = sum(a.amostra_recente for a in achados)
            run.rows_out = gravados
            run.metrics = {"por_tipo": por_tipo, "por_severidade": por_severidade}

    return _saida({
        "ok": True, "comando": "ops-anomaly", "as_of": as_of.isoformat(),
        "achados": len(achados), "por_tipo": por_tipo, "por_severidade": por_severidade,
        "detalhe": [{"tipo": a.anomaly_type, "span": a.span_name,
                     "severidade": a.severity, "titulo": a.title} for a in achados[:10]],
    })


def cmd_daily_baseline(args, settings, logger) -> int:
    """Faixa normal de faturamento por empresa e dia da semana."""
    as_of = date.fromisoformat(args.as_of) if args.as_of else datetime.now(timezone.utc).date()
    saidas: list[dict[str, Any]] = []

    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            faixas = baseline_mod.calcular(conn, empresa.id, as_of)
            info = baseline_mod.resumo(faixas)

            if args.dry_run:
                saidas.append({"empresa": empresa.slug, **info, "gravado": False})
                continue

            with track(conn, "daily_baseline", company_id=empresa.id,
                       run_mode=settings.run_mode, correlation_id=args.correlation_id,
                       model_version=baseline_mod.MODEL_VERSION) as run:
                gravadas = baseline_mod.persist(conn, faixas, run.id)
                run.rows_in = sum(b.amostra for b in faixas)
                run.rows_out = gravadas
                run.metrics = info

            saidas.append({"empresa": empresa.slug, **info, "gravado": True})

    return _saida({"ok": True, "comando": "daily-baseline",
                   "as_of": as_of.isoformat(), "empresas": saidas})


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


def cmd_geo_intelligence(args, settings, logger) -> int:
    """Calcula, explica e persiste oportunidade/forecast por territorio."""
    as_of = date.fromisoformat(args.as_of) if args.as_of else datetime.now(timezone.utc).date()
    saidas = []
    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            scores = geo_intelligence_mod.compute(conn, empresa.id, as_of)
            if args.dry_run:
                saidas.append({"empresa": empresa.slug, "territorios": len(scores), "gravado": False})
                continue
            with track(conn, "geo_intelligence", company_id=empresa.id,
                       run_mode=settings.run_mode, correlation_id=args.correlation_id,
                       model_version=geo_intelligence_mod.MODEL_VERSION) as run:
                gravados = geo_intelligence_mod.persist(conn, empresa.id, scores)
                run.rows_in = sum(s.months_used for s in scores)
                run.rows_out = gravados
                run.metrics = {
                    "territorios": len(scores),
                    "confianca": {level: sum(1 for s in scores if s.confidence == level)
                                   for level in ("alta", "media", "baixa")},
                    "competencia": as_of.strftime("%Y-%m"),
                }
            saidas.append({"empresa": empresa.slug, "territorios": len(scores), "gravado": True})
    return _saida({"ok": True, "comando": "geo-intelligence", "as_of": as_of.isoformat(), "empresas": saidas})


def cmd_geo_backtest(args, settings, logger) -> int:
    """Mede se o score territorial ORDENA — nao se ele existe.

    A saida traz o lift ao lado da taxa base de proposito: "acertei 70% do topo"
    nao vale nada se 70% de todos os territorios cresceram no mes.
    """
    as_of = date.fromisoformat(args.as_of) if args.as_of else datetime.now(timezone.utc).date()
    saidas: list[dict[str, Any]] = []
    algum_nao_ordena = False

    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            resultados = geo_backtest_mod.run(
                conn, empresa.id, as_of,
                horizonte=args.horizonte, janelas=args.janelas, top_n=args.top,
            )
            veredito_por_camada = {r.layer_id: r.veredito for r in resultados}
            if "nao_ordena" in veredito_por_camada.values():
                algum_nao_ordena = True

            resumo = {
                "empresa": empresa.slug,
                "janelas_avaliadas": len(resultados),
                "veredito": veredito_por_camada,
                "lift_medio": {
                    layer: round(
                        sum(r.lift_top_n for r in resultados
                            if r.layer_id == layer and r.lift_top_n is not None)
                        / max(1, sum(1 for r in resultados
                                     if r.layer_id == layer and r.lift_top_n is not None)), 3)
                    for layer in veredito_por_camada
                },
            }

            if args.dry_run:
                resumo["gravado"] = False
                resumo["detalhe"] = [
                    {"camada": r.layer_id, "referencia": r.competencia_referencia,
                     "territorios": r.territorios_avaliados, "lift": r.lift_top_n,
                     "precisao_topo": r.precisao_top_n, "precisao_base": r.precisao_base,
                     "spearman": r.spearman, "mape": r.mape,
                     "cobertura_faixa": r.cobertura_faixa_pct}
                    for r in resultados
                ]
                saidas.append(resumo)
                continue

            with track(conn, "geo_backtest", company_id=empresa.id,
                       run_mode=settings.run_mode, correlation_id=args.correlation_id,
                       model_version=geo_backtest_mod.MODEL_VERSION) as run:
                gravados = geo_backtest_mod.persist(conn, empresa.id, resultados, run.id)
                run.rows_in = sum(r.territorios_avaliados for r in resultados)
                run.rows_out = gravados
                run.metrics = {"veredito": veredito_por_camada,
                               "janelas": len(resultados),
                               "horizonte_meses": args.horizonte}
            resumo["gravado"] = True
            saidas.append(resumo)

    # Exit 0 mesmo com "nao_ordena": o score NAO ordenar e um resultado valido do
    # experimento, nao uma falha do job. Quem precisa reagir e a tela, que deixa
    # de apresentar o score como recomendacao — nao o dead-man switch.
    return _saida({
        "ok": True, "comando": "geo-backtest", "as_of": as_of.isoformat(),
        "algum_layer_nao_ordena": algum_nao_ordena, "empresas": saidas,
    })


def cmd_fiscal_coherence(args, settings, logger) -> int:
    saidas = []
    with connect(settings) as conn:
        for empresa in resolve(conn, args.company):
            with track(conn, "fiscal_mart", company_id=empresa.id,
                       run_mode=settings.run_mode, correlation_id=args.correlation_id,
                       model_version=fiscal_coherence_mod.MODEL_VERSION) as run:
                rows, anomalies = fiscal_coherence_mod.compute(conn, empresa.id)
                if rows and not any(r["freshness_hours"] is not None and r["freshness_hours"] <= 30 for r in rows):
                    raise RuntimeError("mart fiscal sem build recente; a trilha nao pode registrar falso sucesso")
                if not args.dry_run:
                    gravados = fiscal_coherence_mod.persist(conn, empresa.id, rows, anomalies)
                else:
                    gravados = 0
                run.rows_in = sum(r["canonical_invoices"] for r in rows)
                run.rows_out = gravados
                run.metrics = {"competencias": len(rows), "anomalias": len(anomalies),
                    "criticas": sum(1 for r in rows if r["status"] == "critical"),
                    "dry_run": bool(args.dry_run)}
            saidas.append({"empresa": empresa.slug, "competencias": len(rows),
                           "anomalias": len(anomalies), "gravado": not args.dry_run})
    return _saida({"ok": True, "comando": "fiscal-coherence", "empresas": saidas})


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

    fm = sub.add_parser("forecast-mensal", help="previsao do mes por empresa/canal/vendedor — PERSISTE")
    fm.add_argument("--company", default="all")
    fm.add_argument("--as-of", dest="as_of", default=None, help="YYYY-MM-DD (padrão: hoje UTC)")
    fm.add_argument("--dry-run", action="store_true")

    geo = sub.add_parser("geo-intelligence", help="score, forecast e recomendacao por territorio")
    geo.add_argument("--company", default="all")
    geo.add_argument("--as-of", dest="as_of", default=None, help="YYYY-MM-DD (padrao: hoje UTC)")
    geo.add_argument("--dry-run", action="store_true")

    gb = sub.add_parser("geo-backtest", help="mede se o score territorial ORDENA (lift x taxa base)")
    gb.add_argument("--company", default="all")
    gb.add_argument("--as-of", dest="as_of", default=None, help="YYYY-MM-DD (padrao: hoje UTC)")
    gb.add_argument("--horizonte", type=int, default=1, help="meses observados apos a referencia")
    gb.add_argument("--janelas", type=int, default=6, help="quantas competencias de referencia avaliar")
    gb.add_argument("--top", type=int, default=10, help="tamanho do grupo priorizado")
    gb.add_argument("--dry-run", action="store_true")

    fiscal = sub.add_parser("fiscal-coherence", help="prova cobertura, frescor e anomalias do mart fiscal")
    fiscal.add_argument("--company", default="all")
    fiscal.add_argument("--dry-run", action="store_true")

    rec = sub.add_parser("receivables", help="risco de atraso dos titulos em aberto")
    rec.add_argument("--company", default="all")
    rec.add_argument("--as-of", dest="as_of", default=None, help="YYYY-MM-DD (padrao: hoje UTC)")
    rec.add_argument("--dry-run", action="store_true")

    bl = sub.add_parser("daily-baseline", help="faixa normal de faturamento por dia da semana")
    bl.add_argument("--company", default="all")
    bl.add_argument("--as-of", dest="as_of", default=None, help="YYYY-MM-DD (padrao: hoje UTC)")
    bl.add_argument("--dry-run", action="store_true")

    ops = sub.add_parser("ops-anomaly", help="anomalias operacionais na telemetria (sem recorte por empresa)")
    ops.add_argument("--as-of", dest="as_of", default=None, help="YYYY-MM-DD (padrao: hoje UTC)")
    ops.add_argument("--dry-run", action="store_true")

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
        "forecast-mensal": cmd_forecast_mensal,
        "geo-intelligence": cmd_geo_intelligence,
        "geo-backtest": cmd_geo_backtest,
        "fiscal-coherence": cmd_fiscal_coherence,
        "receivables": cmd_receivables,
        "ops-anomaly": cmd_ops_anomaly,
        "daily-baseline": cmd_daily_baseline,
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
