# BIomie — CLI Developer Guide

> Versão: Q.12 | Atualizado: 2026-05-23

---

## Bootstrap padrão para ferramentas CLI

**NUNCA** usar `api/bootstrap.php` em scripts CLI. Ele chama `Auth::requireLogin(true)` que exige sessão HTTP ativa — em CLI produz `{"ok":false,"error":"unauthenticated"}` e encerra.

```php
<?php
declare(strict_types=1);

$root = dirname(dirname(__DIR__)); // ajustar se necessário

require $root . '/app/Support/CliGuard.php';
\BIomie\Support\CliGuard::requireCli();          // bloqueia se chamado via HTTP

require $root . '/app/Bootstrap.php';
\BIomie\Bootstrap::init();                        // carrega .env, constantes, autoload

$config = new \BIomie\Config();
$pdo    = \BIomie\Database::connect($config, 'db');
```

### Onde fica cada ferramenta

| Caminho | projectRoot |
|---------|-------------|
| `tools/dev/*.php` | `dirname(dirname(__DIR__))` |
| `bin/*.php` | `dirname(__DIR__)` |
| `app/AdminConsole/Commands/*.php` | invocado pelo console, bootstrap já feito |

---

## Hostinger: exec() está DESABILITADO

No servidor de produção (Hostinger shared), as funções `exec()`, `shell_exec()`, `passthru()` e `system()` estão desabilitadas. Chamá-las gera erro fatal ou retorno silencioso.

Use `proc_open()` em seu lugar:

```php
function rg_exec(string $cmd): array
{
    $ds      = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $ds, $pipes);
    if (!is_resource($process)) {
        return ['exit' => 1, 'output' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $out];
}
```

Para invocar sub-scripts PHP (padrão dos guards):

```php
function rg_run(string $php, string $script, array $args): array
{
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $r     = rg_exec($cmd);
    $lines = $r['output'] !== '' ? explode("\n", rtrim($r['output'])) : [];
    return ['exit' => $r['exit'], 'lines' => $lines, 'output' => $r['output']];
}
```

### Detectando falha em sub-guards

`proc_close()` pode retornar código não-zero mesmo quando o guard interno passou. Usar presença de símbolos no output como sinal primário:

```php
function rg_failed(array $r): bool
{
    $plain = preg_replace('/\033\[[0-9;]*m/', '', $r['output']);

    // Sinal explícito de PASS — sobrescreve exit code
    if (str_contains($plain, '✔'))                                      return false;
    if (preg_match('/\bPASS\b.+\d+\s+pass\b.+0\s+fail\b/i', $plain))  return false;

    // Sinal explícito de FAIL
    if (str_contains($plain, '✗'))                                      return true;
    if (preg_match('/\bFAIL\b\s*[:\-]\s*[1-9]/i', $plain))             return true;
    if (preg_match('/[1-9]\d*\s+fail\b/i', $plain))                    return true;

    return $r['exit'] !== 0;
}
```

---

## projectRoot: não usar realpath()

`realpath()` falha com symlinks — comum em Hostinger. Calcular a raiz com `dirname()`:

```php
// tools/dev/*.php  →  subir 2 níveis
$root = dirname(dirname(__DIR__));

// bin/*.php  →  subir 1 nível
$root = dirname(__DIR__);
```

---

## Convenção de argumentos

Usar `getopt()` com opções longas apenas (sem alias curto, exceto `-h`):

```php
$opts   = getopt('', ['start:', 'end:', 'pretty', 'json', 'csv', 'limit:', 'class:']);
$start  = trim((string) ($opts['start']  ?? date('Y-01-01')));
$end    = trim((string) ($opts['end']    ?? date('Y-m-d')));
$pretty = isset($opts['pretty']);
$json   = isset($opts['json']);
```

