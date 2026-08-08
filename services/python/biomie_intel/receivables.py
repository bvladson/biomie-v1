"""Risco de atraso em recebíveis.

O QUE ESTE MÓDULO RESPONDE: dos títulos em aberto, quais têm chance real de
atrasar — para a cobrança ir atrás antes do vencimento, não depois.

─── A DESCOBERTA QUE TORNA ISTO POSSÍVEL (e que quase produziu um modelo falso)

À primeira vista o rótulo parecia pronto: 6.446 títulos liquidados, com data de
vencimento e data de liquidação. Amostra ótima. Mas a média de
`liquidacao - vencimento` dava **−115 dias**, e 51% dos títulos tinham
`data_liquidacao` igual à `data_emissao`.

Medindo por forma de pagamento, a razão apareceu:

    Mercado Pago     1.640 títulos   média −141 dias   0,1% de atraso
    Cartão Master      999           média −139        0,0%
    Cartão Visa        852           média −139        0,1%
    Boleto/TED         442           média  −13        2,0%
    PIX                221           média   +2       13,1%

Em título de CARTÃO, `data_vencimento` é a data em que a ADQUIRENTE repassa o
dinheiro — não uma obrigação do cliente. O cliente pagou no ato (por isso
liquidação = emissão). "Atraso" ali não é raro: é impossível por construção, e
entra na conta como um monte de −140 que afunda a média.

Consequência prática: a taxa de "1,9% de inadimplência do grupo" era a média
entre uma taxa real e uma taxa que não existe. E a leitura de que
"a ERGOOFFICE tem 44,6% de atraso" era inteiramente artefato do mix de
pagamento — ela não tem UM título de crédito real.

Por isso a primeira coisa que este módulo faz não é pontuar: é separar universo.

─── O QUE ELE NÃO FAZ

Não treina modelo. O universo válido hoje tem 791 títulos de UMA empresa com 46
atrasos — passa nos mínimos de amostra, mas um corte temporal deixaria ~14
positivos no teste, e métrica sobre 14 positivos é ruído com casas decimais.
Vale o mesmo princípio de `model.py`: o score determinístico é o baseline
honesto, e o modelo só entra quando puder PROVAR que ganha dele.
"""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import date
from typing import Any

import pymysql

from .db import execute, execute_many, fetch_all

MODEL_VERSION = "receivable-risk-v1"

#: Formas em que o cliente NÃO deve nada na data de vencimento — o dinheiro já
#: saiu do bolso dele e o que a data marca é o repasse da adquirente.
#:
#: Lista fechada e explícita em vez de heurística por substring: "Cartao" pegaria
#: "Cartao de Credito do Cliente X" se alguém cadastrasse assim, e um falso
#: positivo aqui EXCLUI um título legítimo do risco — some da tela silenciosamente,
#: que é o pior tipo de erro para uma fila de cobrança.
FORMAS_IMEDIATAS = frozenset({
    "mercado pago",
    "cartao master",
    "cartao visa",
    "cartao elo",
    "cartao amex",
    "cartao hiper",
    "cartao diners",
    "maquininha stone",
    "maquininha",
})

#: Formas em que existe obrigação do cliente numa data.
FORMAS_CREDITO_REAL = frozenset({
    "boleto",
    "boleto/ted",
    "pix",
    "dinheiro",
    "transferencia",
    "duplicata",
    "deposito",
})

#: Diferença a partir da qual se considera atraso. 3 dias absorve fim de semana
#: e compensação bancária — sem essa folga, todo boleto pago na segunda-feira
#: seguinte viraria inadimplência.
TOLERANCIA_ATRASO_DIAS = 3

#: Liquidação MUITO antes do vencimento não é antecipação, é dado inconsistente
#: (sobraram 88 casos assim mesmo depois de excluir cartão: Boleto/TED com −60
#: dias, Dinheiro com −131). Entram na contagem de histórico como "pago", mas
#: não contaminam a média de dias de atraso.
ANTECIPACAO_IMPLAUSIVEL_DIAS = -30

#: Abaixo disto o histórico do cliente não sustenta afirmação nenhuma.
MIN_TITULOS_HISTORICO = 3

BANDA_ALTA = 65.0
BANDA_MEDIA = 35.0


