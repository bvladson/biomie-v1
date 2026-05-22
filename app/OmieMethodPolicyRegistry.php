<?php

declare(strict_types=1);

namespace BIomie;

final class OmieMethodPolicyRegistry
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private const DEFAULT_POLICIES = [
        'ListarPedidos' => [
            'domain' => 'commercial',
            'page_size_target' => 100,
            'max_concurrency' => 2,
            'window' => [
                'strategy' => 'period_list',
                'hot_minutes' => 120,
                'reconcile_days' => 30,
                'notes' => 'Priorizar filtros por data de faturamento ou previsao; item a item apenas para reparo.',
            ],
            'retry' => [
                'max_attempts' => 4,
                'base_delay_ms' => 400,
                'max_delay_ms' => 5000,
            ],
            'fallback' => [
                'criteria' => 'Quebrar por janelas menores quando houver pagina instavel ou volume elevado persistente.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarNF' => [
            'domain' => 'fiscal',
            'page_size_target' => 100,
            'max_concurrency' => 2,
            'window' => [
                'strategy' => 'period_list',
                'hot_minutes' => 1440,
                'reconcile_days' => 60,
                'notes' => 'Usar emissao ou cancelamento conforme o contrato do indicador; reprocessar janela movel para mudancas tardias.',
            ],
            'retry' => [
                'max_attempts' => 4,
                'base_delay_ms' => 600,
                'max_delay_ms' => 7000,
            ],
            'fallback' => [
                'criteria' => 'Se a leitura ampla falhar, repetir por subjanela diaria e deixar reconcile maior corrigir retroativos.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarEtapasPedido' => [
            'domain' => 'order_events',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'event_timeline',
                'hot_minutes' => 120,
                'reconcile_days' => 30,
                'notes' => 'Manter ordenacao temporal e reprocessar com sobreposicao para nao perder eventos fora de ordem.',
            ],
            'retry' => [
                'max_attempts' => 4,
                'base_delay_ms' => 500,
                'max_delay_ms' => 6000,
            ],
            'fallback' => [
                'criteria' => 'Diminuir a janela temporal quando o endpoint devolver volume excessivo ou atraso operacional.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarVendedores' => [
            'domain' => 'master_data',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'periodic_refresh',
                'refresh_minutes' => 60,
                'notes' => 'Cadastro leve; preferir refresh periodico em lote.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 300,
                'max_delay_ms' => 3000,
            ],
            'fallback' => [
                'criteria' => 'Repetir em lote completo; nao ha ganho real em consultar vendedor isolado por item.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarClientesResumido' => [
            'domain' => 'master_data',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'batch_lookup',
                'notes' => 'Consultar por lote de codigos e evitar N+1 absoluto.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 400,
                'max_delay_ms' => 4000,
            ],
            'fallback' => [
                'criteria' => 'Se o resumo falhar ou vier vazio, usar ListarClientes mantendo a mesma lista de codigos.',
                'calls' => ['ListarClientes'],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarClientes' => [
            'domain' => 'master_data',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'batch_lookup',
                'notes' => 'Fallback em lote para cadastro de clientes.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 400,
                'max_delay_ms' => 4000,
            ],
            'fallback' => [
                'criteria' => 'Persistindo falha, reagendar lote para reparo posterior sem abrir consulta item a item em massa.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ConsultarCliente' => [
            'domain' => 'master_data',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'repair_only',
                'notes' => 'Consulta por ID apenas para reparo; nao usar em caminho quente.',
            ],
            'retry' => [
                'max_attempts' => 2,
                'base_delay_ms' => 800,
                'max_delay_ms' => 4000,
            ],
            'fallback' => [
                'criteria' => 'Preferir batch por ListarClientesResumido/ListarClientes; item a item so quando o lote nao resolver.',
                'calls' => ['ListarClientesResumido', 'ListarClientes'],
            ],
            'redundant_cache_ttl_seconds' => 60,
            'redundant_id_keys' => ['codigo_cliente_omie'],
        ],
        'PesquisarCidades' => [
            'domain' => 'master_data',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'cached_lookup',
                'refresh_minutes' => 1440,
                'notes' => 'Cadastro de cidades deve ser consultado em job/cache, nunca no mapa.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 400,
                'max_delay_ms' => 4000,
            ],
            'fallback' => [
                'criteria' => 'Persistindo falha, manter cache local e reprocessar posteriormente.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 3600,
            'redundant_id_keys' => ['cNome', 'cUF', 'nCodIBGE'],
        ],
        'ConsultarPedido' => [
            'domain' => 'commercial',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'repair_only',
                'notes' => 'Consulta de pedido individual apenas para reparo ou rastreabilidade pontual.',
            ],
            'retry' => [
                'max_attempts' => 2,
                'base_delay_ms' => 800,
                'max_delay_ms' => 4000,
            ],
            'fallback' => [
                'criteria' => 'Preferir ListarPedidos por janela antes de consulta item a item.',
                'calls' => ['ListarPedidos'],
            ],
            'redundant_cache_ttl_seconds' => 60,
            'redundant_id_keys' => ['codigo_pedido', 'numero_pedido'],
        ],
        'StatusPedido' => [
            'domain' => 'commercial',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'repair_only',
                'notes' => 'Status pontual de pedido deve ser usado somente em auditoria ou reparo.',
            ],
            'retry' => [
                'max_attempts' => 2,
                'base_delay_ms' => 800,
                'max_delay_ms' => 4000,
            ],
            'fallback' => [
                'criteria' => 'Preferir ListarPedidos no fluxo operacional.',
                'calls' => ['ListarPedidos'],
            ],
            'redundant_cache_ttl_seconds' => 60,
            'redundant_id_keys' => ['codigo_pedido', 'numero_pedido'],
        ],
        'ConsultarNF' => [
            'domain' => 'fiscal',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'repair_only',
                'notes' => 'Consulta de NF individual apenas para rastreabilidade; sync usa ListarNF.',
            ],
            'retry' => [
                'max_attempts' => 2,
                'base_delay_ms' => 800,
                'max_delay_ms' => 4000,
            ],
            'fallback' => [
                'criteria' => 'Preferir ListarNF por janela.',
                'calls' => ['ListarNF'],
            ],
            'redundant_cache_ttl_seconds' => 60,
            'redundant_id_keys' => ['nCodNF', 'nNF', 'cChaveNFe'],
        ],
        'ListarContasCorrentes' => [
            'domain' => 'finance',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'periodic_refresh',
                'refresh_minutes' => 60,
                'notes' => 'Cadastro financeiro de baixa mutacao; manter leitura conservadora.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 400,
                'max_delay_ms' => 4000,
            ],
            'fallback' => [
                'criteria' => 'Repetir em lote completo em vez de consultas granulares.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarMovimentos' => [
            'domain' => 'finance',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'period_list',
                'hot_minutes' => 1440,
                'reconcile_days' => 60,
                'notes' => 'Movimentos financeiros exigem janela mensal ou diaria, evitando varredura excessiva.',
            ],
            'retry' => [
                'max_attempts' => 4,
                'base_delay_ms' => 600,
                'max_delay_ms' => 7000,
            ],
            'fallback' => [
                'criteria' => 'Reexecutar por mes ou subjanela menor quando houver timeout de volume.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'PesquisarLancamentos' => [
            'domain' => 'finance',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'period_list',
                'hot_minutes' => 1440,
                'reconcile_days' => 60,
                'notes' => 'Priorizar busca por periodo; consulta individual fica restrita a reparo.',
            ],
            'retry' => [
                'max_attempts' => 4,
                'base_delay_ms' => 600,
                'max_delay_ms' => 7000,
            ],
            'fallback' => [
                'criteria' => 'Falhando a assinatura principal, usar ListarLancamentos com os mesmos filtros.',
                'calls' => ['ListarLancamentos'],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarLancamentos' => [
            'domain' => 'finance',
            'page_size_target' => 100,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'period_list',
                'hot_minutes' => 1440,
                'reconcile_days' => 60,
                'notes' => 'Fallback operacional para titulos quando PesquisarLancamentos nao responde bem.',
            ],
            'retry' => [
                'max_attempts' => 4,
                'base_delay_ms' => 600,
                'max_delay_ms' => 7000,
            ],
            'fallback' => [
                'criteria' => 'Se ambos falharem, reduzir a janela e reencaminhar para reconcile posterior.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarOrcamentos' => [
            'domain' => 'finance',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'monthly_single_call',
                'notes' => 'Orcamento por mes; manter cadencia leve.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 600,
                'max_delay_ms' => 5000,
            ],
            'fallback' => [
                'criteria' => 'Tentar aliases do endpoint antes de marcar falha do mes.',
                'calls' => ['ListarOrcamento', 'ListarOrcamentosCaixa'],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarOrcamento' => [
            'domain' => 'finance',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'monthly_single_call',
                'notes' => 'Alias de fallback para budget.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 600,
                'max_delay_ms' => 5000,
            ],
            'fallback' => [
                'criteria' => 'Manter a mesma chave de mes e tentar outros aliases conhecidos.',
                'calls' => ['ListarOrcamentos', 'ListarOrcamentosCaixa'],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarOrcamentosCaixa' => [
            'domain' => 'finance',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'monthly_single_call',
                'notes' => 'Alias de fallback para budget de caixa.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 600,
                'max_delay_ms' => 5000,
            ],
            'fallback' => [
                'criteria' => 'Repetir so no mes especifico; sem ampliar escopo de consulta.',
                'calls' => ['ListarOrcamentos', 'ListarOrcamento'],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ListarResumo' => [
            'domain' => 'finance',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'single_period_summary',
                'notes' => 'Resumo agregado; usar apenas quando realmente necessario.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 700,
                'max_delay_ms' => 5000,
            ],
            'fallback' => [
                'criteria' => 'Tentar aliases do resumo; se indisponivel, reconstruir a partir das leituras detalhadas.',
                'calls' => ['ConsultarResumo', 'ObterResumo'],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ConsultarResumo' => [
            'domain' => 'finance',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'single_period_summary',
                'notes' => 'Alias de fallback para resumo financeiro.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 700,
                'max_delay_ms' => 5000,
            ],
            'fallback' => [
                'criteria' => 'Fallback de resumo antes de recorrer a recomputo local.',
                'calls' => ['ListarResumo', 'ObterResumo'],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
        'ObterResumo' => [
            'domain' => 'finance',
            'page_size_target' => 1,
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'single_period_summary',
                'notes' => 'Ultimo alias conhecido para resumo.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 700,
                'max_delay_ms' => 5000,
            ],
            'fallback' => [
                'criteria' => 'Se todos os aliases falharem, montar resumo a partir dos datasets financeiros detalhados.',
                'calls' => ['ListarResumo', 'ConsultarResumo'],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
        ],
    ];

    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $policies = [];
        foreach (self::DEFAULT_POLICIES as $call => $policy) {
            $policies[$call] = $this->forCall($call);
        }

        ksort($policies);
        return $policies;
    }

    /**
     * @return array<string,mixed>
     */
    public function forCall(string $call): array
    {
        $base = self::DEFAULT_POLICIES[$call] ?? $this->genericPolicy($call);
        $override = $this->config->get('omie.method_policy.' . $call, []);
        if (!is_array($override)) {
            $override = [];
        }

        $policy = $this->mergeRecursive($this->withRuntimeDefaults($base), $override);
        $policy['call'] = $call;

        return $policy;
    }

    public function pageSize(string $call, int $fallback = 100): int
    {
        $target = (int) ($this->forCall($call)['page_size_target'] ?? $fallback);
        return max(1, min(100, $target));
    }

    /**
     * @return array<string,mixed>
     */
    private function genericPolicy(string $call): array
    {
        return [
            'domain' => 'generic',
            'page_size_target' => max(1, min(100, (int) $this->config->get('omie.default_page_size', 100))),
            'max_concurrency' => 1,
            'window' => [
                'strategy' => 'on_demand',
                'notes' => 'Metodo sem politica explicita ainda; operar de forma conservadora.',
            ],
            'retry' => [
                'max_attempts' => 3,
                'base_delay_ms' => 400,
                'max_delay_ms' => 5000,
                'hint_cap_ms' => 60000,
            ],
            'runtime' => $this->defaultRuntimePolicy(),
            'fallback' => [
                'criteria' => 'Sem fallback especifico; registrar falha e escalar para reconcile.',
                'calls' => [],
            ],
            'redundant_cache_ttl_seconds' => 0,
            'redundant_id_keys' => [],
            'notes' => 'Politica generica aplicada ao metodo ' . $call . '.',
        ];
    }

    /**
     * @param array<string,mixed> $policy
     * @return array<string,mixed>
     */
    private function withRuntimeDefaults(array $policy): array
    {
        $policy['rate_limit_per_minute'] = max(1, (int) ($policy['rate_limit_per_minute'] ?? 240));
        $policy['global_rate_limit_per_minute'] = max(1, (int) ($policy['global_rate_limit_per_minute'] ?? 960));
        $policy['rate_limit_min_interval_ms'] = max(
            1,
            (int) ($policy['rate_limit_min_interval_ms'] ?? (int) ceil(60000 / $policy['rate_limit_per_minute']))
        );

        $retryDefaults = [
            'hint_cap_ms' => 60000,
        ];
        $policy['retry'] = $this->mergeRecursive($retryDefaults, is_array($policy['retry'] ?? null) ? $policy['retry'] : []);
        $policy['runtime'] = $this->mergeRecursive(
            $this->defaultRuntimePolicy(),
            is_array($policy['runtime'] ?? null) ? $policy['runtime'] : []
        );

        return $policy;
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultRuntimePolicy(): array
    {
        return [
            'web_max_attempts' => 1,
            'web_timeout_seconds' => 8,
            'web_connect_timeout_seconds' => 3,
            'web_max_sleep_ms' => 0,
            'job_max_attempts' => 4,
            'job_timeout_seconds' => 30,
            'job_connect_timeout_seconds' => 10,
            'job_max_sleep_ms' => 60000,
        ];
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $override
     * @return array<string,mixed>
     */
    private function mergeRecursive(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
