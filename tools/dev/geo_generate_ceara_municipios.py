#!/usr/bin/env python3
"""
geo_generate_ceara_municipios.py

Gera assets/geo/ceara_municipios_oficial.geojson — 184 municípios do Ceará
para a camada `ceara_municipios` do BIomie.

Fontes (tentadas em ordem):
  1. IPECE Limites Municipais CE (--source-type=ipece, se arquivo local fornecido)
  2. IBGE Malha Municipal Digital mais recente (API v3 ou arquivo local)
  3. Arquivo existente assets/geo/ceara_mun.json (atualização de schema)

Dependências:
    pip install geopandas

Uso:
    python tools/dev/geo_generate_ceara_municipios.py --dry-run
    python tools/dev/geo_generate_ceara_municipios.py
    python tools/dev/geo_generate_ceara_municipios.py --use-existing
    python tools/dev/geo_generate_ceara_municipios.py --input=path/to/file.shp --source-type=ipece --year=2026
    python tools/dev/geo_generate_ceara_municipios.py --year=2024
"""

import argparse
import io
import json
import re
import sys
import unicodedata
import urllib.request
from datetime import datetime, timezone
from pathlib import Path

try:
    import geopandas as gpd
    from shapely.validation import make_valid as _make_valid
except ImportError as e:
    print(f"[ERRO] {e}. Execute: pip install geopandas")
    sys.exit(1)

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8", errors="replace")

PROJECT_ROOT    = Path(__file__).resolve().parent.parent.parent
DEFAULT_OUTPUT  = PROJECT_ROOT / "assets" / "geo" / "ceara_municipios_oficial.geojson"
EXISTING_SOURCE = PROJECT_ROOT / "assets" / "geo" / "ceara_mun.json"
GENERATED_DIR   = PROJECT_ROOT / "storage" / "geo" / "generated"

# IBGE API endpoints
IBGE_GEOM_URL   = "https://servicodados.ibge.gov.br/api/v3/malhas/estados/23?resolucao=5&formato=application/vnd.geo%2Bjson"
IBGE_NAMES_URL  = "https://servicodados.ibge.gov.br/api/v1/localidades/estados/CE/municipios"

CE_IBGE_STATE   = "23"
EXPECTED_COUNT  = 184
DEFAULT_TOLERANCE = 0.00005


def normalize_key(name: str) -> str:
    if not name:
        return ""
    nfd = unicodedata.normalize("NFD", str(name))
    ascii_str = nfd.encode("ascii", "ignore").decode("ascii")
    lower = ascii_str.lower()
    keyed = re.sub(r"[^a-z0-9]+", "_", lower)
    keyed = re.sub(r"_+", "_", keyed).strip("_")
    return keyed


def load_from_existing(path: Path) -> tuple:
    """Carrega ceara_mun.json existente e retorna (gdf, source_meta)."""
    print(f"  Carregando de {path.name}...")
    with open(path, encoding="utf-8") as f:
        raw = f.read()
    gdf = gpd.read_file(io.StringIO(raw))
    meta = json.loads(raw).get("metadata", {})
    return gdf, {
        "source":       meta.get("source", "IBGE_Malhas_v2_resolucao5"),
        "source_year":  2022,
        "source_url":   meta.get("api_geometry", IBGE_GEOM_URL),
    }


def load_from_ibge_api(year: int) -> tuple:
    """Baixa da API IBGE, enriquece com nomes e retorna (gdf, source_meta)."""
    print(f"  Baixando geometria IBGE (resolucao=5)...")
    ctx = urllib.request.urlopen(IBGE_GEOM_URL, timeout=30)
    geojson_bytes = ctx.read()
    print(f"  Geometria: {len(geojson_bytes)//1024} KB")

    print("  Baixando nomes de municípios...")
    ctx2 = urllib.request.urlopen(IBGE_NAMES_URL, timeout=15)
    names_data = json.loads(ctx2.read())

    name_by_code = {}
    for item in names_data:
        code = str(item.get("id", ""))
        nome = item.get("nome", "")
        if code and nome:
            name_by_code[code] = nome

    gdf = gpd.read_file(io.BytesIO(geojson_bytes))

    def enrich(row):
        code = str(row.get("codarea") or row.get("id") or row.get("geocodigo") or "")[:7]
        nome = name_by_code.get(code, name_by_code.get(code[:6], ""))
        row["_ibge_code"] = code
        row["_ibge_name"] = nome
        return row

    gdf = gdf.apply(enrich, axis=1)
    print(f"  {len(gdf)} features com nomes enriquecidos")
    return gdf, {
        "source":       "IBGE_MALHA_MUNICIPAL_DIGITAL",
        "source_year":  year,
        "source_url":   IBGE_GEOM_URL,
    }


def load_from_local(path: Path, source_type: str, year: int) -> tuple:
    """Carrega de arquivo SHP/GPKG local."""
    print(f"  Lendo {path}...")
    gdf = gpd.read_file(str(path), encoding="utf-8")
    src = "IPECE_LIMITES_MUNICIPAIS_CE" if source_type == "ipece" else "IBGE_MALHA_MUNICIPAL_DIGITAL"
    return gdf, {"source": src, "source_year": year, "source_file": path.name}


