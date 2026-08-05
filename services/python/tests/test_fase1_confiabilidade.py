"""Fase 1 — Confiabilidade: o serviço precisa saber dizer que está quebrado.

O modo de falha deste projeto não é o job que estoura; é o job que devolve
"nenhum resultado" quando na verdade perdeu o acesso, e continua verde. Já
aconteceu três vezes por caminhos diferentes:

  · o cron da VPS ficou 14h fora do ar com `systemctl is-active cron` = active;
  · `_vendedor_destaque` ficou sem corpo e devolveu `None` em todas as execuções,
    com os 90 testes passando, porque `None` é resposta legítima;
  · `_metas` capturava `Exception` genérica: falta de GRANT virava lista vazia,
    o forecast projetava sem meta e reportava "sem risco" — idêntico, na tela,
    a uma empresa que nunca cadastrou meta.

Os testes abaixo cobrem a terceira e o que veio junto dela.
"""

from __future__ import annotations

import re
import unittest
from datetime import date, timedelta
from pathlib import Path

import pymysql

from biomie_intel import forecast_store, health, insights
from biomie_intel.config import READABLE_TABLES, WRITABLE_TABLES
from biomie_intel.forecast_store import ForecastLinha

from fakes import FakeConnection

SCRIPT_GRANTS = (
    Path(__file__).resolve().parents[3]
    / "tools" / "deploy" / "vps" / "provision_intelligence_db_user.sh"
)


class TestPermissaoNaoViraAusenciaDeDado(unittest.TestCase):
    """Erro de permissão precisa SUBIR. É configuração quebrada, não vazio."""

    @staticmethod
    def _conn_falhando(trecho: str, codigo: int) -> FakeConnection:
        conn = FakeConnection()
        conn.fail_on = trecho
        conn.fail_code = codigo
        return conn

    def test_falta_de_grant_nas_metas_sobe_como_erro(self) -> None:
        conn = self._conn_falhando("FROM biomie_company_targets", 1142)

        with self.assertRaises(pymysql.err.MySQLError) as ctx:
            insights._metas(conn, 1, "2026-08")

        self.assertEqual(ctx.exception.args[0], 1142)

    def test_tabela_ausente_continua_tolerada(self) -> None:
        """Schema antigo é caso legítimo — só ele pode virar lista vazia."""
        conn = self._conn_falhando("FROM biomie_company_targets", 1146)

        total, por_canal, por_vendedor = insights._metas(conn, 1, "2026-08")

        self.assertEqual((total, por_canal, por_vendedor), (0.0, {}, {}))

    def test_erro_desconhecido_nao_e_engolido(self) -> None:
        conn = self._conn_falhando("FROM biomie_company_targets", 1213)  # deadlock
        with self.assertRaises(pymysql.err.MySQLError):
            insights._metas(conn, 1, "2026-08")


class TestProvaDeAcessoDoHealth(unittest.TestCase):
    """`SHOW GRANTS` diz o que foi concedido; só o SELECT diz o que funciona."""

    def test_todas_as_tabelas_legiveis_sao_testadas(self) -> None:
        conn = FakeConnection()
        acesso, problemas = health._prova_de_acesso(conn)

        self.assertEqual(set(acesso), set(READABLE_TABLES))
        self.assertEqual(problemas, [])
        for tabela in READABLE_TABLES:
            self.assertIn(tabela, " ".join(conn.sql_executado()))

    def test_sem_permissao_e_nomeado_como_permissao(self) -> None:
        conn = FakeConnection()
        conn.fail_on = "FROM `biomie_company_targets`"
        conn.fail_code = 1142

        acesso, problemas = health._prova_de_acesso(conn)

        self.assertEqual(acesso["biomie_company_targets"], "sem_permissao")
        self.assertTrue(any("SELECT negado" in p for p in problemas))

    def test_tabela_ausente_nao_e_confundida_com_permissao(self) -> None:
        conn = FakeConnection()
        conn.fail_on = "FROM `intelligence_quote_feedback`"
        conn.fail_code = 1146

        acesso, problemas = health._prova_de_acesso(conn)

        self.assertEqual(acesso["intelligence_quote_feedback"], "tabela_ausente")
        self.assertTrue(any("migration" in p for p in problemas))


