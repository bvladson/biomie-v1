#!/usr/bin/env python3
"""
geo_generate_rmf_bairros.py

Gera assets/geo/rmf_bairros_ibge_2022.geojson — bairros metropolitanos (RMF)
para a camada `grande_fortaleza_bairros` do BIomie.

Fonte canônica: IBGE Malha Censitária 2022 — Bairros por UF (CE)
  URL FTP: https://geoftp.ibge.gov.br/organizacao_do_territorio/malhas_territoriais/
            malhas_de_setores_censitarios__divisoes_intramunicipais/censo_2022/
            bairros/shp/UF/CE_bairros_CD2022.zip
  Campos : CD_BAIRRO, NM_BAIRRO, CD_MUN, NM_MUN (+ CD_REGIAO, CD_UF, CD_DIST…)
  CRS    : EPSG:4674 / SIRGAS 2000 → convertido para EPSG:4326 / WGS84
  Encoding: UTF-8  |  Total CE: 1.311 bairros

Dependências:
    pip install geopandas

Uso:
    python tools/dev/geo_generate_rmf_bairros.py --dry-run
    python tools/dev/geo_generate_rmf_bairros.py
    python tools/dev/geo_generate_rmf_bairros.py --include-fortaleza
    python tools/dev/geo_generate_rmf_bairros.py --input-shp=storage/geo/source/CE_bairros_CD2022/CE_bairros_CD2022.shp
    python tools/dev/geo_generate_rmf_bairros.py --tolerance=0.0002 --strict
"""

import argparse
import json
import sys
import unicodedata
import re
import urllib.request
import zipfile
from pathlib import Path
from datetime import datetime, timezone

import io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8", errors="replace")

try:
    import geopandas as gpd
    from shapely.errors import TopologicalError
    try:
        from shapely.validation import make_valid as _shapely_make_valid
        HAS_MAKE_VALID = True
    except ImportError:
        HAS_MAKE_VALID = False
except ImportError as e:
    print(f"[ERRO] Dependência faltando: {e}")
    print("  Execute: pip install geopandas")
    sys.exit(1)

# ─── Constantes ───────────────────────────────────────────────────────────────

PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent

DEFAULT_INPUT_SHP = PROJECT_ROOT / "storage" / "geo" / "source" / "CE_bairros_CD2022" / "CE_bairros_CD2022.shp"
DEFAULT_OUTPUT    = PROJECT_ROOT / "assets" / "geo" / "rmf_bairros_ibge_2022.geojson"
GENERATED_DIR     = PROJECT_ROOT / "storage" / "geo" / "generated"

IBGE_CE_URL = (
    "https://geoftp.ibge.gov.br/organizacao_do_territorio/"
    "malhas_territoriais/malhas_de_setores_censitarios__divisoes_intramunicipais/"
    "censo_2022/bairros/shp/UF/CE_bairros_CD2022.zip"
)

IBGE_DOWNLOAD_DIR = PROJECT_ROOT / "storage" / "geo" / "source" / "CE_bairros_CD2022"

DEFAULT_TOLERANCE = 0.00008

# 19 municípios da RMF de Fortaleza (código IBGE → nome oficial)
RMF_IBGE = {
    2304400: "Fortaleza",
    2303709: "Caucaia",
    2307650: "Maracanaú",
    2307700: "Maranguape",
    2304285: "Eusébio",
    2301000: "Aquiraz",
    2305233: "Horizonte",
    2309607: "Pacajus",
    2309706: "Pacatuba",
    2306256: "Itaitinga",
    2304954: "Guaiúba",
    2303956: "Chorozinho",
    2303501: "Cascavel",
    2310852: "Pindoretama",
    2310209: "Paracuru",
    2310258: "Paraipaba",
    2312403: "São Gonçalo do Amarante",
    2312601: "São Luís do Curu",
    2313500: "Trairi",
}

REQUIRED_COLS = {"CD_MUN", "NM_MUN", "CD_BAIRRO", "NM_BAIRRO"}

