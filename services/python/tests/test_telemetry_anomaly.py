"""Anomalias operacionais: o detector precisa DISPARAR, não só não explodir.

Mesma lição de `_vendedor_destaque`, que ficou sem corpo e devolveu `None` por
dias sem nenhum teste quebrar — porque `None` é resposta legítima ("não há
anomalia") e detector morto é indistinguível de dia tranquilo.

Aqui cada detector tem um teste que EXIGE o achado, e um que exige o silêncio.
"""

from __future__ import annotations

import unittest
from datetime import date

from biomie_intel import telemetry_anomaly as t

from fakes import FakeConnection

HOJE = date(2026, 8, 8)
SQL = "GROUP BY domain_name, span_name, janela"


def _janela(dom, span, janela, execucoes, falhas=0, ms_medio=100.0, ms_max=200.0, ms_desvio=10.0):
    return {"domain_name": dom, "span_name": span, "janela": janela,
            "execucoes": execucoes, "falhas": falhas, "ms_medio": ms_medio,
            "ms_max": ms_max, "ms_desvio": ms_desvio}


def _detectar(linhas):
    return t.detectar(FakeConnection({SQL: linhas}), HOJE)


class TestJanelas(unittest.TestCase):
    def test_compara_7_dias_contra_as_4_semanas_ANTERIORES(self) -> None:
        """"Hoje contra a média de sempre" inclui o hoje e dilui o que se busca."""
        conn = FakeConnection({SQL: []})
        t.carregar_janelas(conn, HOJE)
        corte_recente, corte_baseline, fim = conn.params_de(SQL)
        self.assertEqual((HOJE - corte_recente).days, t.JANELA_RECENTE_DIAS)
        self.assertEqual((HOJE - corte_baseline).days,
                         t.JANELA_RECENTE_DIAS + t.JANELA_BASELINE_DIAS)
        self.assertEqual(fim, HOJE, "a janela recente inclui o dia parcial de hoje")

    def test_completed_conta_como_sucesso(self) -> None:
        """O sync fecha span com `completed`; tratar como falha inverteria tudo."""
        self.assertIn("completed", t.STATUS_SUCESSO)
        self.assertIn("ok", t.STATUS_SUCESSO)
        conn = FakeConnection({SQL: []})
        t.carregar_janelas(conn, HOJE)
        self.assertIn("'ok','completed'", conn.sql_executado()[0])


class TestTaxaDeErro(unittest.TestCase):
    def test_salto_de_falha_VIRA_achado(self) -> None:
        achados = _detectar([
            _janela("sync", "sync.backfill", "baseline", 400, falhas=20),   # 5%
            _janela("sync", "sync.backfill", "recente", 100, falhas=40),    # 40%
        ])
        erro = [a for a in achados if a.anomaly_type == "taxa_erro"]
        self.assertTrue(erro, "detector de taxa de erro não disparou com 5% -> 40%")
        self.assertEqual(erro[0].severity, "critico")
        self.assertIn("sync.backfill", erro[0].title)

    def test_falha_ALTA_mas_ESTAVEL_nao_alerta(self) -> None:
        """26% de falha é problema — mas problema conhecido. Alertar todo dia
        sobre o que não mudou transforma o alerta em ruído de fundo."""
        achados = _detectar([
            _janela("sync", "sync.backfill", "baseline", 400, falhas=106),
            _janela("sync", "sync.backfill", "recente", 100, falhas=27),
        ])
        self.assertEqual([a for a in achados if a.anomaly_type == "taxa_erro"], [])

    def test_variacao_pequena_nao_alerta(self) -> None:
        achados = _detectar([
            _janela("sync", "x", "baseline", 400, falhas=40),   # 10%
            _janela("sync", "x", "recente", 100, falhas=15),    # 15%
        ])
        self.assertEqual([a for a in achados if a.anomaly_type == "taxa_erro"], [])


