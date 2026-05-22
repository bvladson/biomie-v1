(function (window) {
  'use strict';

  const ALIAS_RECORDS = [
    { alias_key: 'boa vista castelao', canonical_key: 'boa vista castelao', motivo: 'separador variante', origem: 'fase_2', ativo: true },
    { alias_key: 'boa vista/castelao', canonical_key: 'boa vista castelao', motivo: 'separador variante', origem: 'fase_2', ativo: true },
    { alias_key: 'boa vista - castelao', canonical_key: 'boa vista castelao', motivo: 'separador variante', origem: 'fase_2', ativo: true },
    { alias_key: 'sao joao tauape', canonical_key: 'sao joao do tauape', motivo: 'preposicao ausente', origem: 'fase_2', ativo: true },
    { alias_key: 'jose walter', canonical_key: 'prefeito jose walter', motivo: 'nome curto conhecido', origem: 'fase_2', ativo: true },
    { alias_key: 'pref jose walter', canonical_key: 'prefeito jose walter', motivo: 'abreviacao conhecida', origem: 'fase_2', ativo: true },
    { alias_key: 'presidente jose walter', canonical_key: 'prefeito jose walter', motivo: 'nome alternativo conhecido', origem: 'fase_2', ativo: true },
    { alias_key: 'cidade dos funcionarios', canonical_key: 'cidade dos funcionarios', motivo: 'alias identitario', origem: 'fase_2', ativo: true },
    { alias_key: 'dionisio torres', canonical_key: 'dionisio torres', motivo: 'alias identitario', origem: 'fase_2', ativo: true },
    { alias_key: 'jose de alencar', canonical_key: 'jose de alencar', motivo: 'alias identitario', origem: 'fase_1', ativo: true },
    { alias_key: 'parque aracagi', canonical_key: 'parque araxa', motivo: 'divergencia dimensao geojson', origem: 'fase_2_auditoria', ativo: true },
    { alias_key: 'parrao', canonical_key: 'parreao', motivo: 'divergencia dimensao geojson', origem: 'fase_2_auditoria', ativo: true },
    { alias_key: 'joquei clube', canonical_key: 'joquei clube', motivo: 'alias identitario', origem: 'legado_geo', ativo: true },
    { alias_key: 'jokey clube', canonical_key: 'joquei clube', motivo: 'grafia alternativa', origem: 'legado_geo', ativo: true },
    { alias_key: 'jockey clube', canonical_key: 'joquei clube', motivo: 'grafia alternativa', origem: 'legado_geo', ativo: true },
    { alias_key: 'praia do futuro', canonical_key: 'praia do futuro i', motivo: 'bairro oficial especifico', origem: 'legado_geo', ativo: true },
    { alias_key: 'sapiranga', canonical_key: 'sapiranga coite', motivo: 'nome curto conhecido', origem: 'legado_geo', ativo: true },
    { alias_key: 'coite', canonical_key: 'sapiranga coite', motivo: 'nome curto conhecido', origem: 'legado_geo', ativo: true },
    { alias_key: 'castelao', canonical_key: 'boa vista castelao', motivo: 'nome curto conhecido', origem: 'legado_geo', ativo: true },
    { alias_key: 'conjunto ceara', canonical_key: 'conjunto ceara i', motivo: 'bairro oficial especifico', origem: 'legado_geo', ativo: true },
    { alias_key: 'eng luciano cavalcante', canonical_key: 'engenheiro luciano cavalcante', motivo: 'abreviacao conhecida', origem: 'legado_geo', ativo: true },
    { alias_key: 'eng luciano', canonical_key: 'engenheiro luciano cavalcante', motivo: 'abreviacao conhecida', origem: 'legado_geo', ativo: true },
    { alias_key: 'luciano cavalcante', canonical_key: 'engenheiro luciano cavalcante', motivo: 'nome curto conhecido', origem: 'legado_geo', ativo: true },
    { alias_key: 'planalto senna', canonical_key: 'planalto ayrton senna', motivo: 'nome curto conhecido', origem: 'legado_geo', ativo: true },
  ];

  const ALIAS_MAP = ALIAS_RECORDS.reduce((map, record) => {
    if (record.ativo) {
      map[record.alias_key] = record;
    }
    return map;
  }, {});

  const ACCENT_MAP = {
    á: 'a', à: 'a', â: 'a', ã: 'a', ä: 'a', å: 'a',
    é: 'e', è: 'e', ê: 'e', ë: 'e',
    í: 'i', ì: 'i', î: 'i', ï: 'i',
    ó: 'o', ò: 'o', ô: 'o', õ: 'o', ö: 'o',
    ú: 'u', ù: 'u', û: 'u', ü: 'u',
    ç: 'c', ñ: 'n',
    Á: 'A', À: 'A', Â: 'A', Ã: 'A', Ä: 'A', Å: 'A',
    É: 'E', È: 'E', Ê: 'E', Ë: 'E',
    Í: 'I', Ì: 'I', Î: 'I', Ï: 'I',
    Ó: 'O', Ò: 'O', Ô: 'O', Õ: 'O', Ö: 'O',
    Ú: 'U', Ù: 'U', Û: 'U', Ü: 'U',
    Ç: 'C', Ñ: 'N',
  };

  function safeString(value) {
    if (value === undefined || value === null) return '';
    try {
      return String(value);
    } catch (error) {
      return '';
    }
  }

  function removeAccents(value) {
    const text = safeString(value);
    try {
      if (typeof text.normalize === 'function') {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
      }
    } catch (error) {
      // Fall through to the manual PT-BR map.
    }
    return text.replace(/[^\u0000-\u007E]/g, (char) => ACCENT_MAP[char] || char);
  }

  function collapseSpaces(value) {
    return safeString(value).replace(/\s+/g, ' ').trim();
  }

  function normalizeBase(value) {
    const trimmed = safeString(value).trim();
    if (!trimmed) {
      return {
        original: value === undefined ? null : value,
        trimmed: '',
        without_accents: '',
        lower: '',
        punctuation_as_space: '',
        collapsed_spaces: '',
      };
    }

    const withoutAccents = removeAccents(trimmed);
    const lower = withoutAccents.toLowerCase();
    const punctuationAsSpace = lower.replace(/[^a-z0-9\s]+/g, ' ');
    const collapsed = collapseSpaces(punctuationAsSpace);

    return {
      original: value === undefined ? null : value,
      trimmed,
      without_accents: withoutAccents,
      lower,
      punctuation_as_space: punctuationAsSpace,
      collapsed_spaces: collapsed,
    };
  }

  function applyNeighborhoodAlias(normalizedKey) {
    const key = safeString(normalizedKey);
    return ALIAS_MAP[key]?.canonical_key || key;
  }

  function normalizeNeighborhoodKey(value) {
    const base = normalizeBase(value);
    return applyNeighborhoodAlias(base.collapsed_spaces);
  }

  function explainNeighborhoodNormalization(value) {
    const base = normalizeBase(value);
    const alias = base.collapsed_spaces ? (ALIAS_MAP[base.collapsed_spaces] || null) : null;
    return Object.assign({}, base, {
      alias_applied: alias,
      final: alias?.canonical_key || base.collapsed_spaces,
      normalizer_engine: 'javascript_nfd',
    });
  }

  function matchConfidence(original, geoName) {
    const source = explainNeighborhoodNormalization(original);
    const target = explainNeighborhoodNormalization(geoName);
    if (!source.final) {
      return { match_type: 'empty', confidence: 0, normalized: source.collapsed_spaces, canonical: source.final };
    }
    if (!target.final || source.final !== target.final) {
      return { match_type: 'missing', confidence: 0, normalized: source.collapsed_spaces, canonical: source.final };
    }
    if (safeString(original).trim() && safeString(original).trim() === safeString(geoName).trim()) {
      return { match_type: 'exact', confidence: 1, normalized: source.collapsed_spaces, canonical: source.final };
    }
    if (source.alias_applied) {
      return { match_type: 'alias', confidence: 0.85, normalized: source.collapsed_spaces, canonical: source.final };
    }
    return { match_type: 'normalized', confidence: 0.95, normalized: source.collapsed_spaces, canonical: source.final };
  }

  window.BIOMIE_GEO_NORMALIZER = {
    aliases: ALIAS_RECORDS.slice(),
    normalizeNeighborhoodKey,
    applyNeighborhoodAlias,
    explainNeighborhoodNormalization,
    matchConfidence,
  };
})(window);