# ─── Normalização ─────────────────────────────────────────────────────────────

def normalize_key(name: str) -> str:
    """Normaliza string para chave estável: lowercase, sem acento, underscore."""
    if not name:
        return ""
    nfd = unicodedata.normalize("NFD", str(name))
    ascii_str = nfd.encode("ascii", "ignore").decode("ascii")
    lower = ascii_str.lower()
    keyed = re.sub(r"[^a-z0-9]+", "_", lower)
    keyed = re.sub(r"_+", "_", keyed).strip("_")
    return keyed


def bairro_key(municipio_ibge: int, bairro_norm: str) -> str:
    """Chave estável: '{municipio_ibge}:{bairro_norm}'. Ex: 2303709:jurema"""
    return f"{municipio_ibge}:{bairro_norm}"


def join_key(cidade_key_str: str, bairro_norm: str) -> str:
    """Chave de join: '{cidade_key}:{bairro_norm}'. Ex: caucaia:jurema"""
    return f"{cidade_key_str}:{bairro_norm}"

# ─── Geometria ────────────────────────────────────────────────────────────────

def repair_geometry(geom):
    """Tenta corrigir geometria inválida. Retorna None se impossível."""
    if geom is None or geom.is_empty:
        return None
    if geom.is_valid:
        return geom
    if HAS_MAKE_VALID:
        try:
            fixed = _shapely_make_valid(geom)
            if fixed is not None and not fixed.is_empty:
                return fixed
        except Exception:
            pass
    try:
        buffered = geom.buffer(0)
        if buffered is not None and not buffered.is_empty and buffered.is_valid:
            return buffered
    except Exception:
        pass
    return None


def simplify_safe(geom, tolerance: float):
    """Simplifica com topologia preservada; retorna original em erro."""
    try:
        simplified = geom.simplify(tolerance, preserve_topology=True)
        return simplified if (simplified is not None and not simplified.is_empty) else geom
    except (TopologicalError, Exception):
        return geom

# ─── Download IBGE direto ─────────────────────────────────────────────────────

def download_ibge_if_missing(target_dir: Path) -> Path:
    """Baixa CE_bairros_CD2022.zip do FTP IBGE e extrai em target_dir."""
    shp_path = target_dir / "CE_bairros_CD2022.shp"
    if shp_path.exists():
        print(f"  Arquivo já existe: {shp_path}")
        return shp_path

    zip_path = target_dir.parent / "CE_bairros_CD2022.zip"
    target_dir.mkdir(parents=True, exist_ok=True)

    if not zip_path.exists():
        print(f"  Baixando {IBGE_CE_URL} ...")
        urllib.request.urlretrieve(IBGE_CE_URL, zip_path)
        print(f"  Download concluído: {zip_path.stat().st_size // 1024} KB")
    else:
        print(f"  Cache ZIP: {zip_path}")

    print("  Extraindo SHP...")
    with zipfile.ZipFile(zip_path, "r") as zf:
        zf.extractall(target_dir)

    if not shp_path.exists():
        shp_files = list(target_dir.rglob("*.shp"))
        if shp_files:
            return shp_files[0]
        raise FileNotFoundError("SHP não encontrado após extração.")

    return shp_path

# ─── Build properties ────────────────────────────────────────────────────────

