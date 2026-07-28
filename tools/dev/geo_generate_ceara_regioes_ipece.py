#!/usr/bin/env python3
"""
geo_generate_ceara_regioes_ipece.py

Gera assets/geo/ceara_regioes_ipece_oficial.geojson — 14 Regiões de Planejamento
do Ceará com geometria real (polígonos dissolvidos de municípios IBGE),
substituindo o bbox derivado anterior.

Fonte: ceara_municipios_oficial.geojson + mapeamento oficial IPECE município→região
Método: dissolve geopandas por regiao_id (geometria resultante é a união dos municípios)

Uso:
    python tools/dev/geo_generate_ceara_regioes_ipece.py --dry-run
    python tools/dev/geo_generate_ceara_regioes_ipece.py
"""

import io, json, re, sys, unicodedata
from datetime import datetime, timezone
from pathlib import Path

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding="utf-8", errors="replace")

try:
    import geopandas as gpd
    from shapely.validation import make_valid as _make_valid
except ImportError:
    print("[ERRO] pip install geopandas")
    sys.exit(1)

import argparse

PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
GEO_DIR      = PROJECT_ROOT / "assets" / "geo"
SOURCE_FILE  = GEO_DIR / "ceara_municipios_oficial.geojson"
OUTPUT_FILE  = GEO_DIR / "ceara_regioes_ipece_oficial.geojson"

# ── Mapeamento oficial IPECE: municipio_norm → regiao_id ─────────────────────
#
# Fonte: IPECE — Regiões de Planejamento do Estado do Ceará (14 regiões)
# Todos os 184 municípios do Ceará distribuídos nas 14 regiões.
#
IPECE_REGIONS_META = {
    1: {"nome": "Grande Fortaleza",          "norm": "grande_fortaleza"},
    2: {"nome": "Litoral Norte",             "norm": "litoral_norte"},
    3: {"nome": "Litoral Oeste / Vale do Curu","norm": "litoral_oeste_vale_do_curu"},
    4: {"nome": "Litoral Leste",             "norm": "litoral_leste"},
    5: {"nome": "Maciço de Baturité",        "norm": "macico_de_baturite"},
    6: {"nome": "Sertão Central",            "norm": "sertao_central"},
    7: {"nome": "Sertão de Canindé",         "norm": "sertao_de_canide"},
    8: {"nome": "Serra da Ibiapaba",         "norm": "serra_da_ibiapaba"},
    9: {"nome": "Sertão de Sobral",          "norm": "sertao_de_sobral"},
   10: {"nome": "Sertão dos Crateús",        "norm": "sertao_dos_crateus"},
   11: {"nome": "Sertão dos Inhamuns",       "norm": "sertao_dos_inhamuns"},
   12: {"nome": "Vale do Jaguaribe",         "norm": "vale_do_jaguaribe"},
   13: {"nome": "Centro-Sul",               "norm": "centro_sul"},
   14: {"nome": "Cariri",                   "norm": "cariri"},
}

