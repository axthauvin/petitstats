# Downloads the two release archives from GitHub Releases and verifies them
# against their SHA256 checksums:
#
#   - petitstats.tar.gz          the Analytics dashboard (app/)
#   - petitstats-landing.tar.gz  the landing page (landing/)
#
# Usage: download-archives.sh [DEST_DIR] [TAG]
#   DEST_DIR  where to save the archives (default: ./dist)
#   TAG       release tag, e.g. v1.2.0 (default: latest)
# -----------------------------------------------------------------------------

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

REPO="${PETITSTATS_REPO:-axthauvin/petitstats}"
DEST_DIR="${1:-${PROJECT_ROOT}/dist}"
TAG="${2:-latest}"
ARCHIVES=("petitstats.tar.gz" "petitstats-landing.tar.gz")

if [ "${TAG}" = "latest" ]; then
    BASE_URL="https://github.com/${REPO}/releases/latest/download"
else
    BASE_URL="https://github.com/${REPO}/releases/download/${TAG}"
fi

if ! command -v curl >/dev/null 2>&1; then
    echo "ERROR: curl is required." >&2
    exit 1
fi

mkdir -p "${DEST_DIR}"

verify_checksum() {
    if command -v sha256sum >/dev/null 2>&1; then
        (cd "${DEST_DIR}" && sha256sum -c "$1")
    elif command -v shasum >/dev/null 2>&1; then
        (cd "${DEST_DIR}" && shasum -a 256 -c "$1")
    else
        echo "WARNING: no sha256sum/shasum found, skipping verification of ${1%.sha256}" >&2
    fi
}

for archive in "${ARCHIVES[@]}"; do
    echo "==> Downloading ${archive} (${TAG}) from ${REPO}"
    curl -fL --progress-bar -o "${DEST_DIR}/${archive}" "${BASE_URL}/${archive}"
    curl -fsSL -o "${DEST_DIR}/${archive}.sha256" "${BASE_URL}/${archive}.sha256"
    verify_checksum "${archive}.sha256"
done

echo "==> Archives saved in: ${DEST_DIR}"
ls -lh "${DEST_DIR}"/petitstats*.tar.gz
