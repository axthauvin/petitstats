#!/usr/bin/env bash
set -euo pipefail

# -----------------------------------------------------------------------------
# Petitstats Release Packaging Script
# -----------------------------------------------------------------------------
# Packages the deployable application (the app/ directory) into a .tar.gz
# archive. The landing page, docs and repository tooling live outside app/ and
# are never part of the archive.
# -----------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
APP_DIR="${PROJECT_ROOT}/app"

# Output configuration
DIST_DIR="${1:-${PROJECT_ROOT}/dist}"
ARCHIVE_NAME="${2:-petitstats.tar.gz}"
OUTPUT_ARCHIVE="${DIST_DIR}/${ARCHIVE_NAME}"

echo "==> Packaging Petitstats from: ${APP_DIR}"
echo "==> Target archive: ${OUTPUT_ARCHIVE}"

mkdir -p "${DIST_DIR}"

# The archive must ship production dependencies so installs without shell
# access (or Composer) work out of the box: `composer install --no-dev`.
if [ ! -f "${APP_DIR}/vendor/autoload.php" ]; then
    echo "ERROR: vendor/autoload.php is missing. Run 'composer install --no-dev --optimize-autoloader' first." >&2
    exit 1
fi
if [ -d "${APP_DIR}/vendor/friendsofphp" ]; then
    echo "ERROR: vendor/ contains dev dependencies. Run 'composer install --no-dev --optimize-autoloader' first." >&2
    exit 1
fi

# Create a clean temporary staging area
STAGING_DIR="$(mktemp -d)"
trap 'rm -rf "${STAGING_DIR}"' EXIT
# mktemp creates a 0700 folder, and tar records it as the archive root: once
# extracted, the web root would be unreadable by the web server.
chmod 755 "${STAGING_DIR}"

# Files/directories inside app/ that must not be deployed. A pattern without
# "/" matches a name at any depth (including the tests/ folders of vendor
# packages), and one with "/" a path from the app folder.
EXCLUDES=(
    "tests"
    "umami-import"
    "bin/seed-demo.php"
    "phpstan*.neon"
    "phpunit.xml"
    ".phpunit.cache"
    ".php-cs-fixer.cache"
    "*.tar.gz"
    "*.zip"
    "*.log"
    ".DS_Store"
)

# Copy app/ to the staging directory, then remove what must not be deployed.
# The same steps run everywhere, so every machine builds the same archive.
cp -R "${APP_DIR}/." "${STAGING_DIR}/"
for pattern in "${EXCLUDES[@]}"; do
    if [[ "${pattern}" == */* ]]; then
        find "${STAGING_DIR}" -path "${STAGING_DIR}/${pattern}" -prune -exec rm -rf {} +
    else
        find "${STAGING_DIR}" -name "${pattern}" -prune -exec rm -rf {} +
    fi
done
# Data folder of older versions: data now lives outside the web root.
rm -rf "${STAGING_DIR}/data"
# Sites tagged before the project was renamed load minilytics.js.
cp "${STAGING_DIR}/petitstats.js" "${STAGING_DIR}/minilytics.js"

# Record the release tag, so the dashboard can show the installed version and
# tell administrators about newer releases (src/ is not publicly served).
if [ -n "${PETITSTATS_VERSION:-}" ]; then
    printf '%s
' "${PETITSTATS_VERSION}" > "${STAGING_DIR}/src/VERSION"
fi

# List every file with its SHA-256 in src/MANIFEST. Updates from the dashboard
# only replace these files, check each one, and remove those a release drops.
# They write them in this order, and src/Updates/guard.php comes first: once
# it is in place, an update from a Minilytics version that is killed halfway
# can still be undone (see the loader in that file).
(
    cd "${STAGING_DIR}"
    if command -v sha256sum >/dev/null 2>&1; then HASH=(sha256sum); else HASH=(shasum -a 256); fi
    UNORDERED="$(mktemp)"
    find . -type f ! -path ./src/MANIFEST -print0 | LC_ALL=C sort -z | xargs -0 "${HASH[@]}" | sed -E 's#^([0-9a-f]{64}) [ *]\./#\1  #' > "${UNORDERED}"
    { grep '  src/Updates/guard\.php$' "${UNORDERED}"; grep -v '  src/Updates/guard\.php$' "${UNORDERED}"; } > src/MANIFEST
    rm "${UNORDERED}"
)

# Ensure .htaccess at root exists
if [ ! -f "${STAGING_DIR}/.htaccess" ] && [ -f "${APP_DIR}/.htaccess" ]; then
    cp "${APP_DIR}/.htaccess" "${STAGING_DIR}/.htaccess"
fi

# Safety check: ensure no database or secret file leaked into staging
LEAKED=$(find "${STAGING_DIR}" \( -name "*.db" -o -name "*.db-wal" -o -name "*.db-shm" -o -name "sites.json" -o -name "database.json" -o -name ".tracking-secret" -o -name ".visitor-salt" \) -print)
if [ -n "${LEAKED}" ]; then
    echo "ERROR: Data file detected in staging: ${LEAKED}" >&2
    exit 1
fi

# Create tar.gz archive with clean relative paths
echo "==> Creating tarball..."
tar -czf "${OUTPUT_ARCHIVE}" -C "${STAGING_DIR}" .

# Generate SHA256 checksum
CHECKSUM_FILE="${OUTPUT_ARCHIVE}.sha256"
if command -v sha256sum >/dev/null 2>&1; then
    (cd "${DIST_DIR}" && sha256sum "$(basename "${OUTPUT_ARCHIVE}")" > "$(basename "${CHECKSUM_FILE}")")
elif command -v shasum >/dev/null 2>&1; then
    (cd "${DIST_DIR}" && shasum -a 256 "$(basename "${OUTPUT_ARCHIVE}")" > "$(basename "${CHECKSUM_FILE}")")
fi

# Installs made before the project was renamed download minilytics.tar.gz
# (and its .sha256) when they update from the dashboard, so every release
# must publish that copy too.
if [ "${ARCHIVE_NAME}" = "petitstats.tar.gz" ]; then
    cp "${OUTPUT_ARCHIVE}" "${DIST_DIR}/minilytics.tar.gz"
    cp "${CHECKSUM_FILE}" "${DIST_DIR}/minilytics.tar.gz.sha256"
fi

echo "==> Archive successfully created:"
ls -lh "${OUTPUT_ARCHIVE}"
if [ -f "${CHECKSUM_FILE}" ]; then
    cat "${CHECKSUM_FILE}"
fi
