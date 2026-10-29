#!/usr/bin/env bash

# -----------------------------------------------------------------------------
# Petitstats Deploy Script
# -----------------------------------------------------------------------------
# Meant to be copied into the web root (e.g. public_html) and run from there.
# It extracts the dashboard and landing archives found in a dist/ folder into
# the directory the script lives in, after verifying their SHA256 checksums.
#
# Runtime data (*.db, sites.json, ...) is never part of the archives, so it is
# left untouched. It lives outside the web root: PETITSTATS_DATA_DIR if set,
# otherwise ../petitstats-data. A backup is made before extracting anyway.
#
# Usage: ./deploy.sh [DIST_DIR]
#   DIST_DIR  folder holding the archives
#             (default: ../petitstats-source-code/dist)
# -----------------------------------------------------------------------------

WEB_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="${1:-${WEB_ROOT}/../petitstats-source-code/dist}"
ARCHIVES=("petitstats.tar.gz" "petitstats-landing.tar.gz")

if [ ! -d "${DIST_DIR}" ]; then
    echo "ERROR: dist folder not found: ${DIST_DIR}" >&2
    exit 1
fi
DIST_DIR="$(cd "${DIST_DIR}" && pwd)"

echo "==> Web root: ${WEB_ROOT}"
echo "==> Archives: ${DIST_DIR}"

# 1. Verify everything before touching the web root
for archive in "${ARCHIVES[@]}"; do
    if [ ! -f "${DIST_DIR}/${archive}" ]; then
        echo "ERROR: missing ${DIST_DIR}/${archive}" >&2
        exit 1
    fi
    if [ -f "${DIST_DIR}/${archive}.sha256" ]; then
        (cd "${DIST_DIR}" && sha256sum -c "${archive}.sha256")
    else
        echo "WARNING: no checksum for ${archive}, skipping verification" >&2
    fi
done

# 2. Back up runtime data
DATA_DIR="${PETITSTATS_DATA_DIR:-${WEB_ROOT}/../petitstats-data}"
STAMP="$(date +%Y%m%d-%H%M%S)"
if [ -d "${DATA_DIR}" ]; then
    BACKUP="${WEB_ROOT}/../data-backup-${STAMP}.tar.gz"
    tar -czf "${BACKUP}" -C "${DATA_DIR}" .
    echo "==> ${DATA_DIR} backed up to: ${BACKUP}"
fi

# 3. Extract dashboard, then landing, into the web root
for archive in "${ARCHIVES[@]}"; do
    echo "==> Extracting ${archive}"
    tar -xzf "${DIST_DIR}/${archive}" -C "${WEB_ROOT}"
done

# 4. The data dir must exist, be writable by the web server, and stay out of the web root
mkdir -p "${DATA_DIR}"
chmod u+rwX,g+rwX "${DATA_DIR}" 2>/dev/null || true
echo "==> Data directory: ${DATA_DIR}"

echo "==> Done. Landing: /  Dashboard: /dashboard/"