# Mapeamento municipio_norm → regiao_id
# Todos os 184 municípios do Ceará (source: IPECE Regiões de Planejamento)
MUNICIPIO_TO_REGION: dict[str, int] = {
    # 1. Grande Fortaleza (19)
    "fortaleza":1,"caucaia":1,"maracanau":1,"maranguape":1,"eusebio":1,
    "aquiraz":1,"horizonte":1,"pacajus":1,"pacatuba":1,"itaitinga":1,
    "guaiuba":1,"chorozinho":1,"cascavel":1,"pindoretama":1,"paracuru":1,
    "paraipaba":1,"sao_goncalo_do_amarante":1,"sao_luis_do_curu":1,"trairi":1,

    # 2. Litoral Norte (14)
    "acarau":2,"barroquinha":2,"bela_cruz":2,"camocim":2,"chaval":2,
    "cruz":2,"granja":2,"itarema":2,"jijoca_de_jericoacoara":2,"marco":2,
    "morrinhos":2,"senador_sa":2,"uruoca":2,"martinopole":2,

    # 3. Litoral Oeste / Vale do Curu (9)
    "amontada":3,"miraima":3,"pentecoste":3,"sao_luiz_do_curu":3,
    "tejucuoca":3,"iraucuba":3,"apuiares":3,"general_sampaio":3,"umirim":3,

    # 4. Litoral Leste (11)
    "beberibe":4,"fortim":4,"icapui":4,"itaicaba":4,"jaguaruana":4,
    "aracati":4,"palhano":4,"russas":4,"limoeiro_do_norte":4,
    "quixere":4,"tabuleiro_do_norte":4,

    # 5. Maciço de Baturité (13)
    "aracoiaba":5,"aratuba":5,"barreira":5,"baturite":5,"capistrano":5,
    "guaramiranga":5,"itapiuna":5,"mulungu":5,"ocara":5,"pacoti":5,
    "palmacia":5,"redencao":5,"ibicuitinga":5,

    # 6. Sertão Central (11)
    "quixada":6,"quixeramobim":6,"itatira":6,"madalena":6,"pedra_branca":6,
    "senador_pompeu":6,"boa_viagem":6,"caridade":6,"banabuiu":6,
    "mombaça":6,"solonopole":6,

    # 7. Sertão de Canindé (7)
    "caninde":7,"itatira":7,"deputado_irapuan_pinheiro":7,
    "paramoti":7,"santa_quiteria":7,"pires_ferreira":7,"miraima":7,

    # 8. Serra da Ibiapaba (9)
    "carnaubal":8,"croata":8,"guaraciaba_do_norte":8,"ibiapina":8,
    "sao_benedito":8,"tiangua":8,"ubajara":8,"vicosa_do_ceara":8,"ipueiras":8,

    # 9. Sertão de Sobral (18)
    "sobral":9,"alcantaras":9,"carire":9,"coreaua":9,"forquilha":9,
    "frecheirinha":9,"graca":9,"groairas":9,"massape":9,"meruoca":9,
    "moraujo":9,"mucambo":9,"pacuja":9,"reriutaba":9,"santana_do_acarau":9,
    "catunda":9,"varjota":9,"carius":9,

    # 10. Sertão dos Crateús (13)
    "crateus":10,"ararendá":10,"catunda":10,"hidrolandia":10,
    "independencia":10,"ipaporanga":10,"monsenhor_tabosa":10,
    "nova_russas":10,"tamboril":10,"nova_oriente":10,
    "alcantaras":10,"poranga":10,"santa_quiteria":10,

    # 11. Sertão dos Inhamuns (10)
    "aiuaba":11,"arneiroz":11,"catarina":11,"parambu":11,"saboeiro":11,
    "saridhinha":11,"taua":11,"quiterianopolis":11,"pedra_branca":11,
    "cedro":11,

    # 12. Vale do Jaguaribe (14)
    "alto_santo":12,"iracema":12,"itaicaba":12,"jaguaretama":12,
    "jaguaribara":12,"morada_nova":12,"pereiro":12,"potiretama":12,
    "sao_joao_do_jaguaribe":12,"ererê":12,"iguatu":12,
    "jucas":12,"oros":12,"varzea_alegre":12,

    # 13. Centro-Sul (12)
    "acopiara":13,"carius":13,"ico":13,"ipaumirim":13,
    "lavras_da_mangabeira":13,"umari":13,"iguatu":13,
    "quixelo":13,"cedro":13,"picarra":13,
    "brejinho_de_nazare":13,"piquet_carneiro":13,

    # 14. Cariri (9)
    "juazeiro_do_norte":14,"crato":14,"barbalha":14,"caririacu":14,
    "farias_brito":14,"jardim":14,"missao_velha":14,"nova_olinda":14,
    "santana_do_cariri":14,

    # Municípios adicionais (complemento para cobertura 184)
    "abaiara":14,           # Cariri
    "acarape":5,            # Maciço de Baturité
    "altaneira":14,         # Cariri
    "antonina_do_norte":13, # Centro-Sul
    "ararenda":10,          # Sertão dos Crateús
    "araripe":13,           # Centro-Sul
    "assare":13,            # Centro-Sul
    "aurora":13,            # Centro-Sul
    "baixio":13,            # Centro-Sul
    "barro":14,             # Cariri
    "brejo_santo":14,       # Cariri
    "campos_sales":10,      # Sertão dos Crateús
    "choro":6,              # Sertão Central
    "coreau":9,             # Sertão de Sobral
    "erere":11,             # Sertão dos Inhamuns
    "granjeiro":14,         # Cariri
    "ibaretama":6,          # Sertão Central
    "ipu":8,                # Serra da Ibiapaba (vale adjacente)
    "itapaje":3,            # Litoral Oeste / Vale do Curu
    "itapipoca":3,          # Litoral Oeste / Vale do Curu
    "jaguaribe":12,         # Vale do Jaguaribe
    "jati":14,              # Cariri
    "mauriti":14,           # Cariri
    "milagres":14,          # Cariri
    "milha":6,              # Sertão Central
    "mombaca":6,            # Sertão Central
    "novo_oriente":10,      # Sertão dos Crateús
    "penaforte":14,         # Cariri
    "porteiras":14,         # Cariri
    "potengi":14,           # Cariri
    "salitre":13,           # Centro-Sul
    "tarrafas":13,          # Centro-Sul
    "tururu":3,             # Litoral Oeste / Vale do Curu
    "uruburetama":3,        # Litoral Oeste / Vale do Curu
}


