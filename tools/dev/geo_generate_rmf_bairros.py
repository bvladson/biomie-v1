#!/usr/bin/env python3
"""
geo_generate_rmf_bairros.py

Gera rmf_bairros_ibge_2022.geojson com os bairros dos 19 municípios da
Região Metropolitana de Fortaleza (RMF).

Fonte canônica: IBGE Malha Censitária 2022 — Bairros por UF (CE)
  URL: https://geoftp.ibge.gov.br/organizacao_do_territorio/malhas_territoriais/
         malhas_de_setores_censitarios__divisoes_intramunicipais/censo_2022/
         bairros/shp/UF/CE_bairros_CD2022.zip
  Campos: CD_BAIRRO, NM_BAIRRO, CD_MUN, NM_MUN  |  CRS: SIRGAS 2000 (EPSG:4674)
  Encoding: UTF-8  |  Total Ceará: 1.311 bairros

Atalho operacional: geobr.read_neighborhood(code_muni="CE", year=2022)
  Acessa o mesmo produto IBGE 2022 via GeoPackage — sem download manual.
  Se geobr falhar, o script usa o fallback IBGE direto automaticamente.

Dependências:
    pip install geobr geopandas

Uso:
    python tools/dev/geo_generate_rmf_bairros.py               # modo padrão
    python tools/dev/geo_generate_rmf_bairros.py --dry-run     # só estatísticas
    python tools/dev/geo_generate_rmf_bairros.py --include-fortaleza
    python tools/dev/geo_generate_rmf_bairros.py --tolerance=0.0002

Output:
    assets/geo/rmf_bairros_ibge_2022.geojson
"""

import argparse
import json
import sys
from pathlib import Path

try:
    import geobr
    import geopandas as gpd
    from shapely.errors import TopologicalError
except ImportError as e:
    print(f"[ERRO] Dependência faltando: {e}")
    print("  Execute: pip install geobr geopandas")
    sys.exit(1)

# ─── Configuração ─────────────────────────────────────────────────────────────

# Raiz do projeto (2 níveis acima de tools/dev/)
PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
OUTPUT_PATH  = PROJECT_ROOT / "assets" / "geo" / "rmf_bairros_ibge_2022.geojson"

