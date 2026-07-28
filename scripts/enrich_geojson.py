#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
GEO.R2.3 - GeoJSON Enrichment Script

Enriquece os 3 GeoJSONs do mapa composto (Visao multiescala do Ceara):
  - centroid {lat, lng}            via pyproj/shapely (centroide metrico)
  - bounds   [S, W, N, E]          para fitBounds instantaneo no cliente
  - area_km2                        via reprojecao EPSG:31984
  - fortaleza_bairros.json:         normalizacao completa (join_key, bairro_norm,
                                    label, regional_administrativa, ibge_code)
  - municipios: populacao 2022      via IBGE Censo API
  - municipios: meso_regiao_ibge    via IBGE localidades API
  - municipios: regiao_planejamento via spatial join com ceara_regioes_ipece
  - ibge_code unificado             em todas as features

Usage:
    python scripts/enrich_geojson.py
"""

import gzip
import json
import re
import sys
import unicodedata
import urllib.error
import urllib.request
from pathlib import Path

from pyproj import Transformer
from shapely.geometry import Point, shape
from shapely.ops import transform as shapely_transform

# -- Paths -----------------------------------------------------------------

ROOT    = Path(__file__).resolve().parent.parent
GEO_DIR = ROOT / 'assets' / 'geo'

F_FORTALEZA = GEO_DIR / 'fortaleza_bairros.json'
F_RMF       = GEO_DIR / 'rmf_bairros_ibge_2022.geojson'
F_CEARA     = GEO_DIR / 'ceara_municipios_oficial.geojson'
F_REGIOES   = GEO_DIR / 'ceara_regioes_ipece_oficial.geojson'

FORTALEZA_IBGE = '2304400'

# -- Normalizacao ----------------------------------------------------------

def normalize(s):
    if not s:
        return ''
    s = str(s).strip().lower()
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode('ascii')
    s = re.sub(r'[^a-z0-9]+', '_', s)
    return s.strip('_')

# -- Transformador de projecao ---------------------------------------------
# EPSG:4674 (SIRGAS 2000 geografico) -> EPSG:31984 (SIRGAS/UTM zone 24S)

_to_utm   = Transformer.from_crs('EPSG:4674', 'EPSG:31984', always_xy=True)
_from_utm = Transformer.from_crs('EPSG:31984', 'EPSG:4674', always_xy=True)


def enrich_geom(geom_data):
    """Computa centroid, bounds e area_km2 para uma geometria GeoJSON."""
    geom = shape(geom_data)
    proj = shapely_transform(_to_utm.transform, geom)

    area_km2 = round(proj.area / 1_000_000, 3)

    cx_m, cy_m = proj.centroid.x, proj.centroid.y
    cx, cy = _from_utm.transform(cx_m, cy_m)
    centroid = {'lat': round(cy, 6), 'lng': round(cx, 6)}

    b = geom.bounds  # (minx=W, miny=S, maxx=E, maxy=N)
    bounds = [round(b[1], 6), round(b[0], 6), round(b[3], 6), round(b[2], 6)]

    return {'centroid': centroid, 'bounds': bounds, 'area_km2': area_km2}


# -- IBGE APIs -------------------------------------------------------------

def _fetch_json(url, timeout=20):
    req = urllib.request.Request(url, headers={
        'User-Agent': 'biomie-geo-enrichment/2.3',
        'Accept-Encoding': 'gzip, deflate',
    })
    with urllib.request.urlopen(req, timeout=timeout) as r:
        raw = r.read()
    if raw[:2] == b'\x1f\x8b':
        raw = gzip.decompress(raw)
    return json.loads(raw)


def fetch_ibge_population():
    """Retorna {codigo_ibge_7d: int} para municipios CE - Censo 2022."""
    urls = [
        ('https://servicodados.ibge.gov.br/api/v3/agregados/4709/'
         'periodos/2022/variaveis/93?localidades=N6[23*]'),
        ('https://servicodados.ibge.gov.br/api/v3/agregados/4714/'
         'periodos/2022/variaveis/9324?localidades=N6[23*]'),
    ]
    for url in urls:
        try:
            data = _fetch_json(url)
            pop = {}
            for var in data:
                for result in var.get('resultados', []):
                    for serie in result.get('series', []):
                        ibge = str(serie.get('localidade', {}).get('id', ''))
                        for val in serie.get('serie', {}).values():
                            if val and val not in ('...', '-', ''):
                                try:
                                    pop[ibge] = int(str(val).replace('.', '').replace(',', ''))
                                except ValueError:
                                    pass
                                break
            if pop:
                print('  IBGE populacao: %d municipios carregados.' % len(pop))
                return pop
        except Exception as exc:
            print('  [WARN] %s... falhou: %s' % (url[:55], exc), file=sys.stderr)
    print('  [WARN] IBGE populacao indisponivel - campo ficara null.', file=sys.stderr)
    return {}


def fetch_ibge_mesoregions():
    """Retorna {codigo_ibge_7d: {meso_regiao_id, meso_regiao_nome, ...}}."""
    url = 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/CE/municipios?view=nivelado'
    try:
        data = _fetch_json(url)
        result = {}
        for m in data:
            ibge = str(m.get('municipio-id', '')).strip()
            if ibge:
                result[ibge] = {
                    'meso_regiao_id':    m.get('mesorregiao-id'),
                    'meso_regiao_nome':  m.get('mesorregiao-nome', ''),
                    'micro_regiao_id':   m.get('microrregiao-id'),
                    'micro_regiao_nome': m.get('microrregiao-nome', ''),
                }
        print('  IBGE mesorregioes: %d municipios carregados.' % len(result))
        return result
    except Exception as exc:
        print('  [WARN] IBGE mesorregioes API falhou: %s' % exc, file=sys.stderr)
        return {}


# -- Indice espacial de regioes de planejamento IPECE ----------------------

def build_region_index(geojson):
    index = []
    for feat in geojson.get('features', []):
        geom = shape(feat['geometry'])
        index.append((feat.get('properties', {}), geom))
    return index


def find_region(lat, lng, index):
    pt = Point(lng, lat)
    for rprops, rgeom in index:
        try:
            if rgeom.contains(pt):
                return rprops
        except Exception:
            continue
    # Fallback: regiao com centroide mais proximo
    try:
        best = min(index, key=lambda x: pt.distance(x[1].centroid))
        return best[0]
    except Exception:
        return None


# -- Normalizacao do fortaleza_bairros.json --------------------------------

_REGIONAL_SHORT = {
    'Secretaria Executiva Regional 1':  'SER I',
    'Secretaria Executiva Regional 2':  'SER II',
    'Secretaria Executiva Regional 3':  'SER III',
    'Secretaria Executiva Regional 4':  'SER IV',
    'Secretaria Executiva Regional 5':  'SER V',
    'Secretaria Executiva Regional 6':  'SER VI',
    'Secretaria Executiva Regional 7':  'SER VII',
    'Secretaria Executiva Regional 8':  'SER VIII',
    'Secretaria Executiva Regional 9':  'SER IX',
    'Secretaria Executiva Regional 10': 'SER X',
    'Secretaria Executiva Regional 11': 'SER XI',
    'Secretaria Executiva Regional 12': 'SER XII',
}


def _get_prop(props, *candidates, default=None):
    """Retorna o primeiro candidato encontrado nas props."""
    for key in candidates:
        if key in props and props[key] is not None:
            return props[key]
    return default


def normalize_fortaleza_feature(feat, geo_extras):
    p = feat.get('properties', {})

    # Idempotente: aceita tanto o formato IPLANFOR original quanto o ja normalizado
    nome = str(_get_prop(p, 'Nome', 'nome', 'bairro_nome', default='') or '').strip()
    bairro_norm = normalize(nome)

    # Area: campo IPLANFOR em hectares -> km2 OU ja como area_km2 (idempotente)
    area_km2 = geo_extras.get('area_km2') or 0.0
    if not area_km2:
        for key in list(p.keys()):
            if 'rea' in key.lower() and 'ha' in key.lower():
                try:
                    area_km2 = round(float(p[key]) / 100, 3)
                except (ValueError, TypeError):
                    pass
                break
        if not area_km2 and p.get('area_km2'):
            area_km2 = float(p['area_km2'])

    # Regional administrativa (aceita nome completo ou abreviacao SER X)
    regional_raw = str(_get_prop(p, 'Regional Atual', 'Regional Antiga',
                                  'regional_administrativa', default='') or '').strip()
    if regional_raw.startswith('SER '):
        regional_short = regional_raw
        regional_norm  = normalize(regional_raw.replace('SER ', 'ser_'))
    else:
        regional_short = _REGIONAL_SHORT.get(regional_raw, regional_raw) or None
        regional_norm  = normalize(
            regional_raw.replace('Secretaria Executiva Regional ', 'ser_')
        ) if regional_raw else None

    # Codigo do bairro
    bairro_id = int(p.get('bairro_id', 0) or 0)
    if not bairro_id:
        for key in list(p.keys()):
            if 'bairro' in key.lower() and 'digo' in key.lower():
                try:
                    bairro_id = int(float(p[key] or 0))
                except (ValueError, TypeError):
                    bairro_id = 0
                break

    new_props = {
        # Identidade / fonte
        'layer_id':                     'fortaleza_bairros',
        'source':                       'IPLANFOR',
        'source_year':                  int(_get_prop(p, 'ano_ref', default=2019) or 2019),
        'crs_origin':                   'EPSG:4674',
        # Localizacao municipal
        'ibge_code':                    FORTALEZA_IBGE,
        'municipio_ibge':               FORTALEZA_IBGE,
        'cidade_key':                   'fortaleza',
        'municipio_nome':               'Fortaleza',
        'municipio_norm':               'fortaleza',
        # Bairro - formato padronizado igual ao RMF
        'bairro_id':                    bairro_id,
        'bairro_nome':                  nome,
        'bairro_norm':                  bairro_norm,
        'bairro_key':                   bairro_norm,   # JS usa props.bairro_key em getFeatureCompositeJoinKey
        'join_key':                     bairro_norm,   # Fortaleza: so bairro_norm (sem prefixo de cidade)
        'label':                        nome,
        # Area
        'area_km2':                     area_km2,
        # Administrativa
        'regional_administrativa':      regional_short,
        'regional_administrativa_norm': regional_norm,
        # Regiao de planejamento IPECE — Fortaleza pertence sempre a Grande Fortaleza
        'regiao_planejamento_id':       1,
        'regiao_planejamento_nome':     'Grande Fortaleza',
        'regiao_planejamento_norm':     'grande_fortaleza',
        # Geometrica (enriquecimento)
        'centroid':                     geo_extras.get('centroid'),
        'bounds':                       geo_extras.get('bounds'),
    }

    return {**feat, 'properties': new_props}


# -- Enriquecimento municipios CE ------------------------------------------

def enrich_municipio_feature(feat, geo_extras, pop_map, meso_map, region_index):
    p    = feat.get('properties', {})
    ibge = str(p.get('codigo_ibge', p.get('municipio_ibge', ''))).strip()

    new_props = dict(p)
    new_props['ibge_code'] = ibge
    new_props['centroid']  = geo_extras.get('centroid')
    new_props['bounds']    = geo_extras.get('bounds')
    new_props['area_km2']  = geo_extras.get('area_km2')
    new_props['populacao'] = pop_map.get(ibge)

    meso = meso_map.get(ibge, {})
    new_props['meso_regiao_id']    = meso.get('meso_regiao_id')
    new_props['meso_regiao_nome']  = meso.get('meso_regiao_nome')
    new_props['micro_regiao_id']   = meso.get('micro_regiao_id')
    new_props['micro_regiao_nome'] = meso.get('micro_regiao_nome')

    if region_index and geo_extras.get('centroid'):
        c = geo_extras['centroid']
        rprops = find_region(c['lat'], c['lng'], region_index)
        if rprops:
            new_props['regiao_planejamento_id']   = rprops.get('regiao_id')
            new_props['regiao_planejamento_nome'] = rprops.get('regiao_nome')
            new_props['regiao_planejamento_norm'] = rprops.get('regiao_norm')

    return {**feat, 'properties': new_props}


# -- Enriquecimento bairros RMF --------------------------------------------

def enrich_rmf_feature(feat, geo_extras, meso_map, region_index):
    p    = feat.get('properties', {})
    ibge = str(p.get('municipio_ibge', p.get('CD_MUN', ''))).strip()

    new_props = dict(p)
    new_props['ibge_code'] = ibge
    new_props['centroid']  = geo_extras.get('centroid')
    new_props['bounds']    = geo_extras.get('bounds')
    new_props['area_km2']  = geo_extras.get('area_km2')

    meso = meso_map.get(ibge, {})
    new_props['meso_regiao_id']   = meso.get('meso_regiao_id')
    new_props['meso_regiao_nome'] = meso.get('meso_regiao_nome')

    if region_index and geo_extras.get('centroid'):
        c = geo_extras['centroid']
        rprops = find_region(c['lat'], c['lng'], region_index)
        if rprops:
            new_props['regiao_planejamento_id']   = rprops.get('regiao_id')
            new_props['regiao_planejamento_nome'] = rprops.get('regiao_nome')
            new_props['regiao_planejamento_norm'] = rprops.get('regiao_norm')

    return {**feat, 'properties': new_props}


# -- Main ------------------------------------------------------------------

def main():
    print('GEO.R2.3 - Enriquecimento de GeoJSONs')
    print('=' * 52)

    # 1. Carregar GeoJSONs
    print('\n[1/7] Carregando GeoJSONs...')
    with open(F_FORTALEZA, encoding='utf-8') as f:
        fortaleza_raw = json.load(f)
    with open(F_RMF, encoding='utf-8') as f:
        rmf_raw = json.load(f)
    with open(F_CEARA, encoding='utf-8') as f:
        ceara_raw = json.load(f)
    with open(F_REGIOES, encoding='utf-8') as f:
        regioes_raw = json.load(f)
    print('  fortaleza_bairros:        %3d features' % len(fortaleza_raw['features']))
    print('  rmf_bairros_ibge_2022:    %3d features' % len(rmf_raw['features']))
    print('  ceara_municipios_oficial: %3d features' % len(ceara_raw['features']))
    print('  ceara_regioes_ipece:       %2d regioes' % len(regioes_raw['features']))

    # 2. Buscar dados IBGE
    print('\n[2/7] Buscando dados IBGE...')
    pop_map  = fetch_ibge_population()
    meso_map = fetch_ibge_mesoregions()

    # 3. Indice espacial das regioes IPECE
    print('\n[3/7] Construindo indice espacial de regioes IPECE...')
    region_index = build_region_index(regioes_raw)
    print('  %d poligonos indexados.' % len(region_index))

    # 4. Enriquecer Fortaleza
    print('\n[4/7] Normalizando + enriquecendo fortaleza_bairros.json...')
    new_fortaleza = []
    n_for = len(fortaleza_raw['features'])
    for i, feat in enumerate(fortaleza_raw['features']):
        geo = enrich_geom(feat['geometry'])
        new_fortaleza.append(normalize_fortaleza_feature(feat, geo))
    print('  %d bairros processados.' % n_for)

    # 5. Enriquecer RMF
    print('\n[5/7] Enriquecendo rmf_bairros_ibge_2022.geojson...')
    new_rmf = []
    n_rmf = len(rmf_raw['features'])
    for i, feat in enumerate(rmf_raw['features']):
        geo = enrich_geom(feat['geometry'])
        new_rmf.append(enrich_rmf_feature(feat, geo, meso_map, region_index))
    print('  %d bairros processados.' % n_rmf)

    # 6. Enriquecer municipios CE
    print('\n[6/7] Enriquecendo ceara_municipios_oficial.geojson...')
    new_ceara = []
    n_ce = len(ceara_raw['features'])
    for i, feat in enumerate(ceara_raw['features']):
        geo = enrich_geom(feat['geometry'])
        new_ceara.append(enrich_municipio_feature(feat, geo, pop_map, meso_map, region_index))
    print('  %d municipios processados.' % n_ce)

    # 7. Salvar
    print('\n[7/7] Salvando GeoJSONs enriquecidos...')

    with open(F_FORTALEZA, 'w', encoding='utf-8') as f:
        json.dump({'type': 'FeatureCollection', 'features': new_fortaleza},
                  f, ensure_ascii=False, separators=(',', ':'))
    print('  [OK] %s  (%d KB)' % (F_FORTALEZA.name, F_FORTALEZA.stat().st_size // 1024))

    with open(F_RMF, 'w', encoding='utf-8') as f:
        json.dump({'type': 'FeatureCollection', 'features': new_rmf},
                  f, ensure_ascii=False, separators=(',', ':'))
    print('  [OK] %s  (%d KB)' % (F_RMF.name, F_RMF.stat().st_size // 1024))

    with open(F_CEARA, 'w', encoding='utf-8') as f:
        json.dump({'type': 'FeatureCollection', 'features': new_ceara},
                  f, ensure_ascii=False, separators=(',', ':'))
    print('  [OK] %s  (%d KB)' % (F_CEARA.name, F_CEARA.stat().st_size // 1024))

    # Sumario
    print('\n' + '=' * 52)
    print('SUMARIO\n')

    s_for = new_fortaleza[0]['properties']
    print('Fortaleza: %r' % s_for['bairro_nome'])
    print('  join_key=%r  area_km2=%s  regional=%r' % (
        s_for['join_key'], s_for['area_km2'], s_for['regional_administrativa']))
    print('  centroid=%s' % s_for['centroid'])

    print()
    s_ce = new_ceara[0]['properties']
    print('Ceara: %r' % s_ce['municipio_nome'])
    print('  ibge=%r  area_km2=%s  pop=%s' % (
        s_ce['ibge_code'], s_ce['area_km2'], s_ce.get('populacao')))
    print('  regiao=%r  meso=%r' % (
        s_ce.get('regiao_planejamento_nome'), s_ce.get('meso_regiao_nome')))

    print()
    s_rmf = new_rmf[0]['properties']
    print('RMF: %r (%r)' % (s_rmf['bairro_nome'], s_rmf['municipio_nome']))
    print('  join_key=%r  area_km2=%s' % (s_rmf['join_key'], s_rmf['area_km2']))
    print('  regiao=%r' % s_rmf.get('regiao_planejamento_nome'))

    print()
    pop_count = sum(1 for f in new_ceara if f['properties'].get('populacao'))
    reg_count = sum(1 for f in new_ceara if f['properties'].get('regiao_planejamento_id'))
    meso_count = sum(1 for f in new_ceara if f['properties'].get('meso_regiao_id'))
    print('Municipios com populacao:  %d/%d' % (pop_count, n_ce))
    print('Municipios com regiao:     %d/%d' % (reg_count, n_ce))
    print('Municipios com meso:       %d/%d' % (meso_count, n_ce))
    print('\n[DONE] Enriquecimento concluido com sucesso!')


if __name__ == '__main__':
    main()