def detect_columns(gdf) -> dict:
    """Auto-detecta colunas de código IBGE e nome do município."""
    cols_lower = {c.lower(): c for c in gdf.columns}
    code_col = (cols_lower.get("cd_mun") or cols_lower.get("cd_geocmu") or
                cols_lower.get("code_muni") or cols_lower.get("geocodigo") or
                cols_lower.get("codarea") or cols_lower.get("municipio_ibge") or
                cols_lower.get("codigo_ibge") or cols_lower.get("_ibge_code"))
    name_col = (cols_lower.get("nm_mun") or cols_lower.get("nm_municip") or
                cols_lower.get("name_muni") or cols_lower.get("municipio_nome") or
                cols_lower.get("_ibge_name"))
    return {"code": code_col, "name": name_col}


def build_properties(row, col_map: dict, source_meta: dict) -> dict:
    code_col = col_map["code"]
    name_col = col_map["name"]

    raw_code = str(row[code_col] if code_col else "").strip()
    codigo_ibge = raw_code[:7] if len(raw_code) >= 7 else raw_code

    nome = str(row[name_col] if name_col else "").strip()
    norm = normalize_key(nome)
    cidade_key = norm
    label = nome

    return {
        "layer_id":      "ceara_municipios",
        "source":        source_meta["source"],
        "source_year":   source_meta["source_year"],
        "source_url":    source_meta.get("source_url", source_meta.get("source_file", "")),
        "crs_origin":    f"EPSG:{row['_crs_epsg']}",
        "generated_at":  source_meta["generated_at"],
        "codigo_ibge":   codigo_ibge,
        "municipio_nome": nome,
        "municipio_norm": norm,
        "municipio_ibge": codigo_ibge,
        "uf":            "CE",
        "cidade_key":    cidade_key,
        "label":         label,
        "join_key":      cidade_key,
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--input",          type=str, help="Arquivo SHP/GPKG local (opcional)")
    parser.add_argument("--source-type",    type=str, default="ibge", choices=["ibge","ipece"])
    parser.add_argument("--year",           type=int, default=2022)
    parser.add_argument("--use-existing",   action="store_true", help="Usar ceara_mun.json existente como base")
    parser.add_argument("--output",         type=str, default=str(DEFAULT_OUTPUT))
    parser.add_argument("--tolerance",      type=float, default=DEFAULT_TOLERANCE)
    parser.add_argument("--dry-run",        action="store_true")
    parser.add_argument("--strict",         action="store_true")
    args = parser.parse_args()

    output_path = Path(args.output)
    now_iso = datetime.now(timezone.utc).isoformat()

    print()
    print("=" * 66)
    print("  BIomie — Gerador Ceará Municípios Oficial")
    print("=" * 66)
    print(f"  Source     : {args.source_type} (year={args.year})")
    print(f"  Output     : {output_path}")
    print(f"  Tolerance  : {args.tolerance}")
    print(f"  Dry-run    : {args.dry_run}")
    print()

    warnings = []

    # ── 1. Carrega fonte ──────────────────────────────────────────────────────
    print("[1/5] Carregando fonte...")
    source_meta = {}

    if args.input:
        gdf, source_meta = load_from_local(Path(args.input), args.source_type, args.year)
    elif args.use_existing and EXISTING_SOURCE.exists():
        gdf, source_meta = load_from_existing(EXISTING_SOURCE)
    else:
        # Tentar API IBGE
        try:
            gdf, source_meta = load_from_ibge_api(args.year)
        except Exception as e:
            if EXISTING_SOURCE.exists():
                warnings.append(f"API IBGE falhou ({e}) — usando ceara_mun.json existente")
                print(f"  [AVISO] {warnings[-1]}")
                gdf, source_meta = load_from_existing(EXISTING_SOURCE)
            else:
                print(f"[ERRO] Falha ao baixar de IBGE e ceara_mun.json não existe: {e}")
                sys.exit(1)

    source_meta["generated_at"] = now_iso
    print(f"  Fonte: {source_meta['source']} ({source_meta['source_year']})")
    print(f"  Features: {len(gdf)}")
    print(f"  CRS original: {gdf.crs}")

    # ── 2. Valida e filtra Ceará ──────────────────────────────────────────────
    print("[2/5] Validando e filtrando Ceará...")
    crs_epsg = gdf.crs.to_epsg() if gdf.crs else 4674

    col_map = detect_columns(gdf)
    if not col_map["code"]:
        # Try existing fields
        if "municipio_ibge" in gdf.columns:
            col_map["code"] = "municipio_ibge"
        elif "codarea" in gdf.columns:
            col_map["code"] = "codarea"
        else:
            print(f"[ERRO] Coluna de código IBGE não detectada. Colunas: {list(gdf.columns)}")
            sys.exit(1)
    if not col_map["name"]:
        if "municipio_nome" in gdf.columns:
            col_map["name"] = "municipio_nome"
        else:
            print(f"[ERRO] Coluna de nome não detectada. Colunas: {list(gdf.columns)}")
            sys.exit(1)

    print(f"  Código IBGE: {col_map['code']}")
    print(f"  Nome: {col_map['name']}")

    # Filtra apenas CE (código começa com 23)
    def is_ce(row):
        code = str(row[col_map["code"]] or "").strip()[:2]
        return code == CE_IBGE_STATE

    gdf_ce = gdf[gdf.apply(is_ce, axis=1)].copy()
    if len(gdf_ce) != EXPECTED_COUNT:
        warnings.append(f"Total inesperado: {len(gdf_ce)} (esperado {EXPECTED_COUNT})")
        if args.strict:
            print(f"[ERRO] {warnings[-1]}")
            sys.exit(1)
        print(f"  [AVISO] {warnings[-1]}")
    else:
        print(f"  Municípios CE: {len(gdf_ce)} ✓")

    # Verifica duplicados
    codes = [str(r[col_map["code"]]).strip()[:7] for _, r in gdf_ce.iterrows()]
    dupes = len(codes) - len(set(codes))
    if dupes > 0:
        warnings.append(f"{dupes} código(s) IBGE duplicados")
        if args.strict:
            print(f"[ERRO] {warnings[-1]}")
            sys.exit(1)

    gdf_ce["_crs_epsg"] = str(crs_epsg)

    # ── 3. Converte CRS ───────────────────────────────────────────────────────
    print(f"[3/5] Convertendo CRS {gdf_ce.crs} → EPSG:4326...")
    gdf_ce = gdf_ce.to_crs("EPSG:4326")

    # ── 4. Corrige e simplifica geometrias ────────────────────────────────────
    print(f"[4/5] Corrigindo e simplificando geometrias (tolerance={args.tolerance})...")
    invalid_before = (~gdf_ce.geometry.is_valid).sum()
    if invalid_before > 0:
        print(f"  Corrigindo {invalid_before} geometria(s) inválida(s)...")
        gdf_ce["geometry"] = gdf_ce["geometry"].apply(_make_valid)
    invalid_after = (~gdf_ce.geometry.is_valid).sum()
    null_geom = gdf_ce.geometry.isna().sum()

    gdf_ce["geometry"] = gdf_ce["geometry"].apply(
        lambda g: g.simplify(args.tolerance, preserve_topology=True) if g and not g.is_empty else g
    )

    if null_geom > 0 or invalid_after > 0:
        warnings.append(f"{null_geom} nulas, {invalid_after} inválidas após correção")
    print(f"  Inválidas: {invalid_before}→{invalid_after} | Nulas: {null_geom}")

    # ── 5. Constrói GeoJSON ───────────────────────────────────────────────────
    print("[5/5] Construindo GeoJSON...")
    features = []
    skipped = 0
    for _, row in gdf_ce.iterrows():
        geom = row.geometry
        if geom is None or geom.is_empty:
            skipped += 1
            continue
        try:
            geom_dict = json.loads(gpd.GeoSeries([geom], crs="EPSG:4326").to_json())["features"][0]["geometry"]
        except Exception:
            skipped += 1
            continue
        props = build_properties(row, col_map, source_meta)
        features.append({"type": "Feature", "properties": props, "geometry": geom_dict})

    output_str = json.dumps({"type": "FeatureCollection", "features": features}, ensure_ascii=False, separators=(",", ":"))
    size_kb = len(output_str.encode("utf-8")) / 1024

    print()
    print("-" * 66)
    print(f"  Features           : {len(features)}")
    print(f"  Tamanho estimado   : {size_kb:.0f} KB")
    print(f"  Source             : {source_meta['source']} ({source_meta['source_year']})")
    if warnings:
        for w in warnings:
            print(f"  [AVISO] {w}")
    size_ok = size_kb < 5000
    ready = len(features) >= EXPECTED_COUNT and size_ok and not invalid_after
    print(f"  Pronto para probe  : {'SIM' if ready else 'NAO'}")
    print("-" * 66)

    if args.dry_run:
        print("\n  [DRY-RUN] Arquivo não salvo.\n")
        sys.exit(0 if ready else 1)

    output_path.parent.mkdir(parents=True, exist_ok=True)
    geojson_final = {
        "type": "FeatureCollection",
        "metadata": {
            "source":       source_meta["source"],
            "source_year":  source_meta["source_year"],
            "source_url":   source_meta.get("source_url", ""),
            "generated_by": "geo_generate_ceara_municipios.py",
            "generated_at": now_iso,
            "features_total": len(features),
            "crs_output":   "EPSG:4326/WGS84",
            "tolerance":    args.tolerance,
        },
        "features": features,
    }
    out_json = json.dumps(geojson_final, ensure_ascii=False, separators=(",", ":"))
    output_path.write_text(out_json, encoding="utf-8")
    GENERATED_DIR.mkdir(parents=True, exist_ok=True)
    (GENERATED_DIR / "ceara_municipios_oficial.geojson").write_text(out_json, encoding="utf-8")
    print(f"\n  Salvo em : {output_path}")
    print(f"  Próximo  : php tools/dev/geo_ceara_municipios_source_probe.php\n")


if __name__ == "__main__":
    main()
