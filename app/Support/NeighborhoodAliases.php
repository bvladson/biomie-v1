<?php

declare(strict_types=1);

namespace BIomie\Support;

final class NeighborhoodAliases
{
    /**
     * @return array<string,array{alias_key:string,canonical_key:string,motivo:string,origem:string,ativo:bool}>
     */
    public static function records(): array
    {
        $records = [
            ['boa vista castelao', 'boa vista castelao', 'separador variante', 'fase_2', true],
            ['boa vista/castelao', 'boa vista castelao', 'separador variante', 'fase_2', true],
            ['boa vista - castelao', 'boa vista castelao', 'separador variante', 'fase_2', true],
            ['sao joao tauape', 'tauape', 'nome historico validado', 'fase_2', true],
            ['sao joao do tauape', 'tauape', 'nome historico validado', 'geo_r2_quality', true],
            ['jose walter', 'prefeito jose walter', 'nome curto conhecido', 'fase_2', true],
            ['pref jose walter', 'prefeito jose walter', 'abreviacao conhecida', 'fase_2', true],
            ['presidente jose walter', 'prefeito jose walter', 'nome alternativo conhecido', 'fase_2', true],
            ['cidade dos funcionarios', 'cidade dos funcionarios', 'alias identitario', 'fase_2', true],
            ['dionisio torres', 'dionisio torres', 'alias identitario', 'fase_2', true],
            ['jose de alencar', 'jose de alencar', 'alias identitario', 'fase_1', true],
            ['parque aracagi', 'parque araxa', 'divergencia dimensao geojson', 'fase_2_auditoria', true],
            ['parrao', 'parreao', 'divergencia dimensao geojson', 'fase_2_auditoria', true],
            ['joquei clube', 'joquei clube', 'alias identitario', 'legado_geo', true],
            ['jokey clube', 'joquei clube', 'grafia alternativa', 'legado_geo', true],
            ['jockey clube', 'joquei clube', 'grafia alternativa', 'legado_geo', true],
            ['praia do futuro', 'praia do futuro i', 'bairro oficial especifico', 'legado_geo', true],
            ['sapiranga', 'sapiranga coite', 'nome curto conhecido', 'legado_geo', true],
            ['coite', 'sapiranga coite', 'nome curto conhecido', 'legado_geo', true],
            ['castelao', 'boa vista castelao', 'nome curto conhecido', 'legado_geo', true],
            ['conjunto ceara', 'conjunto ceara i', 'bairro oficial especifico', 'legado_geo', true],
            ['eng luciano cavalcante', 'engenheiro luciano cavalcante', 'abreviacao conhecida', 'legado_geo', true],
            ['eng luciano', 'engenheiro luciano cavalcante', 'abreviacao conhecida', 'legado_geo', true],
            ['luciano cavalcante', 'engenheiro luciano cavalcante', 'nome curto conhecido', 'legado_geo', true],
            ['planalto senna', 'planalto ayrton senna', 'nome curto conhecido', 'legado_geo', true],
            ['jardim da oliveiras', 'jardim das oliveiras', 'grafia validada por auditoria', 'geo_r2_quality', true],
            ['plalto ayton senna', 'planalto ayrton senna', 'grafia validada por auditoria', 'geo_r2_quality', true],
            ['cj ceara', 'conjunto ceara i', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['vicente pizon', 'vicente pinzon', 'grafia validada por auditoria', 'geo_r2_quality', true],
            ['vl velha', 'vila velha', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['jacarenganga', 'jacarecanga', 'grafia validada por auditoria', 'geo_r2_quality', true],
            ['guarapapes', 'guararapes', 'grafia validada por auditoria', 'geo_r2_quality', true],
            ['vl peri', 'vila peri', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['prq manibura', 'parque manibura', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['prq dois irmaos', 'parque dois irmaos', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['manuel satiro', 'manoel satiro', 'grafia validada por auditoria', 'geo_r2_quality', true],
            ['pan americano', 'panamericano', 'grafia validada por auditoria', 'geo_r2_quality', true],
            ['prq iracema', 'parque iracema', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['lga redonda', 'lagoa redonda', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['planalto airton sena', 'planalto ayrton senna', 'grafia validada por auditoria', 'geo_r2_quality', true],
            ['d lustosa', 'dom lustosa', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['cj esperanca', 'conjunto esperanca', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['prq genibau', 'genibau', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['parque genibau', 'genibau', 'nome alternativo validado por auditoria', 'geo_r2_quality', true],
            ['pres kennedy', 'presidente kennedy', 'abreviacao validada por auditoria', 'geo_r2_quality', true],
            ['vila ellery', 'ellery', 'nome alternativo validado por auditoria', 'geo_r2_quality', true],
            ['parque santa maria ancuri', 'parque santa maria', 'nome oficial validado por auditoria', 'geo_r2_quality', true],
            ['vila manoel satiro', 'manoel satiro', 'nome alternativo validado por auditoria', 'geo_r2_quality', true],
            ['boa vista', 'boa vista castelao', 'nome oficial composto validado', 'geo_r2_quality', true],
        ];

        $indexed = [];
        foreach ($records as [$aliasKey, $canonicalKey, $motivo, $origem, $ativo]) {
            $indexed[$aliasKey] = [
                'alias_key' => $aliasKey,
                'canonical_key' => $canonicalKey,
                'motivo' => $motivo,
                'origem' => $origem,
                'ativo' => $ativo,
            ];
        }

        return $indexed;
    }

    /** @return array<string,string> */
    public static function activeMap(): array
    {
        $map = [];
        foreach (self::records() as $record) {
            if ($record['ativo']) {
                $map[$record['alias_key']] = $record['canonical_key'];
            }
        }
        return $map;
    }

    /** @return array{alias_key:string,canonical_key:string,motivo:string,origem:string,ativo:bool}|null */
    public static function find(string $aliasKey): ?array
    {
        $record = self::records()[$aliasKey] ?? null;
        return $record !== null && $record['ativo'] ? $record : null;
    }
}