| Flag | Tipo | Descrição |
|------|------|-----------|
| `--start=YYYY-MM-DD` | string | Início do período |
| `--end=YYYY-MM-DD` | string | Fim do período |
| `--pretty` | bool | Saída colorida e legível |
| `--json` | bool | Saída JSON (para CI/integração) |
| `--csv` | bool | Saída CSV (quando aplicável) |
| `--dry-run` | bool | Simular sem gravar |
| `--apply` | bool | Executar efetivamente (requer dry-run antes) |
| `--limit=N` | int | Limitar linhas (relatórios) |
| `--clear-cache` | bool | Limpar cache ao finalizar |

---

## Exit codes

| Código | Significado |
|--------|-------------|
| `0` | OK — todos os checks passaram |
| `1` | FAIL — um ou mais checks falharam |
| `2` | Erro fatal — bootstrap falhou, PDO não conectou, argumento inválido |

Padrão de saída no final do script:

```php
exit($allPass ? 0 : 1);
```

---

## Saída: pretty vs JSON

```php
if ($jsonMode) {
    echo json_encode(['result' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    echo PHP_EOL;
    exit($ok ? 0 : 1);
}

// Pretty
$hr = str_repeat('═', 72);
echo PHP_EOL . $hr . PHP_EOL;
echo '  Script — Descrição' . PHP_EOL;
echo $hr . PHP_EOL . PHP_EOL;
// ... linhas do relatório ...
exit($ok ? 0 : 1);
```

Cores ANSI (só usar em modo `--pretty`):

```php
function col(string $c, string $s): string
{
    $map = ['g' => '32', 'r' => '31', 'y' => '33', 'b' => '34'];
    return isset($map[$c]) ? "\033[{$map[$c]}m{$s}\033[0m" : $s;
}
```

---

## Referência dos guards disponíveis

| Script | Flags | Propósito |
|--------|-------|-----------|
| `biomie_release_guard.php` | `--start --end --pretty --json --clear-cache` | Master: orquestra D1-D6 |
| `fiscal_value_basis_guard.php` | `--pretty` | D1: valida Base Comercial NF |
| `fase_l_qa.php` | `--start --end --pretty` | D2: valida exclusão de Licitação |
| `reconciliation_guard.php` | `--start --end --pretty` | D3: valida conciliação NF × Título |
| `reconciliation_pending_report.php` | `--start --end --pretty --json --csv --limit --class` | Pendências reais (read-only) |
| `omie_sync_status.php` | `--pretty --json` | Status do sync Omie |
| `geo_holistic_audit.php` | `--pretty` | Diagnóstico de cobertura geo |

---

## Padrão de verificação de tabela antes de usar

```php
$tableExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($tableName)
)->fetchColumn();

if (!$tableExists) {
    // tratar ausência, não falhar silenciosamente
}
```

---

## Deploy de ferramentas CLI (Hostinger)

Upload individual via pscp:

```powershell
& pscp.exe -P 65002 -pw "SENHA" tools/dev/meu_script.php u106688109@82.112.247.199:/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/tools/dev/meu_script.php
```

Execução remota via plink (lote, sem interação):

```powershell
& plink.exe -P 65002 -pw "SENHA" -batch u106688109@82.112.247.199 "php /home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie/tools/dev/meu_script.php --pretty"
```

> **Nota:** `plink` retorna exit code 255 ao encerrar a sessão SSH — esse é o código do plink, não do script PHP. Verificar a saída de texto para confirmar PASS/FAIL.

---

## Checklist para nova ferramenta CLI

- [ ] Bootstrap: `CliGuard::requireCli()` + `Bootstrap::init()`
- [ ] projectRoot: `dirname(dirname(__DIR__))` (para `tools/dev/`)
- [ ] Sem `exec()`, `shell_exec()`, `passthru()` — usar `rg_exec()` se precisar de subprocesso
- [ ] Flags: `--pretty`, `--json` (sem conflito de flags)
- [ ] Exit code: 0 = OK, 1 = FAIL, 2 = erro fatal
- [ ] Read-only por padrão; `--apply` explícito para operações destrutivas
- [ ] `BiVisibilityScope::getCondition()` em qualquer query comercial
- [ ] `FiscalValueBasis::preComputedBaseSql('nf')` para valor base
