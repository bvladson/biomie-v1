<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Infrastructure;

use BIomie\Logger;
use BIomie\Support\TenantContext;
use PDO;
use Throwable;

final class CustomerGeoSyncRepository
{
    public function __construct(
        private readonly PDO    $pdo,
        private readonly Logger $logger,
    ) {}

    /**
     * Garante que a linha skeleton exista em biomie_clientes_geo_dim e atualiza
     * os campos de endereço e bairro oficial quando disponíveis.
     *
     * Invariante: nunca apaga bairro_raw/cidade_raw/uf preenchido com valor vazio.
     *
     * @param array{cidade:string,cidade_norm:string,uf:string,bairro:string,cep:string,endereco:string,numero:string,complemento:string} $fields
     * @param array{id:int,nome:string}|null $oficialBairro
     */
    public function sync(int $codigoOmie, array $fields, ?string $bairroNorm, ?array $oficialBairro): void
    {
        try {
            $sets   = [];
            $companyId = TenantContext::companyId();
            if ($companyId <= 0) {
                throw new \RuntimeException('Selecione uma empresa antes de atualizar a geografia do cliente.');
            }
            $params = ['company_id' => $companyId, 'codigo' => $codigoOmie];

            if ($fields['cidade'] !== '')      { $sets[] = 'cidade_raw = :cidade_raw';   $params['cidade_raw']  = $fields['cidade']; }
            if ($fields['uf'] !== '')          { $sets[] = 'uf = :uf';                   $params['uf']          = $fields['uf']; }
            if ($fields['bairro'] !== '')      { $sets[] = 'bairro_raw = :bairro_raw';   $params['bairro_raw']  = $fields['bairro']; }
            if ($fields['cep'] !== '')         { $sets[] = 'cep = :cep';                 $params['cep']         = $fields['cep']; }
            if ($fields['cidade_norm'] !== '') { $sets[] = 'cidade_norm = :cidade_norm'; $params['cidade_norm'] = $fields['cidade_norm']; }
            if ($bairroNorm !== null)          { $sets[] = 'bairro_norm = :bairro_norm'; $params['bairro_norm'] = $bairroNorm; }
            if ($fields['endereco'] !== '')    { $sets[] = 'logradouro = :logradouro';   $params['logradouro']  = $fields['endereco']; }
            if ($fields['numero'] !== '')      { $sets[] = 'numero = :numero';           $params['numero']      = $fields['numero']; }
            if ($fields['complemento'] !== '') { $sets[] = 'complemento = :complemento'; $params['complemento'] = $fields['complemento']; }

            if ($oficialBairro !== null) {
                $sets[] = 'bairro_oficial_id_fortaleza = :bairro_id';
                $sets[] = 'bairro_oficial_nome_fortaleza = :bairro_nome';
                $sets[] = "match_source = 'omie_exact'";
                $params['bairro_id']   = $oficialBairro['id'];
                $params['bairro_nome'] = $oficialBairro['nome'];
            }

            $hasCity = $fields['cidade_norm'] !== '';
            $hasNeighborhood = $bairroNorm !== null
                && trim($bairroNorm) !== ''
                && !in_array(
                    strtolower(trim($bairroNorm)),
                    ['nao informado', 'nao_informado', 'sem bairro', 'sem_bairro'],
                    true
                );
            $sets[] = 'geo_status = :geo_status';
            $params['geo_status'] = $hasCity
                ? ($hasNeighborhood ? 'resolved' : 'city_only')
                : 'missing';

            // Skeleton row: garante existência antes do UPDATE (clientes novos ainda não materializados)
            $this->pdo->prepare(
                'INSERT IGNORE INTO biomie_clientes_geo_dim (company_id, codigo_cliente_omie)
                 VALUES (:company_id, :ensure_codigo)'
            )->execute(['company_id' => $companyId, 'ensure_codigo' => $codigoOmie]);

            $this->pdo->prepare(
                'UPDATE biomie_clientes_geo_dim SET ' . implode(', ', $sets)
                . ' WHERE company_id = :company_id AND codigo_cliente_omie = :codigo'
            )->execute($params);
        } catch (Throwable $e) {
            $this->logger->warning('CustomerGeoSyncRepository: falha ao sync geo_dim.', [
                'codigo_cliente_omie' => $codigoOmie,
                'message'             => $e->getMessage(),
            ]);
        }
    }
}
