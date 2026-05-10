<?php

declare(strict_types=1);

namespace BIomie\DataGovernance;

/**
 * Dicionário oficial de segmentos de mercado mapeados a partir das
 * primeiras duas divisões do código CNAE 2.0.
 *
 * Regras canônicas:
 *  - O segmento é derivado dos dois primeiros dígitos numéricos do CNAE.
 *  - CNAEs com divisão 84 → Administração Pública (tratado também em ClientClassifier).
 *  - CNAEs com divisão 94 → Associações e Sindicatos.
 *  - CNAE ausente ou vazio → 'Não classificado'.
 *
 * Esta classe é o ponto único de verdade para segmento.
 * Nenhum outro lugar no código deve mapear CNAE → segmento diretamente.
 */
final class SegmentDictionary
{
    // Valor canônico para cliente sem CNAE informado.
    public const UNCLASSIFIED = 'Não classificado';

    // Mapa: primeiros 2 dígitos do CNAE → nome canônico do segmento.
    // Fonte: Classificação Nacional de Atividades Econômicas (CNAE 2.0 — IBGE).
    private const MAP = [
        // Seção A — Agropecuária, Pecuária, Produção Florestal, Pesca e Aquicultura
        '01' => 'Agropecuária e Pecuária',
        '02' => 'Silvicultura e Florestal',
        '03' => 'Pesca e Aquicultura',
        // Seção B — Indústrias Extrativas
        '05' => 'Mineração de Carvão',
        '06' => 'Petróleo e Gás',
        '07' => 'Mineração de Metais',
        '08' => 'Mineração Não Metálica',
        '09' => 'Atividades de Apoio à Mineração',
        // Seção C — Indústrias de Transformação
        '10' => 'Indústria de Alimentos',
        '11' => 'Indústria de Bebidas',
        '12' => 'Indústria do Fumo',
        '13' => 'Indústria Têxtil',
        '14' => 'Confecção e Vestuário',
        '15' => 'Calçados e Couro',
        '16' => 'Madeira e Mobiliário',
        '17' => 'Papel e Embalagens',
        '18' => 'Gráfica e Editorial',
        '19' => 'Petróleo e Combustíveis',
        '20' => 'Química e Fertilizantes',
        '21' => 'Farmacêutico',
        '22' => 'Borracha e Plásticos',
        '23' => 'Minerais Não Metálicos e Cerâmica',
        '24' => 'Metalurgia',
        '25' => 'Produtos de Metal',
        '26' => 'Equipamentos Eletrônicos e Ópticos',
        '27' => 'Máquinas e Equipamentos Elétricos',
        '28' => 'Máquinas e Equipamentos',
        '29' => 'Automotivo',
        '30' => 'Outros Equipamentos de Transporte',
        '31' => 'Móveis',
        '32' => 'Outros Produtos Industriais',
        '33' => 'Manutenção e Instalação Industrial',
        // Seção D — Eletricidade e Gás
        '35' => 'Energia Elétrica e Gás',
        // Seção E — Água, Esgoto, Atividades de Gestão de Resíduos
        '36' => 'Saneamento e Água',
        '37' => 'Esgoto e Tratamento de Efluentes',
        '38' => 'Coleta e Tratamento de Resíduos',
        '39' => 'Descontaminação e Gestão Ambiental',
        // Seção F — Construção
        '41' => 'Construção Civil',
        '42' => 'Obras de Infraestrutura',
        '43' => 'Serviços Especializados de Construção',
        // Seção G — Comércio, Reparação de Veículos Automotores e Motocicletas
        '45' => 'Comércio de Veículos e Peças',
        '46' => 'Comércio Atacadista',
        '47' => 'Comércio Varejista',
        // Seção H — Transporte, Armazenagem e Correio
        '49' => 'Transporte Terrestre',
        '50' => 'Transporte Aquaviário',
        '51' => 'Transporte Aéreo',
        '52' => 'Armazenagem e Logística',
        '53' => 'Correios e Entregas',
        // Seção I — Alojamento e Alimentação
        '55' => 'Hospedagem e Hotelaria',
        '56' => 'Alimentação e Restaurantes',
        // Seção J — Informação e Comunicação
        '58' => 'Publicação e Mídia',
        '59' => 'Audiovisual e Produção de Conteúdo',
        '60' => 'Rádio e Televisão',
        '61' => 'Telecomunicações',
        '62' => 'TI e Desenvolvimento de Software',
        '63' => 'Serviços de Informação e Dados',
        // Seção K — Atividades Financeiras, de Seguros e Serviços Relacionados
        '64' => 'Serviços Financeiros e Bancários',
        '65' => 'Seguros e Previdência',
        '66' => 'Atividades Auxiliares Financeiras',
        // Seção L — Atividades Imobiliárias
        '68' => 'Imobiliário',
        // Seção M — Atividades Profissionais, Científicas e Técnicas
        '69' => 'Advocacia e Contabilidade',
        '70' => 'Gestão e Consultoria Empresarial',
        '71' => 'Arquitetura e Engenharia',
        '72' => 'Pesquisa e Desenvolvimento',
        '73' => 'Publicidade e Marketing',
        '74' => 'Design, Fotografia e Artes',
        '75' => 'Veterinária',
        // Seção N — Atividades Administrativas e Serviços Complementares
        '77' => 'Locação de Bens Móveis',
        '78' => 'Recursos Humanos e Seleção',
        '79' => 'Agências de Viagem e Turismo',
        '80' => 'Segurança e Vigilância Patrimonial',
        '81' => 'Serviços para Edifícios e Paisagismo',
        '82' => 'Serviços de Apoio Empresarial',
        // Seção O — Administração Pública, Defesa e Seguridade Social
        '84' => 'Administração Pública',
        // Seção P — Educação
        '85' => 'Educação',
        // Seção Q — Saúde Humana e Serviços Sociais
        '86' => 'Saúde Hospitalar e Clínicas',
        '87' => 'Assistência Social com Internação',
        '88' => 'Assistência Social sem Internação',
        // Seção R — Artes, Cultura, Esporte e Recreação
        '90' => 'Artes e Cultura',
        '91' => 'Bibliotecas, Museus e Patrimônio',
        '92' => 'Apostas e Jogos',
        '93' => 'Esporte, Lazer e Recreação',
        // Seção S — Outras Atividades de Serviços
        '94' => 'Associações e Sindicatos',
        '95' => 'Manutenção de Equipamentos Pessoais',
        '96' => 'Serviços Pessoais (Beleza, Funerária, etc.)',
        // Seção T — Serviços Domésticos
        '97' => 'Serviços Domésticos',
        '98' => 'Organismos Extraterritoriais',
        // Seção U — Organismos Internacionais
        '99' => 'Organismos Internacionais',
    ];

