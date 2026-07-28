<?php

declare(strict_types=1);

namespace BIomie\Install;

use PDO;
use Throwable;

final class InstallDatabaseTester
{
    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,message:string,checks:list<array<string,string|bool>>,pdo:?PDO,error:?string}
     */
    public function test(array $input): array
    {
        $checks = [];
        $pdo = null;

        try {
            $host = trim((string) ($input['DB_HOST'] ?? 'localhost'));
            $port = (int) ($input['DB_PORT'] ?? 3306);
            $database = trim((string) ($input['DB_DATABASE'] ?? $input['DB_NAME'] ?? ''));
            $username = trim((string) ($input['DB_USERNAME'] ?? $input['DB_USER'] ?? ''));
            $password = (string) ($input['DB_PASSWORD'] ?? $input['DB_PASS'] ?? '');
            $charset = trim((string) ($input['DB_CHARSET'] ?? 'utf8mb4')) ?: 'utf8mb4';

            if ($host === '' || $database === '' || $username === '') {
                return [
                    'ok' => false,
                    'message' => 'Informe host, banco e usuario.',
                    'checks' => [],
                    'pdo' => null,
                    'error' => null,
                ];
            }

            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $checks[] = $this->checkRow('Conexao', 'conectado', true, 'Tudo certo');

            $pdo->query('SELECT 1');
            $checks[] = $this->checkRow('SELECT', 'permitido', true, 'Tudo certo');

            $probe = 'biomie_install_probe_' . bin2hex(random_bytes(4));
            $pdo->exec("CREATE TABLE `{$probe}` (id INT NOT NULL AUTO_INCREMENT, label VARCHAR(40) NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $checks[] = $this->checkRow('CREATE', 'permitido', true, 'Tudo certo');

            $pdo->exec("ALTER TABLE `{$probe}` ADD COLUMN flag TINYINT(1) NOT NULL DEFAULT 0");
            $checks[] = $this->checkRow('ALTER', 'permitido', true, 'Tudo certo');

            $stmt = $pdo->prepare("INSERT INTO `{$probe}` (label, flag) VALUES (:label, 1)");
            $stmt->execute(['label' => 'ok']);
            $pdo->exec("UPDATE `{$probe}` SET flag = 0 WHERE id = 1");
            $checks[] = $this->checkRow('INSERT/UPDATE', 'permitido', true, 'Tudo certo');

            $pdo->exec("DROP TABLE IF EXISTS `{$probe}`");
            $checks[] = $this->checkRow('DROP', 'permitido', true, 'Tudo certo');

            return [
                'ok' => true,
                'message' => 'Conexao validada com sucesso.',
                'checks' => $checks,
                'pdo' => $pdo,
                'error' => null,
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'message' => 'Nao foi possivel conectar ao banco. Confira host, porta, nome do banco, usuario e senha.',
                'checks' => $checks,
                'pdo' => $pdo,
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,string|bool>
     */
    private function checkRow(string $name, string $found, bool $ok, string $action): array
    {
        return [
            'name' => $name,
            'found' => $found,
            'required' => 'permitido',
            'status' => $ok ? 'OK' : 'Erro',
            'ok' => $ok,
            'action' => $action,
        ];
    }
}
