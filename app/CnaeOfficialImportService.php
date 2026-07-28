<?php

declare(strict_types=1);

namespace BIomie;

use BIomie\Support\CnaeCodeNormalizer;
use PDO;
use RuntimeException;
use Throwable;
use ZipArchive;

final class CnaeOfficialImportService
{
    public const DEFAULT_OFFICIAL_URL = 'https://concla.ibge.gov.br/images/concla/documentacao/CNAE_Subclasses_2_3_Estrutura_Detalhada.xlsx';
    public const DEFAULT_VERSION = 'CNAE Subclasses 2.3';

    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{
     *   source?:string|null,
     *   url?:string|null,
     *   apply?:bool,
     *   version?:string|null,
     *   source_label?:string|null,
     * } $options
     * @return array<string,mixed>
     */
    public function sync(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $source = trim((string) ($options['source'] ?? ''));
        $url = trim((string) ($options['url'] ?? ''));
        $version = trim((string) ($options['version'] ?? self::DEFAULT_VERSION));
        $sourceLabel = trim((string) ($options['source_label'] ?? 'CONCLA/IBGE'));

        if ($source === '') {
            $source = $this->defaultLocalSource();
        }
        if (!is_file($source) && $url !== '') {
            $source = $this->downloadSource($url);
        }
        if (!is_file($source)) {
            throw new RuntimeException('Fonte CNAE nao encontrada: ' . $source);
        }

        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            throw new RuntimeException('Falha ao calcular hash da fonte CNAE.');
        }

        $parsed = $this->parseSource($source);
        $rows = $parsed['rows'];
        $warnings = $parsed['warnings'];

        $existing = $this->existingCodes(array_column($rows, 'cnae_codigo'));
        $wouldInsert = 0;
        $wouldUpdate = 0;
        foreach ($rows as $row) {
            $code = (string) $row['cnae_codigo'];
            if (!isset($existing[$code])) {
                $wouldInsert++;
                continue;
            }
            if ($this->rowDiffers($existing[$code], $row, $hash, $version, $sourceLabel)) {
                $wouldUpdate++;
            }
        }

        $runId = null;
        if ($apply && $this->tableExists('biomie_cnae_sync_runs')) {
            $runId = $this->createRun($source, $hash, false, $parsed['rows_read'], count($rows), $parsed['invalid_count'], $warnings);
        }

        $inserted = 0;
        $updated = 0;
        $clientDescriptionsRepaired = 0;
        $martDescriptionsRepaired = 0;

        if ($apply) {
            $this->pdo->beginTransaction();
            try {
                [$inserted, $updated] = $this->upsertRows($rows, $existing, $hash, $version, $sourceLabel);
                $clientDescriptionsRepaired = $this->repairClientDescriptions();
                $martDescriptionsRepaired = $this->repairMartDescriptions();
                $this->pdo->commit();

                if ($runId !== null) {
                    $this->finishRun($runId, 'ok', $inserted, $updated, $warnings);
                }
            } catch (Throwable $exception) {
                $this->pdo->rollBack();
                if ($runId !== null) {
                    $this->finishRun($runId, 'error', $inserted, $updated, array_merge($warnings, [$exception->getMessage()]));
                }
                throw $exception;
            }
        } elseif ($this->tableExists('biomie_cnae_sync_runs')) {
            $dryRunId = $this->createRun($source, $hash, true, $parsed['rows_read'], count($rows), $parsed['invalid_count'], $warnings);
            if ($dryRunId !== null) {
                $runId = $dryRunId;
                $this->finishRun($dryRunId, 'dry_run', 0, 0, $warnings);
            }
        }

        $result = [
            'ok' => true,
            'mode' => $apply ? 'apply' : 'dry_run',
            'source' => [
                'path' => $source,
                'hash' => $hash,
                'version' => $version,
                'label' => $sourceLabel,
                'official_url' => self::DEFAULT_OFFICIAL_URL,
            ],
            'summary' => [
                'rows_read' => $parsed['rows_read'],
                'rows_valid' => count($rows),
                'rows_invalid' => $parsed['invalid_count'],
                'would_insert' => $wouldInsert,
                'would_update' => $wouldUpdate,
                'inserted' => $inserted,
                'updated' => $updated,
                'client_descriptions_repaired' => $clientDescriptionsRepaired,
                'mart_descriptions_repaired' => $martDescriptionsRepaired,
            ],
            'warnings' => array_slice($warnings, 0, 20),
            'run_id' => $runId,
        ];

