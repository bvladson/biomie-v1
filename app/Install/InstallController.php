<?php

declare(strict_types=1);

namespace BIomie\Install;

use BIomie\Auth;
use BIomie\Bootstrap;
use BIomie\Config;
use BIomie\Logger;
use Throwable;

final class InstallController
{
    public function __construct(
        private readonly InstallService $service,
        private readonly InstallLogger $logger,
    ) {
    }

    public function handle(): void
    {
        $step = $this->requestedStep();

        if ($this->service->isInstalled()) {
            if ($step === 'diagnostic') {
                if (!$this->authorizeDiagnostic()) {
                    $this->logger->warning('diagnostic_access_denied');
                    $this->renderForbidden();
                    return;
                }

                $this->logger->info('diagnostic_opened');
                echo $this->layout('diagnostic', 'Diagnostico seguro', $this->renderDiagnostic());
                return;
            }

            $this->logger->info('installed_access_blocked', ['step' => $step]);
            echo $this->layout('blocked', 'BIomie ja instalado', $this->renderInstalledBlocked(), [], false);
            return;
        }

        $this->ensureInstallSession();
        $this->logger->info('step_opened', ['step' => $step]);

        if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->service->validateCsrf($_POST['_csrf'] ?? null)) {
                http_response_code(403);
                echo $this->layout($step, 'Sessao expirada', $this->alert('error', 'Sessao expirada. Volte uma etapa e tente novamente.'));
                return;
            }