def build_properties(row) -> dict:
    """
    Constrói o dict de properties padronizado para cada feature GeoJSON.

    Campos obrigatórios validados pelo probe:
      layer_id, source, source_year, source_file, crs_origin,
      municipio_ibge, municipio_nome, municipio_norm,
      bairro_ibge, bairro_nome, bairro_norm, bairro_key, cidade_key,
      label, join_key
    """
    muni_ibge = int(row["CD_MUN"])
    muni_nome = str(row.get("NM_MUN") or "").strip()
    muni_norm = normalize_key(muni_nome)

    bairro_ibge_val = str(row.get("CD_BAIRRO") or "").strip()
    bairro_nome     = str(row.get("NM_BAIRRO") or "").strip()
    bairro_norm_val = normalize_key(bairro_nome)

    cidade_key_str = muni_norm  # normalize_key já remove acentos/espaços
    bk = bairro_key(muni_ibge, bairro_norm_val)
    jk = join_key(cidade_key_str, bairro_norm_val)

    return {
        "layer_id":       "grande_fortaleza_bairros",
        "source":         "IBGE_BAIRROS_CD2022",
        "source_year":    2022,
        "source_file":    "CE_bairros_CD2022",
        "crs_origin":     "EPSG:4674/SIRGAS2000",
        "municipio_ibge": str(muni_ibge),
        "municipio_nome": muni_nome,
        "municipio_norm": muni_norm,
        "bairro_ibge":    bairro_ibge_val,
        "bairro_nome":    bairro_nome,
        "bairro_norm":    bairro_norm_val,
        "bairro_key":     bk,
        "cidade_key":     cidade_key_str,
        "label":          f"{bairro_nome} — {muni_nome}",
        "join_key":       jk,
    }

