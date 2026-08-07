"""Contratos matemáticos do forecast segmentado e dos alertas."""

from __future__ import annotations

import ast
import inspect
import unittest
from datetime import date, timedelta
from pathlib import Path

from biomie_intel import insights
from biomie_intel.insights import (
    _erro_historico,
    _risco_nao_bater,
    _vendedor_destaque,
)

from fakes import FakeConnection


class TestForecastSegmentado(unittest.TestCase):
    def test_erro_tem_piso_para_nao_exibir_falsa_precisao(self) -> None:
        self.assertGreaterEqual(_erro_historico([100.0, 100.0, 100.0]), 0.15)

    def test_serie_curta_recebe_banda_conservadora(self) -> None:
        self.assertEqual(_erro_historico([100.0, 120.0]), 0.30)

    def test_risco_cresce_quando_projecao_fica_abaixo_da_meta(self) -> None:
        abaixo = _risco_nao_bater(1_500_000, 1_200_000, 0.20)
        acima = _risco_nao_bater(1_500_000, 1_800_000, 0.20)
        self.assertIsNotNone(abaixo)
        self.assertIsNotNone(acima)
        self.assertGreater(abaixo, acima)

    def test_sem_meta_nao_inventa_risco(self) -> None:
        self.assertIsNone(_risco_nao_bater(0, 100.0, 0.20))


class TestDetectorDeVendedor(unittest.TestCase):
    """O detector precisa DISPARAR, não só deixar de explodir.

    Motivo de existir: em 2026-08-05 `_vendedor_destaque` ficou sem corpo — o
    trecho que compara as janelas foi parar dentro de `_anomalia_cancelamentos`,
    depois de um `return`, como código inalcançável. A função continuava
    existindo, continuava sendo chamada e devolvia `None` sempre. Nenhum teste
    quebrou, porque `None` é uma resposta legítima ("não há anomalia"), e a
    suíte inteira passava com o detector morto.
    """

    #: data fixa: o detector recebe `hoje` de quem chama, então o teste não
    #: precisa (nem deve) depender do relógio da máquina que roda a suíte.
    HOJE = date(2026, 8, 7)

    @classmethod
    def _conexao_com_queda(cls) -> FakeConnection:
        linhas = []
        # Janela antiga (> 28 dias): Ana faturava forte.
        for i in range(3):
            linhas.append({"vendedor": "Ana", "dia": cls.HOJE - timedelta(days=40 + i), "receita": 2000.0})
        # Janela recente: praticamente parou.
        for i in range(3):
            linhas.append({"vendedor": "Ana", "dia": cls.HOJE - timedelta(days=5 + i), "receita": 200.0})
        return FakeConnection({"GROUP BY vendedor, data_emissao": linhas})

    def test_queda_forte_de_vendedor_vira_insight(self) -> None:
        achado = _vendedor_destaque(
            self._conexao_com_queda(), 1, self.HOJE - timedelta(days=90), self.HOJE
        )

        self.assertIsNotNone(achado, "detector de vendedor não disparou com queda de 90%")
        assert achado is not None
        self.assertEqual(achado.kind, "vendedor_queda")
        self.assertEqual(achado.metrics["vendedor"], "Ana")
        self.assertAlmostEqual(achado.metrics["queda_relativa"], 0.90, places=2)

    def test_amostra_pequena_nao_vira_alarme(self) -> None:
        conn = FakeConnection({"GROUP BY vendedor, data_emissao": [
            {"vendedor": "Ana", "dia": self.HOJE, "receita": 10.0},
        ]})
        self.assertIsNone(
            _vendedor_destaque(conn, 1, self.HOJE - timedelta(days=90), self.HOJE)
        )

    def test_o_corte_segue_o_hoje_recebido_e_nao_o_relogio_local(self) -> None:
        """A janela de 28 dias é medida a partir do `hoje` do chamador.

        `date.today()` aqui dentro deslocava o corte em um dia entre 21h e 00h
        (servidor em -03, resto do módulo em UTC) e tornava o detector refém do
        relógio. Deslocando `hoje` 60 dias para frente, a "janela recente" fica
        vazia e não há queda a reportar — o que só acontece se o parâmetro for
        de fato quem manda.
        """
        futuro = self.HOJE + timedelta(days=60)
        self.assertIsNone(
            _vendedor_destaque(self._conexao_com_queda(), 1, futuro - timedelta(days=90), futuro),
            "o corte ignorou o `hoje` recebido",
        )


