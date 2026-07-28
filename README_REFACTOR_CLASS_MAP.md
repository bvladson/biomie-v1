# Mapa de Responsabilidades das Classes-Centro

Este documento fecha a etapa de mapeamento da refatoracao das classes grandes do BIomie.
O objetivo aqui nao e contar linhas; e deixar explicito o motivo principal de mudanca de cada modulo.

## CanonicalRepository.php

- `write model`: `upsertVendor`, `upsertOrder`, `replacePendingOrdersWindow`, `replaceInvoicesWindow`, `upsertInvoice`, `upsertFinancialTitle`, `upsertXmlDocument`, `appendCanonicalEvent`
- `read model`: consultas comerciais/fiscais para `overview`, `vendors`, reconciliacao e displays
- `sync state`: [app/Canonical/CanonicalSyncStateRepository.php](c:/Users/Usuario/Downloads/biomie/app/Canonical/CanonicalSyncStateRepository.php:1)
- `divergencias`: [app/Canonical/CanonicalDivergenceRepository.php](c:/Users/Usuario/Downloads/biomie/app/Canonical/CanonicalDivergenceRepository.php:1)
- `reconciliacao`: [app/Canonical/CanonicalReconciliationRepository.php](c:/Users/Usuario/Downloads/biomie/app/Canonical/CanonicalReconciliationRepository.php:1)
- `support`: [app/Canonical/Support/CanonicalJsonCodec.php](c:/Users/Usuario/Downloads/biomie/app/Canonical/Support/CanonicalJsonCodec.php:1) e [app/Canonical/Support/CanonicalHashing.php](c:/Users/Usuario/Downloads/biomie/app/Canonical/Support/CanonicalHashing.php:1)
- papel atual: `CanonicalRepository` permanece como fachada/transicao, enquanto sync state, divergencia e reconciliacao ja vivem em modulos separados

## OmieFiscalSyncService.php

- `sync coordinator`: [app/OmieFiscalSyncService.php](c:/Users/Usuario/Downloads/biomie/app/OmieFiscalSyncService.php:1)
- `flags/status/stats`: [app/FiscalSync/FiscalSupport.php](c:/Users/Usuario/Downloads/biomie/app/FiscalSync/FiscalSupport.php:1)
- `resolucao de vendedor/cliente`: [app/FiscalSync/FiscalPartyResolver.php](c:/Users/Usuario/Downloads/biomie/app/FiscalSync/FiscalPartyResolver.php:1)
- `mapeamento canonico`: [app/FiscalSync/FiscalCanonicalMapper.php](c:/Users/Usuario/Downloads/biomie/app/FiscalSync/FiscalCanonicalMapper.php:1)
- `detector de divergencia`: [app/FiscalSync/FiscalDivergenceDetector.php](c:/Users/Usuario/Downloads/biomie/app/FiscalSync/FiscalDivergenceDetector.php:1)

## CommercialCompetenciaSnapshotService.php

- `coordinator/catalog/persistencia`: [app/CommercialCompetenciaSnapshotService.php](c:/Users/Usuario/Downloads/biomie/app/CommercialCompetenciaSnapshotService.php:1)
- `builders de payload historico`: [app/Competencia/CompetenciaPayloadBuilder.php](c:/Users/Usuario/Downloads/biomie/app/Competencia/CompetenciaPayloadBuilder.php:1)
- `support/meta/serializacao`: [app/Competencia/CompetenciaSupport.php](c:/Users/Usuario/Downloads/biomie/app/Competencia/CompetenciaSupport.php:1)

## SalesAnalyticsService.php

- `coordinator/read snapshot`: [app/SalesAnalyticsService.php](c:/Users/Usuario/Downloads/biomie/app/SalesAnalyticsService.php:1)
- `normalizacao de pedidos/notas`: [app/SalesAnalytics/SalesDatasetNormalizer.php](c:/Users/Usuario/Downloads/biomie/app/SalesAnalytics/SalesDatasetNormalizer.php:1)
- `flags/status/vendor support`: [app/SalesAnalytics/SalesSupport.php](c:/Users/Usuario/Downloads/biomie/app/SalesAnalytics/SalesSupport.php:1)
- `calculo de meta`: [app/SalesAnalytics/SalesTargetCalculator.php](c:/Users/Usuario/Downloads/biomie/app/SalesAnalytics/SalesTargetCalculator.php:1)
- `status blocks/presenter operacional`: [app/SalesAnalytics/SalesStatusBlockBuilder.php](c:/Users/Usuario/Downloads/biomie/app/SalesAnalytics/SalesStatusBlockBuilder.php:1)

## FinanceAnalyticsService.php

- `coordinator/read snapshot`: [app/FinanceAnalyticsService.php](c:/Users/Usuario/Downloads/biomie/app/FinanceAnalyticsService.php:1)
- `normalizacao`: [app/FinanceAnalytics/FinanceNormalizer.php](c:/Users/Usuario/Downloads/biomie/app/FinanceAnalytics/FinanceNormalizer.php:1)
- `resolucao de labels/documento/pagamento`: [app/FinanceAnalytics/FinanceLabelResolver.php](c:/Users/Usuario/Downloads/biomie/app/FinanceAnalytics/FinanceLabelResolver.php:1)
- `trend/forecast/budget`: [app/FinanceAnalytics/FinanceTrendBuilder.php](c:/Users/Usuario/Downloads/biomie/app/FinanceAnalytics/FinanceTrendBuilder.php:1)
- `formatter de linhas`: [app/FinanceAnalytics/FinanceRowFormatter.php](c:/Users/Usuario/Downloads/biomie/app/FinanceAnalytics/FinanceRowFormatter.php:1)

## Criterio de Saida

- as classes-centro permanecem como coordenadoras finas
- regras puras e transformacoes agora vivem em modulos menores por motivo de mudanca
- leitura analitica e escrita canonica continuam com contrato externo preservado
