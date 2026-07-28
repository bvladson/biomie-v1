<?php

declare(strict_types=1);

namespace BIomie\DataPlatform\ReadModel;

use BIomie\DataPlatform\Contracts\CustomerSourceContracts;
use BIomie\DataPlatform\Contracts\ReadModelResponse;
use BIomie\DataPlatform\Contracts\SourceContractRegistry;
use BIomie\DataPlatform\Query\CustomerQuery;
use BIomie\DataPlatform\Repository\CustomerReadRepository;
use BIomie\DataPlatform\Support\CnaeStatusResolver;
use BIomie\DataPlatform\Support\CustomerDocumentClassifier;
use BIomie\DataPlatform\Support\CustomerIdentityResolver;
use BIomie\DataPlatform\Support\CustomerQualityResolver;

final class CustomerReadModel
{
    public function __construct(private readonly CustomerReadRepository $repo) {}

    /** @return array{query_count:int,sql_time_ms:float} */
    public function performanceMetrics(): array
    {
        return $this->repo->metrics();
    }

    public function resetPerformanceMetrics(): void
    {
        $this->repo->resetMetrics();
    }

    /**
     * @param list<string|int|null> $codes
     * @return array<string,array<string,mixed>>
     */
    public function profilesByCustomerCodes(array $codes, bool $canViewFullDocument = false): array
    {
        $profiles = [];
        foreach ($this->repo->profilesByCustomerCodes($codes) as $code => $raw) {
            $profiles[(string) $code] = $this->buildCustomerPayload($raw, $canViewFullDocument);
        }

        return $profiles;
    }

