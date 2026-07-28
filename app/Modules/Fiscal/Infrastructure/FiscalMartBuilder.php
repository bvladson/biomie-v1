<?php

declare(strict_types=1);

namespace BIomie\Modules\Fiscal\Infrastructure;

use BIomie\Support\TenantContext;
use PDO;

/**
 * Materializa mart_fiscal_items a partir do bronze ListarNF.
 *
 * Cada linha = 1 item de NF. Enriquece com contexto canonical (vendedor,
 * cliente, status) e dados fiscais do bronze (NCM, CFOP, impostos, CBS/IBS).
 *
 * Chamada via api/fiscal_build_mart.php ou CLI.
 */
final class FiscalMartBuilder
{
    private const TABLE  = 'mart_fiscal_items';
    private const BATCH  = 400;
    private const METHOD = 'ListarNF';

    public function __construct(private readonly PDO $pdo) {}

    public function build(): array
    {
        $companyId = TenantContext::companyId();
        if ($companyId <= 0) {
            throw new \RuntimeException('Selecione uma empresa antes de materializar o mart fiscal.');
        }

        $t0 = microtime(true);
        $this->ensureTable();

        // ── Canonical lookup: nota_id_omie → contexto NF ────────────────
        $canonical = [];
        $rows = $this->pdo->query(
            "SELECT n.company_id, n.nota_id_omie, n.nota_key, n.numero_nf, n.data_emissao, n.data_competencia,
                    n.status_fiscal, n.codigo_vendedor_omie, n.nome_vendedor,
                    n.codigo_cliente_omie, n.nome_cliente, n.documento_cliente,
                    d.uf AS destination_uf, d.cidade AS destination_city
             FROM biomie_notas_fiscais_canonicas n
             LEFT JOIN biomie_clientes_dim d
               ON d.codigo_cliente_omie = n.codigo_cliente_omie
              AND d.company_id = n.company_id
             WHERE n.company_id = {$companyId}
               AND n.nota_id_omie IS NOT NULL"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $canonical[(int) $r['nota_id_omie']] = $r;
        }
        unset($rows);

        // ── Pass 1: Stream bronze into memory (no DB writes) ───────────
        // We collect all mart rows first, then close the cursor before
        // TRUNCATE/INSERT to avoid "unbuffered query active" PDO errors.
        $stmt = $this->pdo->query(
            "SELECT payload_json
             FROM biomie_omie_bronze
             WHERE company_id = {$companyId}
               AND omie_method = '" . self::METHOD . "'
             ORDER BY ingested_at DESC"
        );

        $seen    = [];
        $allRows = [];
        $snapshots = [];
        $skipped = 0;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $raw = $row['payload_json'];

            if (!preg_match('/"nIdNF"\s*:\s*(\d+)/', $raw, $m)) {
                $skipped++;
                continue;
            }
            $nfId = (int) $m[1];

            if (isset($seen[$nfId])) {
                $skipped++;
                continue;
            }
            $seen[$nfId] = true;

            if (!isset($canonical[$nfId])) {
                $skipped++;
                continue;
            }
            $can = $canonical[$nfId];

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                $skipped++;
                continue;
            }

            $det = $payload['det'] ?? [];
            $ide = $payload['ide'] ?? [];

            $dataEmissao = $this->parseDate($ide['dEmi'] ?? null)
                        ?? $can['data_emissao'];
            $competencia = $can['data_competencia']
                ? substr((string) $can['data_competencia'], 0, 7)
                : ($dataEmissao ? substr($dataEmissao, 0, 7) : null);