def classificar_universo(forma_pagamento: str | None) -> str:
    """`credito_real`, `imediato` ou `indefinido`.

    `indefinido` é resposta legítima e frequente (1.700 títulos com forma vazia,
    cujo perfil — prazo de 112 dias, média de −119 — é idêntico ao do cartão).
    Chutar "crédito real" para eles encheria a fila de cobrança de gente que já
    pagou; chutar "imediato" esconderia devedor de verdade. Fica indefinido, sai
    do score e APARECE no resumo, para o buraco ter tamanho conhecido.
    """
    chave = (forma_pagamento or "").strip().lower()
    if not chave:
        return "indefinido"
    if chave in FORMAS_IMEDIATAS:
        return "imediato"
    if chave in FORMAS_CREDITO_REAL:
        return "credito_real"
    return "indefinido"


@dataclass
class HistoricoPagador:
    """Como este cliente pagou no passado, só no universo de crédito real."""

    titulos: int = 0
    atrasados: int = 0
    dias_atraso_total: float = 0.0
    pior_atraso: int = 0

    @property
    def taxa_atraso(self) -> float | None:
        if self.titulos < MIN_TITULOS_HISTORICO:
            return None
        return self.atrasados / self.titulos

    @property
    def atraso_medio(self) -> float | None:
        if not self.atrasados:
            return 0.0 if self.titulos >= MIN_TITULOS_HISTORICO else None
        return self.dias_atraso_total / self.atrasados


@dataclass
class RiscoTitulo:
    company_id: int
    titulo_key: str
    codigo_cliente_omie: int | None
    nome_cliente: str
    forma_pagamento: str
    universo: str
    valor_aberto: float
    data_vencimento: date | None
    dias_para_vencer: int | None
    score: float | None
    band: str | None
    reasons: list[str] = field(default_factory=list)
    features: dict[str, Any] = field(default_factory=dict)


def carregar_historico(
    conn: pymysql.connections.Connection, company_id: int, as_of: date
) -> dict[int, HistoricoPagador]:
    """Histórico de pagamento por cliente — SÓ do universo de crédito real.

    Título liquidado ANTES do `as_of`, para o histórico não conter o futuro
    quando alguém reprocessar uma data passada.
    """
    linhas = fetch_all(
        conn,
        """SELECT codigo_cliente_omie, forma_pagamento,
                  DATEDIFF(data_liquidacao, data_vencimento) AS dias
             FROM biomie_titulos_financeiros_canonicos
            WHERE company_id = %s
              AND status_titulo = 'liquidado'
              AND data_liquidacao IS NOT NULL
              AND data_vencimento IS NOT NULL
              AND data_liquidacao <= %s
              AND codigo_cliente_omie IS NOT NULL""",
        (company_id, as_of),
    )

    historico: dict[int, HistoricoPagador] = {}
    for linha in linhas:
        if classificar_universo(linha.get("forma_pagamento")) != "credito_real":
            continue
        cliente = int(linha["codigo_cliente_omie"])
        dias = int(linha["dias"] or 0)
        h = historico.setdefault(cliente, HistoricoPagador())
        h.titulos += 1
        # Antecipação implausível conta como título pago, mas o "quanto" é
        # descartado: somar −131 dias puxaria a média para um número que
        # descreve o defeito do dado, não o comportamento do cliente.
        if dias > TOLERANCIA_ATRASO_DIAS:
            h.atrasados += 1
            h.dias_atraso_total += dias
            h.pior_atraso = max(h.pior_atraso, dias)
    return historico


def carregar_abertos(
    conn: pymysql.connections.Connection, company_id: int
) -> list[dict[str, Any]]:
    return fetch_all(
        conn,
        """SELECT titulo_key, codigo_cliente_omie, nome_cliente, forma_pagamento,
                  valor_aberto, valor_liquido, data_vencimento, data_emissao
             FROM biomie_titulos_financeiros_canonicos
            WHERE company_id = %s
              AND status_titulo = 'aberto'
              AND valor_aberto > 0""",
        (company_id,),
    )


def _pontuar_atraso_corrente(dias_para_vencer: int | None) -> tuple[float, str | None]:
    """Já vencido é o sinal mais forte que existe — e é FATO, não previsão."""
    if dias_para_vencer is None or dias_para_vencer >= 0:
        return 0.0, None
    atraso = -dias_para_vencer
    if atraso >= 60:
        return 45.0, f"Vencido há {atraso} dias — cobrança formal"
    if atraso >= 30:
        return 38.0, f"Vencido há {atraso} dias"
    if atraso >= 7:
        return 28.0, f"Vencido há {atraso} dias"
    return 18.0, f"Vencido há {atraso} dia(s)"


