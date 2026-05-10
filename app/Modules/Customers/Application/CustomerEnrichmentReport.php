<?php

declare(strict_types=1);

namespace BIomie\Modules\Customers\Application;

final class CustomerEnrichmentReport
{
    /**
     * Retorna o array de relatório zerado para iniciar um ciclo de enriquecimento.
     *
     * @return array<string,mixed>
     */
    public static function empty(): array
    {
        return [
            'ok'                         => false,
            'status'                     => 'pending',
            'mode'                       => 'local_first',
            'dry_run'                    => false,
            'limit'                      => 25,
            'offset'                     => 0,
            'pagina'                     => 1,
            'pagina_atual'               => 1,
            'total_paginas_omie'         => 0,
            'total_registros_omie'       => 0,
            'clientes_locais_lidos'      => 0,
            'total_eligible'             => 0,
            'clientes_consultados_omie'  => 0,
            'clientes_atualizados'       => 0,
            'clientes_ignorados'         => 0,
            'clientes_nao_encontrados'   => 0,
            'api_errors'                 => 0,
            'api_rate_limited'           => 0,
            'bronze_saved'               => 0,
            'clientes_com_cidade'        => 0,
            'clientes_com_bairro'        => 0,
            'clientes_com_cnae'          => 0,
            'clientes_sem_bairro'        => 0,
            'clientes_sem_cnae'          => 0,
            'bairros_matched'            => 0,
            'bairros_unmatched'          => 0,
            'queued'                     => 0,
            'not_found'                  => 0,
            'cidade_atualizados'         => 0,
            'bairro_atualizados'         => 0,
            'cnae_atualizados'           => 0,
            'has_more'                   => false,
            'next_offset'                => null,
            'preview'                    => [],
            'error_message'              => null,
            'duration_ms'                => 0,
        ];
    }
}