    /**
     * Retorna o nome canônico do segmento a partir de um código CNAE.
     *
     * Aceita qualquer formato: "4712-1/00", "47121", "47", etc.
     * Retorna UNCLASSIFIED se o CNAE estiver ausente ou não mapeado.
     */
    public static function fromCnae(?string $cnae): string
    {
        if ($cnae === null || $cnae === '') {
            return self::UNCLASSIFIED;
        }

        $digits  = preg_replace('/\D/', '', $cnae) ?? '';
        $division = substr($digits, 0, 2);

        return self::MAP[$division] ?? self::UNCLASSIFIED;
    }

    /**
     * Retorna verdadeiro se o CNAE pertence ao setor público.
     * Divisão 84 = Administração Pública, Defesa e Seguridade Social.
     */
    public static function isGovernment(?string $cnae): bool
    {
        if ($cnae === null) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $cnae) ?? '';
        return str_starts_with($digits, '84');
    }

    /**
     * Retorna verdadeiro se o CNAE corresponde a associações, sindicatos
     * ou organizações sem fins lucrativos (Divisão 94).
     */
    public static function isAssociation(?string $cnae): bool
    {
        if ($cnae === null) {
            return false;
        }
        $digits = preg_replace('/\D/', '', $cnae) ?? '';
        return str_starts_with($digits, '94');
    }

    /**
     * Retorna o dicionário completo mapeado (divisão → segmento).
     *
     * @return array<string,string>
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