def _pontuar_historico(h: HistoricoPagador | None) -> tuple[float, str | None]:
    """Cliente que atrasou antes tende a atrasar de novo — mas só com amostra."""
    if h is None or h.taxa_atraso is None:
        return 0.0, None
    taxa = h.taxa_atraso
    if taxa >= 0.5:
        return 30.0, f"Atrasou {h.atrasados} de {h.titulos} títulos anteriores"
    if taxa >= 0.25:
        return 22.0, f"Atrasou {h.atrasados} de {h.titulos} títulos anteriores"
    if taxa > 0:
        return 12.0, f"Já atrasou {h.atrasados}x em {h.titulos} títulos"
    return 0.0, None


def _pontuar_severidade_historica(h: HistoricoPagador | None) -> tuple[float, str | None]:
    """Atrasar 3 dias e atrasar 90 não são o mesmo comportamento."""
    if h is None or not h.atrasados:
        return 0.0, None
    if h.pior_atraso >= 60:
        return 12.0, f"Pior atraso histórico: {h.pior_atraso} dias"
    if h.pior_atraso >= 30:
        return 8.0, None
    return 4.0, None


def _pontuar_exposicao(valor: float, mediana_empresa: float) -> tuple[float, str | None]:
    """Valor RELATIVO à empresa, como no score de orçamentos.

    Não é probabilidade de atraso — é quanto custa se atrasar. Uma fila de
    cobrança ordenada só por probabilidade manda o vendedor atrás de R$ 80
    enquanto R$ 80 mil vence sozinho.
    """
    if mediana_empresa <= 0:
        return 0.0, None
    razao = valor / mediana_empresa
    if razao >= 10:
        return 13.0, f"Exposição alta: {_brl(valor)}"
    if razao >= 3:
        return 9.0, f"Exposição relevante: {_brl(valor)}"
    if razao >= 1:
        return 5.0, None
    return 0.0, None


def _brl(v: float) -> str:
    return f"R$ {v:,.0f}".replace(",", "X").replace(".", ",").replace("X", ".")


def band_for(score: float) -> str:
    if score >= BANDA_ALTA:
        return "alta"
    if score >= BANDA_MEDIA:
        return "media"
    return "baixa"


