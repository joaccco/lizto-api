#!/usr/bin/env bash
set -e

# ==============================================================================
# LIZTO BACKEND STAGING DEPLOYMENT SCRIPT (< 5 MIN)
# Usage: ./scripts/deploy-backend.sh
# ==============================================================================

echo "========================================================"
echo " [DEPLOY] Starting Backend Staging Deployment..."
echo "========================================================"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
API_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${API_DIR}"

START_TIME=$(date +%s)

echo ">> 1. Verifying pre-requisites..."
if [ ! -f .env.staging ]; then
    echo "❌ Error: .env.staging not found!"
    exit 1
fi

echo ">> 2. Building Docker images..."
docker-compose -f docker-compose.staging.yml build staging-api staging-worker

echo ">> 3. Starting infrastructure (Postgres, Redis)..."
docker-compose -f docker-compose.staging.yml up -d staging-postgres staging-redis

echo ">> 4. Running database migrations..."
docker-compose -f docker-compose.staging.yml run --rm staging-api php artisan migrate --force

echo ">> 5. Launching API & Queue workers..."
docker-compose -f docker-compose.staging.yml up -d staging-api staging-worker

echo ">> 6. Cache optimizations..."
docker-compose -f docker-compose.staging.yml exec -T staging-api php artisan config:cache
docker-compose -f docker-compose.staging.yml exec -T staging-api php artisan route:cache

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

echo "========================================================"
echo "✅ [DEPLOY] Backend Staging deployed in ${DURATION}s!"
echo "   Endpoint: https://staging-api.lizto.app (or localhost:9000)"
echo "========================================================"
