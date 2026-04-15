<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$reportDir = $root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'qa-reports';

if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}

$timestamp = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
$slug = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--slug=')) {
        $slug = trim(substr($arg, 7));
    }
}

$safeSlug = $slug !== null && $slug !== ''
    ? preg_replace('/[^a-z0-9\\-]+/i', '-', strtolower($slug))
    : 'manual';
$safeSlug = trim((string) $safeSlug, '-');
if ($safeSlug === '') {
    $safeSlug = 'manual';
}

$fileName = sprintf(
    'dark-mode-qa-%s-%s.md',
    $timestamp->format('Ymd-His'),
    $safeSlug
);

$target = $reportDir . DIRECTORY_SEPARATOR . $fileName;

$template = <<<'MD'
# Relatorio de Homologacao de Modo Escuro

## Metadados

- data: %s
- rodada:
- responsavel:
- ambiente:
- navegador:
- viewport:
- build:

## Preflight

- `php tools/dev/dark_mode_qa_audit.php`:
- observacoes:

## Escopo validado

- telas:
- modos:
- PWA:
- mobile:
- teclado:

## Resumo por severidade

- `P0`:
- `P1`:
- `P2`:

## Achados

| ID | Severidade | Tela | Modo | Viewport | Componente | Descricao | Evidencia | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| EX-001 | P0 | index | dark | desktop | tooltip | Descricao objetiva do problema | print ou observacao | aberto |

## Checklist de aceite

- [ ] sem flash claro inesperado
- [ ] tema persiste entre sessoes
- [ ] `system` acompanha o sistema operacional
- [ ] sem bloco claro perdido
- [ ] sem texto lavado
- [ ] sem grafico ilegivel
- [ ] foco por teclado visivel
- [ ] drawers e modais coerentes
- [ ] `offline.html` respeita o tema
- [ ] PWA instalada abre no tema correto

## Decisao da rodada

- status:
- proxima acao:
- observacoes finais:
MD;

$content = sprintf($template, $timestamp->format(DateTimeInterface::ATOM));
file_put_contents($target, $content);

echo json_encode([
    'ok' => true,
    'report' => str_replace($root . DIRECTORY_SEPARATOR, '', $target),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