class TestLatencia(unittest.TestCase):
    def test_regressao_real_VIRA_achado(self) -> None:
        achados = _detectar([
            _janela("omie", "omie.post", "baseline", 400, ms_medio=5000, ms_desvio=1000),
            _janela("omie", "omie.post", "recente", 100, ms_medio=15000, ms_desvio=3000),
        ])
        lat = [a for a in achados if a.anomaly_type == "latencia"]
        self.assertTrue(lat, "detector de latência não disparou com 5s -> 15s")
        self.assertEqual(lat[0].severity, "critico")

    def test_cauda_pesada_NAO_vira_achado(self) -> None:
        """`job.sync_realtime_pipeline`: média de 30s, máximo de 26min. Com desvio
        maior que o dobro da média, a média descreve outlier, não o caso típico —
        apontar regressão ali seria apontar variação de outlier."""
        achados = _detectar([
            _janela("sync", "job.x", "baseline", 400, ms_medio=30000, ms_desvio=90000),
            _janela("sync", "job.x", "recente", 100, ms_medio=90000, ms_desvio=200000),
        ])
        self.assertEqual([a for a in achados if a.anomaly_type == "latencia"], [])

    def test_dobrar_de_8ms_para_16ms_e_ruido(self) -> None:
        """Fator 2x, mas 8 ms de diferença absoluta é ruído de medição."""
        achados = _detectar([
            _janela("http", "http.notifications", "baseline", 400, ms_medio=8, ms_desvio=2),
            _janela("http", "http.notifications", "recente", 100, ms_medio=16, ms_desvio=3),
        ])
        self.assertEqual([a for a in achados if a.anomaly_type == "latencia"], [])


class TestVolume(unittest.TestCase):
    def test_span_que_PAROU_completamente_e_critico(self) -> None:
        """O modo de falha mais silencioso: nada dá erro, nada fica lento, o
        trabalho só deixa de ser feito. Foi assim que o mart fiscal ficou 43
        dias parado."""
        achados = _detectar([_janela("sync", "sync.delta", "baseline", 2800)])
        self.assertEqual(len(achados), 1)
        self.assertEqual(achados[0].anomaly_type, "volume_sumiu")
        self.assertEqual(achados[0].severity, "critico")
        self.assertIn("parou completamente", achados[0].title)

    def test_span_parado_NAO_e_barrado_pelo_minimo_de_amostra(self) -> None:
        """A armadilha: zero execuções é justamente o caso que o gate de amostra
        recente descartaria — e é o mais grave de todos."""
        achados = _detectar([_janela("sync", "sync.delta", "baseline", 2800),
                             _janela("sync", "sync.delta", "recente", 0)])
        self.assertTrue(achados, "o span que zerou escapou pelo gate de amostra")

    def test_queda_forte_de_volume_vira_achado(self) -> None:
        # baseline 2800/28d = 100/dia · recente 70/7d = 10/dia => 90% a menos
        achados = _detectar([_janela("sync", "s", "baseline", 2800),
                             _janela("sync", "s", "recente", 70)])
        vol = [a for a in achados if a.anomaly_type == "volume_sumiu"]
        self.assertTrue(vol)

    def test_volume_estavel_nao_alerta(self) -> None:
        achados = _detectar([_janela("sync", "s", "baseline", 2800),
                             _janela("sync", "s", "recente", 700)])
        self.assertEqual([a for a in achados if a.anomaly_type == "volume_sumiu"], [])

    def test_endpoint_HTTP_sem_trafego_NAO_e_incidente(self) -> None:
        """Ninguém abriu o painel ≠ o painel quebrou.

        A primeira versão apontou `http.commercial_dashboard`,
        `http.global_search` e `http.set_context` como CRÍTICOS por zero
        execuções. Nenhum estava quebrado. Três falsos críticos ao lado de um
        `sync.backfill` real ensinam a ignorar a lista.
        """
        self.assertEqual(_detectar([_janela("http", "http.commercial_dashboard", "baseline", 55)]), [])
        self.assertEqual(
            [a for a in _detectar([_janela("http", "http.x", "baseline", 2800),
                                   _janela("http", "http.x", "recente", 70)])
             if a.anomaly_type == "volume_sumiu"],
            [],
        )

    def test_webhook_que_parou_CONTINUA_sendo_incidente(self) -> None:
        """Ele não depende do nosso agendador, mas depende de a Omie chamar —
        e parar de receber webhook é o silêncio que já custou caro aqui."""
        achados = _detectar([_janela("webhook", "http.omie_webhook", "baseline", 2800)])
        self.assertTrue(achados, "webhook parado deixou de ser reportado")
        self.assertEqual(achados[0].severity, "critico")

    def test_o_erro_e_a_latencia_CONTINUAM_valendo_para_http(self) -> None:
        """A exceção é só de VOLUME. Endpoint HTTP que passa a falhar ou a
        ficar lento continua sendo problema nosso."""
        achados = _detectar([
            _janela("http", "http.overview", "baseline", 400, falhas=0, ms_medio=100, ms_desvio=20),
            _janela("http", "http.overview", "recente", 100, falhas=40, ms_medio=5000, ms_desvio=800),
        ])
        tipos = {a.anomaly_type for a in achados}
        self.assertIn("taxa_erro", tipos)
        self.assertIn("latencia", tipos)


