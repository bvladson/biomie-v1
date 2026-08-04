"""Integridade do pipeline: vazamento, split, promoção, tenancy e ciclo de vida.

Cada classe aqui trava um modo de falha que JÁ aconteceu neste projeto ou que
produziria um número bonito e mentiroso:

  · vazamento temporal      — AUC de 0,94 que não sobrevive à produção
  · split temporal          — o mesmo pedido no treino e no teste infla a métrica
  · promoção shadow→active  — "não consegui comparar" não pode virar "ganhou"
  · isolamento por empresa   — já houve leitura de mart_ sem company_id
  · expiração dos insights   — conclusão velha na tela é pior que tela vazia
  · idempotência / rollback  — job que morre no meio não pode deixar meia verdade

Rodar:  python -m unittest discover -s tests -v
"""

from __future__ import annotations

import unittest
from datetime import date, timedelta

from biomie_intel import insights as insights_mod
from biomie_intel.dataset import (
    FEATURE_COLUMNS,
    LABEL_COLUMN,
    _assert_sem_vazamento,
    _COLUNAS_PROIBIDAS,
    features_sem_variancia,
)
from biomie_intel.jobs import track
from biomie_intel.model import GANHO_MINIMO_AUC, TrainResult, _split_temporal
from biomie_intel.tenancy import UnknownCompanyError, resolve
from fakes import FakeConnection

CONSULTA_EMPRESAS = "FROM biomie_companies"


def amostra(pedido: int, *, corte: date, convertido: int = 0, **extra):
    linha = {
        "company_id": 1,
        "pedido_id_omie": pedido,
        "cut_date": corte,
        LABEL_COLUMN: convertido,
    }
    linha.update({c: 1.0 for c in FEATURE_COLUMNS})
    linha.update(extra)
    return linha


# ───────────────────────────── vazamento temporal ────────────────────────────

class TestVazamentoTemporal(unittest.TestCase):
    def test_nenhuma_coluna_de_desfecho_e_feature(self) -> None:
        """A trava central: saber o desfecho na hora de prever é trapaça."""
        vazadas = _COLUNAS_PROIBIDAS.intersection(FEATURE_COLUMNS)
        self.assertEqual(
            vazadas, set(),
            f"{sorted(vazadas)} descreve o DESFECHO e não pode ser feature",
        )

    def test_a_lista_de_proibidas_nao_esvaziou(self) -> None:
        """Sem isto, o teste acima passaria com _COLUNAS_PROIBIDAS = {}."""
        self.assertIn("data_faturamento", _COLUNAS_PROIBIDAS)
        self.assertIn("etapa_omie", _COLUNAS_PROIBIDAS)
        self.assertGreaterEqual(len(_COLUNAS_PROIBIDAS), 8)

    def test_pedido_repetido_em_dois_cortes_e_recusado(self) -> None:
        """O mesmo pedido em dois cortes pode cair no treino E no teste."""
        samples = [
            amostra(1, corte=date(2026, 6, 1)),
            amostra(1, corte=date(2026, 6, 15)),
        ]
        with self.assertRaises(RuntimeError) as ctx:
            _assert_sem_vazamento(samples)
        self.assertIn("mais de um corte", str(ctx.exception))

    def test_mesmo_pedido_de_empresas_diferentes_e_valido(self) -> None:
        """A chave é (empresa, pedido) — ids da Omie se repetem entre empresas."""
        a = amostra(1, corte=date(2026, 6, 1))
        b = amostra(1, corte=date(2026, 6, 1))
        b["company_id"] = 2
        _assert_sem_vazamento([a, b])  # não levanta

    def test_dataset_sem_as_features_declaradas_e_recusado(self) -> None:
        incompleta = amostra(1, corte=date(2026, 6, 1))
        del incompleta["valor_percentil"]
        with self.assertRaises(RuntimeError) as ctx:
            _assert_sem_vazamento([incompleta])
        self.assertIn("valor_percentil", str(ctx.exception))

    def test_feature_constante_e_denunciada(self) -> None:
        """Feature sem variância não informa nada e mascara dataset quebrado."""
        samples = [amostra(i, corte=date(2026, 6, 1)) for i in range(5)]
        mortas = features_sem_variancia(samples)
        self.assertIn("valor_total", mortas)


# ────────────────────────────── split temporal ───────────────────────────────