class TestJanelaDoForecastNaoOlhaOFuturo(unittest.TestCase):
    """`--as-of` precisa governar a janela de dados, com piso E teto.

    ISTO ERA UM VAZAMENTO REAL: `_forecast_rows` ancorava o piso em
    `date.today()` e não tinha teto nenhum. Rodar
    `forecast-mensal --as-of=<data passada>` lia notas emitidas DEPOIS da
    referência; como `historicos` junta todos os meses diferentes da
    competência corrente, os meses posteriores ao `as_of` entravam como
    "passado" e a média histórica passava a conter o futuro.

    O estrago não é a previsão de um dia: `intelligence_forecasts` existe para o
    erro ser COBRADO depois. Uma linha gravada por replay que enxergou o futuro
    seria conferida contra o realizado e aprovada — exatamente o formato do
    viés de sobrevivência que já inflou um AUC para 0,94 neste projeto.
    """

    AS_OF = date(2026, 3, 15)

    def _janela(self, dimensao: str = "empresa") -> tuple[date, date]:
        conn = FakeConnection()
        insights._forecast_rows(conn, 1, dimensao, self.AS_OF)
        _, params = conn.executed[0]
        return params[1], params[2]

    def test_a_janela_termina_na_data_de_referencia(self) -> None:
        _, teto = self._janela()
        self.assertEqual(teto, self.AS_OF, "a consulta lê notas posteriores ao as-of")

    def test_a_janela_comeca_a_partir_da_data_de_referencia(self) -> None:
        piso, _ = self._janela()
        self.assertEqual(piso, self.AS_OF - timedelta(days=insights.JANELA_FORECAST_DIAS))

    def test_o_sql_declara_os_dois_limites(self) -> None:
        """Sem o teto no SQL os parâmetros certos não adiantam."""
        conn = FakeConnection()
        insights._forecast_rows(conn, 1, "vendedor", self.AS_OF)
        sql = conn.sql_executado()[0]
        self.assertIn("data_emissao >= %s", sql)
        self.assertIn("data_emissao <= %s", sql)

    def test_todas_as_dimensoes_respeitam_a_mesma_janela(self) -> None:
        for dimensao in ("empresa", "canal", "vendedor"):
            with self.subTest(dimensao=dimensao):
                self.assertEqual(self._janela(dimensao)[1], self.AS_OF)

    def test_calcular_forecasts_propaga_o_as_of_ate_a_consulta(self) -> None:
        """O elo que faltava: quem chama tem que PASSAR o `hoje` adiante.

        Corrigir só `_forecast_rows` e esquecer o argumento no chamador
        devolveria o defeito inteiro, com a função nova parecendo correta.
        """
        conn = FakeConnection()
        insights.calcular_forecasts(conn, 1, self.AS_OF)
        janelas = [
            params[1:3] for sql, params in conn.executed
            if "GROUP BY codigo, nome, data_emissao" in " ".join(sql.split())
        ]
        self.assertTrue(janelas, "calcular_forecasts não consultou a série")
        for piso, teto in janelas:
            self.assertEqual(teto, self.AS_OF)
            self.assertEqual(piso, self.AS_OF - timedelta(days=insights.JANELA_FORECAST_DIAS))