    public function profile(CustomerQuery $query, bool $canViewFullDocument = false): ReadModelResponse
    {
        $raw = $this->repo->profile($query);
        $customer = $this->buildCustomerPayload($raw, $canViewFullDocument);

        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CustomerSourceContracts::PROFILE),
            data: ['customer' => $customer],
            meta: $this->meta($query, 'CustomerReadModel') + [
                'source_mode' => $raw['source'] ?? 'unknown',
                'pending_enrichment' => !($raw['has_dim_profile'] ?? false),
            ],
            insights: $this->profileInsights($customer),
            warnings: $this->profileWarnings($customer),
        );
    }

    public function search(CustomerQuery $query): ReadModelResponse
    {
        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CustomerSourceContracts::SEARCH),
            data: ['items' => $this->repo->search($query)],
            meta: $this->meta($query, 'CustomerReadModel'),
        );
    }

    public function quality(CustomerQuery $query): ReadModelResponse
    {
        $quality = $this->repo->quality($query);

        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CustomerSourceContracts::QUALITY),
            data: ['quality' => $quality],
            meta: $this->meta($query, 'CustomerReadModel'),
            insights: $this->qualityInsights($quality),
            warnings: $this->qualityWarnings($quality),
        );
    }

    public function opportunities(CustomerQuery $query): ReadModelResponse
    {
        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CustomerSourceContracts::OPPORTUNITIES),
            data: ['items' => $this->repo->opportunities($query)],
            meta: $this->meta($query, 'CustomerReadModel'),
            insights: ['Priorize clientes com faturamento e cadastro incompleto antes de abrir novas frentes comerciais.'],
        );
    }

    public function commercialHistory(CustomerQuery $query): ReadModelResponse
    {
        return new ReadModelResponse(
            ok: true,
            sourceContract: SourceContractRegistry::get(CustomerSourceContracts::COMMERCIAL_HISTORY),
            data: $this->repo->commercialHistory($query),
            meta: $this->meta($query, 'CustomerReadModel'),
        );
    }

    /** @return list<array<string,mixed>> */
    public function sampleCustomers(int $limit = 10): array
    {
        return $this->repo->sampleCustomers($limit);
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function buildCustomerPayload(array $raw, bool $canViewFullDocument): array
    {
        $identity = (array) ($raw['identity'] ?? []);
        $geo = (array) ($raw['geo'] ?? []);
        $cnae = (array) ($raw['cnae'] ?? []);
        $commercial = (array) ($raw['commercial'] ?? []);
        $ownership = (array) ($raw['ownership'] ?? []);
        $documentRaw = (string) (($identity['cpf_cnpj'] ?? '') ?: ($identity['documento_normalizado'] ?? ''));
        $document = CustomerDocumentClassifier::classify($documentRaw, $canViewFullDocument);

        $identityForQuality = [
            'document_type' => $document['type'],
            'document_status' => $document['status'],
        ];
        $quality = CustomerQualityResolver::resolve(
            $identityForQuality,
            $geo,
            $cnae,
            $commercial,
            $ownership,
            (bool) ($raw['has_dim_profile'] ?? false),
        );

        $codigo = (string) ($identity['codigo_cliente_omie'] ?? '');
        $nome = (string) (($identity['nome_cliente'] ?? '') ?: ($identity['cliente_nome_exibicao'] ?? '') ?: 'Cliente pendente de enriquecimento');

        return [
            'customer_key' => CustomerIdentityResolver::customerKey($codigo, $document['digits'], $nome),
            'codigo_cliente_omie' => $codigo,
            'nome' => $nome,
            'nome_fantasia' => (string) ($identity['nome_fantasia'] ?? ''),
            'source' => (string) ($raw['source'] ?? 'unknown'),
            'pending_enrichment' => !($raw['has_dim_profile'] ?? false),
            'document' => [
                'type' => $document['type'],
                'label' => $document['label'],
                'masked' => $document['masked'],
                'status' => $document['status'],
            ],
            'geo' => [
                'cidade_key' => (string) ($geo['cidade_key'] ?? ''),
                'cidade_label' => (string) ($geo['cidade_label'] ?? ''),
                'bairro_key' => (string) ($geo['bairro_key'] ?? ''),
                'bairro_label' => (string) ($geo['bairro_label'] ?? ''),
                'uf' => (string) ($geo['uf'] ?? ''),
                'cep' => (string) ($geo['cep'] ?? ''),
                'quality' => $this->geoQuality($geo),
                'source' => (string) ($geo['source'] ?? ''),
                'confidence' => $geo['confidence'] ?? null,
            ],
            'cnae' => [
                'status' => (string) ($cnae['status'] ?? CnaeStatusResolver::UNKNOWN),
                'status_label' => (string) ($cnae['status_label'] ?? CnaeStatusResolver::label((string) ($cnae['status'] ?? CnaeStatusResolver::UNKNOWN))),
                'codigo' => $cnae['codigo'] ?? null,
                'descricao' => $cnae['descricao'] ?? null,
                'source' => (string) ($cnae['source'] ?? ''),
                'confidence' => $cnae['confidence'] ?? null,
                'unknown_reason' => (string) ($cnae['unknown_reason'] ?? ''),
            ],
            'commercial' => [
                'first_purchase_date' => $commercial['first_purchase_date'] ?? null,
                'last_purchase_date' => $commercial['last_purchase_date'] ?? null,
                'revenue_period' => (float) ($commercial['revenue_period'] ?? 0),
                'invoices_period' => (int) ($commercial['invoices_period'] ?? 0),
                'orders_period' => (int) ($commercial['orders_period'] ?? 0),
                'active_months' => (int) ($commercial['active_months'] ?? 0),
                'recurrence_status' => (string) ($commercial['recurrence_status'] ?? 'sem_historico'),
                'open_amount' => (float) ($commercial['open_amount'] ?? 0),
            ],
            'ownership' => [
                'seller_id' => (string) ($ownership['seller_id'] ?? ''),
                'seller_name' => (string) ($ownership['seller_name'] ?? ''),
                'owner_source' => (string) ($ownership['owner_source'] ?? 'missing'),
                'confidence' => $ownership['confidence'] ?? null,
            ],
            'quality' => $quality,
        ];
    }

    /** @return array<string,mixed> */
    private function meta(CustomerQuery $query, string $readModel): array
    {
        return [
            'period' => $query->periodMeta(),
            'filters' => $query->filtersMeta(),
            'freshness' => $this->repo->freshness(),
            'read_model' => $readModel,
        ];
    }

    /** @param array<string,mixed> $geo */
    private function geoQuality(array $geo): string
    {
        $city = trim((string) ($geo['cidade_key'] ?? ''));
        $bairro = trim((string) ($geo['bairro_key'] ?? ''));
        if ($city !== '' && $bairro !== '') {
            $quality = trim((string) ($geo['quality'] ?? ''));
            if ($quality === '' || $quality === 'unknown' || $quality === 'pending_enrichment') {
                return 'ok';
            }
            return $quality;
        }
        if ($city !== '') {
            return 'geo_sem_bairro';
        }
        return 'geo_sem_cidade';
    }

    /**
     * @param array<string,mixed> $customer
     * @return list<string>
     */
    private function profileInsights(array $customer): array
    {
        if (($customer['pending_enrichment'] ?? false) === true) {
            return ['Cliente possui movimento comercial, mas ainda nao tem golden record completo em clientes_dim.'];
        }
        if (($customer['quality']['status'] ?? '') !== CustomerQualityResolver::COMPLETE) {
            return ['Cliente com cadastro incompleto deve entrar na fila de governanca antes de analises comerciais finas.'];
        }
        return [];
    }

    /**
     * @param array<string,mixed> $customer
     * @return list<string>
     */
    private function profileWarnings(array $customer): array
    {
        $warnings = [];
        if (($customer['cnae']['status'] ?? '') === CnaeStatusResolver::MISSING_CNPJ) {
            $warnings[] = 'CNPJ com faturamento sem CNAE; oportunidade B2B depende de enriquecimento.';
        }
        if (in_array('missing_geo', (array) ($customer['quality']['missing'] ?? []), true)) {
            $warnings[] = 'Cliente sem cidade/bairro confiavel; mapa deve tratar como qualidade de dados.';
        }
        return $warnings;
    }

    /**
     * @param array<string,mixed> $quality
     * @return list<string>
     */
    private function qualityInsights(array $quality): array
    {
        $impact = (array) ($quality['impact'] ?? []);
        if ((float) ($impact['missing_cnae_cnpj_revenue'] ?? 0) > 0) {
            return ['CNPJ sem CNAE tem impacto financeiro e deve orientar enriquecimento B2B.'];
        }
        return ['CPF sem CNAE nao e erro; qualidade deve focar CNPJ, geo e ownership.'];
    }

    /**
     * @param array<string,mixed> $quality
     * @return list<string>
     */
    private function qualityWarnings(array $quality): array
    {
        $customers = (array) ($quality['customers'] ?? []);
        $warnings = [];
        if ((int) ($customers['missing_city'] ?? 0) > 0) {
            $warnings[] = 'Existem clientes sem cidade no golden record.';
        }
        if ((int) ($customers['cnpj_missing_cnae'] ?? 0) > 0) {
            $warnings[] = 'Existem CNPJs sem CNAE no cadastro de cliente.';
        }
        return $warnings;
    }
}