class TestSplitTemporal(unittest.TestCase):
    def setUp(self) -> None:
        self.cortes = [date(2026, 5, 1) + timedelta(days=7 * i) for i in range(10)]
        self.samples = [
            amostra(i, corte=c)
            for i, c in enumerate(c for c in self.cortes for _ in range(4))
        ]

    def test_teste_vem_do_FUTURO_do_treino(self) -> None:
        treino, teste = _split_temporal(self.samples)
        self.assertTrue(treino and teste)
        self.assertLess(
            max(s["cut_date"] for s in treino),
            min(s["cut_date"] for s in teste),
            "há corte de teste anterior a corte de treino — o split não é temporal",
        )

    def test_nenhum_corte_aparece_dos_dois_lados(self) -> None:
        treino, teste = _split_temporal(self.samples)
        self.assertEqual(
            {s["cut_date"] for s in treino} & {s["cut_date"] for s in teste}, set()
        )

    def test_nenhum_pedido_aparece_dos_dois_lados(self) -> None:
        treino, teste = _split_temporal(self.samples)
        chave = lambda rows: {(r["company_id"], r["pedido_id_omie"]) for r in rows}
        self.assertEqual(chave(treino) & chave(teste), set())

    def test_nada_se_perde_no_split(self) -> None:
        treino, teste = _split_temporal(self.samples)
        self.assertEqual(len(treino) + len(teste), len(self.samples))

    def test_poucos_cortes_nao_produz_teste_falso(self) -> None:
        """Com 2 cortes não dá para separar — devolve teste VAZIO, não um split ruim."""
        poucos = [amostra(i, corte=self.cortes[i % 2]) for i in range(8)]
        treino, teste = _split_temporal(poucos)
        self.assertEqual(teste, [])
        self.assertEqual(len(treino), 8)


# ──────────────────────── promoção shadow → active ───────────────────────────

class TestPromocao(unittest.TestCase):
    """"Não consegui comparar" não é "ganhou" — o portão exige baseline."""

    def test_apto_sem_baseline_NAO_pode_ativar(self) -> None:
        r = TrainResult(apto=True, motivo="treinou", comparado_ao_baseline=False)
        self.assertTrue(r.apto)
        self.assertFalse(
            r.apto_para_ativacao,
            "modelo sem baseline comparável não pode sair do modo silencioso",
        )

    def test_apto_com_baseline_pode_ativar(self) -> None:
        r = TrainResult(apto=True, motivo="venceu", comparado_ao_baseline=True)
        self.assertTrue(r.apto_para_ativacao)

    def test_inapto_nunca_ativa_mesmo_comparado(self) -> None:
        r = TrainResult(apto=False, motivo="perdeu", comparado_ao_baseline=True)
        self.assertFalse(r.apto_para_ativacao)

    def test_o_ganho_minimo_e_positivo(self) -> None:
        """Empatar com o baseline não justifica trocar de modelo."""
        self.assertGreater(GANHO_MINIMO_AUC, 0)


# ───────────────────────────── tenancy / empresa ─────────────────────────────

def conn_empresas(linhas):
    return FakeConnection({CONSULTA_EMPRESAS: linhas})


EMPRESAS = [
    {"id": 1, "slug": "MAG_FILIAL", "nome": "Mag Filial"},
    {"id": 2, "slug": "EXPERT", "nome": "Expert"},
    {"id": 3, "slug": "UNI_DESIGN", "nome": "Uni Design"},
]


class TestSelecaoDeEmpresa(unittest.TestCase):
    def test_all_devolve_todas_as_ativas(self) -> None:
        empresas = resolve(conn_empresas(EMPRESAS), "all")
        self.assertEqual([c.id for c in empresas], [1, 2, 3])

    def test_all_e_o_padrao(self) -> None:
        self.assertEqual(len(resolve(conn_empresas(EMPRESAS))), 3)

    def test_seleciona_por_slug_sem_diferenciar_caixa(self) -> None:
        for entrada in ("EXPERT", "expert", "Expert"):
            with self.subTest(entrada=entrada):
                empresas = resolve(conn_empresas(EMPRESAS), entrada)
                self.assertEqual([c.slug for c in empresas], ["EXPERT"])

    def test_seleciona_por_id(self) -> None:
        self.assertEqual([c.slug for c in resolve(conn_empresas(EMPRESAS), "3")],
                         ["UNI_DESIGN"])

    def test_seletor_desconhecido_e_ERRO_e_nao_lista_vazia(self) -> None:
        """Job que roda "para nenhuma empresa" e reporta sucesso é pior que falhar."""
        with self.assertRaises(UnknownCompanyError) as ctx:
            resolve(conn_empresas(EMPRESAS), "NAO_EXISTE")
        # A mensagem tem que dizer o que EXISTE, senão não ajuda a corrigir.
        self.assertIn("EXPERT", str(ctx.exception))

    def test_sem_empresa_ativa_e_erro(self) -> None:
        with self.assertRaises(UnknownCompanyError):
            resolve(conn_empresas([]), "all")

    def test_a_consulta_filtra_por_ativo(self) -> None:
        conn = conn_empresas(EMPRESAS)
        resolve(conn, "all")
        self.assertIn("ativo = 1", conn.sql_executado()[0])


