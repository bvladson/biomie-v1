#!/usr/bin/env python3
"""
geo_generate_metropolitan_regions.py

Gera GeoJSONs das Regiões Metropolitanas do Ceará (GEO.R1.5):
  - assets/geo/metro_sobral_municipios.geojson   (18 municípios)
  - assets/geo/metro_cariri_municipios.geojson   (9 municípios)
  - assets/geo/metro_todas_municipios.geojson    (todas as 3 RMs juntas)

Fonte: ceara_municipios_oficial.geojson (já validado pelo probe GEO.R1.4)
Referência legal:
  - RMF    : LC CE 14/1973 + atualizações
  - Sobral : LC CE 97/2010
  - Cariri : LC CE 78/2009

Uso:
    python tools/dev/geo_generate_metropolitan_regions.py --dry-run
    python tools/dev/geo_generate_metropolitan_regions.py
"""

import io, json, re, sys, unicodedata
from datetime import datetime, timezone
from pathlib import Path

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8", errors="replace")

try:
    import geopandas as gpd
except ImportError:
    print("[ERRO] pip install geopandas")
    sys.exit(1)

import argparse

PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
GEO_DIR      = PROJECT_ROOT / "assets" / "geo"
SOURCE_FILE  = GEO_DIR / "ceara_municipios_oficial.geojson"

# ── Definições oficiais das regiões metropolitanas ──────────────────────────

RMF_CODES = [
    2304400, 2303709, 2307650, 2307700, 2304285, 2301000, 2305233,
    2309607, 2309706, 2306256, 2304954, 2303956, 2303501, 2310852,
    2310209, 2310258, 2312403, 2312601, 2313500,
]

METRO_REGIONS = {
    "metro_sobral": {
        "id": "metro_sobral", "nome": "Região Metropolitana de Sobral",
        "nome_curto": "Metrópole Sobral", "norm": "metro_sobral",
        "source_law": "LC_CE_97_2010", "source_year": 2010,
        "municipios_ibge": [
            2313005, 2300200, 2303105, 2303808, 2304905, 2305001, 2304657,
            2305100, 2307601, 2307809, 2308351, 2309003, 2309904, 2310951,
            2311603, 2312007, 2312809, 2313906,
        ],
    },
    "metro_cariri": {
        "id": "metro_cariri", "nome": "Região Metropolitana do Cariri",
        "nome_curto": "Metrópole Cariri", "norm": "metro_cariri",
        "source_law": "LC_CE_78_2009", "source_year": 2009,
        "municipios_ibge": [
            2307304, 2304202, 2301901, 2303303, 2304301, 2306801, 2308104,
            2309300, 2312205,
        ],
    },
    "metro_fortaleza": {
        "id": "metro_fortaleza", "nome": "Região Metropolitana de Fortaleza",
        "nome_curto": "Metrópole Fortaleza", "norm": "metro_fortaleza",
        "source_law": "LC_CE_14_1973_atualizada", "source_year": 2022,
        "municipios_ibge": RMF_CODES,
    },
}


def normalize_key(name: str) -> str:
    if not name:
        return ""
    nfd = unicodedata.normalize("NFD", str(name))
    ascii_str = nfd.encode("ascii", "ignore").decode("ascii")
    keyed = re.sub(r"[^a-z0-9]+", "_", ascii_str.lower())
    return re.sub(r"_+", "_", keyed).strip("_")


def build_feature_properties(mun_props: dict, region: dict, now_iso: str) -> dict:
    return {
        "layer_id":           region["id"],
        "metro_region_id":    region["id"],
        "metro_region_nome":  region["nome"],
        "metro_region_norm":  region["norm"],
        "source":             "IBGE_MALHA_MUNICIPAL_DIGITAL",
        "source_year":        2022,
        "source_law":         region["source_law"],
        "crs_origin":         mun_props.get("crs_origin", "EPSG:4674/SIRGAS2000"),
        "generated_at":       now_iso,
        "codigo_ibge":        mun_props.get("codigo_ibge", ""),
        "municipio_nome":     mun_props.get("municipio_nome", ""),
        "municipio_norm":     mun_props.get("municipio_norm", ""),
        "municipio_ibge":     mun_props.get("municipio_ibge", mun_props.get("codigo_ibge", "")),
        "uf":                 "CE",
        "cidade_key":         mun_props.get("cidade_key", mun_props.get("municipio_norm", "")),
        "label":              mun_props.get("label", mun_props.get("municipio_nome", "")),
        "join_key":           mun_props.get("join_key", mun_props.get("cidade_key", "")),
    }


