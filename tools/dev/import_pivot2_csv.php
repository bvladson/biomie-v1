<?php

/**
 * Importador CLI para pivot2_faturamento_normalizado.csv → omie_pivot2_faturamento_staging.
 *
 * Uso:
 *   php tools/dev/import_pivot2_csv.php --file=pivot2_faturamento_normalizado.csv
 *   php tools/dev/import_pivot2_csv.php --file=pivot2.csv --dry-run
 *   php tools/dev/import_pivot2_csv.php --file=pivot2.csv --truncate
 *
 * Colunas esperadas no CSV (separador vírgula ou ponto-e-vírgula, cabeçalho na linha 1):
 *   Data de Emissão, Nota Fiscal, CEP, Cidade, Cliente (Razão Social), CNAE Principal,
 *   CNPJ/CPF, Contato, MDF-e - Número, Vendedor, Total de Mercadoria, Desconto,
 *   Valor do ICMS ST, Seguro, Valor do IPI, Total da Nota Fiscal
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();

require dirname(__DIR__, 2) . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();

$config = new \BIomie\Config();
$logger = new \BIomie\Logger(dirname(__DIR__, 2) . '/storage/logs/biomie.log');

function argValue(string $name, ?string $default = null): ?string {
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) return substr($arg, strlen($prefix));
    }
    return $default;
}
function hasFlag(string $name): bool {
    global $argv;
    return in_array('--' . $name, $argv, true);
}

$file    = argValue('file');
$dryRun  = hasFlag('dry-run');
$truncate = hasFlag('truncate');

if (!$file || !file_exists($file)) {
    fwrite(STDERR, "Uso: php " . basename(__FILE__) . " --file=caminho/pivot2.csv [--dry-run] [--truncate]\n");
    fwrite(STDERR, "Arquivo '$file' não encontrado.\n");
    exit(2);
}

$pdo = \BIomie\Database::connect($config, \BIomie\Database::isConfigured($config, 'flight_plan_db') ? 'flight_plan_db' : 'db');

// Ensure staging table exists
$pdo->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS omie_pivot2_faturamento_staging (
        id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        data_emissao        DATE            NULL,
        numero_nf           VARCHAR(32)     NOT NULL,
        cep                 VARCHAR(20)     NULL,
        cidade              VARCHAR(120)    NULL,
        cliente_nome        VARCHAR(255)    NULL,
        cnae_principal      TEXT            NULL,
        documento           VARCHAR(32)     NULL,
        contato             VARCHAR(120)    NULL,
        mdfe_numero         VARCHAR(60)     NULL,
        vendedor_nome       VARCHAR(120)    NULL,
        total_mercadoria    DECIMAL(15,2)   NOT NULL DEFAULT 0,
        desconto            DECIMAL(15,2)   NOT NULL DEFAULT 0,
        valor_icms_st       DECIMAL(15,2)   NOT NULL DEFAULT 0,
        seguro              DECIMAL(15,2)   NOT NULL DEFAULT 0,
        valor_ipi           DECIMAL(15,2)   NOT NULL DEFAULT 0,
        total_nota_fiscal   DECIMAL(15,2)   NOT NULL DEFAULT 0,
        source_file         VARCHAR(120)    NULL,
        imported_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY ux_pivot2_nf_doc_valor (numero_nf, documento(32), total_nota_fiscal),
        KEY idx_pivot2_data      (data_emissao),
        KEY idx_pivot2_vendedor  (vendedor_nome(80)),
        KEY idx_pivot2_documento (documento(32)),
        KEY idx_pivot2_cidade    (cidade(60))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

if ($truncate && !$dryRun) {
    $pdo->exec('TRUNCATE TABLE omie_pivot2_faturamento_staging');
    echo "Staging table truncated.\n";
}

// --- CSV parsing helpers ---
function parseMoneyBr(string $v): float {
    $v = trim($v);
    // Remove R$, spaces
    $v = preg_replace('/[R$\s]/', '', $v);
    // Brazilian format: 1.234,56 → 1234.56
    if (preg_match('/^\d{1,3}(\.\d{3})*(,\d+)?$/', $v)) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (preg_match('/^\d+(,\d{3})*(\.\d+)?$/', $v)) {
        // Already dot-decimal
    } else {
        $v = str_replace(',', '.', $v);
    }
    return (float) $v;
}

function parseDateBr(string $v): ?string {
    $v = trim($v);
    // Try dd/mm/YYYY
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    // Try YYYY-mm-dd already
    if (preg_match('#^\d{4}-\d{2}-\d{2}$#', $v)) {
        return $v;
    }
    return null;
}

function detectSeparator(string $line): string {
    $semi = substr_count($line, ';');
    $comma = substr_count($line, ',');
    return $semi >= $comma ? ';' : ',';
}

$handle = fopen($file, 'r');
if (!$handle) {
    fwrite(STDERR, "Não foi possível abrir o arquivo: $file\n");
    exit(2);
}

$firstLine = fgets($handle);
rewind($handle);
$sep = detectSeparator($firstLine);

// Map CSV column names to DB fields
$colMap = [
    'data de emissão'        => 'data_emissao',
    'data de emissao'        => 'data_emissao',
    'nota fiscal'            => 'numero_nf',
    'cep'                    => 'cep',
    'cidade'                 => 'cidade',
    'cliente (razão social)' => 'cliente_nome',
    'cliente (razao social)' => 'cliente_nome',
    'cnae principal'         => 'cnae_principal',
    'cnpj/cpf'               => 'documento',
    'contato'                => 'contato',
    'mdf-e - número'         => 'mdfe_numero',
    'mdf-e - numero'         => 'mdfe_numero',
    'vendedor'               => 'vendedor_nome',
    'total de mercadoria'    => 'total_mercadoria',
    'desconto'               => 'desconto',
    'valor do icms st'       => 'valor_icms_st',
    'seguro'                 => 'seguro',
    'valor do ipi'           => 'valor_ipi',
    'total da nota fiscal'   => 'total_nota_fiscal',
];

$headers = null;
$fieldIndex = [];
$inserted = 0; $skipped = 0; $errors = 0; $lineNum = 0;

$stmt = $dryRun ? null : $pdo->prepare(<<<'SQL'
    INSERT IGNORE INTO omie_pivot2_faturamento_staging
        (data_emissao, numero_nf, cep, cidade, cliente_nome, cnae_principal, documento,
         contato, mdfe_numero, vendedor_nome, total_mercadoria, desconto, valor_icms_st,
         seguro, valor_ipi, total_nota_fiscal, source_file)
    VALUES
        (:data_emissao, :numero_nf, :cep, :cidade, :cliente_nome, :cnae_principal, :documento,
         :contato, :mdfe_numero, :vendedor_nome, :total_mercadoria, :desconto, :valor_icms_st,
         :seguro, :valor_ipi, :total_nota_fiscal, :source_file)
SQL);

$sourceFile = basename($file);
$moneyFields = ['total_mercadoria', 'desconto', 'valor_icms_st', 'seguro', 'valor_ipi', 'total_nota_fiscal'];

while (($row = fgetcsv($handle, 4096, $sep)) !== false) {
    $lineNum++;
    if ($headers === null) {
        $headers = array_map(fn($h) => strtolower(trim($h)), $row);
        foreach ($headers as $i => $h) {
            if (isset($colMap[$h])) {
                $fieldIndex[$colMap[$h]] = $i;
            }
        }
        if (!isset($fieldIndex['numero_nf'])) {
            fwrite(STDERR, "ERRO: coluna 'Nota Fiscal' não encontrada no cabeçalho.\n");
            fwrite(STDERR, "Colunas detectadas: " . implode(', ', $headers) . "\n");
            exit(2);
        }
        continue;
    }

    $get = fn(string $field): string => trim($row[$fieldIndex[$field] ?? -1] ?? '');

    $numeroNf = $get('numero_nf');
    if ($numeroNf === '') { $skipped++; continue; }

    $record = [
        ':data_emissao'     => parseDateBr($get('data_emissao')),
        ':numero_nf'        => $numeroNf,
        ':cep'              => $get('cep') ?: null,
        ':cidade'           => $get('cidade') ?: null,
        ':cliente_nome'     => $get('cliente_nome') ?: null,
        ':cnae_principal'   => $get('cnae_principal') ?: null,
        ':documento'        => preg_replace('/\D/', '', $get('documento')) ?: null,
        ':contato'          => $get('contato') ?: null,
        ':mdfe_numero'      => $get('mdfe_numero') ?: null,
        ':vendedor_nome'    => $get('vendedor_nome') ?: null,
        ':total_mercadoria' => parseMoneyBr($get('total_mercadoria')),
        ':desconto'         => parseMoneyBr($get('desconto')),
        ':valor_icms_st'    => parseMoneyBr($get('valor_icms_st')),
        ':seguro'           => parseMoneyBr($get('seguro')),
        ':valor_ipi'        => parseMoneyBr($get('valor_ipi')),
        ':total_nota_fiscal'=> parseMoneyBr($get('total_nota_fiscal')),
        ':source_file'      => $sourceFile,
    ];

    if ($dryRun) {
        if ($inserted < 3) echo "DRY-RUN linha $lineNum: " . json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";
        $inserted++;
        continue;
    }

    try {
        $stmt->execute($record);
        $rows = $stmt->rowCount();
        if ($rows > 0) $inserted++;
        else $skipped++;
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, "ERRO linha $lineNum (NF={$numeroNf}): " . $e->getMessage() . "\n");
    }
}
fclose($handle);

$total = $inserted + $skipped + $errors;
echo ($dryRun ? "[DRY-RUN] " : "") . "Processadas: $total | Inseridas: $inserted | Duplicadas/ignoradas: $skipped | Erros: $errors\n";

if (!$dryRun && $inserted > 0) {
    $check = $pdo->query("SELECT COUNT(*) AS cnt, ROUND(SUM(total_nota_fiscal),2) AS total FROM omie_pivot2_faturamento_staging")->fetch(\PDO::FETCH_ASSOC);
    echo "Staging total: {$check['cnt']} linhas, R\${$check['total']}\n";
}

exit($errors > 0 ? 1 : 0);
