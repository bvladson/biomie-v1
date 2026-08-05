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

    @staticmethod
    def _conexao_com_queda() -> FakeConnection:
        hoje = date.today()
        linhas = []
        # Janela antiga (> 28 dias): Ana faturava forte.
        for i in range(3):
            linhas.append({"vendedor": "Ana", "dia": hoje - timedelta(days=40 + i), "receita": 2000.0})
        # Janela recente: praticamente parou.
        for i in range(3):
            linhas.append({"vendedor": "Ana", "dia": hoje - timedelta(days=5 + i), "receita": 200.0})
        return FakeConnection({"GROUP BY vendedor, data_emissao": linhas})

    def test_queda_forte_de_vendedor_vira_insight(self) -> None:
        achado = _vendedor_destaque(self._conexao_com_queda(), 1, date.today() - timedelta(days=90))

        self.assertIsNotNone(achado, "detector de vendedor não disparou com queda de 90%")
        assert achado is not None
        self.assertEqual(achado.kind, "vendedor_queda")
        self.assertEqual(achado.metrics["vendedor"], "Ana")
        self.assertAlmostEqual(achado.metrics["queda_relativa"], 0.90, places=2)

    def test_amostra_pequena_nao_vira_alarme(self) -> None:
        conn = FakeConnection({"GROUP BY vendedor, data_emissao": [
            {"vendedor": "Ana", "dia": date.today(), "receita": 10.0},
        ]})
        self.assertIsNone(_vendedor_destaque(conn, 1, date.today() - timedelta(days=90)))


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
