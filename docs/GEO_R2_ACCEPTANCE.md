# GEO.R2 - Suite de aceitacao estatica

O probe `tools/dev/geo_r2_acceptance_probe.php` valida os contratos planejados
para a evolucao GEO.R2 sem consultar banco, alterar dados ou editar arquivos de
producao.

## Modos de execucao

```bash
php tools/dev/geo_r2_acceptance_probe.php
php tools/dev/geo_r2_acceptance_probe.php --json
php tools/dev/geo_r2_acceptance_probe.php --list
php tools/dev/geo_r2_acceptance_probe.php --strict
```

- `PASS`: o contrato foi confirmado estaticamente.
- `PENDING`: a implementacao ainda esta em transicao. No modo padrao, nao
  bloqueia o trabalho paralelo.
- `ERROR`: o probe nao conseguiu ler fontes obrigatorias ou avaliar o contrato.
- `--strict`: transforma qualquer `PENDING` ou `ERROR` em saida diferente de
  zero, adequada para gate de release.

## Criterios

| ID | Contrato de aceitacao |
|---|---|
| GEO.R2.C01 | Cidade nula nao entra em filtro normal de cidade. |
| GEO.R2.C02 | Bucket `sem_geo` permanece explicito no backend e no frontend. |
| GEO.R2.C03 | Apenas um writer oficial escreve `mart_compradores_por_geo` no pipeline. |
| GEO.R2.C04 | Existe somente um filtro de vendedor na pagina. |
| GEO.R2.C05 | Existe somente uma barra ou superficie de modos do mapa. |
| GEO.R2.C06 | Preset `Este ano` representa janeiro ate hoje. |
| GEO.R2.C07 | Diagnostico tecnico e admin-only ou apresentado como cobertura comercial. |
| GEO.R2.C08 | Defaults de `geo_readmodel_v2` e `geo_single_payload` estao ativos. |
| GEO.R2.C09 | Plugins avancados do mapa sao carregados sob demanda. |
| GEO.R2.C10 | Cobertura geografica usa linguagem comercial para o usuario. |

## Escopo e limites

O probe usa heuristicas estaticas e foi desenhado para acompanhar refatoracoes
durante implementacao paralela. Ele nao substitui probes de banco, testes HTTP,
auditoria visual ou validacao manual de permissao. Antes do release, execute o
modo `--strict` junto aos probes GEO de dados e desempenho existentes.
