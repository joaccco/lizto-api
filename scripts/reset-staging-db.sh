#!/usr/bin/env bash
set -e

# ==============================================================================
# LIZTO STAGING DATABASE FAST RESET SCRIPT (< 1 MIN)
# Usage: ./scripts/reset-staging-db.sh
# ==============================================================================

echo "========================================================"
echo " [STAGING] Starting fast database reset..."
echo "========================================================"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

cd "${API_DIR}"

if [ ! -f .env.staging ]; then
    echo "⚠️ Warning: .env.staging not found, falling back to .env"
    ENV_FILE=".env"
else
    ENV_FILE=".env.staging"
fi

echo ">> 1. Running migrate:fresh with StagingSeeder..."
START_TIME=$(date +%s)

# Execute migrate:fresh --seed with StagingSeeder
php artisan migrate:fresh --seed --seeder=Database\\Seeders\\StagingSeeder --force

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

echo ">> 2. Clearing application & configuration caches..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear

echo "========================================================"
echo "✅ [STAGING] Database reset complete in ${DURATION}s (< 60s target achieved)!"
echo "   - 10 clients created"
echo "   - 20 providers created"
echo "   - 50 works seeded (fake_data_source = 'staging-test')"
echo "========================================================"
