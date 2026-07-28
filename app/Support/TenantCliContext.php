<?php

declare(strict_types=1);

namespace BIomie\Support;

use PDO;

final class TenantCliContext
{
    /**
     * @param list<string> $args
     * @return array{args:list<string>,company_id:int,slug:string,canal:string}
     */
    public static function boot(PDO $pdo, array $args): array
    {
        $selector = null;
        $channelSelector = null;
        $filtered = [];
        foreach ($args as $index => $arg) {
            if ($index === 0) {
                $filtered[] = $arg;
                continue;
            }
            if (str_starts_with($arg, '--company=')) {
                $selector = trim(substr($arg, 10));
                continue;
            }
            if (str_starts_with($arg, '--channel=')) {
                $channelSelector = strtolower(trim(substr($arg, 10)));
                continue;
            }
            $filtered[] = $arg;
        }

        if ($selector === null || $selector === '') {
            throw new \InvalidArgumentException(
                'Informe explicitamente --company=SLUG|ID para executar este comando.'
            );
        }
        $stmt = $pdo->prepare(
            'SELECT id, slug, canal_padrao
               FROM biomie_companies
              WHERE ativo = 1
                AND (id = :id OR slug = :slug)
              LIMIT 1'
        );
        $stmt->execute([
            'id' => ctype_digit($selector) ? (int) $selector : 0,
            'slug' => strtoupper($selector),
        ]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($company)) {
            throw new \InvalidArgumentException("Empresa ativa nao encontrada: {$selector}.");
        }

        $companyId = (int) $company['id'];
        $canal = $channelSelector ?: (string) ($company['canal_padrao'] ?: 'varejo');
        if (!in_array($canal, ['varejo', 'licitacao'], true)) {
            throw new \InvalidArgumentException('--channel aceita somente varejo ou licitacao.');
        }
        TenantContext::bootForCompany($pdo, $companyId, $canal);

        return [
            'args' => array_values($filtered),
            'company_id' => $companyId,
            'slug' => (string) $company['slug'],
            'canal' => $canal,
        ];
    }
}