# ─────────────────────────── ciclo de vida dos insights ──────────────────────

class TestExpiracaoDeInsights(unittest.TestCase):
    def test_persist_marca_as_anteriores_como_nao_vigentes(self) -> None:
        conn = FakeConnection()
        insights_mod.persist(conn, 1, [_insight()], job_run_id=9)
        sqls = conn.sql_executado()
        self.assertTrue(
            any("UPDATE intelligence_insights SET is_current = 0" in s for s in sqls),
            "as leituras antigas continuariam vigentes ao lado das novas",
        )

    def test_persist_grava_validade(self) -> None:
        conn = FakeConnection()
        insights_mod.persist(conn, 1, [_insight()], job_run_id=9)
        _, rows = next(
            (sql, p) for sql, p in conn.executed if "INSERT INTO intelligence_insights" in sql
        )
        self.assertEqual(len(rows), 1)
        self.assertIsNotNone(rows[0][-1], "valid_until não foi preenchido")

    def test_sem_insight_ainda_assim_invalida_os_antigos(self) -> None:
        """Nada a dizer hoje não pode deixar a conclusão de ontem na tela."""
        conn = FakeConnection()
        n = insights_mod.persist(conn, 1, [], job_run_id=9)
        self.assertEqual(n, 0)
        self.assertTrue(
            any("is_current = 0" in s for s in conn.sql_executado())
        )
        self.assertEqual(conn.commits, 1)

    def test_expurgo_nunca_apaga_o_que_esta_na_tela(self) -> None:
        conn = FakeConnection(rowcounts={"DELETE FROM intelligence_insights": 4})
        insights_mod.purge_expired(conn, 1)
        sql = next(s for s in conn.sql_executado() if s.startswith("DELETE"))
        self.assertIn("is_current = 0", sql)
        self.assertIn("valid_until <", sql)

    def test_expurgo_e_escopado_a_uma_empresa(self) -> None:
        conn = FakeConnection(rowcounts={"DELETE FROM intelligence_insights": 4})
        insights_mod.purge_expired(conn, 7)
        sql = next(s for s in conn.sql_executado() if s.startswith("DELETE"))
        self.assertIn("company_id = %s", sql)
        self.assertEqual(conn.params_de("DELETE FROM intelligence_insights")[0], 7)

    def test_expurgo_respeita_a_retencao(self) -> None:
        conn = FakeConnection(rowcounts={"DELETE FROM intelligence_insights": 0})
        insights_mod.purge_expired(conn, 1, retencao_dias=45)
        self.assertEqual(conn.params_de("DELETE FROM intelligence_insights")[1], 45)

    def test_expurgo_devolve_quantas_saiu(self) -> None:
        conn = FakeConnection(rowcounts={"DELETE FROM intelligence_insights": 12})
        self.assertEqual(insights_mod.purge_expired(conn, 1), 12)


def _insight():
    return insights_mod.Insight(
        kind="tendencia_receita", severity="atencao",
        title="Faturamento em queda", body="corpo", rank_weight=80,
        metrics={"variacao": -0.34},
    )


# ─────────────────────── trilha de execução / rollback ───────────────────────

