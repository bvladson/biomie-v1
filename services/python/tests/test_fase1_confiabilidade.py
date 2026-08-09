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


class TestPalavrasReservadasNoSQL(unittest.TestCase):
    """Identificador que colide com palavra reservada precisa de crase.

    `year_month` é unidade de intervalo no MariaDB (YEAR_MONTH). A query de
    metas por vendedor ficou meses devolvendo 1064 sem que ninguém notasse,
    porque o `except Exception` de `_metas` transformava o erro de sintaxe em
    "esta empresa não tem meta cadastrada". Havia 4 metas de agosto somando
    R$ 160 mil sendo descartadas em silêncio.

    Este teste é estático porque o duplo de conexão não valida SQL — só um
    MariaDB de verdade recusaria, e é justamente o que não roda no laptop.
    """

    #: Palavras reservadas que já apareceram como nome de coluna neste schema.
    RESERVADAS = ("year_month", "rank", "system", "groups", "lead", "rows")

    def test_toda_coluna_reservada_aparece_entre_crases(self) -> None:
        fonte = Path(insights.__file__).read_text(encoding="utf-8")
        # Remove as ocorrências corretas para sobrar só as nuas.
        nuas = []
        for palavra in self.RESERVADAS:
            for trecho in re.finditer(rf"(?<![`\w.]){re.escape(palavra)}(?![`\w])", fonte):
                linha = fonte[: trecho.start()].count("\n") + 1
                contexto = fonte.splitlines()[linha - 1].strip()
                # Só interessa dentro de SQL; comentário e nome de variável não.
                if contexto.startswith("#") or "%s" not in contexto and "SELECT" not in contexto.upper():
                    continue
                nuas.append(f"{palavra} @ linha {linha}: {contexto[:70]}")
        self.assertEqual(nuas, [], f"palavra reservada sem crase em SQL: {nuas}")


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

    def test_grava_probabilidade_auditavel_por_nivel(self) -> None:
        conn = FakeConnection()
        niveis = [
            {"level": 1, "target": 40000.0, "achieved": True, "probability": 1.0, "actionable": True},
            {"level": 2, "target": 60000.0, "achieved": False, "probability": 0.72, "actionable": True},
        ]
        forecast_store.persist(conn, 1, "2026-08", [self._linha(
            base_target=40000.0, active_target=60000.0, maximum_target=80000.0,
            achieved_level=1, active_level=2, levels_total=3,
            level_probabilities=niveis,
        )])
        params = conn.params_de("INSERT INTO intelligence_forecasts")
        payload = next(p for p in params if isinstance(p, str) and '"level"' in p)
        self.assertIn('"probability": 0.72', payload)


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


class TestPortaoDeConfianca(unittest.TestCase):
    """Projeção com erro alto é gravada, mas não vira alerta.

    Na primeira execução real apareceram vendedores com `erro_historico = 1.0`
    (MAPE de 100%) produzindo "risco de não bater a meta: 66%" e severidade
    `atencao` — ou seja, notificação no sininho a partir de um modelo sem
    nenhum poder preditivo para aquela entidade. A faixa ia de R$ 5.493 a
    R$ 64.081: cobre qualquer desfecho.
    """

    def test_erro_alto_nao_gera_severidade_de_alerta(self) -> None:
        self.assertEqual(insights._confianca(1.0), "baixa")
        self.assertEqual(insights._confianca(0.60), "baixa")

    def test_erro_baixo_continua_confiavel(self) -> None:
        self.assertEqual(insights._confianca(0.10), "alta")
        self.assertEqual(insights._confianca(0.30), "media")

    def test_o_limite_de_alerta_e_menor_que_o_teto_do_erro(self) -> None:
        """Se o limite fosse 1.0, o portão nunca fecharia — `_erro_historico` satura em 1."""
        self.assertLess(insights.ERRO_CONFIANCA_MINIMA, 1.0)
        self.assertLess(insights.ERRO_CONFIANCA_ALTA, insights.ERRO_CONFIANCA_MINIMA)