class TestAmostraPequena(unittest.TestCase):
    def test_baseline_curto_nao_produz_veredito(self) -> None:
        """3 execuções com 1 falha não é '33% de erro', é ruído."""
        self.assertEqual(_detectar([
            _janela("x", "y", "baseline", 3, falhas=0),
            _janela("x", "y", "recente", 3, falhas=1),
        ]), [])

    def test_recente_curto_nao_produz_veredito(self) -> None:
        self.assertEqual(_detectar([
            _janela("x", "y", "baseline", 400, falhas=0),
            _janela("x", "y", "recente", 5, falhas=3),
        ]), [])


class TestOrdenacaoEGravacao(unittest.TestCase):
    def test_critico_vem_antes_de_atencao(self) -> None:
        achados = _detectar([
            _janela("a", "leve", "baseline", 400, falhas=0),
            _janela("a", "leve", "recente", 100, falhas=12),      # 12% => atencao
            _janela("b", "grave", "baseline", 400, falhas=0),
            _janela("b", "grave", "recente", 100, falhas=40),     # 40% => critico
        ])
        self.assertEqual(achados[0].severity, "critico")

    def test_lista_vazia_AINDA_rebaixa_o_vigente(self) -> None:
        """Problema resolvido tem que SAIR da tela."""
        conn = FakeConnection()
        self.assertEqual(t.persist(conn, []), 0)
        self.assertTrue(any("SET is_current = 0" in q for q in conn.sql_executado()))
        self.assertEqual(conn.commits, 1)

    def test_grava_rebaixando_antes(self) -> None:
        conn = FakeConnection()
        t.persist(conn, [t.OpsAnomalia("taxa_erro", "sync", "s", "critico", "t", "b")])
        sqls = conn.sql_executado()
        self.assertLess(
            next(i for i, q in enumerate(sqls) if "SET is_current = 0" in q),
            next(i for i, q in enumerate(sqls) if "INSERT INTO intelligence_ops_anomalies" in q),
        )


class TestNenhumDetectorMorto(unittest.TestCase):
    """Guard estrutural: pega a classe do defeito, não a instância."""

    def test_todo_detector_e_chamado_por_detectar(self) -> None:
        import ast
        import inspect
        from pathlib import Path

        arvore = ast.parse(Path(inspect.getfile(t)).read_text(encoding="utf-8"))
        funcoes = [n for n in arvore.body if isinstance(n, ast.FunctionDef)]
        corpo_detectar = next(ast.unparse(f) for f in funcoes if f.name == "detectar")
        usados = {n.id for n in ast.walk(ast.parse(corpo_detectar)) if isinstance(n, ast.Name)}
        orfaos = [f.name for f in funcoes
                  if f.name.startswith(("_regressao", "_volume")) and f.name not in usados]
        self.assertEqual(orfaos, [], f"detectores nunca chamados: {orfaos}")

    def test_nenhum_detector_ficou_sem_return_de_valor(self) -> None:
        import ast
        import inspect
        from pathlib import Path

        arvore = ast.parse(Path(inspect.getfile(t)).read_text(encoding="utf-8"))
        mudos = [
            f.name for f in arvore.body
            if isinstance(f, ast.FunctionDef) and f.returns is not None
            and "OpsAnomalia" in ast.unparse(f.returns)
            and not any(isinstance(n, ast.Return) and n.value is not None for n in ast.walk(f))
        ]
        self.assertEqual(mudos, [], f"detectores que nunca devolvem achado: {mudos}")


if __name__ == "__main__":
    unittest.main()