class TestTrilhaDeExecucao(unittest.TestCase):
    def test_abre_a_linha_ANTES_de_trabalhar(self) -> None:
        """Job que morre calado é indistinguível de job nunca agendado."""
        conn = FakeConnection({"SELECT LAST_INSERT_ID()": [{"id": 77}]})
        with track(conn, "insights", company_id=1, run_mode="baseline",
                   correlation_id="abc") as run:
            self.assertEqual(run.id, 77)
            # A linha já existe aqui dentro, antes de qualquer trabalho.
            self.assertTrue(
                any("INSERT INTO intelligence_job_runs" in s for s in conn.sql_executado())
            )

    def test_sucesso_fecha_como_completed(self) -> None:
        conn = FakeConnection({"SELECT LAST_INSERT_ID()": [{"id": 1}]})
        with track(conn, "insights", company_id=1, run_mode="baseline",
                   correlation_id="abc"):
            pass
        status = conn.params_de("UPDATE intelligence_job_runs")[0]
        self.assertEqual(status, "completed")

    def test_excecao_marca_failed_E_faz_rollback(self) -> None:
        conn = FakeConnection({"SELECT LAST_INSERT_ID()": [{"id": 1}]})
        with self.assertRaises(ValueError):
            with track(conn, "insights", company_id=1, run_mode="baseline",
                       correlation_id="abc"):
                raise ValueError("estourou no meio")

        self.assertEqual(conn.rollbacks, 1, "o trabalho parcial não foi desfeito")
        # Ordem em _finish: status, duration_ms, rows_in, rows_out,
        # metrics_json, error_message, model_version, id.
        params = conn.params_de("UPDATE intelligence_job_runs")
        self.assertEqual(params[0], "failed")
        self.assertIn(
            "estourou no meio", params[5],
            "a mensagem do erro não foi gravada — a trilha diria 'failed' sem dizer por quê",
        )
        self.assertIn("ValueError", params[5])

    def test_a_excecao_continua_subindo(self) -> None:
        """Marcar failed não pode ENGOLIR o erro — o cron precisa sair != 0."""
        conn = FakeConnection({"SELECT LAST_INSERT_ID()": [{"id": 1}]})
        with self.assertRaises(ZeroDivisionError):
            with track(conn, "insights", company_id=1, run_mode="baseline",
                       correlation_id="abc"):
                1 / 0

    def test_falha_de_UMA_empresa_nao_apaga_a_trilha_das_outras(self) -> None:
        """Cada empresa tem sua própria linha: a que quebrou fica failed, as
        outras seguem completed. Sem isso, um erro na 3ª empresa faria o job
        inteiro parecer perdido."""
        conn = FakeConnection({"SELECT LAST_INSERT_ID()": [{"id": 1}]})
        status = []

        for empresa in (1, 2, 3):
            try:
                with track(conn, "insights", company_id=empresa, run_mode="baseline",
                           correlation_id="abc"):
                    if empresa == 2:
                        raise RuntimeError("empresa 2 sem dados")
            except RuntimeError:
                pass
            status.append(conn.params_de("UPDATE intelligence_job_runs")[0])
            conn.executed = [e for e in conn.executed if "UPDATE intelligence_job_runs" not in e[0]]

        self.assertEqual(status, ["completed", "failed", "completed"])


class TestFaxinaNaoDerrubaAMissao(unittest.TestCase):
    """O expurgo é manutenção; produzir as leituras é a missão.

    ISTO ACONTECEU (2026-08-04): o DELETE do expurgo foi ao ar antes do GRANT
    correspondente. O job passava a falhar INTEIRO — depois de já ter gravado as
    leituras com sucesso. A home ficava correta e o dead-man switch disparava.
    """

    def _rodar(self, purge_erro: Exception | None):
        import argparse
        from unittest import mock

        from biomie_intel import cli

        conn = FakeConnection({
            CONSULTA_EMPRESAS: EMPRESAS[:1],
            "SELECT LAST_INSERT_ID()": [{"id": 5}],
        })

        args = argparse.Namespace(company="all", dry_run=False, correlation_id="x")
        settings = mock.Mock(run_mode="baseline")
        logger = mock.Mock()

        with mock.patch.object(cli, "connect") as fake_connect, \
                mock.patch.object(cli.insights_mod, "compute", return_value=[_insight()]), \
                mock.patch.object(cli.insights_mod, "persist", return_value=1), \
                mock.patch.object(cli.insights_mod, "purge_expired") as purge, \
                mock.patch.object(cli, "_saida", side_effect=lambda payload, code=0: code):
            fake_connect.return_value.__enter__ = lambda _self: conn
            fake_connect.return_value.__exit__ = lambda *_: None
            if purge_erro is not None:
                purge.side_effect = purge_erro
            else:
                purge.return_value = 3
            código = cli.cmd_insights(args, settings, logger)

        return código, conn, logger

    def test_expurgo_que_falha_nao_marca_o_job_como_failed(self) -> None:
        código, conn, logger = self._rodar(RuntimeError("DELETE command denied"))

        self.assertEqual(código, 0, "o job caiu por causa da faxina")
        status = conn.params_de("UPDATE intelligence_job_runs")[0]
        self.assertEqual(
            status, "completed",
            "as leituras foram gravadas e estão na tela; marcar failed faria o "
            "dead-man switch disparar com a home correta",
        )
        logger.warning.assert_called()

    def test_a_falha_do_expurgo_fica_registrada(self) -> None:
        """Engolir sem deixar rastro seria o outro extremo."""
        _, conn, _ = self._rodar(RuntimeError("DELETE command denied"))
        metrics = conn.params_de("UPDATE intelligence_job_runs")[4]
        self.assertIn("expurgo_falhou", metrics)
        self.assertIn("DELETE command denied", metrics)

    def test_expurgo_bem_sucedido_entra_nas_metricas(self) -> None:
        código, conn, _ = self._rodar(None)
        self.assertEqual(código, 0)
        metrics = conn.params_de("UPDATE intelligence_job_runs")[4]
        self.assertIn("expurgados", metrics)


if __name__ == "__main__":  # pragma: no cover
    unittest.main()