        $this->logger->info('CNAE official sync finished.', $result['summary'] + [
            'mode' => $result['mode'],
            'source_hash' => $hash,
        ]);

        return $result;
    }

    private function defaultLocalSource(): string
    {
        return Bootstrap::rootPath('storage/sources/cnae/CNAE_Subclasses_2_3_Estrutura_Detalhada.xlsx');
    }

    private function downloadSource(string $url): string
    {
        $dir = Bootstrap::rootPath('storage/sources/cnae');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'cnae_source.xlsx');
        $name = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $name) ?: 'cnae_source.xlsx';
        $path = $dir . '/' . $name;

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Falha ao abrir destino da fonte CNAE: ' . $path);
        }

        $curl = curl_init($url);
        if ($curl === false) {
            fclose($handle);
            throw new RuntimeException('Falha ao iniciar download da fonte CNAE.');
        }

        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_FAILONERROR => true,
            CURLOPT_USERAGENT => 'BIomie-CNAE-R2.3/1.0',
        ]);

        $ok = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($ok !== true || !is_file($path) || filesize($path) === 0) {
            @unlink($path);
            throw new RuntimeException('Falha ao baixar fonte CNAE: ' . ($error !== '' ? $error : $url));
        }

        return $path;
    }

    /**
     * @return array{rows:list<array<string,string>>,rows_read:int,invalid_count:int,warnings:list<string>}
     */
    private function parseSource(string $path): array
    {
        $lower = strtolower($path);
        if (str_ends_with($lower, '.xlsx')) {
            return $this->parseOfficialXlsx($path);
        }
        if (str_ends_with($lower, '.csv')) {
            return $this->parseCsv($path);
        }

        throw new RuntimeException('Formato CNAE nao suportado. Use .xlsx ou .csv.');
    }

    /**
     * @return array{rows:list<array<string,string>>,rows_read:int,invalid_count:int,warnings:list<string>}
     */
    private function parseOfficialXlsx(string $path): array
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('Extensao PHP zip indisponivel para ler XLSX.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Falha ao abrir XLSX CNAE: ' . $path);
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheetXml === false) {
                throw new RuntimeException('Aba principal sheet1.xml nao encontrada no XLSX CNAE.');
            }

            $xml = simplexml_load_string($sheetXml);
            if ($xml === false) {
                throw new RuntimeException('Falha ao interpretar sheet1.xml do XLSX CNAE.');
            }

            $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rowsXml = $xml->xpath('//m:sheetData/m:row') ?: [];

            $context = [
                'secao' => '',
                'secao_descricao' => '',
                'divisao' => '',
                'divisao_descricao' => '',
                'grupo' => '',
                'grupo_descricao' => '',
                'classe' => '',
                'classe_descricao' => '',
            ];
            $rows = [];
            $warnings = [];
            $rowsRead = 0;
            $invalid = 0;

            foreach ($rowsXml as $rowXml) {
                $rowNumber = (int) ((string) ($rowXml['r'] ?? '0'));
                if ($rowNumber < 5) {
                    continue;
                }

                $cells = $this->xlsxRowCells($rowXml, $sharedStrings);
                $a = trim($cells['A'] ?? '');
                $b = trim($cells['B'] ?? '');
                $c = trim($cells['C'] ?? '');
                $d = trim($cells['D'] ?? '');
                $e = trim($cells['E'] ?? '');
                $f = trim($cells['F'] ?? '');

                if ($a === '' && $b === '' && $c === '' && $d === '' && $e === '' && $f === '') {
                    continue;
                }
                $rowsRead++;

                if ($a !== '') {
                    $context['secao'] = $a;
                    $context['secao_descricao'] = $f;
                    continue;
                }
                if ($b !== '') {
                    $context['divisao'] = $b;
                    $context['divisao_descricao'] = $f;
                    continue;
                }
                if ($c !== '') {
                    $context['grupo'] = $c;
                    $context['grupo_descricao'] = $f;
                    continue;
                }
                if ($d !== '') {
                    $context['classe'] = $d;
                    $context['classe_descricao'] = $f;
                    continue;
                }
                if ($e === '') {
                    continue;
                }

                $code = CnaeCodeNormalizer::normalizeSubclass($e);
                if ($code === '') {
                    $invalid++;
                    if (count($warnings) < 20) {
                        $warnings[] = 'Linha ' . $rowNumber . ': subclasse CNAE invalida "' . $e . '".';
                    }
                    continue;
                }

                $description = $f !== '' ? $f : 'CNAE sem descricao oficial';
                $rows[] = [
                    'cnae_codigo' => $code,
                    'cnae_codigo_normalizado' => $code,
                    'cnae_descricao' => $description,
                    'descricao_oficial' => $description,
                    'secao' => $context['secao'],
                    'secao_descricao' => $context['secao_descricao'],
                    'divisao' => $context['divisao'],
                    'divisao_descricao' => $context['divisao_descricao'],
                    'grupo' => $context['grupo'],
                    'grupo_descricao' => $context['grupo_descricao'],
                    'classe' => $context['classe'],
                    'classe_descricao' => $context['classe_descricao'],
                    'subclasse' => CnaeCodeNormalizer::formatSubclass($code),
                    'subclasse_descricao' => $description,
                ];
            }

            return [
                'rows' => $rows,
                'rows_read' => $rowsRead,
                'invalid_count' => $invalid,
                'warnings' => $warnings,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{rows:list<array<string,string>>,rows_read:int,invalid_count:int,warnings:list<string>}
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Falha ao abrir CSV CNAE: ' . $path);
        }

        $delimiter = $this->detectCsvDelimiter($path);
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headers)) {
            fclose($handle);
            throw new RuntimeException('CSV CNAE sem cabecalho.');
        }
        $headers = array_map([$this, 'normalizeHeader'], $headers);

        $rows = [];
        $warnings = [];
        $rowsRead = 0;
        $invalid = 0;

        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rowsRead++;
            $record = [];
            foreach ($headers as $idx => $header) {
                $record[$header] = trim((string) ($line[$idx] ?? ''));
            }

            $rawCode = $record['cnae_codigo'] ?? $record['codigo'] ?? $record['subclasse'] ?? '';
            $code = CnaeCodeNormalizer::normalizeSubclass($rawCode);
            if ($code === '') {
                $invalid++;
                if (count($warnings) < 20) {
                    $warnings[] = 'CSV linha ' . ($rowsRead + 1) . ': CNAE invalido "' . $rawCode . '".';
                }
                continue;
            }

            $description = $record['descricao_oficial']
                ?? $record['subclasse_descricao']
                ?? $record['cnae_descricao']
                ?? $record['descricao']
                ?? '';
            $description = trim((string) $description);

            $rows[] = [
                'cnae_codigo' => $code,
                'cnae_codigo_normalizado' => $code,
                'cnae_descricao' => $description !== '' ? $description : 'CNAE sem descricao oficial',
                'descricao_oficial' => $description !== '' ? $description : 'CNAE sem descricao oficial',
                'secao' => $record['secao'] ?? '',
                'secao_descricao' => $record['secao_descricao'] ?? '',
                'divisao' => $record['divisao'] ?? CnaeCodeNormalizer::divisionFromSubclass($code),
                'divisao_descricao' => $record['divisao_descricao'] ?? '',
                'grupo' => $record['grupo'] ?? CnaeCodeNormalizer::groupFromSubclass($code),
                'grupo_descricao' => $record['grupo_descricao'] ?? '',
                'classe' => $record['classe'] ?? CnaeCodeNormalizer::formatClasse(CnaeCodeNormalizer::classFromSubclass($code)),
                'classe_descricao' => $record['classe_descricao'] ?? '',
                'subclasse' => ($record['subclasse'] ?? '') !== '' ? $record['subclasse'] : CnaeCodeNormalizer::formatSubclass($code),
                'subclasse_descricao' => $record['subclasse_descricao'] ?? ($description !== '' ? $description : 'CNAE sem descricao oficial'),
            ];
        }

        fclose($handle);

        return [
            'rows' => $rows,
            'rows_read' => $rowsRead,
            'invalid_count' => $invalid,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xmlString = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlString === false) {
            return [];
        }

        $xml = simplexml_load_string($xmlString);
        if ($xml === false) {
            return [];
        }

        $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        foreach (($xml->xpath('//m:si') ?: []) as $si) {
            $si->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = [];
            foreach (($si->xpath('.//m:t') ?: []) as $text) {
                $parts[] = (string) $text;
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /**
     * @param list<string> $sharedStrings
     * @return array<string,string>
     */
    private function xlsxRowCells(\SimpleXMLElement $rowXml, array $sharedStrings): array
    {
        $rowXml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = [];
        foreach (($rowXml->xpath('m:c') ?: []) as $cell) {
            $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $ref = (string) ($cell['r'] ?? '');
            if ($ref === '') {
                continue;
            }
            $column = preg_replace('/\d+/', '', $ref) ?? '';
            if ($column === '') {
                continue;
            }

            $value = '';
            $v = $cell->xpath('m:v');
            if ($v !== false && isset($v[0])) {
                $value = (string) $v[0];
            }
            if ((string) ($cell['t'] ?? '') === 's' && $value !== '') {
                $idx = (int) $value;
                $value = $sharedStrings[$idx] ?? '';
            }

            $cells[$column] = $value;
        }

        return $cells;
    }

    private function detectCsvDelimiter(string $path): string
    {
        $line = (string) (file($path, FILE_IGNORE_NEW_LINES)[0] ?? '');
        return substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header));
        $header = strtr($header, [
            'ã' => 'a', 'á' => 'a', 'à' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
        return trim($header, '_');
    }

    /**
     * @param list<string> $codes
     * @return array<string,array<string,mixed>>
     */
    private function existingCodes(array $codes): array
    {
        if ($codes === [] || !$this->tableExists('biomie_cnae_dim')) {
            return [];
        }

        $existing = [];
        $chunks = array_chunk(array_values(array_unique($codes)), 500);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT * FROM biomie_cnae_dim WHERE cnae_codigo IN ({$placeholders})"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existing[(string) $row['cnae_codigo']] = $row;
            }
        }

        return $existing;
    }

    /**
     * @param array<string,mixed> $existing
     * @param array<string,string> $row
     */
    private function rowDiffers(array $existing, array $row, string $hash, string $version, string $sourceLabel): bool
    {
        $fields = [
            'cnae_codigo_normalizado',
            'cnae_descricao',
            'descricao_oficial',
            'secao',
            'secao_descricao',
            'divisao',
            'divisao_descricao',
            'grupo',
            'grupo_descricao',
            'classe',
            'classe_descricao',
            'subclasse',
            'subclasse_descricao',
        ];

        foreach ($fields as $field) {
            if (trim((string) ($existing[$field] ?? '')) !== trim((string) ($row[$field] ?? ''))) {
                return true;
            }
        }

        return trim((string) ($existing['versao'] ?? '')) !== $version
            || trim((string) ($existing['fonte'] ?? '')) !== $sourceLabel
            || trim((string) ($existing['hash_fonte'] ?? '')) !== $hash;
    }

    /**
     * @param list<array<string,string>> $rows
     * @param array<string,array<string,mixed>> $existing
     * @return array{0:int,1:int}
     */
    private function upsertRows(array $rows, array $existing, string $hash, string $version, string $sourceLabel): array
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO biomie_cnae_dim (
                cnae_codigo, cnae_codigo_normalizado, cnae_descricao, descricao_oficial,
                secao, secao_descricao, divisao, divisao_descricao, grupo, grupo_descricao,
                classe, classe_descricao, subclasse, subclasse_descricao,
                origem, versao, fonte, hash_fonte, is_active, created_at, updated_at
             ) VALUES (
                :cnae_codigo, :cnae_codigo_normalizado, :cnae_descricao, :descricao_oficial,
                :secao, :secao_descricao, :divisao, :divisao_descricao, :grupo, :grupo_descricao,
                :classe, :classe_descricao, :subclasse, :subclasse_descricao,
                'concla_ibge', :versao, :fonte, :hash_fonte, 1, NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                cnae_codigo_normalizado = VALUES(cnae_codigo_normalizado),
                cnae_descricao = VALUES(cnae_descricao),
                descricao_oficial = VALUES(descricao_oficial),
                secao = VALUES(secao),
                secao_descricao = VALUES(secao_descricao),
                divisao = VALUES(divisao),
                divisao_descricao = VALUES(divisao_descricao),
                grupo = VALUES(grupo),
                grupo_descricao = VALUES(grupo_descricao),
                classe = VALUES(classe),
                classe_descricao = VALUES(classe_descricao),
                subclasse = VALUES(subclasse),
                subclasse_descricao = VALUES(subclasse_descricao),
                origem = VALUES(origem),
                versao = VALUES(versao),
                fonte = VALUES(fonte),
                hash_fonte = VALUES(hash_fonte),
                is_active = 1,
                updated_at = NOW()"
        );

        $inserted = 0;
        $updated = 0;
        foreach ($rows as $row) {
            $code = $row['cnae_codigo'];
            if (!isset($existing[$code])) {
                $inserted++;
            } elseif ($this->rowDiffers($existing[$code], $row, $hash, $version, $sourceLabel)) {
                $updated++;
            }

            $params = $row + [
                'versao' => $version,
                'fonte' => $sourceLabel,
                'hash_fonte' => $hash,
            ];
            $stmt->execute($params);
        }

        return [$inserted, $updated];
    }

    private function repairClientDescriptions(): int
    {
        if (!$this->tableExists('biomie_clientes_dim') || !$this->columnExists('biomie_clientes_dim', 'cnae_descricao')) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE biomie_clientes_dim AS d
             JOIN biomie_cnae_dim AS c
               ON c.cnae_codigo_normalizado = REGEXP_REPLACE(COALESCE(d.cnae_codigo, ''), '[^0-9]', '')
             SET d.cnae_descricao = c.cnae_descricao,
                 d.cnae_source = IF(d.cnae_source IS NULL OR d.cnae_source = '', COALESCE(NULLIF(d.enrichment_source, ''), 'clientes_dim_legacy'), d.cnae_source),
                 d.cnae_confidence = IF(d.cnae_confidence = 0, 70, d.cnae_confidence),
                 d.cnae_captured_at = IF(d.cnae_captured_at IS NULL, COALESCE(d.enriched_at, d.updated_at, NOW()), d.cnae_captured_at),
                 d.cnae_unknown_reason = NULL
             WHERE d.cnae_codigo IS NOT NULL
               AND d.cnae_codigo != ''
               AND (d.cnae_descricao IS NULL
                    OR TRIM(d.cnae_descricao) = ''
                    OR LOWER(TRIM(d.cnae_descricao)) IN ('sem cnae informado', 'cnae nao informado', 'cnae não informado'))"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function repairMartDescriptions(): int
    {
        if (!$this->tableExists('mart_vendas_por_cnae_cnpj') || !$this->columnExists('mart_vendas_por_cnae_cnpj', 'cnae_descricao')) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE mart_vendas_por_cnae_cnpj AS m
             JOIN biomie_cnae_dim AS c
               ON c.cnae_codigo_normalizado = REGEXP_REPLACE(COALESCE(m.cnae_codigo, ''), '[^0-9]', '')
             SET m.cnae_descricao = c.cnae_descricao,
                 m.updated_at = IF(m.updated_at IS NULL, NOW(), m.updated_at)
             WHERE m.cnae_codigo IS NOT NULL
               AND m.cnae_codigo != ''
               AND (m.cnae_descricao IS NULL
                    OR TRIM(m.cnae_descricao) = ''
                    OR LOWER(TRIM(m.cnae_descricao)) IN ('sem cnae informado', 'cnae nao informado', 'cnae não informado'))"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @param list<string> $warnings
     */
    private function createRun(string $source, string $hash, bool $dryRun, int $rowsRead, int $rowsValid, int $rowsInvalid, array $warnings): ?int
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO biomie_cnae_sync_runs (
                    source_type, source_uri, source_hash, dry_run, status,
                    rows_read, rows_valid, rows_invalid, warnings_json, started_at
                 ) VALUES (
                    :source_type, :source_uri, :source_hash, :dry_run, 'started',
                    :rows_read, :rows_valid, :rows_invalid, :warnings, NOW()
                 )"
            );
            $stmt->execute([
                'source_type' => strtolower(pathinfo($source, PATHINFO_EXTENSION)) ?: 'file',
                'source_uri' => $source,
                'source_hash' => $hash,
                'dry_run' => $dryRun ? 1 : 0,
                'rows_read' => $rowsRead,
                'rows_valid' => $rowsValid,
                'rows_invalid' => $rowsInvalid,
                'warnings' => json_encode(array_slice($warnings, 0, 50), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return (int) $this->pdo->lastInsertId();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $warnings
     */
    private function finishRun(int $runId, string $status, int $inserted, int $updated, array $warnings): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE biomie_cnae_sync_runs
                 SET status = :status,
                     rows_inserted = :inserted,
                     rows_updated = :updated,
                     warnings_json = :warnings,
                     finished_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                'status' => $status,
                'inserted' => $inserted,
                'updated' => $updated,
                'warnings' => json_encode(array_slice($warnings, 0, 50), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => $runId,
            ]);
        } catch (Throwable) {
            // Best effort only.
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
