# BIomie — Contratos de Dados

## Base temporal oficial
- Realizado fiscal: `data_competencia`.
- Carteira: `data_emissao`.
- Meta: `year_month`.

## Contrato mínimo de API
Toda API comercial deve retornar:

```json
{
  "ok": true,
  "source": "canonical|gold|fallback|empty",
  "period": { "start": "YYYY-MM-DD", "end": "YYYY-MM-DD" },
  "period_basis": { "realized": "data_competencia" },
  "summary": {},
  "empty_states": {},
  "diagnostics": { "request_id": "..." }
}
```

## Mix Faturado
Fonte de verdade: `biomie_notas_fiscais_itens_canonicos` com join em `biomie_notas_fiscais_canonicas` por `nota_key`.

## Mapa
Todo endpoint geográfico deve transportar `city`, `city_normalized`, `bairro_key`, `bairro_nome`, `bairro_normalizado`.
