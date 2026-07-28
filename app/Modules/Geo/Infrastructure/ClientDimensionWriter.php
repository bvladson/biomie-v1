<?php

declare(strict_types=1);

namespace BIomie\Modules\Geo\Infrastructure;

use BIomie\Support\TenantContext;
use PDO;

/**
 * Escreve nas dimensões de clientes usadas pela camada analítica geográfica.
 *
 * Extraído de CanonicalRepository (Fase 5).
 */
final class ClientDimensionWriter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<int>
     */
    public function getPendingEnrichmentClients(int $limit): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT codigo_cliente_omie
            FROM biomie_clientes_para_enriquecer
            WHERE company_id = :company_id AND status = 'pendente'
            ORDER BY data_descoberta ASC
            LIMIT :lim
        SQL);
        $stmt->bindValue(':company_id', TenantContext::companyId(), PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<int> $codes
     */
    public function markEnrichmentStatus(array $codes, string $status, string $reason = ''): void
    {
        if ($codes === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $params = [
            $status,
            $reason !== '' ? $reason : null,
            date('Y-m-d H:i:s'),
            TenantContext::companyId(),
            ...$codes,
        ];

        $this->pdo->prepare(sprintf(
            'UPDATE biomie_clientes_para_enriquecer
             SET status = ?, erro = ?, ultima_tentativa = ?
             WHERE company_id = ? AND codigo_cliente_omie IN (%s)',
            $placeholders,
        ))->execute($params);
    }

    /**
     * @param array<string,mixed> $dim
     * @param array<string,mixed> $geo
     */
    public function upsertClientDimensions(array $dim, array $geo): void
    {
        $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_clientes_dim
                (company_id, codigo_cliente_omie, cpf_cnpj, tipo_documento, nome_cliente,
                 nome_fantasia, natureza_cliente, cnae_codigo, cnae_descricao, segmento_macro)
            VALUES
                (:company_id, :codigo_cliente_omie, :cpf_cnpj, :tipo_documento, :nome_cliente,
                 :nome_fantasia, :natureza_cliente, :cnae_codigo, :cnae_descricao, :segmento_macro)
            ON DUPLICATE KEY UPDATE
                cpf_cnpj          = VALUES(cpf_cnpj),
                tipo_documento    = VALUES(tipo_documento),
                nome_cliente      = VALUES(nome_cliente),
                nome_fantasia     = VALUES(nome_fantasia),
                natureza_cliente  = VALUES(natureza_cliente),
                cnae_codigo       = VALUES(cnae_codigo),
                cnae_descricao    = VALUES(cnae_descricao),
                segmento_macro    = VALUES(segmento_macro),
                sincronizado_em   = CURRENT_TIMESTAMP
        SQL)->execute([
            'company_id'          => TenantContext::companyId(),
            'codigo_cliente_omie' => $dim['codigo_cliente_omie'],
            'cpf_cnpj'            => $dim['cpf_cnpj'] ?? null,
            'tipo_documento'      => $dim['tipo_documento'] ?? 'OUTRO',
            'nome_cliente'        => $dim['nome_cliente'] ?? 'Cliente sem nome',
            'nome_fantasia'       => $dim['nome_fantasia'] ?? null,
            'natureza_cliente'    => $dim['natureza_cliente'] ?? null,
            'cnae_codigo'         => $dim['cnae_codigo'] ?? null,
            'cnae_descricao'      => $dim['cnae_descricao'] ?? null,
            'segmento_macro'      => $dim['segmento_macro'] ?? 'PJ_SEM_CNAE',
        ]);

        $this->pdo->prepare(<<<'SQL'
            INSERT INTO biomie_clientes_geo_dim
                (company_id, codigo_cliente_omie, cep, uf, municipio_ibge, codigo_cidade_omie,
                 cidade_raw, cidade_norm, bairro_raw, bairro_norm,
                 logradouro, numero, complemento)
            VALUES
                (:company_id, :codigo_cliente_omie, :cep, :uf, :municipio_ibge, :codigo_cidade_omie,
                 :cidade_raw, :cidade_norm, :bairro_raw, :bairro_norm,
                 :logradouro, :numero, :complemento)
            ON DUPLICATE KEY UPDATE
                cep               = VALUES(cep),
                uf                = VALUES(uf),
                municipio_ibge    = VALUES(municipio_ibge),
                codigo_cidade_omie = VALUES(codigo_cidade_omie),
                cidade_raw        = VALUES(cidade_raw),
                cidade_norm       = VALUES(cidade_norm),
                bairro_raw        = VALUES(bairro_raw),
                bairro_norm       = VALUES(bairro_norm),
                logradouro        = VALUES(logradouro),
                numero            = VALUES(numero),
                complemento       = VALUES(complemento)
        SQL)->execute([
            'company_id'           => TenantContext::companyId(),
            'codigo_cliente_omie'  => $geo['codigo_cliente_omie'],
            'cep'                  => $geo['cep'] ?? null,
            'uf'                   => $geo['uf'] ?? null,
            'municipio_ibge'       => $geo['municipio_ibge'] ?? null,
            'codigo_cidade_omie'   => $geo['codigo_cidade_omie'] ?? null,
            'cidade_raw'           => $geo['cidade_raw'] ?? null,
            'cidade_norm'          => $geo['cidade_norm'] ?? null,
            'bairro_raw'           => $geo['bairro_raw'] ?? null,
            'bairro_norm'          => $geo['bairro_norm'] ?? null,
            'logradouro'           => $geo['logradouro'] ?? null,
            'numero'               => $geo['numero'] ?? null,
            'complemento'          => $geo['complemento'] ?? null,
        ]);
    }
}
