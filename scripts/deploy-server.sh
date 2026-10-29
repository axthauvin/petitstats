#!/usr/bin/env bash
set -euo pipefail

# -----------------------------------------------------------------------------
# Petitstats Server Deploy Script
# -----------------------------------------------------------------------------
# Placed in the web root (e.g. ~/public_html), it downloads the latest release
# archives (application and landing page), empties the folder except for this
# script, then extracts both archives into it:
#
#   ./deploy-server.sh            # latest release
#   ./deploy-server.sh v1.4.0     # a given release tag
#
# Data lives outside the web root (../petitstats-data), so it is kept.
# -----------------------------------------------------------------------------

REPO="axthauvin/petitstats"
TAG="${1:-latest}"
ARCHIVES=("petitstats.tar.gz" "petitstats-landing.tar.gz")

TARGET_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SELF="$(basename "${BASH_SOURCE[0]}")"

if [ "${TAG}" = "latest" ]; then
    BASE_URL="https://github.com/${REPO}/releases/latest/download"
else
    BASE_URL="https://github.com/${REPO}/releases/download/${TAG}"
fi

if [ -z "${TARGET_DIR}" ] || [ "${TARGET_DIR}" = "/" ] || [ "${TARGET_DIR}" = "${HOME}" ]; then
    echo "ERROR: refusing to deploy into ${TARGET_DIR:-an empty path}." >&2
    exit 1
fi

# Downloads go outside the web root, so emptying it never removes them.
DOWNLOAD_DIR="$(mktemp -d)"
trap 'rm -rf "${DOWNLOAD_DIR}"' EXIT

# Download and check every archive before touching the current site.
for archive in "${ARCHIVES[@]}"; do
    echo "==> Downloading ${archive} (${TAG})"
    curl -fsSL -o "${DOWNLOAD_DIR}/${archive}" "${BASE_URL}/${archive}"
    curl -fsSL -o "${DOWNLOAD_DIR}/${archive}.sha256" "${BASE_URL}/${archive}.sha256"
    (cd "${DOWNLOAD_DIR}" && sha256sum -c "${archive}.sha256")
    tar -tzf "${DOWNLOAD_DIR}/${archive}" >/dev/null
done

echo "==> Emptying ${TARGET_DIR} (keeping ${SELF})"
find "${TARGET_DIR}" -mindepth 1 -maxdepth 1 ! -name "${SELF}" -exec rm -rf {} +

for archive in "${ARCHIVES[@]}"; do
    echo "==> Extracting ${archive}"
    tar -xzf "${DOWNLOAD_DIR}/${archive}" -C "${TARGET_DIR}"
done

echo "==> Deployed ${TAG} into ${TARGET_DIR}"