class TestDeclaracoesQueNaoPodemDivergir(unittest.TestCase):
    """As listas do código e os GRANTs do script são a mesma verdade."""

    @classmethod
    def setUpClass(cls) -> None:
        if not SCRIPT_GRANTS.is_file():
            raise unittest.SkipTest(f"script não encontrado: {SCRIPT_GRANTS}")
        cls.texto = SCRIPT_GRANTS.read_text(encoding="utf-8")

    def test_toda_tabela_legivel_tem_grant_de_select(self) -> None:
        for tabela in READABLE_TABLES:
            if tabela == "biomie_companies":
                # Concedida POR COLUNA de propósito: a tabela guarda
                # omie_app_key/omie_app_secret e um job de análise não pode vê-los.
                self.assertRegex(self.texto, r"GRANT SELECT \([^)]*\)\s*ON \$\{DB\}\.biomie_companies")
                continue
            self.assertRegex(
                self.texto,
                rf"GRANT SELECT ON \$\{{DB\}}\.{re.escape(tabela)}\b",
                f"{tabela} está em READABLE_TABLES mas não tem GRANT SELECT no script — "
                f"a próxima execução do provisionamento (que começa com REVOKE ALL) "
                f"tiraria o acesso sem ninguém tocar em código",
            )

    def test_forecast_persistido_tem_grant_de_escrita(self) -> None:
        self.assertIn("intelligence_forecasts", WRITABLE_TABLES)
        self.assertRegex(
            self.texto,
            r"GRANT SELECT, INSERT, UPDATE ON \$\{DB\}\.intelligence_forecasts\b",
        )

    def test_forecast_nao_recebe_delete(self) -> None:
        """A previsão anterior é rebaixada, nunca apagada — é ela que audita o erro."""
        for linha in self.texto.splitlines():
            if "intelligence_forecasts" in linha and "GRANT" in linha:
                self.assertNotIn("DELETE", linha)


class TestPersistenciaDoForecast(unittest.TestCase):
    @staticmethod
    def _linha(**kw) -> ForecastLinha:
        base = dict(
            dimensao="canal", entidade_codigo="licitacao", entidade_nome="Licitação",
            competencia="2026-08", realizado=100.0, projecao=200.0,
            valor_min=150.0, valor_max=260.0, meta_valor=300.0,
            risco_nao_bater=0.8, erro_historico=0.2, meses_historicos=4,
            dados_usados=42, fatores=["ritmo atual"],
        )
        base.update(kw)
        return ForecastLinha(**base)

    def test_rebaixamento_e_escopado_por_empresa_e_competencia(self) -> None:
        conn = FakeConnection()
        forecast_store.persist(conn, 3, "2026-08", [self._linha()])

        update = next(s for s in conn.sql_executado() if s.startswith("UPDATE intelligence_forecasts"))
        self.assertIn("company_id = %s", update)
        self.assertIn("competencia = %s", update)
        self.assertEqual(conn.params_de("UPDATE intelligence_forecasts"), (3, "2026-08"))

    def test_nada_a_gravar_nao_rebaixa_o_que_existe(self) -> None:
        """Lista vazia com rebaixamento cego apagaria a previsão vigente."""
        conn = FakeConnection()
        self.assertEqual(forecast_store.persist(conn, 3, "2026-08", []), 0)
        self.assertEqual(conn.sql_executado(), [])

    def test_meta_ausente_grava_NULL_e_nao_zero(self) -> None:
        conn = FakeConnection()
        forecast_store.persist(conn, 1, "2026-08", [self._linha(meta_valor=None, risco_nao_bater=None)])

        params = conn.params_de("INSERT INTO intelligence_forecasts")
        self.assertIn(None, params, "meta ausente precisa ir como NULL")
        self.assertNotIn(0.0, params[10:12], "meta zero afirmaria risco 0%, que é conclusão, não ausência")

    def test_grava_uma_linha_por_entidade(self) -> None:
        conn = FakeConnection()
        n = forecast_store.persist(conn, 1, "2026-08", [
            self._linha(entidade_codigo="a"), self._linha(entidade_codigo="b"),
        ])
        self.assertEqual(n, 2)
        self.assertEqual(sum(1 for s in conn.sql_executado() if s.startswith("INSERT")), 2)
        self.assertEqual(conn.commits, 1)