            foreach ($det as $seq => $item) {
                $prod = $item['prod'] ?? [];
                $allRows[] = $this->buildItemRow($can, $prod, $ide, (int) $seq, $dataEmissao, $competencia);
            }
            $snapshots[] = $this->buildDocumentSnapshot($can, $payload, $ide);
        }
        $stmt = null; // explicitly release cursor before DDL

        // ── Pass 2: TRUNCATE + batch INSERT (no open cursor) ───────────
        $delete = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE company_id = :company_id');
        $delete->execute(['company_id' => $companyId]);

        $total  = 0;
        $buffer = [];
        foreach ($allRows as $item) {
            $buffer[] = $item;
            if (count($buffer) >= self::BATCH) {
                $total += $this->flush($buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            $total += $this->flush($buffer);
        }
        $this->replaceSnapshots($snapshots);
        $this->refreshMonthlySettlement();
        unset($allRows);

        $this->updateIndexStats();

        return [
            'company_id'   => $companyId,
            'ok'           => true,
            'inserted'     => $total,
            'skipped'      => $skipped,
            'canonical_nfs'=> count($canonical),
            'elapsed_ms'   => round((microtime(true) - $t0) * 1000),
        ];
    }

    private function buildItemRow(
        array  $can,
        array  $prod,
        array  $ide,
        int    $seq,
        ?string $dataEmissao,
        ?string $competencia
    ): array {
        $vProd     = (float) ($prod['vProd']    ?? 0);
        $vDesc     = (float) ($prod['vDesc']    ?? 0);
        $vTotItem  = (float) ($prod['vTotItem'] ?? ($vProd - $vDesc));

        return [
            'company_id'          => (int) $can['company_id'],
            'nota_key'            => $can['nota_key'],
            'nota_id_omie'        => (int) ($can['nota_id_omie'] ?? 0) ?: null,
            'numero_nf'           => (string) $can['numero_nf'],
            'document_model'      => $this->documentModel((string) ($ide['mod'] ?? '55')),
            'direction'           => ((string) ($ide['tpNF'] ?? '1')) === '0' ? 'entrada' : 'saida',
            'competencia'         => $competencia,
            'data_emissao'        => $dataEmissao,
            'status_fiscal'       => $can['status_fiscal'],
            'codigo_vendedor_omie'=> (string) $can['codigo_vendedor_omie'],
            'nome_vendedor'       => (string) $can['nome_vendedor'],
            'codigo_cliente_omie' => $can['codigo_cliente_omie'] ? (int) $can['codigo_cliente_omie'] : null,
            'nome_cliente'        => (string) $can['nome_cliente'],
            'documento_cliente'   => $can['documento_cliente'] ?? null,
            'destination_uf'      => $can['destination_uf'] ?? null,
            'destination_city'    => $can['destination_city'] ?? null,
            'destination_city_ibge'=> $this->cityIbge($can['destination_uf'] ?? null, $can['destination_city'] ?? null),
            'sequencia_item'      => $seq,
            'codigo_produto'      => isset($prod['cProd']) && $prod['cProd'] !== '' ? (string) $prod['cProd'] : null,
            'descricao_produto'   => isset($prod['xProd']) && $prod['xProd'] !== '' ? (string) $prod['xProd'] : null,
            'ncm'                 => isset($prod['NCM'])   && $prod['NCM']   !== '' ? (string) $prod['NCM']   : null,
            'cfop'                => isset($prod['CFOP'])  && $prod['CFOP']  !== '' ? (string) $prod['CFOP']  : null,
            'origem_produto'      => isset($prod['cOrigem']) ? (string) $prod['cOrigem'] : null,
            'cst_icms'            => $this->firstString($prod, ['CST', 'cstICMS', 'CSOSN']),
            'cst_pis'             => $this->firstString($prod, ['CSTPIS', 'cstPIS']),
            'cst_cofins'          => $this->firstString($prod, ['CSTCOFINS', 'cstCOFINS']),
            'quantidade'          => (float) ($prod['qCom']   ?? 0),
            'valor_produto'       => $vProd,
            'valor_desconto'      => $vDesc,
            'valor_total_item'    => $vTotItem,
            'aliq_icms'           => (float) ($prod['pICMS']   ?? 0),
            'aliq_pis'            => (float) ($prod['pPIS']    ?? 0),
            'aliq_cofins'         => (float) ($prod['pCOFINS'] ?? 0),
            'aliq_ipi'            => (float) ($prod['pIPI']    ?? 0),
            'base_icms'           => (float) ($prod['vBC']       ?? 0),
            'base_pis'            => (float) ($prod['vBCPIS']    ?? 0),
            'base_cofins'         => (float) ($prod['vBCCOFINS'] ?? 0),
            'base_ipi'            => (float) ($prod['vBCIPI']    ?? 0),
            'valor_icms'          => (float) ($prod['vICMS']   ?? 0),
            'valor_icms_st'       => (float) ($prod['vICMSST'] ?? ($prod['vST'] ?? 0)),
            'valor_difal_destino' => (float) ($prod['vICMDest'] ?? 0),
            'valor_difal_origem'  => (float) ($prod['vICMRemet'] ?? 0),
            'valor_fecop'         => (float) ($prod['vFCP'] ?? ($prod['vFCPUFDest'] ?? 0)),
            'valor_pis'           => (float) ($prod['vPIS']    ?? 0),
            'valor_cofins'        => (float) ($prod['vCOFINS'] ?? 0),
            'valor_ipi'           => (float) ($prod['vIPI']    ?? 0),
            'valor_iss'           => (float) ($prod['vISS']    ?? 0),
            'aliq_cbs'            => (float) ($prod['pAliqCbs']    ?? 0),
            'aliq_ibs'            => (float) ($prod['pAliqIBSUf']  ?? 0),
            'base_cbs_ibs'        => (float) ($prod['vBCIbsCbs']   ?? 0),
            'valor_cbs'           => (float) ($prod['vCbs']         ?? 0),
            'valor_ibs'           => (float) ($prod['vIBS']    ?? ($prod['vIbsUf'] ?? 0)),
            'valor_ibs_uf'        => (float) ($prod['vIbsUf'] ?? ($prod['vIBS'] ?? 0)),
            'valor_ibs_municipio' => (float) ($prod['vIbsMun'] ?? 0),
        ];
    }

    private function buildDocumentSnapshot(array $can, array $payload, array $ide): array
    {
        $total = (array) ($payload['total'] ?? []);
        $icms = (array) ($total['ICMSTot'] ?? []);
        $iss = (array) ($total['ISSQNtot'] ?? []);
        $ret = (array) ($total['retTrib'] ?? []);
        $cbs = (array) ($total['CBSTot'] ?? []);
        $ibs = (array) ($total['IBSTot'] ?? []);

        $pisRet = (float) ($ret['vRetPIS'] ?? 0);
        $cofinsRet = (float) ($ret['vRetCOFINS'] ?? 0);
        $csllRet = (float) ($ret['vRetCSLL'] ?? 0);

        return [
            'company_id' => (int) $can['company_id'],
            'nota_key' => $can['nota_key'],
            'source_system' => 'omie',
            'document_model' => $this->documentModel((string) ($ide['mod'] ?? '55')),
            'direction' => ((string) ($ide['tpNF'] ?? '1')) === '0' ? 'entrada' : 'saida',
            'origin_uf' => 'CE',
            'origin_city_ibge' => '2304400',
            'destination_uf' => $can['destination_uf'] ?? null,
            'destination_city_ibge' => $this->cityIbge($can['destination_uf'] ?? null, $can['destination_city'] ?? null),
            'valor_servicos' => (float) ($iss['vServ'] ?? 0),
            'valor_icms_st' => (float) ($icms['vST'] ?? 0),
            'valor_difal_destino' => $this->sumProductField((array) ($payload['det'] ?? []), 'vICMDest'),
            'valor_difal_origem' => $this->sumProductField((array) ($payload['det'] ?? []), 'vICMRemet'),
            'valor_fecop' => $this->sumProductField((array) ($payload['det'] ?? []), 'vFCP'),
            'valor_iss' => (float) ($iss['vISS'] ?? 0),
            'valor_iss_retido' => $this->sumRetainedFlag((array) ($payload['det'] ?? []), 'cISSRetido', 'vISS'),
            'valor_pis_retido' => $pisRet,
            'valor_cofins_retido' => $cofinsRet,
            'valor_csll_retido' => $csllRet,
            'valor_irrf' => (float) ($ret['vIRRF'] ?? 0),
            'valor_csrf' => $pisRet + $cofinsRet + $csllRet,
            'base_cbs' => (float) ($cbs['vCBCbs'] ?? 0),
            'valor_cbs' => (float) ($cbs['vCbs'] ?? 0),
            'base_ibs' => (float) ($ibs['vBCIbs '] ?? ($ibs['vBCIbs'] ?? 0)),
            'valor_ibs_uf' => (float) ($ibs['vIbsUf'] ?? 0),
            'valor_ibs_municipio' => (float) ($ibs['vIbsMun'] ?? 0),
            'is_informative' => 1,
            'source_hash' => hash('sha256', json_encode($total, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
        ];
    }

    private function replaceSnapshots(array $rows): void
    {
        if ($rows === []) return;
        $delete = $this->pdo->prepare(
            'DELETE FROM fiscal_document_tax_snapshot WHERE company_id = :company_id'
        );
        $delete->execute(['company_id' => TenantContext::companyId()]);
        $cols = array_keys($rows[0]);
        $sql = 'INSERT INTO fiscal_document_tax_snapshot (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        $stmt = $this->pdo->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
        }
    }

    private function refreshMonthlySettlement(): void
    {
        (new FiscalSettlementRefresher($this->pdo))->refresh();
    }

    private function documentModel(string $model): string
    {
        return match (trim($model)) {
            '65' => 'nfce',
            '57' => 'cte',
            default => 'nfe',
        };
    }

    private function cityIbge(mixed $uf, mixed $city): ?string
    {
        $normalized = mb_strtolower(trim((string) $city));
        return strtoupper(trim((string) $uf)) === 'CE' && $normalized === 'fortaleza' ? '2304400' : null;
    }

    private function firstString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return null;
    }

    private function sumProductField(array $items, string $field): float
    {
        $sum = 0.0;
        foreach ($items as $item) $sum += (float) (($item['prod'][$field] ?? 0));
        return $sum;
    }

    private function sumRetainedFlag(array $items, string $flag, string $value): float
    {
        $sum = 0.0;
        foreach ($items as $item) {
            $prod = (array) ($item['prod'] ?? []);
            if (strtoupper(trim((string) ($prod[$flag] ?? ''))) === 'S') $sum += (float) ($prod[$value] ?? 0);
        }
        return $sum;
    }

    private function flush(array $buffer): int
    {
        if ($buffer === []) {
            return 0;
        }

        $cols = array_keys($buffer[0]);
        $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $allPlaceholders = implode(',', array_fill(0, count($buffer), $placeholders));

        $sql = 'INSERT IGNORE INTO ' . self::TABLE . ' (`' . implode('`,`', $cols) . '`) VALUES ' . $allPlaceholders;

        $values = [];
        foreach ($buffer as $row) {
            foreach ($row as $v) {
                $values[] = $v;
            }
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        return $stmt->rowCount();
    }

    private function updateIndexStats(): void
    {
        try {
            $this->pdo->exec('ANALYZE TABLE ' . self::TABLE);
        } catch (\Throwable) {}
    }

    private function parseDate(?string $brDate): ?string
    {
        if ($brDate === null || $brDate === '') {
            return null;
        }
        // "dd/mm/yyyy" → "yyyy-mm-dd"
        $parts = explode('/', $brDate);
        if (count($parts) === 3) {
            return $parts[2] . '-' . $parts[1] . '-' . $parts[0];
        }
        return null;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS `mart_fiscal_items` (
                `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `company_id`           TINYINT UNSIGNED NOT NULL,
                `nota_key`             VARCHAR(80)  NOT NULL DEFAULT '',
                `nota_id_omie`         BIGINT,
                `numero_nf`            VARCHAR(32)  NOT NULL DEFAULT '',
                `document_model`       VARCHAR(12)  NOT NULL DEFAULT 'nfe',
                `direction`            ENUM('saida','entrada') NOT NULL DEFAULT 'saida',
                `competencia`          CHAR(7),
                `data_emissao`         DATE,
                `status_fiscal`        ENUM('autorizada','cancelada','nao_autorizada') NOT NULL DEFAULT 'nao_autorizada',
                `codigo_vendedor_omie` VARCHAR(32)  NOT NULL DEFAULT '',
                `nome_vendedor`        VARCHAR(120) NOT NULL DEFAULT 'Sem vendedor',
                `codigo_cliente_omie`  BIGINT,
                `nome_cliente`         VARCHAR(200) NOT NULL DEFAULT 'Cliente nao informado',
                `documento_cliente`    VARCHAR(20),
                `destination_uf`       CHAR(2),
                `destination_city`     VARCHAR(120),
                `destination_city_ibge` CHAR(7),
                `sequencia_item`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `codigo_produto`       VARCHAR(60),
                `descricao_produto`    VARCHAR(255),
                `ncm`                  VARCHAR(10),
                `cfop`                 VARCHAR(10),
                `origem_produto`       VARCHAR(4),
                `cst_icms`             VARCHAR(8),
                `cst_pis`              VARCHAR(8),
                `cst_cofins`           VARCHAR(8),
                `quantidade`           DECIMAL(15,4) NOT NULL DEFAULT 0,
                `valor_produto`        DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_desconto`       DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_total_item`     DECIMAL(14,2) NOT NULL DEFAULT 0,
                `aliq_icms`            DECIMAL(8,4)  NOT NULL DEFAULT 0,
                `aliq_pis`             DECIMAL(8,4)  NOT NULL DEFAULT 0,
                `aliq_cofins`          DECIMAL(8,4)  NOT NULL DEFAULT 0,
                `aliq_ipi`             DECIMAL(8,4)  NOT NULL DEFAULT 0,
                `base_icms`            DECIMAL(14,2) NOT NULL DEFAULT 0,
                `base_pis`             DECIMAL(14,2) NOT NULL DEFAULT 0,
                `base_cofins`          DECIMAL(14,2) NOT NULL DEFAULT 0,
                `base_ipi`             DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_icms`           DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_icms_st`        DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_difal_destino`  DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_difal_origem`   DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_fecop`          DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_pis`            DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_cofins`         DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_ipi`            DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_iss`            DECIMAL(14,2) NOT NULL DEFAULT 0,
                `aliq_cbs`             DECIMAL(8,4)  NOT NULL DEFAULT 0,
                `aliq_ibs`             DECIMAL(8,4)  NOT NULL DEFAULT 0,
                `base_cbs_ibs`         DECIMAL(14,2) NOT NULL DEFAULT 0,
                `valor_cbs`            DECIMAL(14,4) NOT NULL DEFAULT 0,
                `valor_ibs`            DECIMAL(14,4) NOT NULL DEFAULT 0,
                `valor_ibs_uf`         DECIMAL(14,4) NOT NULL DEFAULT 0,
                `valor_ibs_municipio`  DECIMAL(14,4) NOT NULL DEFAULT 0,
                `valor_trib_total`     DECIMAL(14,2) GENERATED ALWAYS AS (
                                           `valor_icms`+`valor_pis`+`valor_cofins`+`valor_ipi`+`valor_iss`
                                       ) STORED,
                `built_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uk_company_nota_item` (`company_id`, `nota_key`, `sequencia_item`),
                INDEX `idx_company` (`company_id`),
                INDEX `idx_competencia` (`competencia`),
                INDEX `idx_data_emissao` (`data_emissao`),
                INDEX `idx_ncm` (`ncm`),
                INDEX `idx_cfop` (`cfop`),
                INDEX `idx_vendedor` (`codigo_vendedor_omie`),
                INDEX `idx_status` (`status_fiscal`),
                INDEX `idx_nota_key` (`nota_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