# 19 municípios da RMF com códigos IBGE oficiais
RMF_IBGE_CODES = {
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

# Tolerância padrão de simplificação (graus decimais)
# 0.00005 ≈ 5 metros, bom para zoom ~12
# 0.0001  ≈ 10 metros, menor arquivo, zoom ~11
# 0.0002  ≈ 20 metros, arquivo menor ainda, zoom ~10
DEFAULT_TOLERANCE = 0.00008


# ─── Funções auxiliares ───────────────────────────────────────────────────────

def detect_columns(gdf: "gpd.GeoDataFrame") -> dict:
    """
    Auto-detecta os nomes das colunas de código e nome de município e bairro.
    geobr pode mudar os nomes entre versões da biblioteca.
    """
    cols = [c.lower() for c in gdf.columns]
    col_map = dict(zip(cols, gdf.columns))  # lowercase → original

    def find(candidates):
        for c in candidates:
            if c in col_map:
                return col_map[c]
        return None

    muni_code = find(["code_muni", "cd_municipio", "cd_mun", "codigo_municipio", "muni_code"])
    muni_name = find(["name_muni", "nm_municipio", "nm_mun", "nome_municipio", "municipio"])
    bairro_code = find(["code_neighborhood", "cd_bairro", "codigo_bairro", "id_bairro"])
    bairro_name = find(["name_neighborhood", "nm_bairro", "nome_bairro", "bairro"])

    return {
        "muni_code":   muni_code,
        "muni_name":   muni_name,
        "bairro_code": bairro_code,
        "bairro_name": bairro_name,
    }


def normalize_muni_code(value) -> int:
    """Normaliza código IBGE para int (pode vir como string ou float)."""
    try:
        return int(float(str(value).strip()))
    except (ValueError, TypeError):
        return 0


def simplify_safe(geom, tolerance: float):
    """Simplifica geometria preservando topologia; retorna original em caso de erro."""
    try:
        simplified = geom.simplify(tolerance, preserve_topology=True)
        return simplified if simplified is not None and not simplified.is_empty else geom
    except (TopologicalError, Exception):
        return geom


def build_properties(row, cols: dict, muni_code_int: int) -> dict:
    """
    Constrói o dict de properties padronizado para o GeoJSON de saída.

    Campos obrigatórios validados pelo probe:
      municipio_ibge, municipio_nome, bairro_ibge, bairro_nome, bairro_key, cidade_key
    """
    bairro_name = ""
    bairro_ibge = ""  # CD_BAIRRO do IBGE — campo canônico de identificação

    if cols["bairro_name"] and cols["bairro_name"] in row.index:
        bairro_name = str(row[cols["bairro_name"]] or "").strip()
    if cols["bairro_code"] and cols["bairro_code"] in row.index:
        bairro_ibge = str(row[cols["bairro_code"]] or "").strip()

    muni_name = RMF_IBGE_CODES.get(muni_code_int, "")
    if cols["muni_name"] and cols["muni_name"] in row.index and not muni_name:
        muni_name = str(row[cols["muni_name"]] or "").strip()

    return {
        "bairro_nome":    bairro_name,
        "bairro_key":     normalize_bairro_key(bairro_name),
        "bairro_ibge":    bairro_ibge,           # CD_BAIRRO — fonte canônica IBGE
        "municipio_ibge": str(muni_code_int),    # CD_MUN — fonte canônica IBGE
        "municipio_nome": muni_name,
        "cidade_key":     normalize_bairro_key(muni_name),
        "source":         "IBGE_Malha_Bairros_2022",
        "crs_origin":     "SIRGAS2000_EPSG4674",  # CRS original antes da conversão para WGS84
    }


def normalize_bairro_key(name: str) -> str:
    """Normaliza para o formato bairro_key do BIomie (underscore, sem acentos)."""
    import unicodedata
    import re
    normalized = unicodedata.normalize("NFD", name)
    ascii_str  = normalized.encode("ascii", "ignore").decode("ascii")
    lower      = ascii_str.lower()
    keyed      = re.sub(r"[^a-z0-9]+", "_", lower)
    keyed      = re.sub(r"_+", "_", keyed).strip("_")
    return keyed


# ─── Fallback IBGE direto ─────────────────────────────────────────────────────

def _download_ibge_direct(project_root: Path) -> "gpd.GeoDataFrame | None":
    """
    Fallback: baixa o arquivo de bairros CE diretamente do FTP do IBGE.

    Fonte canônica IBGE — Censo 2022, produto Bairros por UF:
      https://geoftp.ibge.gov.br/organizacao_do_territorio/malhas_territoriais/
        malhas_de_setores_censitarios__divisoes_intramunicipais/censo_2022/
        bairros/shp/UF/CE_bairros_CD2022.zip

    Campos no SHP: CD_BAIRRO, NM_BAIRRO, CD_MUN, NM_MUN, CD_UF, NM_UF
    CRS: SIRGAS 2000 (EPSG:4674)
    Encoding: UTF-8

    O cache local fica em tools/dev/.ibge_cache/ e é reutilizado em execuções
    subsequentes para evitar re-download (~alguns MB por UF).
    """
    import urllib.request
    import zipfile

    # URL canônica CE — pacote UF direto, sem precisar filtrar arquivo nacional.
    IBGE_CE_URL = (
        "https://geoftp.ibge.gov.br/organizacao_do_territorio/"
        "malhas_territoriais/malhas_de_setores_censitarios__divisoes_intramunicipais/"
        "censo_2022/bairros/shp/UF/CE_bairros_CD2022.zip"
    )

    cache_dir = project_root / "tools" / "dev" / ".ibge_cache"
    cache_dir.mkdir(parents=True, exist_ok=True)
    zip_path  = cache_dir / "CE_bairros_CD2022.zip"
    shp_dir   = cache_dir / "CE_bairros_CD2022"

    try:
        if not zip_path.exists():
            print(f"  Baixando {IBGE_CE_URL} ...")
            urllib.request.urlretrieve(IBGE_CE_URL, zip_path)
            print(f"  Download concluído: {zip_path.stat().st_size // 1024} KB")
        else:
            print(f"  Usando cache: {zip_path}")

        if not shp_dir.exists():
            print("  Extraindo SHP...")
            with zipfile.ZipFile(zip_path, "r") as zf:
                zf.extractall(shp_dir)

        shp_files = list(shp_dir.rglob("*.shp")) + list(shp_dir.rglob("*.SHP"))
        if not shp_files:
            print("  [ERRO] Nenhum .shp encontrado no ZIP.")
            return None

        source_file = shp_files[0]
        print(f"  Lendo {source_file.name} ...")
        gdf = gpd.read_file(source_file, encoding="utf-8")
        print(f"  Carregados: {len(gdf)} bairros do Ceará")
        return gdf

    except Exception as e:
        print(f"  [ERRO] Fallback IBGE direto falhou: {e}")
        return None


# ─── Script principal ─────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--tolerance",         type=float, default=DEFAULT_TOLERANCE,
                        help=f"Tolerância de simplificação (default: {DEFAULT_TOLERANCE})")
    parser.add_argument("--include-fortaleza", action="store_true",
                        help="Incluir Fortaleza no output (por padrão é excluída — já existe fortaleza_bairros.json)")
    parser.add_argument("--dry-run",           action="store_true",
                        help="Apenas exibe estatísticas, não salva o arquivo")
    parser.add_argument("--output",            type=str, default=str(OUTPUT_PATH),
                        help="Caminho de saída do GeoJSON")
    args = parser.parse_args()

    output_path = Path(args.output)
    include_fortaleza = args.include_fortaleza

    print()
    print("═" * 62)
    print("  GEO — Gerador RMF Bairros IBGE 2022")
    print("═" * 62)
    print(f"  Tolerância de simplificação : {args.tolerance}")
    print(f"  Incluir Fortaleza           : {include_fortaleza}")
    print(f"  Output                      : {output_path}")
    print(f"  Dry-run                     : {args.dry_run}")
    print()

    # ── 1. Download via geobr (atalho para IBGE 2022) ────────────────────────
    print("[1/5] Baixando bairros do Ceará via IBGE/geobr 2022...")
    print("       Fonte canônica: IBGE Malha Censitária 2022 — Bairros por UF (CE)")
    print("       Atalho: geobr.read_neighborhood (acessa o mesmo produto IBGE)")
    bairros_ce = None
    try:
        bairros_ce = geobr.read_neighborhood(code_muni="CE", year=2022)
        print(f"       geobr OK — {len(bairros_ce)} bairros carregados do Ceará")
    except Exception as e:
        print(f"[AVISO] geobr falhou: {e}")
        print("  Tentando fallback: download direto IBGE FTP...")
        bairros_ce = _download_ibge_direct(PROJECT_ROOT)
        if bairros_ce is None:
            print("[ERRO] Ambas as fontes falharam. Verifique a conexão.")
            print("  geobr:       pip install --upgrade geobr")
            print("  IBGE direto: https://geoftp.ibge.gov.br/organizacao_do_territorio/"
                  "malhas_territoriais/malhas_de_bairros/")
            sys.exit(1)

    print(f"       Total Ceará: {len(bairros_ce)} bairros")
    if len(bairros_ce) < 1200:
        print(f"[AVISO] Esperado ≥1.311 bairros no Ceará, mas vieram {len(bairros_ce)}. "
              "Verificar se o dataset está completo.")

    # ── 2. Auto-detecta colunas ───────────────────────────────────────────────
    print("[2/5] Detectando colunas...")
    cols = detect_columns(bairros_ce)
    print(f"       Detectado: {cols}")

    if not cols["muni_code"]:
        print("[ERRO] Coluna de código de município não detectada.")
        print(f"  Colunas disponíveis: {list(bairros_ce.columns)}")
        sys.exit(1)

    if not cols["bairro_name"]:
        print("[AVISO] Coluna de nome de bairro não detectada — bairro_nome ficará vazio.")

    # ── 3. Filtra municípios da RMF ───────────────────────────────────────────
    print("[3/5] Filtrando 19 municípios da RMF...")

    bairros_ce["_muni_code_int"] = bairros_ce[cols["muni_code"]].apply(normalize_muni_code)

    target_codes = list(RMF_IBGE_CODES.keys())
    if not include_fortaleza:
        target_codes = [c for c in target_codes if c != 2304400]
        print("       Fortaleza excluída (já existe fortaleza_bairros.json).")

    rmf_bairros = bairros_ce[bairros_ce["_muni_code_int"].isin(target_codes)].copy()
    print(f"       Bairros filtrados (RMF): {len(rmf_bairros)}")

    if len(rmf_bairros) == 0:
        print("[ERRO] Nenhum bairro encontrado para os municípios da RMF.")
        print(f"  Códigos procurados: {target_codes[:5]}...")
        print(f"  Exemplo de código no dataset: {bairros_ce['_muni_code_int'].iloc[0] if len(bairros_ce) > 0 else 'vazio'}")
        sys.exit(1)

    # Estatísticas por município
    coverage = {}
    for code in target_codes:
        nome     = RMF_IBGE_CODES[code]
        count    = len(rmf_bairros[rmf_bairros["_muni_code_int"] == code])
        coverage[nome] = count
        status = "✓" if count > 0 else "✗ SEM BAIRROS"
        print(f"       {status:18s} {nome:35s} {count:4d} bairros")

    sem_bairros = [nome for nome, count in coverage.items() if count == 0]
    if sem_bairros:
        print(f"\n  [AVISO] {len(sem_bairros)} município(s) sem bairros no dataset IBGE 2022: {', '.join(sem_bairros)}")
        print("  Use OSM como fallback para esses com badge WARN.")
    print()

    # ── 4. Converte CRS e simplifica geometrias ───────────────────────────────
    print(f"[4/5] Convertendo CRS e simplificando geometrias (tolerance={args.tolerance})...")
    # IBGE usa SIRGAS 2000 (EPSG:4674) — reprojetar para WGS84 (EPSG:4326) para o Leaflet
    src_crs = str(rmf_bairros.crs) if rmf_bairros.crs is not None else "desconhecido"
    print(f"       CRS de origem: {src_crs}")
    rmf_bairros = rmf_bairros.to_crs("EPSG:4326")
    print("       CRS final: EPSG:4326 (WGS84)")
    rmf_bairros["geometry"] = rmf_bairros["geometry"].apply(
        lambda g: simplify_safe(g, args.tolerance)
    )

    # ── 5. Constrói GeoJSON de saída ──────────────────────────────────────────
    print("[5/5] Construindo GeoJSON padronizado para o BIomie...")

    features = []
    skipped  = 0
    for _, row in rmf_bairros.iterrows():
        muni_code_int = int(row["_muni_code_int"])
        geom = row.geometry
        if geom is None or geom.is_empty:
            skipped += 1
            continue

        try:
            geom_dict = json.loads(gpd.GeoSeries([geom]).to_json())["features"][0]["geometry"]
        except Exception:
            skipped += 1
            continue

        props = build_properties(row, cols, muni_code_int)
        features.append({
            "type":       "Feature",
            "properties": props,
            "geometry":   geom_dict,
        })

    if skipped > 0:
        print(f"  [AVISO] {skipped} feature(s) ignoradas por geometria vazia/inválida.")

    geojson_out = {
        "type":     "FeatureCollection",
        "metadata": {
            "source":          "IBGE_Malha_Bairros_2022",
            "generated_by":    "geo_generate_rmf_bairros.py",
            "generated_at":    __import__("datetime").datetime.utcnow().isoformat() + "Z",
            "total_features":  len(features),
            "municipalities":  len(target_codes),
            "tolerance":       args.tolerance,
            "includes_fortaleza": include_fortaleza,
            "rmf_coverage_pct": round(
                (len([v for v in coverage.values() if v > 0]) / len(target_codes)) * 100
            ),
        },
        "features": features,
    }

    output_json = json.dumps(geojson_out, ensure_ascii=False, separators=(",", ":"))
    size_kb     = len(output_json.encode("utf-8")) / 1024

    print()
    print("─" * 62)
    print(f"  Features geradas         : {len(features)}")
    print(f"  Municípios com bairros   : {len([v for v in coverage.values() if v > 0])}/{len(target_codes)}")
    print(f"  Tamanho estimado         : {size_kb:.0f} KB")
    print(f"  Fortaleza incluída       : {include_fortaleza}")

    if size_kb > 3000:
        print(f"\n  [AVISO] Arquivo grande ({size_kb:.0f} KB). Rode com --tolerance=0.0002 para reduzir.")

    if args.dry_run:
        print("\n  [DRY-RUN] Arquivo não salvo.")
        print("─" * 62)
        print()
        sys.exit(0)

    # ── Salva arquivo ─────────────────────────────────────────────────────────
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output_json, encoding="utf-8")
    print(f"\n  Salvo em: {output_path}")
    print("─" * 62)
    print()
    print("  Próximo passo: php tools/dev/geo_rmf_bairros_source_probe.php")
    print()


if __name__ == "__main__":
    main()