def generate_region_geojson(all_features: list, region: dict, now_iso: str, dry_run: bool = False) -> dict:
    """Filtra features pelo código IBGE da região e adiciona propriedades metro."""
    target_codes = set(str(c) for c in region["municipios_ibge"])
    features = []
    missing_codes = list(target_codes)

    for feat in all_features:
        props = feat.get("properties") or {}
        code = str(props.get("codigo_ibge") or props.get("municipio_ibge") or "")
        if code in target_codes:
            new_props = build_feature_properties(props, region, now_iso)
            features.append({"type": "Feature", "properties": new_props, "geometry": feat["geometry"]})
            if code in missing_codes:
                missing_codes.remove(code)

    return {
        "type": "FeatureCollection",
        "metadata": {
            "layer_id":     region["id"],
            "metro_nome":   region["nome"],
            "source":       "IBGE_MALHA_MUNICIPAL_DIGITAL_filtrado_por_regiao_metropolitana",
            "source_law":   region["source_law"],
            "source_year":  2022,
            "generated_by": "geo_generate_metropolitan_regions.py",
            "generated_at": now_iso,
            "total_features": len(features),
            "missing_from_source": missing_codes,
            "crs_output":   "EPSG:4326/WGS84",
        },
        "features": features,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dry-run",     action="store_true")
    parser.add_argument("--include-rmf", action="store_true", help="Gerar também metro_fortaleza_municipios.geojson")
    args = parser.parse_args()

    now_iso = datetime.now(timezone.utc).isoformat()

    print()
    print("=" * 66)
    print("  BIomie — Gerador Regiões Metropolitanas CE (GEO.R1.5)")
    print("=" * 66)
    print(f"  Fonte  : {SOURCE_FILE.name}")
    print(f"  Dry-run: {args.dry_run}")
    print()

    if not SOURCE_FILE.exists():
        print(f"[ERRO] {SOURCE_FILE} não encontrado.")
        print("  Execute primeiro: python tools/dev/geo_generate_ceara_municipios.py")
        sys.exit(1)

    with open(SOURCE_FILE, encoding="utf-8") as f:
        ceara = json.load(f)
    all_features = ceara.get("features", [])
    print(f"  Municípios CE carregados: {len(all_features)}")

    # Regiões a gerar (sempre Sobral + Cariri; RMF opcional)
    regions_to_generate = {k: v for k, v in METRO_REGIONS.items() if k != "metro_fortaleza"}
    if args.include_rmf:
        regions_to_generate["metro_fortaleza"] = METRO_REGIONS["metro_fortaleza"]

    results = {}
    for region_id, region in regions_to_generate.items():
        geojson = generate_region_geojson(all_features, region, now_iso, args.dry_run)
        found = len(geojson["features"])
        total = len(region["municipios_ibge"])
        missing = geojson["metadata"]["missing_from_source"]
        marker = "✓" if found == total else "~"
        print(f"  {marker} {region['nome_curto']:30s} {found}/{total} municípios")
        if missing:
            print(f"    [AVISO] Ausentes no source: {missing}")
        results[region_id] = geojson

    # Arquivo combinado metro_todas_municipios
    all_metro_features = []
    for gj in results.values():
        all_metro_features.extend(gj["features"])
    todas_geojson = {
        "type": "FeatureCollection",
        "metadata": {
            "layer_id":     "metro_todas_municipios",
            "source":       "IBGE_MALHA_MUNICIPAL_DIGITAL_todas_regioes_metropolitanas_CE",
            "regions":      [r["id"] for r in regions_to_generate.values()],
            "generated_by": "geo_generate_metropolitan_regions.py",
            "generated_at": now_iso,
            "total_features": len(all_metro_features),
            "crs_output":   "EPSG:4326/WGS84",
        },
        "features": all_metro_features,
    }

    total_all = len(all_metro_features)
    print(f"\n  metro_todas: {total_all} municípios no total")

    if args.dry_run:
        print("\n  [DRY-RUN] Arquivos não salvos.\n")
        sys.exit(0)

    GEO_DIR.mkdir(parents=True, exist_ok=True)
    outputs = []
    for region_id, gj in results.items():
        out = GEO_DIR / f"{region_id}_municipios.geojson"
        out.write_text(json.dumps(gj, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
        outputs.append(str(out))

    todas_out = GEO_DIR / "metro_todas_municipios.geojson"
    todas_out.write_text(json.dumps(todas_geojson, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    outputs.append(str(todas_out))

    print()
    for o in outputs:
        print(f"  Salvo: {Path(o).name}")
    print()
    print("  Próximo: php tools/dev/geo_metropolitan_regions_probe.php")
    print()


if __name__ == "__main__":
    main()
