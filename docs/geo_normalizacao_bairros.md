# Normalizacao de bairros de Fortaleza

## Objetivo

A normalizacao geografica cria uma chave unica para comparar bairros entre GeoJSON, APIs, mart comercial e dimensao oficial. O nome exibido para o usuario continua sendo o nome bonito/original; a chave normalizada serve apenas para match tecnico.

## Nome exibido x chave normalizada

- Exibicao: `Sao Joao do Tauape`, `Jose Bonifacio`, `Boa Vista/Castelao`.
- Chave interna: `sao joao do tauape`, `jose bonifacio`, `boa vista castelao`.

A interface comercial deve preferir o nome original/oficial. A chave pode aparecer no diagnostico tecnico e nas auditorias.

## Regra oficial

O normalizador oficial fica em `app/Support/NeighborhoodNormalizer.php`; o equivalente do mapa fica em `assets/js/geo-normalizer.js`.

A regra aplicada e:

1. Valor nulo vira string vazia.
2. Conversao segura para string.
3. `trim`.
4. Normalizacao Unicode quando disponivel.
5. Remocao de acentos/diacriticos.
6. Caixa baixa.
7. Pontuacao, barras, hifens, underscores e simbolos viram espaco.
8. Espacos duplicados sao colapsados.
9. `trim` final.
10. Aplicacao de alias controlado.

Exemplos:

- `Sao Joao do Tauape` => `sao joao do tauape`
- `São João do Tauape` => `sao joao do tauape`
- `Boa Vista/Castelão` => `boa vista castelao`
- `Boa Vista - Castelão` => `boa vista castelao`
- `José Bonifácio` => `jose bonifacio`
- `Prefeito José Walter` => `prefeito jose walter`

## Aliases

Aliases oficiais ficam em `app/Support/NeighborhoodAliases.php` e sao espelhados em `assets/js/geo-normalizer.js`.

Cada alias tem:

- `alias_key`
- `canonical_key`
- `motivo`
- `origem`
- `ativo`

Use aliases apenas quando houver seguranca humana de que duas grafias representam o mesmo bairro. Nao use alias para fuzzy matching automatico em producao.

## Auditorias

Validar paridade PHP:

```bash
php tools/dev/geo_normalization_audit.php
php tools/dev/geo_normalization_audit.php --json
```

Validar paridade JS, quando Node estiver disponivel:

```bash
node tools/dev/geo_normalization_audit_js.mjs
```

Auditar GeoJSON x API equivalente x dimensao x mart:

```bash
php tools/dev/geo_map_match_audit.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza
php tools/dev/geo_map_match_audit.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza --json
```

Gerar relatorio de qualidade geografica:

```bash
php tools/dev/geo_quality_report.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza
php tools/dev/geo_quality_report.php --start=2026-05-01 --end=2026-05-31 --city=Fortaleza --json
```

As ferramentas funcionam parcialmente sem banco: nesse caso, auditam o GeoJSON e indicam que mart/dimensao/API equivalente ficaram indisponiveis.

## Interpretando match_rate

`match_rate_percent` mede quantos bairros retornados pela API equivalente tambem existem no GeoJSON apos normalizacao e aliases. Um match baixo indica uma destas causas:

- bairro vazio no mart;
- grafia nao coberta pela normalizacao;
- alias necessario, mas ainda nao aprovado;
- dimensao oficial divergente do GeoJSON;
- materializacao geografica incompleta.

## Diagnostico no mapa

O painel tecnico do mapa mostra:

- total de features do GeoJSON;
- propriedade detectada (`Nome`);
- features sem nome;
- total de bairros da API;
- total e percentual de match;
- API sem match no GeoJSON;
- GeoJSON sem dados da API;
- aliases aplicados;
- exemplos com nome original e chave normalizada;
- classificacao simples de confianca: `exact`, `normalized`, `alias`, `missing`, `empty`, `ambiguous`.

## Como adicionar alias com seguranca

1. Rode `geo_map_match_audit.php` e `geo_quality_report.php`.
2. Confira se o bairro sugerido existe no GeoJSON/dimensao oficial.
3. Valide manualmente com a equipe se a grafia representa o mesmo bairro.
4. Adicione o alias em PHP e JS.
5. Adicione um caso em `tools/dev/geo_normalization_cases.json`.
6. Rode auditorias PHP/JS e `php tools/dev/lint_all.php`.

## Pendencias para Fase 3

- Avaliar coluna `nome_key` ou padronizar `bairro_nome_normalizado` como chave oficial indexada na dimensao.
- Popular/atualizar chaves normalizadas com `NeighborhoodNormalizer`.
- Auditar origem Omie/clientes para bairros vazios.
- Criar fluxo administrativo pequeno para revisar `correcoes_sugeridas`.
- Nao alterar collation automaticamente; apenas diagnosticar e planejar migracao se necessario.