class TestCalculoDoForecastMensal(unittest.TestCase):
    """Contrato dos números antes de virarem linha no banco."""

    @staticmethod
    def _conn_com_serie(meta_rows: list[dict] | None = None) -> FakeConnection:
        hoje = date.today()
        linhas = []
        for mes_atras in range(0, 4):
            ref = (hoje.replace(day=1) - timedelta(days=31 * mes_atras)).replace(day=1)
            for dia in range(1, 6):
                linhas.append({
                    "codigo": "empresa", "nome": "Empresa",
                    "dia": ref + timedelta(days=dia), "receita": 1000.0, "notas": 2,
                })
        return FakeConnection({
            "GROUP BY codigo, nome, data_emissao": linhas,
            "FROM biomie_company_targets": meta_rows or [],
            "FROM biomie_sales_targets_monthly": [],
        })

    def test_sem_meta_cadastrada_a_meta_e_None_e_o_risco_tambem(self) -> None:
        hoje = date.today().replace(day=15)
        linhas = insights.calcular_forecasts(self._conn_com_serie(), 1, hoje)

        self.assertTrue(linhas, "esperava ao menos a linha de empresa")
        for linha in linhas:
            self.assertIsNone(linha.meta_valor)
            self.assertIsNone(
                linha.risco_nao_bater,
                "sem meta não existe risco de não bater — 0% seria uma afirmação",
            )

    def test_a_faixa_contem_a_projecao(self) -> None:
        hoje = date.today().replace(day=15)
        for linha in insights.calcular_forecasts(self._conn_com_serie(), 1, hoje):
            self.assertLessEqual(linha.valor_min, linha.valor_max)
            self.assertLessEqual(linha.valor_min, max(linha.projecao, linha.realizado))
            self.assertGreaterEqual(linha.valor_max, linha.projecao)

    def test_inicio_do_mes_nao_projeta(self) -> None:
        """Três dias de mês não sustentam projeção mensal."""
        cedo = date.today().replace(day=2)
        self.assertEqual(insights.calcular_forecasts(self._conn_com_serie(), 1, cedo), [])

    def test_a_narrativa_deriva_do_mesmo_calculo(self) -> None:
        """Insight e linha persistida não podem sair de contas diferentes."""
        hoje = date.today().replace(day=15)
        conn = self._conn_com_serie()
        linhas = insights.calcular_forecasts(conn, 1, hoje)
        narrativas = insights._forecasts_segmentados(self._conn_com_serie(), 1, hoje)

        self.assertTrue(narrativas)
        por_metrica = {n.metrics["projecao"] for n in narrativas}
        self.assertTrue(
            por_metrica <= {round(linha.projecao, 2) for linha in linhas},
            "a frase da home mostra um número que a tabela não tem",
        )


class TestJanelaDeInatividade(unittest.TestCase):
    def test_todo_job_agendado_tem_limite_declarado(self) -> None:
        """Job sem limite não tem dead-man switch — foi o caso do insights."""
        self.assertEqual(
            set(health.MAX_IDLE_HORAS), {"quote_score", "insights", "forecast"},
        )

    def test_limite_cobre_ao_menos_uma_execucao_perdida(self) -> None:
        cadencias_horas = {"quote_score": 2, "insights": 6, "forecast": 24}
        for job, cadencia in cadencias_horas.items():
            with self.subTest(job=job):
                self.assertGreater(
                    health.MAX_IDLE_HORAS[job], cadencia,
                    "limite menor que a cadência dispara alarme falso a cada execução",
                )


if __name__ == "__main__":
    unittest.main()
