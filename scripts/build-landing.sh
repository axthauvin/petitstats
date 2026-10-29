#!/usr/bin/env bash
set -euo pipefail

# -----------------------------------------------------------------------------
# Petitstats Landing Packaging Script
# -----------------------------------------------------------------------------
# Packages the landing page (landing/ directory) into a .tar.gz archive meant to
# be extracted into the same web root as the application, next to it:
#
#   tar -xzf petitstats-landing.tar.gz -C /path/to/web-root
# -----------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
LANDING_DIR="${PROJECT_ROOT}/landing"

DIST_DIR="${1:-${PROJECT_ROOT}/dist}"
ARCHIVE_NAME="${2:-petitstats-landing.tar.gz}"
OUTPUT_ARCHIVE="${DIST_DIR}/${ARCHIVE_NAME}"

echo "==> Packaging landing page from: ${LANDING_DIR}"
echo "==> Target archive: ${OUTPUT_ARCHIVE}"

mkdir -p "${DIST_DIR}"

# The documentation pages are generated from docs/ into landing/docs/.
php "${SCRIPT_DIR}/build-docs.php" "${LANDING_DIR}/docs"
tar -czf "${OUTPUT_ARCHIVE}" --exclude ".DS_Store" -C "${LANDING_DIR}" .

if command -v sha256sum >/dev/null 2>&1; then
    (cd "${DIST_DIR}" && sha256sum "${ARCHIVE_NAME}" > "${ARCHIVE_NAME}.sha256")
elif command -v shasum >/dev/null 2>&1; then
    (cd "${DIST_DIR}" && shasum -a 256 "${ARCHIVE_NAME}" > "${ARCHIVE_NAME}.sha256")
fi

echo "==> Archive successfully created:"
ls -lh "${OUTPUT_ARCHIVE}"