class TestIntegridadeDosDetectores(unittest.TestCase):
    """Guard estrutural: pega a classe do defeito, não só a instância.

    Um detector anotado `-> Insight | None` que nunca devolve um `Insight` está
    morto, e morre em silêncio. Código depois de um `return` no corpo da função
    é o sintoma de edição que colou bloco no lugar errado.
    """

    @staticmethod
    def _arvore() -> ast.Module:
        fonte = Path(inspect.getfile(insights)).read_text(encoding="utf-8")
        return ast.parse(fonte)

    def _funcoes(self) -> list[ast.FunctionDef]:
        return [n for n in self._arvore().body if isinstance(n, ast.FunctionDef)]

    def test_nenhum_detector_ficou_sem_corpo(self) -> None:
        mudos = [
            fn.name for fn in self._funcoes()
            if fn.returns is not None
            and "Insight" in ast.unparse(fn.returns)
            and not any(isinstance(n, ast.Return) and n.value is not None for n in ast.walk(fn))
        ]
        self.assertEqual(mudos, [], f"detectores que nunca devolvem Insight: {mudos}")

    def test_nenhuma_funcao_tem_codigo_apos_return(self) -> None:
        mortos = [
            f"{fn.name}:{stmt.lineno}"
            for fn in self._funcoes()
            for i, stmt in enumerate(fn.body)
            if isinstance(stmt, ast.Return) and i < len(fn.body) - 1
        ]
        self.assertEqual(mortos, [], f"código inalcançável após return: {mortos}")

    @staticmethod
    def _sql_da_funcao(fn: ast.FunctionDef) -> str:
        """Só as strings que viram SQL — o docstring fica de fora.

        A primeira versão deste guard usava `ast.unparse(fn)` inteiro e por isso
        NÃO pegou a mutação: o docstring que explica a regra cita
        `ESCOPO_COMERCIAL` e `UNIVERSO_COMERCIAL` por extenso, então o teste
        casava com a prosa mesmo depois de o código perder a referência. Um
        guard que lê comentário aprova exatamente o que deveria reprovar.
        """
        doc = ast.get_docstring(fn, clean=False)
        partes = [
            n.value for n in ast.walk(fn)
            if isinstance(n, ast.Constant) and isinstance(n.value, str) and n.value != doc
        ]
        return " ".join(partes)

    @staticmethod
    def _nomes_usados(fn: ast.FunctionDef) -> set[str]:
        """Identificadores REFERENCIADOS — não citados em texto."""
        return {n.id for n in ast.walk(fn) if isinstance(n, ast.Name)}

    def test_todo_leitor_de_nf_declara_escopo_comercial(self) -> None:
        """Sem escopo, a leitura soma remessa, uso interno e transferência.

        `_anomalia_cancelamentos` era o único de fora: contava cancelamento de
        operação que nem venda é. Ele não pode usar `UNIVERSO_COMERCIAL` (que
        exige `autorizada` e excluiria justamente as canceladas), então a regra
        aceita qualquer um dos dois — o que NÃO se aceita é nenhum.
        """
        sem_escopo = [
            fn.name for fn in self._funcoes()
            if "biomie_notas_fiscais_canonicas" in self._sql_da_funcao(fn)
            and not (self._nomes_usados(fn) & {"UNIVERSO_COMERCIAL", "ESCOPO_COMERCIAL"})
        ]
        self.assertEqual(sem_escopo, [], f"leem NF sem escopo comercial: {sem_escopo}")

    def test_o_detector_de_leitores_de_nf_nao_esvaziou(self) -> None:
        """Sem isto, o teste acima passaria se `_sql_da_funcao` parasse de achar SQL."""
        leitores = [
            fn.name for fn in self._funcoes()
            if "biomie_notas_fiscais_canonicas" in self._sql_da_funcao(fn)
        ]
        self.assertIn("_anomalia_cancelamentos", leitores)
        self.assertIn("_forecast_rows", leitores)
        self.assertGreaterEqual(len(leitores), 5, f"achou só {leitores}")

    def test_o_universo_continua_derivando_do_escopo(self) -> None:
        """Se os dois virarem literais separados, voltam a divergir em silêncio."""
        self.assertIn(insights.ESCOPO_COMERCIAL, insights.UNIVERSO_COMERCIAL)
        self.assertIn("status_fiscal = 'autorizada'", insights.UNIVERSO_COMERCIAL)
        self.assertNotIn("status_fiscal", insights.ESCOPO_COMERCIAL)

    def test_todo_detector_declarado_entra_no_compute(self) -> None:
        """Detector que ninguém chama é o mesmo problema com outra roupa."""
        fonte_compute = ""
        for fn in self._funcoes():
            if fn.name == "compute":
                fonte_compute = ast.unparse(fn)
        self.assertNotEqual(fonte_compute, "", "compute() não encontrado")

        orfaos = [
            fn.name for fn in self._funcoes()
            if fn.name.startswith(("_anomalia", "_vendedor", "_tendencia", "_concentracao", "_ritmo", "_forecasts"))
            and fn.name not in fonte_compute
        ]
        self.assertEqual(orfaos, [], f"detectores nunca chamados por compute(): {orfaos}")


if __name__ == "__main__":
    unittest.main()