class TestJanelaDeInatividade(unittest.TestCase):
    """Job sem limite não tem dead-man switch — foi o caso do insights.

    A versão anterior comparava o conjunto de jobs por IGUALDADE, e quebrava a
    cada job novo legítimo (`geo_backtest` foi o terceiro a derrubá-la). Teste
    que precisa ser editado toda vez que o sistema cresce ensina a editar o
    teste em vez de olhar o que ele diz. Aqui o que se afere é o INVARIANTE:
    os jobs conhecidos continuam declarados, e nenhum limite é curto demais.
    """

    #: cadência real de cada job, em horas — vem do `biomie.cron`
    CADENCIAS_HORAS = {
        "quote_score": 2, "insights": 6, "forecast": 24,
        "geo_intelligence": 24, "fiscal_mart": 24, "geo_backtest": 168,
        # `40 5 * * *` e `15 6 * * *` — os dois 1x/dia.
        "receivable_risk": 24, "ops_anomaly": 24, "daily_baseline": 24,
    }

    def test_os_jobs_conhecidos_continuam_declarados(self) -> None:
        faltando = set(self.CADENCIAS_HORAS) - set(health.MAX_IDLE_HORAS)
        self.assertEqual(faltando, set(), f"job perdeu o dead-man switch: {faltando}")

    def test_nenhum_job_agendado_ficou_sem_limite(self) -> None:
        """O sentido inverso: SLA declarado sem cadência conhecida aqui."""
        sem_cadencia = set(health.MAX_IDLE_HORAS) - set(self.CADENCIAS_HORAS)
        self.assertEqual(
            sem_cadencia, set(),
            f"job novo sem cadência declarada no teste — confirme o cron: {sem_cadencia}",
        )

    def test_limite_cobre_ao_menos_uma_execucao_perdida(self) -> None:
        for job, cadencia in self.CADENCIAS_HORAS.items():
            with self.subTest(job=job):
                self.assertGreater(
                    health.MAX_IDLE_HORAS[job], cadencia,
                    "limite menor que a cadência dispara alarme falso a cada execução",
                )


class TestConfiancaBaixaNuncaViraNotificacao(unittest.TestCase):
    """DE PONTA A PONTA: MAPE alto não pode chegar ao sininho.

    O teste anterior verificava só `_confianca()` — a função de limiar, isolada.
    Isso passaria mesmo se alguém religasse o alerta DEPOIS do portão, que é
    exatamente o caminho por onde o defeito voltaria. O invariante que interessa
    atravessa três componentes:

        calcular_forecasts  →  severidade do Insight  →  filtro do publicador

    O publicador (`bin/publish_intelligence_notifications.php`) só publica
    `severity IN ('atencao','critico')`. Logo, o contrato acionável é: nenhuma
    linha com erro >= ERRO_CONFIANCA_MINIMA pode produzir uma dessas duas
    severidades — por maior que seja o risco calculado.
    """

    #: severidades que o publicador transforma em notificação
    ACIONAVEIS = {"atencao", "critico"}

    @staticmethod
    def _linha(erro: float, risco: float | None, **kw) -> ForecastLinha:
        base = dict(
            dimensao="vendedor", entidade_codigo="v1", entidade_nome="Vendedor 1",
            competencia="2026-08", realizado=5000.0, projecao=32000.0,
            valor_min=5000.0, valor_max=64000.0, meta_valor=40000.0,
            risco_nao_bater=risco, erro_historico=erro, meses_historicos=3,
            dados_usados=20, fatores=["ritmo"],
        )
        base.update(kw)
        return ForecastLinha(**base)

    def _severidades(self, linhas: list[ForecastLinha]) -> list[str]:
        original = insights.calcular_forecasts
        insights.calcular_forecasts = lambda *_a, **_k: linhas
        try:
            return [i.severity for i in insights._forecasts_segmentados(None, 1, date.today())]
        finally:
            insights.calcular_forecasts = original

    def test_erro_alto_nunca_produz_severidade_acionavel(self) -> None:
        """Varre o pior caso: erro no teto e risco quase certo."""
        for erro in (0.50, 0.60, 0.75, 0.90, 1.00):
            for risco in (0.45, 0.70, 0.95, 0.99):
                with self.subTest(erro=erro, risco=risco):
                    severidades = self._severidades([self._linha(erro, risco)])
                    self.assertTrue(severidades, "esperava ao menos uma leitura")
                    for s in severidades:
                        self.assertNotIn(
                            s, self.ACIONAVEIS,
                            f"erro {erro:.0%} com risco {risco:.0%} virou '{s}' e iria para o sininho",
                        )

    def test_erro_baixo_com_risco_alto_CONTINUA_alertando(self) -> None:
        """O portão não pode virar mordaça: confiança boa tem que alertar."""
        severidades = self._severidades([self._linha(0.15, 0.85)])
        self.assertIn("critico", severidades)

    def test_confianca_baixa_nao_mostra_projecao_nem_probabilidade(self) -> None:
        original = insights.calcular_forecasts
        insights.calcular_forecasts = lambda *_a, **_k: [self._linha(0.90, 0.80)]
        try:
            achados = insights._forecasts_segmentados(None, 1, date.today())
        finally:
            insights.calcular_forecasts = original

        corpo = achados[0].body
        self.assertIn("irregular", corpo, "o texto precisa dizer POR QUE está neutro")
        self.assertNotIn("chance estimada", corpo)
        self.assertNotIn("Risco de não bater", corpo)

    def test_o_publicador_so_publica_as_duas_severidades(self) -> None:
        """Se o filtro do publicador mudar, o invariante acima perde o sentido."""
        publicador = (
            Path(__file__).resolve().parents[3] / "bin" / "publish_intelligence_notifications.php"
        )
        if not publicador.is_file():
            self.skipTest("publicador não encontrado")
        sql = publicador.read_text(encoding="utf-8")
        self.assertIn("severity IN ('atencao', 'critico')", sql)


if __name__ == "__main__":
    unittest.main()