# ─── Main ─────────────────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--input-shp",          type=str, default=str(DEFAULT_INPUT_SHP))
    parser.add_argument("--output",             type=str, default=str(DEFAULT_OUTPUT))
    parser.add_argument("--tolerance",          type=float, default=DEFAULT_TOLERANCE)
    parser.add_argument("--include-fortaleza",  action="store_true", help="Incluir bairros de Fortaleza (padrão: excluído)")
    parser.add_argument("--exclude-fortaleza",  action="store_true", help="Excluir Fortaleza explicitamente (padrão)")
    parser.add_argument("--download-if-missing",action="store_true", help="Baixar SHP do IBGE se não encontrar localmente")
    parser.add_argument("--dry-run",            action="store_true", help="Exibe estatísticas sem gerar arquivo")
    parser.add_argument("--strict",             action="store_true", help="Falha em qualquer WARN")
    args = parser.parse_args()

    include_fortaleza = args.include_fortaleza and not args.exclude_fortaleza
    output_path = Path(args.output)
    input_shp   = Path(args.input_shp)

    print()
    print("═" * 66)
    print("  BIomie — Gerador RMF Bairros IBGE 2022")
    print("═" * 66)
    print(f"  Fonte     : IBGE Malha Censitária 2022 — CE_bairros_CD2022")
    print(f"  Input SHP : {input_shp}")
    print(f"  Output    : {output_path}")
    print(f"  Tolerance : {args.tolerance}")
    print(f"  Fortaleza : {'INCLUÍDA' if include_fortaleza else 'EXCLUÍDA (usa fortaleza_bairros.json)'}")
    print(f"  Dry-run   : {args.dry_run}")
    print(f"  Strict    : {args.strict}")
    print()

    warnings = []

    # ── 1. Localiza SHP ──────────────────────────────────────────────────────
    print("[1/6] Localizando arquivo SHP...")
    if not input_shp.exists():
        if args.download_if_missing:
            print("  Arquivo não encontrado — iniciando download IBGE...")
            input_shp = download_ibge_if_missing(IBGE_DOWNLOAD_DIR)
        else:
            print(f"[ERRO] Arquivo não encontrado: {input_shp}")
            print("  Use --download-if-missing para baixar automaticamente, ou")
            print("  copie os arquivos para storage/geo/source/CE_bairros_CD2022/")
            sys.exit(1)

    # Verifica componentes obrigatórios do shapefile
    required_ext = {".shp", ".shx", ".dbf", ".prj"}
    base = input_shp.with_suffix("")
    missing_ext = [ext for ext in required_ext if not base.with_suffix(ext).exists()]
    if missing_ext:
        print(f"[ERRO] Componentes SHP faltando: {missing_ext}")
        sys.exit(1)
    print(f"  SHP localizado com todos os componentes: {input_shp.name}")

    # ── 2. Lê e valida shapefile ─────────────────────────────────────────────
    print("[2/6] Lendo shapefile...")
    try:
        gdf = gpd.read_file(str(input_shp), encoding="utf-8")
    except Exception as e:
        print(f"[ERRO] Falha ao ler SHP: {e}")
        sys.exit(1)

    print(f"  Total CE     : {len(gdf)} bairros")
    print(f"  CRS original : {gdf.crs}")

    # Valida CRS
    crs_epsg = gdf.crs.to_epsg() if gdf.crs else None
    if crs_epsg != 4674:
        warnings.append(f"CRS inesperado: {gdf.crs} (esperado EPSG:4674/SIRGAS2000)")
        if args.strict:
            print(f"[ERRO] {warnings[-1]}")
            sys.exit(1)

    # Valida colunas
    missing_cols = REQUIRED_COLS - set(gdf.columns)
    if missing_cols:
        print(f"[ERRO] Colunas obrigatórias ausentes: {missing_cols}")
        print(f"  Colunas disponíveis: {list(gdf.columns)}")
        sys.exit(1)

    # Geometrias nulas
    null_geom = gdf.geometry.isna().sum()
    if null_geom > 0:
        warnings.append(f"{null_geom} geometria(s) nula(s) no shapefile")

    print(f"  Colunas OK   : {sorted(REQUIRED_COLS)}")
    print(f"  Geom nulas   : {null_geom}")

    # ── 3. Filtra RMF ────────────────────────────────────────────────────────
    print("[3/6] Filtrando municípios da RMF...")
    gdf["_muni_int"] = gdf["CD_MUN"].astype(int)

    target_codes = list(RMF_IBGE.keys())
    if not include_fortaleza:
        target_codes = [c for c in target_codes if c != 2304400]

    rmf = gdf[gdf["_muni_int"].isin(target_codes)].copy()
    print(f"  Bairros RMF  : {len(rmf)}")

    # Cobertura por município
    coverage = {}
    for code, nome in RMF_IBGE.items():
        if code == 2304400 and not include_fortaleza:
            coverage[code] = {"nome": nome, "count": 0, "included": False, "reason": "excluída — usa fortaleza_bairros.json"}
            continue
        count = len(rmf[rmf["_muni_int"] == code])
        coverage[code] = {"nome": nome, "count": count, "included": count > 0, "reason": ""}

    with_bairros    = [c for c, v in coverage.items() if v["count"] > 0]
    without_bairros = [c for c, v in coverage.items() if v["count"] == 0 and c != 2304400]

    print()
    for code, v in coverage.items():
        marker = "✓" if v["count"] > 0 else ("━" if v["reason"] else "✗")
        extra  = f"  [{v['reason']}]" if v["reason"] else ""
        print(f"    {marker} {v['nome']:35s} {v['count']:4d} bairros{extra}")
    print()

    if without_bairros:
        names = ", ".join(RMF_IBGE[c] for c in without_bairros)
        warnings.append(
            f"{len(without_bairros)} município(s) sem bairros no IBGE 2022: {names}. "
            "Continuam disponíveis via rmf_municipios."
        )
        print(f"  [AVISO] {warnings[-1]}")

    # Verifica bairros fora da RMF
    extra = len(rmf[~rmf["_muni_int"].isin(target_codes)])
    if extra > 0:
        warnings.append(f"{extra} feature(s) de municípios fora da RMF no arquivo filtrado")
        if args.strict:
            print(f"[ERRO] {warnings[-1]}")
            sys.exit(1)

    # ── 4. Converte CRS → WGS84 ──────────────────────────────────────────────
    print(f"[4/6] Convertendo CRS: {gdf.crs} → EPSG:4326 (WGS84)...")
    rmf = rmf.to_crs("EPSG:4326")
    print("  CRS final    : EPSG:4326")

    # ── 5. Simplifica e corrige geometrias ───────────────────────────────────
    print(f"[5/6] Simplificando geometrias (tolerance={args.tolerance})...")
    invalid_before = (~rmf.geometry.is_valid).sum()
    if invalid_before > 0:
        print(f"  Geometrias inválidas antes: {invalid_before} — aplicando correção...")
        rmf["geometry"] = rmf["geometry"].apply(repair_geometry)
        rmf = rmf.dropna(subset=["geometry"])

    rmf["geometry"] = rmf["geometry"].apply(lambda g: simplify_safe(g, args.tolerance))
    invalid_after = (~rmf.geometry.is_valid).sum()
    if invalid_after > 0:
        msg = f"{invalid_after} geometria(s) ainda inválida(s) após correção"
        if args.strict:
            print(f"[ERRO] {msg}")
            sys.exit(1)
        warnings.append(msg)
    print(f"  Inválidas restantes: {invalid_after}")

    # ── 6. Constrói GeoJSON ───────────────────────────────────────────────────
    print("[6/6] Construindo GeoJSON...")

    features = []
    skipped  = 0
    for _, row in rmf.iterrows():
        geom = row.geometry
        if geom is None or geom.is_empty:
            skipped += 1
            continue
        try:
            geom_dict = json.loads(gpd.GeoSeries([geom], crs="EPSG:4326").to_json())["features"][0]["geometry"]
        except Exception:
            skipped += 1
            continue

        props = build_properties(row)
        features.append({"type": "Feature", "properties": props, "geometry": geom_dict})

    if skipped > 0:
        warnings.append(f"{skipped} feature(s) ignoradas por geometria vazia")

    # Estatísticas finais
    output_str  = json.dumps({"type": "FeatureCollection", "features": features}, ensure_ascii=False, separators=(",", ":"))
    size_kb     = len(output_str.encode("utf-8")) / 1024

    print()
    print("─" * 66)
    print(f"  Features no GeoJSON    : {len(features)}")
    print(f"  Municípios com bairros : {len(with_bairros)}/{len(target_codes)}")
    print(f"  Tamanho estimado       : {size_kb:.0f} KB")
    print(f"  Fortaleza incluída     : {include_fortaleza}")
    if warnings:
        for w in warnings:
            print(f"  [AVISO] {w}")

    size_warn = size_kb > 3000
    if size_warn:
        print(f"  [AVISO] Arquivo grande ({size_kb:.0f} KB) — rode com --tolerance=0.0002")

    ready = len(features) > 0 and not size_warn and (not args.strict or not warnings)
    print(f"  Pronto para probe      : {'SIM' if ready else 'NÃO (ver avisos)'}")
    print("─" * 66)

    if args.dry_run:
        print()
        print("  [DRY-RUN] Arquivo não salvo. Rode sem --dry-run para gerar.")
        print()
        sys.exit(0 if ready else 1)

    # Salva output principal
    output_path.parent.mkdir(parents=True, exist_ok=True)

    geojson_final = {
        "type": "FeatureCollection",
        "metadata": {
            "source":              "IBGE_BAIRROS_CD2022",
            "source_year":         2022,
            "source_file":         "CE_bairros_CD2022",
            "generated_by":        "geo_generate_rmf_bairros.py",
            "generated_at":        datetime.now(timezone.utc).isoformat(),
            "total_features":      len(features),
            "municipalities_with": len(with_bairros),
            "municipalities_total": len(target_codes),
            "includes_fortaleza":  include_fortaleza,
            "crs_origin":          "EPSG:4674/SIRGAS2000",
            "crs_output":          "EPSG:4326/WGS84",
            "tolerance":           args.tolerance,
            "warnings":            warnings,
        },
        "features": features,
    }

    output_json = json.dumps(geojson_final, ensure_ascii=False, separators=(",", ":"))
    output_path.write_text(output_json, encoding="utf-8")

    # Cópia em storage/geo/generated/ para rastreabilidade
    GENERATED_DIR.mkdir(parents=True, exist_ok=True)
    generated_copy = GENERATED_DIR / "rmf_bairros_ibge_2022.geojson"
    generated_copy.write_text(output_json, encoding="utf-8")

    print()
    print(f"  Salvo em    : {output_path}")
    print(f"  Cópia em    : {generated_copy}")
    print()
    print("  Próximo passo: php tools/dev/geo_rmf_bairros_source_probe.php")
    print()


if __name__ == "__main__":
    main()
