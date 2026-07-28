# KPI por página e implantação prática

## Norte para não pesar o projeto

1. Não criar novas chamadas de API só para "embelezar" KPIs já existentes.
2. Reusar `summary`, `summary_status`, `team`, `status_breakdown` e metadados já entregues pelos endpoints atuais.
3. Manter **um KPI principal por página** e deixar os demais como contexto.
4. Em listas densas de NF, pedidos e vendedores, manter **tabela + links de detalhe** em vez de trocar tudo por cards.
5. Carregar mapa por cidade **em página própria e sob demanda**, nunca junto do dashboard principal enquanto a dimensão de cidade não estiver materializada.

## KPI principal recomendado por página

- `index`: **Receita faturada** no período.
- `vendedores`: **Faturado por vendedor / atingimento**.
- `pedidos`: **NF autorizadas** no período.
- `mercadorias`: **Receita do mix faturado**.
- `financeiro`: **Recebido** e, como alerta, **índice de inadimplência**.
- `plano_voo`: **% de meta atingida**.
- `integracoes`: **cobertura/sincronização da leitura principal**.
- `observabilidade`: **divergências ativas / saúde operacional**.
- `reconciliacao`: **percentual de divergência aberta**.

## Cidade / mapa de vendas

### O que existe hoje
A camada canônica atual concentra pedido, nota, item, cliente e vendedor, mas **não materializa cidade/UF do cliente** nas tabelas principais. Sem essa dimensão, qualquer mapa agora ficaria incompleto ou dependeria de parsing caro de payload bruto.

### Implementação enxuta recomendada

#### Fase 1 — pronta para agora
- manter o dashboard leve;
- reforçar drill-down em NF e vendedor;
- definir o contrato do futuro endpoint `api/sales_by_city.php`;
- não carregar biblioteca de mapa no `index`.

#### Fase 2 — quando a cidade entrar na camada canônica
- materializar `cidade_cliente` e `uf_cliente` em pedidos/notas ou em uma dimensão de clientes;
- criar agregação por período com `GROUP BY cidade_cliente, uf_cliente`;
- expor `meta.complete_coverage`, `data[]`, ranking e total.

#### Fase 3 — visualização
- criar página própria `pracas`/`cidades`;
- carregar mapa somente nessa rota;
- usar marcadores/bubbles por cidade e ranking lateral;
- manter fallback para tabela simples quando não houver coordenada.

## Contrato sugerido do endpoint futuro

```json
{
  "ok": true,
  "mode": "canonical",
  "period": { "start": "2026-01-01", "end": "2026-01-31", "label": "01/01/2026 até 31/01/2026" },
  "summary": {
    "cities": 12,
    "revenue": 152340.88,
    "orders": 87,
    "top_city": "Fortaleza/CE"
  },
  "data": [
    {
      "city": "Fortaleza",
      "state": "CE",
      "revenue": 82340.10,
      "orders": 41,
      "clients": 13,
      "latitude": -3.7319,
      "longitude": -38.5267
    }
  ],
  "meta": {
    "complete_coverage": true,
    "reason": null,
    "served_from": "canonical-db"
  }
}
```

## O que foi implementado neste diff

- Links mais visíveis em NF e documentos da tela.
- Atalho de vendedor em linhas de tabela, reaproveitando a página `vendedores` como destino de drill-down.
- Modal leve de detalhe rápido para NF/pedido/item fiscal, sem nova chamada de API.
- Deep link para abrir automaticamente o drawer do vendedor quando vier de outra página.
- Retenção do design mais simplificado de linhas que removeu a poluição empilhada original.

## Próximo diff sugerido

1. materializar cidade/UF na camada canônica;
2. criar `api/sales_by_city.php` com cache;
3. entregar página própria de mapa, carregada sob demanda.