def avaliar(
    conn: pymysql.connections.Connection, company_id: int, as_of: date
) -> list[RiscoTitulo]:
    """Pontua os títulos em aberto de uma empresa."""
    abertos = carregar_abertos(conn, company_id)
    if not abertos:
        return []
    historico = carregar_historico(conn, company_id, as_of)

    # A mediana de referência sai SÓ do universo de crédito real.
    #
    # Usar todos os títulos misturaria o ticket de cartão (R$ 522 de média, e a
    # maioria das linhas) com o de boleto (R$ 2.121). A mediana despencaria e
    # quase todo boleto pareceria "exposição alta" — a comparação tem que ser
    # contra o que de fato disputa a fila de cobrança, não contra o que nem
    # entra nela.
    cobraveis = [float(r["valor_aberto"]) for r in abertos
                 if classificar_universo(r.get("forma_pagamento")) == "credito_real"]
    ordenados = sorted(cobraveis)
    mediana = ordenados[len(ordenados) // 2] if ordenados else 0.0

    saida: list[RiscoTitulo] = []
    for r in abertos:
        forma = str(r.get("forma_pagamento") or "")
        universo = classificar_universo(forma)
        venc = r.get("data_vencimento")
        dias = (venc - as_of).days if venc else None
        valor = float(r["valor_aberto"])
        cliente = int(r["codigo_cliente_omie"]) if r.get("codigo_cliente_omie") is not None else None

        comum = {
            "company_id": company_id,
            "titulo_key": str(r["titulo_key"]),
            "codigo_cliente_omie": cliente,
            "nome_cliente": str(r.get("nome_cliente") or "Cliente nao informado"),
            "forma_pagamento": forma,
            "universo": universo,
            "valor_aberto": valor,
            "data_vencimento": venc,
            "dias_para_vencer": dias,
        }

        # Fora do universo de crédito real o score é NULL, nunca "baixa".
        # Pontuar cartão como baixo risco seria tecnicamente verdade e
        # praticamente uma mentira: ele não está na fila de cobrança porque não
        # há o que cobrar, não porque o cliente é bom pagador.
        if universo != "credito_real":
            motivo = ("Cartão/adquirente: o vencimento é repasse, não obrigação do cliente"
                      if universo == "imediato"
                      else "Forma de pagamento não informada — não dá para afirmar risco")
            saida.append(RiscoTitulo(
                **comum, score=None, band=None, reasons=[motivo],
                features={"universo": universo, "motivo_sem_score": universo},
            ))
            continue

        h = historico.get(cliente) if cliente is not None else None
        partes = [
            _pontuar_atraso_corrente(dias),
            _pontuar_historico(h),
            _pontuar_severidade_historica(h),
            _pontuar_exposicao(valor, mediana),
        ]
        total = round(sum(p[0] for p in partes), 2)
        motivos = [p[1] for p in partes if p[1]]

        if h is None or h.taxa_atraso is None:
            motivos.append(
                f"Cliente sem histórico suficiente ({h.titulos if h else 0} título(s) a prazo)"
            )

        saida.append(RiscoTitulo(
            **comum,
            score=total,
            band=band_for(total),
            reasons=motivos,
            features={
                "universo": universo,
                "dias_para_vencer": dias,
                "valor_aberto": valor,
                "mediana_empresa": round(mediana, 2),
                "historico_titulos": h.titulos if h else 0,
                "historico_atrasados": h.atrasados if h else 0,
                "historico_taxa": round(h.taxa_atraso, 4) if h and h.taxa_atraso is not None else None,
                "historico_pior_atraso": h.pior_atraso if h else 0,
                "componentes": {
                    "atraso_corrente": round(partes[0][0], 2),
                    "historico": round(partes[1][0], 2),
                    "severidade": round(partes[2][0], 2),
                    "exposicao": round(partes[3][0], 2),
                },
            },
        ))
    return saida


def resumo(scores: list[RiscoTitulo]) -> dict[str, Any]:
    """O que o job reporta — inclusive o tamanho do que NÃO deu para pontuar.

    O buraco precisa ter tamanho conhecido: sem isto, "12 títulos de alto risco"
    parece cobertura completa quando pode ser 12 de 40 pontuáveis num universo
    de 554.
    """
    pontuados = [s for s in scores if s.score is not None]
    por_universo: dict[str, int] = {}
    for s in scores:
        por_universo[s.universo] = por_universo.get(s.universo, 0) + 1
    bandas: dict[str, int] = {}
    for s in pontuados:
        bandas[str(s.band)] = bandas.get(str(s.band), 0) + 1
    return {
        "titulos": len(scores),
        "pontuados": len(pontuados),
        "por_universo": por_universo,
        "bandas": bandas,
        "exposicao_total": round(sum(s.valor_aberto for s in scores), 2),
        "exposicao_pontuada": round(sum(s.valor_aberto for s in pontuados), 2),
        "exposicao_vencida": round(
            sum(s.valor_aberto for s in scores
                if s.dias_para_vencer is not None and s.dias_para_vencer < 0), 2
        ),
        "dispersao": _dispersao([s.score for s in pontuados if s.score is not None]),
    }


def _dispersao(valores: list[float]) -> dict[str, float]:
    """Score que não separa é indistinguível de não ter score — e tem que
    aparecer na saída do job, como no score de orçamentos."""
    if not valores:
        return {}
    ordenado = sorted(valores)
    n = len(ordenado)

    def pct(p: float) -> float:
        return round(ordenado[min(n - 1, int(p * n))], 1)

    return {"min": round(ordenado[0], 1), "p25": pct(0.25), "mediana": pct(0.50),
            "p75": pct(0.75), "max": round(ordenado[-1], 1)}


def persist(
    conn: pymysql.connections.Connection,
    company_id: int,
    scores: list[RiscoTitulo],
    job_run_id: int | None = None,
) -> int:
    """Rebaixa o vigente da empresa e grava a leitura nova.

    Rebaixa mesmo com lista vazia: se a empresa quitou tudo, deixar a fila de
    ontem na tela mandaria cobrar quem já pagou.
    """
    execute(
        conn,
        """UPDATE intelligence_receivable_risk SET is_current = 0
            WHERE company_id = %s AND is_current = 1""",
        (company_id,),
    )
    if not scores:
        conn.commit()
        return 0

    linhas = [
        (
            company_id, job_run_id, s.titulo_key[:120], s.codigo_cliente_omie,
            s.nome_cliente[:200], s.forma_pagamento[:64], s.universo,
            round(s.valor_aberto, 2), s.data_vencimento, s.dias_para_vencer,
            round(s.score, 2) if s.score is not None else None,
            s.band,
            json.dumps(s.reasons, ensure_ascii=False),
            json.dumps(s.features, ensure_ascii=False, default=str),
            MODEL_VERSION,
        )
        for s in scores
    ]
    n = execute_many(
        conn,
        """INSERT INTO intelligence_receivable_risk
               (company_id, job_run_id, titulo_key, codigo_cliente_omie, nome_cliente,
                forma_pagamento, universo, valor_aberto, data_vencimento, dias_para_vencer,
                risk_score, risk_band, reasons_json, features_json, model_version,
                is_current, computed_at)
           VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1,UTC_TIMESTAMP())""",
        linhas,
    )
    conn.commit()
    return n