            $this->handlePost($step);
            return;
        }

        echo $this->renderStep($step);
    }

    private function requestedStep(): string
    {
        $step = strtolower(trim((string) ($_GET['step'] ?? 'welcome')));
        return in_array($step, InstallService::STEPS, true) ? $step : 'welcome';
    }

    private function handlePost(string $step): void
    {
        $action = (string) ($_POST['action'] ?? '');
        $postedStep = strtolower(trim((string) ($_POST['step'] ?? $step)));
        if (!in_array($postedStep, InstallService::STEPS, true)) {
            $postedStep = $step;
        }

        if ($action === 'go') {
            $this->redirect($this->nextStep($postedStep));
            return;
        }

        if ($action === 'test_database' && $postedStep === 'database') {
            $result = $this->service->testDatabase($_POST);
            echo $this->layout('database', 'Banco de dados', $this->renderDatabase($result));
            return;
        }

        if ($action === 'migrate' && $postedStep === 'migrate') {
            $result = $this->service->migrate();
            echo $this->layout('migrate', 'Aplicar migrations', $this->renderMigrate($result));
            return;
        }

        if ($action === 'create_admin' && $postedStep === 'admin') {
            $result = $this->service->createAdmin($_POST);
            echo $this->layout('admin', 'Primeiro administrador', $this->renderAdmin($result));
            return;
        }

        if ($action === 'finish' && $postedStep === 'finish') {
            $result = $this->service->finish();
            echo $this->layout('finish', 'Finalizar instalacao', $this->renderFinish($result));
            return;
        }

        echo $this->layout($postedStep, 'Acao invalida', $this->alert('error', 'A acao solicitada nao esta disponivel.'));
    }

    private function renderStep(string $step): string
    {
        return match ($step) {
            'requirements' => $this->layout($step, 'Requisitos PHP', $this->renderRequirements()),
            'permissions' => $this->layout($step, 'Permissoes de pasta', $this->renderPermissions()),
            'database' => $this->layout($step, 'Banco de dados', $this->renderDatabase()),
            'migrations' => $this->layout($step, 'Status das migrations', $this->renderMigrations()),
            'migrate' => $this->layout($step, 'Aplicar migrations', $this->renderMigrate()),
            'admin' => $this->layout($step, 'Primeiro administrador', $this->renderAdmin()),
            'finish' => $this->layout($step, 'Finalizar instalacao', $this->renderFinish()),
            'diagnostic' => $this->layout($step, 'Diagnostico seguro', $this->renderDiagnostic()),
            default => $this->layout('welcome', 'Assistente de instalacao', $this->renderWelcome()),
        };
    }

    private function renderWelcome(): string
    {
        return <<<HTML
            <section class="install-hero">
                <span class="install-kicker">BIomie MAG</span>
                <h1>Assistente de instalação do BIomie</h1>
                <p>Use este instalador apenas na implantação inicial. Ao finalizar, exclua a pasta <code>/install</code> do servidor.</p>
            </section>
            <div class="install-grid two">
                <div class="install-panel">
                    <h2>O que será feito</h2>
                    <ul class="install-checklist">
                        <li>Verificar PHP e extensões obrigatórias.</li>
                        <li>Validar permissões de <code>config/</code> e <code>storage/</code>.</li>
                        <li>Testar conexão MySQL/MariaDB com PDO.</li>
                        <li>Listar e aplicar migrations pendentes.</li>
                        <li>Criar o primeiro administrador.</li>
                        <li>Gerar <code>config/installed.lock</code>.</li>
                    </ul>
                </div>
                <div class="install-panel muted">
                    <h2>Cuidados de segurança</h2>
                    <p>Nenhuma senha será exibida na tela ou gravada nos logs. Configuração existente não será substituída sem confirmação explícita.</p>
                    {$this->postForm('welcome', 'go', 'Iniciar instalação')}
                </div>
            </div>
        HTML;
    }

    private function renderRequirements(): string
    {
        $result = $this->service->requirements();
        $table = $this->statusTable($result['rows'], ['Requisito', 'Encontrado', 'Necessário', 'Status', 'Ação recomendada']);
        $footer = $result['blocking']
            ? $this->alert('error', 'Corrija os requisitos obrigatórios antes de continuar.')
            : $this->postForm('requirements', 'go', 'Continuar');

        return <<<HTML
            <div class="install-section-head">
                <h1>Checagem PHP e extensões</h1>
                <p>Validamos apenas o necessário para o BIomie rodar com segurança.</p>
            </div>
            {$table}
            {$footer}
        HTML;
    }

    private function renderPermissions(): string
    {
        $result = $this->service->permissions();
        $table = $this->statusTable($result['rows'], ['Pasta/arquivo', 'Encontrado', 'Necessário', 'Status', 'Ação recomendada']);
        $configNotice = $this->service->configLocalExists()
            ? $this->alert('warn', 'Já existe configuração local. O instalador pode usar essa configuração ou criar backup e substituir quando você confirmar.')
            : $this->alert('ok', 'Nenhuma configuração local foi detectada; ela poderá ser criada após o teste do banco.');
        $footer = $result['blocking']
            ? $this->alert('error', 'Corrija as permissões obrigatórias antes de continuar.')
            : $this->postForm('permissions', 'go', 'Continuar');

        return <<<HTML
            <div class="install-section-head">
                <h1>Permissões de pasta</h1>
                <p>O instalador verifica leitura e escrita sem recomendar permissões excessivas.</p>
            </div>
            {$configNotice}
            {$table}
            {$footer}
        HTML;
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function renderDatabase(?array $result = null): string
    {
        $host = $this->fieldValue('DB_HOST', 'localhost');
        $port = $this->fieldValue('DB_PORT', '3306');
        $database = $this->fieldValue('DB_DATABASE', '');
        $username = $this->fieldValue('DB_USERNAME', '');
        $charset = $this->fieldValue('DB_CHARSET', 'utf8mb4');
        $appUrl = $this->fieldValue('APP_URL', $this->guessedAppUrl());
        $appEnv = $this->fieldValue('APP_ENV', 'production');
        $hasConfig = $this->service->configLocalExists();
        $modeExistingChecked = $hasConfig ? 'checked' : '';
        $modeReplaceChecked = $hasConfig ? '' : 'checked';
        $resultBlock = '';
        if ($result !== null) {
            $resultBlock .= $this->alert(($result['ok'] ?? false) ? 'ok' : 'error', (string) ($result['message'] ?? 'Resultado do teste carregado.'));
            if (($result['checks'] ?? []) !== []) {
                $resultBlock .= $this->statusTable($result['checks'], ['Teste', 'Encontrado', 'Necessário', 'Status', 'Ação recomendada']);
            }
            if (($result['ok'] ?? false) === true) {
                $resultBlock .= '<div class="install-actions"><a class="install-button primary" href="index.php?step=migrations">Continuar para migrations</a></div>';
            }
        }

        $confirm = $hasConfig ? <<<HTML
            <label class="install-checkbox">
                <input type="checkbox" name="confirm_replace_config" value="1">
                Criar backup e substituir a configuração local quando eu escolher substituir.
            </label>
        HTML : '';

        return <<<HTML
            <div class="install-section-head">
                <h1>Conexão MySQL/MariaDB</h1>
                <p>Teste a conexão e grave a configuração somente quando estiver tudo certo.</p>
            </div>
            {$resultBlock}
            <form class="install-form" method="post" action="index.php?step=database" autocomplete="off">
                {$this->hiddenPostFields('database', 'test_database')}
                <fieldset class="install-fieldset">
                    <legend>Configuração</legend>
                    <label class="install-radio">
                        <input type="radio" name="config_mode" value="use_existing" {$modeExistingChecked} {$this->disabled(!$hasConfig)}>
                        Usar configuração existente
                    </label>
                    <label class="install-radio">
                        <input type="radio" name="config_mode" value="replace" {$modeReplaceChecked}>
                        Criar backup e substituir configuração local
                    </label>
                    {$confirm}
                </fieldset>
                <div class="install-grid two">
                    {$this->input('DB_HOST', 'Host', $host)}
                    {$this->input('DB_PORT', 'Porta', $port)}
                    {$this->input('DB_DATABASE', 'Banco', $database)}
                    {$this->input('DB_USERNAME', 'Usuário', $username)}
                    {$this->input('DB_PASSWORD', 'Senha', '', 'password')}
                    {$this->input('DB_CHARSET', 'Charset', $charset)}
                    {$this->input('APP_URL', 'APP_URL', $appUrl)}
                    {$this->input('APP_ENV', 'APP_ENV', $appEnv)}
                </div>
                <div class="install-actions">
                    <button class="install-button primary" type="submit">Testar conexão</button>
                    <a class="install-button secondary" href="index.php?step=permissions">Voltar</a>
                </div>
            </form>
        HTML;
    }

    private function renderMigrations(): string
    {
        $status = $this->service->migrationStatus();
        $tables = $this->service->tableStatus();
        $summary = $this->migrationSummary($status);
        $migrationTable = $this->migrationTable($status);
        $tablesBlock = $this->tablesBlock($tables);
        $statusSummary = $status['result']['summary'] ?? [];
        $hasConnectionProblem = (int) (($statusSummary['connection_error'] ?? 0)) > 0
            || (int) (($statusSummary['missing_connection'] ?? 0)) > 0;
        $canMigrate = ($status['ok'] ?? false) === true
            && (int) (($statusSummary['pending'] ?? 0)) > 0
            && !$hasConnectionProblem;
        if ($hasConnectionProblem) {
            $actions = $this->alert('error', 'Corrija a conexão do banco antes de aplicar migrations ou criar administrador.')
                . '<div class="install-actions"><a class="install-button primary" href="index.php?step=database">Revisar banco</a></div>';
        } elseif (!$this->service->databaseValidated()) {
            $actions = $this->alert('warn', 'Teste a conexão do banco nesta sessão antes de aplicar migrations.')
                . '<div class="install-actions"><a class="install-button primary" href="index.php?step=database">Testar banco</a></div>';
        } elseif ($canMigrate) {
            $actions = '<div class="install-actions"><a class="install-button primary" href="index.php?step=migrate">Aplicar migrations pendentes</a></div>';
        } else {
            $actions = '<div class="install-actions"><a class="install-button primary" href="index.php?step=admin">Continuar</a></div>';
        }

        return <<<HTML
            <div class="install-section-head">
                <h1>Status das migrations</h1>
                <p>O instalador usa o mesmo mecanismo do <code>bin/schema_migrate.php</code>.</p>
            </div>
            {$summary}
            {$migrationTable}
            <h2>Tabelas existentes e ausentes</h2>
            {$tablesBlock}
            {$actions}
        HTML;
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function renderMigrate(?array $result = null): string
    {
        $resultBlock = '';
        if ($result !== null) {
            $resultBlock .= $this->alert(($result['ok'] ?? false) ? 'ok' : 'error', (string) ($result['message'] ?? 'Resultado carregado.'));
            $applied = $result['result']['applied'] ?? [];
            if (is_array($applied) && $applied !== []) {
                $rows = array_map(static fn (array $row): array => [
                    'name' => (string) ($row['version'] ?? ''),
                    'found' => (string) ($row['description'] ?? ''),
                    'required' => (string) ($row['connection'] ?? ''),
                    'status' => 'Aplicada',
                    'ok' => true,
                    'action' => ((int) ($row['execution_ms'] ?? 0)) . ' ms',
                ], $applied);
                $resultBlock .= $this->statusTable($rows, ['Migration', 'Descrição', 'Conexão', 'Status', 'Tempo']);
            }
            if (($result['ok'] ?? false) === true) {
                $resultBlock .= '<div class="install-actions"><a class="install-button primary" href="index.php?step=admin">Continuar para admin</a></div>';
            }
        }

        return <<<HTML
            <div class="install-section-head">
                <h1>Aplicar migrations pendentes</h1>
                <p>Esta ação altera o banco de dados e executa apenas migrations ainda pendentes.</p>
            </div>
            {$resultBlock}
            <div class="install-panel danger-soft">
                <h2>Confirmação</h2>
                <p>Esta ação irá alterar o banco de dados. Se uma migration falhar, a execução será interrompida e o detalhe técnico irá para o log sanitizado.</p>
                <form method="post" action="index.php?step=migrate">
                    {$this->hiddenPostFields('migrate', 'migrate')}
                    <button class="install-button primary" type="submit">Aplicar migrations pendentes</button>
                    <a class="install-button secondary" href="index.php?step=migrations">Voltar</a>
                </form>
            </div>
        HTML;
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function renderAdmin(?array $result = null): string
    {
        $status = $this->service->adminStatus();
        $notice = $this->alert($status['ready'] ? 'ok' : 'error', $status['message']);
        if ($result !== null) {
            $notice = $this->alert(($result['ok'] ?? false) ? 'ok' : 'error', (string) ($result['message'] ?? 'Resultado carregado.')) . $notice;
        }

        if (!$status['ready']) {
            return <<<HTML
                <div class="install-section-head"><h1>Primeiro administrador</h1><p>A tabela de usuários precisa existir antes desta etapa.</p></div>
                {$notice}
                <div class="install-actions"><a class="install-button secondary" href="index.php?step=migrations">Voltar para migrations</a></div>
            HTML;
        }

        if ($status['admins'] > 0 || (($result['ok'] ?? false) === true)) {
            return <<<HTML
                <div class="install-section-head"><h1>Primeiro administrador</h1><p>O BIomie já possui administrador ativo.</p></div>
                {$notice}
                <div class="install-actions"><a class="install-button primary" href="index.php?step=finish">Continuar para finalização</a></div>
            HTML;
        }

        $username = $this->fieldValue('username', 'admin');
        $fullName = $this->fieldValue('full_name', '');
        $email = $this->fieldValue('email', '');

        return <<<HTML
            <div class="install-section-head">
                <h1>Criar primeiro administrador</h1>
                <p>Esse usuário terá acesso total ao BIomie.</p>
            </div>
            {$notice}
            <form class="install-form" method="post" action="index.php?step=admin" autocomplete="off">
                {$this->hiddenPostFields('admin', 'create_admin')}
                <div class="install-grid two">
                    {$this->input('full_name', 'Nome', $fullName)}
                    {$this->input('username', 'Login', $username)}
                    {$this->input('email', 'E-mail', $email, 'email')}
                    {$this->input('password', 'Senha', '', 'password')}
                    {$this->input('password_confirm', 'Confirmar senha', '', 'password')}
                </div>
                <p class="install-help">Use 10+ caracteres com letra, número e símbolo. A senha não será exibida nem gravada em log.</p>
                <div class="install-actions">
                    <button class="install-button primary" type="submit">Criar administrador</button>
                    <a class="install-button secondary" href="index.php?step=migrations">Voltar</a>
                </div>
            </form>
        HTML;
    }

    /**
     * @param array<string,mixed>|null $result
     */
    private function renderFinish(?array $result = null): string
    {
        if ($result !== null) {
            $type = ($result['ok'] ?? false) ? 'ok' : 'error';
            $message = (string) ($result['message'] ?? 'Resultado carregado.');
            $extra = '';
            if (($result['ok'] ?? false) === true) {
                $extra = <<<HTML
                    <div class="install-panel success">
                        <h2>Instalação concluída</h2>
                        <p>Instalação concluída. Por segurança, exclua a pasta /install do servidor antes de usar o BIomie em produção.</p>
                        <ul class="install-checklist">
                            <li>Requisitos OK</li>
                            <li>Banco OK</li>
                            <li>Migrations OK</li>
                            <li>Admin OK</li>
                            <li>Lock criado</li>
                            <li>Pasta install ainda precisa ser excluída manualmente</li>
                        </ul>
                        <a class="install-button primary" href="../index.php">Acessar BIomie</a>
                    </div>
                HTML;
            }
            return $this->alert($type, $message) . $extra;
        }

        $admin = $this->service->adminStatus();
        $migrations = $this->service->migrationStatus();
        $summary = $migrations['result']['summary'] ?? [];
        $ready = $admin['admins'] > 0
            && (int) ($summary['pending'] ?? 0) === 0
            && (int) ($summary['connection_error'] ?? 0) === 0
            && (int) ($summary['missing_connection'] ?? 0) === 0;
        $checklist = $this->finishChecklist($ready, $admin, $summary);
        $actions = $ready
            ? '<form method="post" action="index.php?step=finish">' . $this->hiddenPostFields('finish', 'finish') . '<button class="install-button primary" type="submit">Finalizar instalação</button></form>'
            : '<div class="install-actions"><a class="install-button secondary" href="index.php?step=migrations">Revisar pendências</a></div>';

        return <<<HTML
            <div class="install-section-head">
                <h1>Finalizar instalação</h1>
                <p>O lock só será criado se banco, migrations e administrador estiverem prontos.</p>
            </div>
            {$checklist}
            {$actions}
        HTML;
    }

    private function renderDiagnostic(): string
    {
        $data = $this->service->diagnostic();
        $requirements = $this->statusTable($data['requirements']['rows'] ?? [], ['Requisito', 'Encontrado', 'Necessário', 'Status', 'Ação']);
        $permissions = $this->statusTable($data['permissions']['rows'] ?? [], ['Pasta/arquivo', 'Encontrado', 'Necessário', 'Status', 'Ação']);
        $migrations = $this->migrationTable($data['migrations'] ?? []);
        $tables = $this->tablesBlock($data['tables'] ?? []);
        $lock = $data['lock']['exists'] ?? false ? 'Existe' : 'Nao existe';
        $env = $data['environment'] ?? [];
        $logRows = '';
        foreach (($data['install_log'] ?? []) as $line) {
            $logRows .= '<li><code>' . $this->h($this->safeLogLine((string) $line)) . '</code></li>';
        }
        if ($logRows === '') {
            $logRows = '<li>Nenhum log de instalacao encontrado.</li>';
        }

        return <<<HTML
            <div class="install-section-head">
                <h1>Diagnóstico seguro</h1>
                <p>Informações úteis para suporte, sem segredos, tokens ou stack trace.</p>
            </div>
            <div class="install-grid three">
                <div class="install-metric"><span>PHP</span><strong>{$this->h((string) ($env['php'] ?? PHP_VERSION))}</strong></div>
                <div class="install-metric"><span>APP_ENV</span><strong>{$this->h((string) ($env['app_env'] ?? 'production'))}</strong></div>
                <div class="install-metric"><span>installed.lock</span><strong>{$lock}</strong></div>
            </div>
            <details class="install-details" open><summary>Requisitos</summary>{$requirements}</details>
            <details class="install-details"><summary>Permissões</summary>{$permissions}</details>
            <details class="install-details"><summary>Migrations</summary>{$migrations}</details>
            <details class="install-details"><summary>Tabelas</summary>{$tables}</details>
            <details class="install-details"><summary>Últimos logs sanitizados</summary><ul class="install-log">{$logRows}</ul></details>
        HTML;
    }

    private function renderInstalledBlocked(): string
    {
        $lock = $this->service->lockData();
        $installedAt = $lock['installed_at'] ?? 'data nao informada';
        $database = $lock['database'] ?? 'banco nao informado';

        return <<<HTML
            <section class="install-hero">
                <span class="install-kicker">BIomie MAG</span>
                <h1>O BIomie já está instalado.</h1>
                <p>Por segurança, remova a pasta <code>/install</code> do servidor.</p>
            </section>
            <div class="install-panel">
                <dl class="install-definition">
                    <dt>Instalado em</dt><dd>{$this->h((string) $installedAt)}</dd>
                    <dt>Banco</dt><dd>{$this->h((string) $database)}</dd>
                </dl>
                <p>O instalador público está bloqueado. O diagnóstico só abre para administrador logado.</p>
                <div class="install-actions">
                    <a class="install-button primary" href="../index.php">Acessar BIomie</a>
                    <a class="install-button secondary" href="index.php?step=diagnostic">Diagnóstico admin</a>
                </div>
            </div>
        HTML;
    }

    private function renderForbidden(): void
    {
        http_response_code(403);
        echo $this->layout('forbidden', 'Acesso negado', $this->alert('error', 'Acesso permitido apenas para administrador logado.'), [], false);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function migrationSummary(array $data): string
    {
        if (($data['ok'] ?? false) !== true) {
            return $this->alert('error', (string) ($data['message'] ?? 'Nao foi possivel consultar migrations.'));
        }

        $summary = $data['result']['summary'] ?? [];
        $items = [
            'Encontradas' => (int) ($summary['discovered'] ?? 0),
            'Aplicadas' => (int) ($summary['applied'] ?? 0),
            'Pendentes' => (int) ($summary['pending'] ?? 0),
            'Erro conexão' => (int) ($summary['connection_error'] ?? 0),
            'Conexão ausente' => (int) ($summary['missing_connection'] ?? 0),
            'Checksum' => (int) ($summary['checksum_mismatch'] ?? 0),
        ];

        $html = '<div class="install-grid three">';
        foreach ($items as $label => $value) {
            $html .= '<div class="install-metric"><span>' . $this->h($label) . '</span><strong>' . $this->h((string) $value) . '</strong></div>';
        }
        return $html . '</div>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private function migrationTable(array $data): string
    {
        $migrations = $data['result']['migrations'] ?? [];
        if (!is_array($migrations) || $migrations === []) {
            return $this->alert('warn', 'Nenhuma migration encontrada ou status indisponível.');
        }

        $html = '<div class="install-table-wrap"><table class="install-table"><thead><tr><th>Migration</th><th>Descrição</th><th>Status</th><th>Aplicada em</th></tr></thead><tbody>';
        foreach ($migrations as $migration) {
            $status = (string) ($migration['status'] ?? 'pendente');
            $html .= '<tr>'
                . '<td><code>' . $this->h((string) ($migration['version'] ?? '')) . '</code></td>'
                . '<td>' . $this->h((string) ($migration['description'] ?? '')) . '</td>'
                . '<td>' . $this->badge($status, $this->statusType($status)) . '</td>'
                . '<td>' . $this->h((string) ($migration['executed_at'] ?? '-')) . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /**
     * @param array<string,mixed> $data
     */
    private function tablesBlock(array $data): string
    {
        if (($data['ok'] ?? false) !== true) {
            return $this->alert('warn', (string) ($data['message'] ?? 'Nao foi possivel consultar tabelas.'));
        }

        $html = '';
        foreach (($data['groups'] ?? []) as $group => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $html .= '<details class="install-details" open><summary>' . $this->h((string) $group) . '</summary>';
            $html .= '<div class="install-table-wrap"><table class="install-table"><thead><tr><th>Tabela</th><th>Existe?</th><th>Registros</th><th>Status</th><th>Migration</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $exists = (bool) ($row['exists'] ?? false);
                $status = (string) ($row['status'] ?? ($exists ? 'ok' : 'missing'));
                $html .= '<tr>'
                    . '<td><code>' . $this->h((string) ($row['table'] ?? '')) . '</code></td>'
                    . '<td>' . ($exists ? 'Sim' : 'Não') . '</td>'
                    . '<td>' . $this->h((string) ($row['rows'] ?? '-')) . '</td>'
                    . '<td>' . $this->badge($exists ? 'Encontrada' : 'Ausente', $exists ? 'ok' : 'warn') . '</td>'
                    . '<td>' . $this->h((string) ($row['migration_hint'] ?? '-')) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table></div></details>';
        }

        return $html;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $headers
     */
    private function statusTable(array $rows, array $headers): string
    {
        if ($rows === []) {
            return $this->alert('warn', 'Nenhum item para exibir.');
        }

        $html = '<div class="install-table-wrap"><table class="install-table"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . $this->h($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $ok = (bool) ($row['ok'] ?? false);
            $status = (string) ($row['status'] ?? ($ok ? 'OK' : 'Erro'));
            $html .= '<tr>'
                . '<td>' . $this->h((string) ($row['name'] ?? '')) . '</td>'
                . '<td>' . $this->h((string) ($row['found'] ?? '')) . '</td>'
                . '<td>' . $this->h((string) ($row['required'] ?? '')) . '</td>'
                . '<td>' . $this->badge($status, $this->statusType($status)) . '</td>'
                . '<td>' . $this->h((string) ($row['action'] ?? '')) . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /**
     * @param array<string,mixed> $admin
     * @param array<string,mixed> $summary
     */
    private function finishChecklist(bool $ready, array $admin, array $summary): string
    {
        $items = [
            ['Requisitos e permissões revisados', true],
            ['Banco configurado', true],
            ['Migrations sem pendência', (int) ($summary['pending'] ?? 1) === 0],
            ['Sem erro de conexão em migrations', (int) ($summary['connection_error'] ?? 1) === 0],
            ['Administrador ativo existe', (int) ($admin['admins'] ?? 0) > 0],
            ['Lock será criado ao finalizar', $ready],
        ];

        $html = '<ul class="install-checklist boxed">';
        foreach ($items as [$label, $ok]) {
            $html .= '<li class="' . ($ok ? 'is-ok' : 'is-warn') . '">' . $this->h((string) $label) . '</li>';
        }
        return $html . '</ul>';
    }

    private function layout(string $step, string $title, string $content, array $alerts = [], bool $withProgress = true): string
    {
        $safeTitle = $this->h($title);
        $progress = $withProgress ? $this->progress($step) : '';
        $alertHtml = '';
        foreach ($alerts as $alert) {
            if (is_array($alert)) {
                $alertHtml .= $this->alert((string) ($alert['type'] ?? 'warn'), (string) ($alert['message'] ?? ''));
            }
        }

        return <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{$safeTitle} - Instalador BIomie</title>
    <link rel="stylesheet" href="assets/install.css">
</head>
<body class="install-page" data-step="{$this->h($step)}">
    <main class="install-shell">
        <header class="install-brand">
            <a href="index.php" class="install-logo" aria-label="BIomie">BIomie</a>
            <span>Instalador MAG</span>
        </header>
        {$progress}
        <section class="install-card">
            {$alertHtml}
            {$content}
        </section>
    </main>
    <script src="assets/install.js" defer></script>
</body>
</html>
HTML;
    }

    private function progress(string $currentStep): string
    {
        $visible = [
            'welcome' => 'Boas-vindas',
            'requirements' => 'Requisitos',
            'permissions' => 'Permissões',
            'database' => 'Banco',
            'migrations' => 'Migrations',
            'admin' => 'Admin',
            'finish' => 'Finalizar',
        ];
        $currentIndex = array_search($currentStep, array_keys($visible), true);
        if ($currentStep === 'migrate') {
            $currentIndex = array_search('migrations', array_keys($visible), true);
        }
        $currentIndex = $currentIndex === false ? 0 : (int) $currentIndex;

        $html = '<nav class="install-progress" aria-label="Etapas">';
        $index = 0;
        foreach ($visible as $step => $label) {
            $state = $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'pending');
            $html .= '<a class="' . $state . '" href="index.php?step=' . $this->h($step) . '"><span>' . ($index + 1) . '</span>' . $this->h($label) . '</a>';
            $index++;
        }
        return $html . '</nav>';
    }

    private function hiddenPostFields(string $step, string $action): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->h($this->service->csrfToken()) . '">'
            . '<input type="hidden" name="step" value="' . $this->h($step) . '">'
            . '<input type="hidden" name="action" value="' . $this->h($action) . '">';
    }

    private function postForm(string $step, string $action, string $label): string
    {
        return '<form class="install-actions" method="post" action="index.php?step=' . $this->h($step) . '">'
            . $this->hiddenPostFields($step, $action)
            . '<button class="install-button primary" type="submit">' . $this->h($label) . '</button>'
            . '</form>';
    }

    private function input(string $name, string $label, string $value = '', string $type = 'text'): string
    {
        return '<label class="install-field"><span>' . $this->h($label) . '</span>'
            . '<input type="' . $this->h($type) . '" name="' . $this->h($name) . '" value="' . $this->h($value) . '">'
            . '</label>';
    }

    private function alert(string $type, string $message): string
    {
        return '<div class="install-alert ' . $this->h($type) . '">' . $this->h($message) . '</div>';
    }

    private function badge(string $label, string $type): string
    {
        return '<span class="install-badge ' . $this->h($type) . '">' . $this->h($label) . '</span>';
    }

    private function statusType(string $status): string
    {
        $status = strtolower($status);
        if (str_contains($status, 'ok') || str_contains($status, 'aplicada') || str_contains($status, 'applied')) {
            return 'ok';
        }
        if (str_contains($status, 'aten') || str_contains($status, 'pend') || str_contains($status, 'warn')) {
            return 'warn';
        }
        if (str_contains($status, 'erro') || str_contains($status, 'error') || str_contains($status, 'missing') || str_contains($status, 'falha')) {
            return 'error';
        }
        return 'muted';
    }

    private function nextStep(string $step): string
    {
        return match ($step) {
            'welcome' => 'requirements',
            'requirements' => 'permissions',
            'permissions' => 'database',
            'database' => 'migrations',
            'migrations', 'migrate' => 'admin',
            'admin' => 'finish',
            default => 'welcome',
        };
    }

    private function redirect(string $step): void
    {
        header('Location: index.php?step=' . rawurlencode($step));
        exit;
    }

    private function ensureInstallSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
        session_name('BIOMIEINSTALLSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    private function authorizeDiagnostic(): bool
    {
        try {
            Bootstrap::init();
            Auth::boot(new Config(), new Logger(Bootstrap::rootPath('storage/logs/biomie.log')));
            return Auth::isAuthenticated() && (Auth::isAdmin() || Auth::canAccessAdminArea());
        } catch (Throwable $exception) {
            $this->logger->warning('diagnostic_authorization_failed', ['message' => $exception->getMessage()]);
            return false;
        }
    }

    private function fieldValue(string $name, string $default): string
    {
        if ($name === 'DB_PASSWORD' || $name === 'password' || $name === 'password_confirm') {
            return '';
        }
        return trim((string) ($_POST[$name] ?? $default));
    }

    private function guessedAppUrl(): string
    {
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            return '';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $base = rtrim(dirname(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/install/index.php'))), '/\\');
        return $scheme . '://' . $host . ($base === '' || $base === '.' ? '' : $base);
    }

    private function disabled(bool $disabled): string
    {
        return $disabled ? 'disabled' : '';
    }

    private function safeLogLine(string $line): string
    {
        $line = preg_replace('/SQLSTATE\[[^\]]+\](?:\s*\[[^\]]+\])?[^"}]*/i', 'Falha tecnica sanitizada', $line) ?? $line;
        $line = preg_replace('/[A-Z]:\\\\[^"\s]+/i', '[path]', $line) ?? $line;
        $line = preg_replace('#/(?:home|var|srv|www|tmp)/[^"\s]+#i', '[path]', $line) ?? $line;
        $line = preg_replace('/(password|pass|secret|token|cookie|authorization|app_key|app_secret|db_pass)(["\s:=]+)([^",}\s]+)/i', '$1$2[redacted]', $line) ?? $line;

        return $line;
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