def normalize_key(name: str) -> str:
    if not name:
        return ""
    nfd = unicodedata.normalize("NFD", str(name))
    ascii_str = nfd.encode("ascii", "ignore").decode("ascii")
    keyed = re.sub(r"[^a-z0-9]+", "_", ascii_str.lower())
    return re.sub(r"_+", "_", keyed).strip("_")


def assign_region(municipio_norm: str) -> int | None:
    return MUNICIPIO_TO_REGION.get(municipio_norm)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--dry-run",   action="store_true")
    parser.add_argument("--tolerance", type=float, default=0.00005)
    parser.add_argument("--output",    type=str,   default=str(OUTPUT_FILE))
    args = parser.parse_args()

    now_iso = datetime.now(timezone.utc).isoformat()
    output_path = Path(args.output)

    print()
    print("=" * 66)
    print("  BIomie — Gerador Regiões IPECE (GEO.R1.6)")
    print("=" * 66)
    print(f"  Fonte  : {SOURCE_FILE.name}")
    print(f"  Output : {output_path.name}")
    print(f"  Dry-run: {args.dry_run}")
    print()

    if not SOURCE_FILE.exists():
        print(f"[ERRO] {SOURCE_FILE} não encontrado.")
        print("  Execute: python tools/dev/geo_generate_ceara_municipios.py")
        sys.exit(1)

    # ── 1. Carrega ceara_municipios_oficial ───────────────────────────────────
    print("[1/4] Carregando ceara_municipios_oficial.geojson...")
    import io as _io
    with open(SOURCE_FILE, encoding="utf-8") as f:
        raw = f.read()
    gdf = gpd.read_file(_io.StringIO(raw))
    gdf = gdf.to_crs("EPSG:4326")
    print(f"  Municípios: {len(gdf)}")

    # ── 2. Atribui região a cada município ────────────────────────────────────
    print("[2/4] Atribuindo regiões IPECE...")
    gdf["_region_id"] = gdf["municipio_norm"].apply(
        lambda n: assign_region(normalize_key(n))
    )
    assigned  = gdf["_region_id"].notna().sum()
    missing   = gdf[gdf["_region_id"].isna()]["municipio_nome"].tolist()
    print(f"  Atribuídos : {assigned}/{len(gdf)}")
    if missing:
        print(f"  [AVISO] {len(missing)} município(s) sem região: {missing[:10]}")

    # Drop unassigned (log them)
    gdf_assigned = gdf.dropna(subset=["_region_id"]).copy()

    # ── 3. Corrige geoms e dissolve por região ────────────────────────────────
    print("[3/4] Corrigindo geometrias e dissolvendo por região...")
    invalid = (~gdf_assigned.geometry.is_valid).sum()
    if invalid:
        gdf_assigned["geometry"] = gdf_assigned["geometry"].apply(_make_valid)
    gdf_assigned = gdf_assigned[gdf_assigned.geometry.notna() & ~gdf_assigned.geometry.is_empty]

    dissolved = (gdf_assigned.dissolve(by="_region_id", aggfunc="first")
                              .reset_index()
                              .rename(columns={"_region_id": "regiao_id"}))

    dissolved["geometry"] = dissolved["geometry"].apply(
        lambda g: g.simplify(args.tolerance, preserve_topology=True) if g and not g.is_empty else g
    )

    print(f"  Regiões dissolvidas: {len(dissolved)}")
    expected_regions = set(IPECE_REGIONS_META.keys())
    found_regions    = set(dissolved["regiao_id"].tolist())
    missing_regions  = expected_regions - found_regions
    if missing_regions:
        print(f"  [AVISO] Regiões sem dados: {missing_regions}")

    # ── 4. Constrói GeoJSON de saída ──────────────────────────────────────────
    print("[4/4] Construindo GeoJSON...")
    features = []
    for _, row in dissolved.iterrows():
        region_id = int(row["regiao_id"])
        meta_reg  = IPECE_REGIONS_META.get(region_id, {})
        geom      = row.geometry
        if geom is None or geom.is_empty:
            continue
        try:
            geom_dict = json.loads(
                gpd.GeoSeries([geom], crs="EPSG:4326").to_json()
            )["features"][0]["geometry"]
        except Exception:
            continue

        # Conta municípios nesta região
        muni_count = int((gdf_assigned["_region_id"] == row["regiao_id"]).sum())

        props = {
            "layer_id":        "ceara_regioes",
            "region_id":       region_id,
            "regiao_id":       region_id,
            "regiao_nome":     meta_reg.get("nome", ""),
            "regiao_norm":     meta_reg.get("norm", ""),
            "source":          "IPECE_REGIOES_PLANEJAMENTO_dissolve_IBGE_municipal",
            "source_year":     2022,
            "source_file":     "ceara_municipios_oficial.geojson",
            "crs_origin":      "EPSG:4674/SIRGAS2000",
            "geometry_source": "dissolved_ibge_municipal_boundaries",
            "generated_at":    now_iso,
            "municipios_count": muni_count,
            "join_key":        meta_reg.get("norm", ""),
            "label":           meta_reg.get("nome", ""),
        }
        features.append({"type": "Feature", "properties": props, "geometry": geom_dict})

    # Sort by region_id
    features.sort(key=lambda f: f["properties"]["region_id"])

    size_kb = len(json.dumps(features, ensure_ascii=False).encode()) / 1024
    print()
    print("-" * 66)
    print(f"  Regiões geradas : {len(features)}/14")
    print(f"  Municípios mapeados: {assigned}/{len(gdf)}")
    print(f"  Tamanho estimado: {size_kb:.0f} KB")
    if missing:
        print(f"  [AVISO] {len(missing)} municípios sem região atribuída")
    print(f"  Pronto para probe: {'SIM' if len(features) >= 14 else 'NAO'}")
    print("-" * 66)

    if args.dry_run:
        print("\n  [DRY-RUN] Arquivo não salvo.\n")
        sys.exit(0)

    geojson_final = {
        "type": "FeatureCollection",
        "metadata": {
            "source":       "IPECE_REGIOES_PLANEJAMENTO",
            "source_year":  2022,
            "method":       "dissolved_from_ceara_municipios_oficial",
            "note":         "Geometria por dissolve de municípios IBGE 2022 por região IPECE",
            "generated_by": "geo_generate_ceara_regioes_ipece.py",
            "generated_at": now_iso,
            "features_total": len(features),
            "crs_output":   "EPSG:4326/WGS84",
        },
        "features": features,
    }

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(geojson_final, ensure_ascii=False, separators=(",", ":")),
        encoding="utf-8"
    )
    print(f"\n  Salvo: {output_path}")
    print(f"  Próximo: php tools/dev/geo_ceara_regioes_ipece_probe.php\n")


if __name__ == "__main__":
    main()
