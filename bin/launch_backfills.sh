#!/bin/bash
BASE=/home/u106688109/domains/magmoveiscorporativos.com.br/public_html/biomie
cd $BASE

echo "Lancando backfills historicos..."

nohup php bin/initial_sync_company.php --company=MAG_FILIAL --from=2024-01 --pause=7 --skip-marts > storage/companies/mag_filial/logs/backfill_20260621.log 2>&1 &
echo "MAG_FILIAL PID=$!"

nohup php bin/initial_sync_company.php --company=EXPERT --from=2025-07 --pause=13 --skip-marts > storage/companies/expert/logs/backfill_20260621b.log 2>&1 &
echo "EXPERT PID=$!"

nohup php bin/initial_sync_company.php --company=UNI_DESIGN --from=2024-01 --pause=7 --skip-marts > storage/companies/uni_design/logs/backfill_20260621b.log 2>&1 &
echo "UNI_DESIGN PID=$!"

nohup php bin/initial_sync_company.php --company=MAG_MATRIZ --from=2025-07 --pause=13 --skip-marts > storage/companies/mag_matriz/logs/backfill_20260621b.log 2>&1 &
echo "MAG_MATRIZ PID=$!"

echo "Todos lancados. Aguardando 10s..."
sleep 10
echo ""
echo "Processos rodando:"
ps -ef | grep initial_sync | grep -v grep | awk '{print $2, $5, $11, $12, $13, $14, $15}'
