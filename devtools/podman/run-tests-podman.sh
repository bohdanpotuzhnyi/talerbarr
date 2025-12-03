#!/usr/bin/env bash
set -euo pipefail

IMAGE_NAME="talerbarr-ci"
CONTAINER_NAME="talerbarr-ci-run"
SNAPSHOT_MODULE_DIR_IN_CONTAINER="/opt/talerbarr-src"

# Where this script lives (and where Containerfile + ci-run.sh are)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODULE_SRC="${MODULE_SRC:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
MODULE_DIR_IN_CONTAINER="${MODULE_DIR_IN_CONTAINER:-/opt/talerbarr-src}"
USE_LOCAL_MODULE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --local-module)
      USE_LOCAL_MODULE=1
      shift
      ;;
    --help|-h)
      echo "Usage: $0 [--local-module]"
      echo "  default: use module snapshot baked into image (${SNAPSHOT_MODULE_DIR_IN_CONTAINER})"
      echo "  --local-module: mount local module source from MODULE_SRC into container"
      exit 0
      ;;
    *)
      echo "Unknown argument: $1"
      echo "Use --help for usage."
      exit 1
      ;;
  esac
done

# Podman network mode (host often fixes DNS in corporate/VPN setups)
PODMAN_NETWORK="${PODMAN_NETWORK:-host}"

# Build image (only re-build when necessary)
echo "== Building image ${IMAGE_NAME} =="
podman build --network="${PODMAN_NETWORK}" -t "${IMAGE_NAME}" "${SCRIPT_DIR}"

MODULE_DIR_ENV="${SNAPSHOT_MODULE_DIR_IN_CONTAINER}"
PODMAN_MOUNT_ARGS=()
if [ "${USE_LOCAL_MODULE}" = "1" ]; then
  if [ ! -d "${MODULE_SRC}" ]; then
    echo "Local module source directory does not exist: ${MODULE_SRC}"
    exit 1
  fi
  MODULE_DIR_ENV="${MODULE_DIR_IN_CONTAINER}"
  PODMAN_MOUNT_ARGS=(-v "${MODULE_SRC}:${MODULE_DIR_IN_CONTAINER}:ro,z")
  echo "== Using local module source: ${MODULE_SRC} -> ${MODULE_DIR_IN_CONTAINER} =="
else
  echo "== Using snapshot module source baked into image: ${SNAPSHOT_MODULE_DIR_IN_CONTAINER} =="
fi

echo "== Running tests in container =="
podman run --rm \
  --name "${CONTAINER_NAME}" \
  --network "${PODMAN_NETWORK}" \
  "${PODMAN_MOUNT_ARGS[@]}" \
  -e DOLIBARR_BRANCH="${DOLIBARR_BRANCH:-22.0.3}" \
  -e DB="${DB:-mysql}" \
  -e TRAVIS_PHP_VERSION="${TRAVIS_PHP_VERSION:-8.3}" \
  -e TALER_STACK_HOST="${TALER_STACK_HOST:-test.taler.potuzhnyi.com}" \
  -e TALER_WEBHOOK_SINK_URL="${TALER_WEBHOOK_SINK_URL:-}" \
  -e TALER_WEBHOOK_SINK_RESET_URL="${TALER_WEBHOOK_SINK_RESET_URL:-}" \
  -e MYSQL_PORT="${MYSQL_PORT:-13306}" \
  -e MYSQL_PASSWORD="${MYSQL_PASSWORD:-password}" \
  -e MODULE_DIR="${MODULE_DIR_ENV}" \
  -e MODULE_NAME="talerbarr" \
  "${IMAGE_NAME}"
