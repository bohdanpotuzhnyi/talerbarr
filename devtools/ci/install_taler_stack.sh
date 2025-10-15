#!/usr/bin/env bash
# Travis helper to build the GNU Taler stack from source so integration tests
# can run without relying on external services.

set -euo pipefail

export PATH="$HOME/.local/bin:$PATH"

log() {
  printf '[taler-ci] %s\n' "$*"
}

# Default locations allow callers to override when needed.
readonly TALER_PREFIX="${TALER_PREFIX:-/usr/local}"
readonly TALER_BUILD_ROOT="${TALER_BUILD_ROOT:-$HOME/taler-build}"
readonly TALER_LOG_DIR="${TALER_LOG_DIR:-$TALER_BUILD_ROOT/logs}"
readonly TALER_BUILD_JOBS="${TALER_BUILD_JOBS:-$(command -v nproc >/dev/null 2>&1 && nproc || printf '2')}"
readonly TALER_MERCHANT_REPO="${TALER_MERCHANT_REPO:-https://git.taler.net/merchant.git}"
readonly TALER_EXCHANGE_REPO="${TALER_EXCHANGE_REPO:-https://git.taler.net/exchange.git}"
readonly TALER_MERCHANT_REF="${TALER_MERCHANT_REF:-master}"
readonly TALER_EXCHANGE_REF="${TALER_EXCHANGE_REF:-master}"
readonly GNUNET_REPO="${GNUNET_REPO:-https://git.gnunet.org/gnunet.git}"
readonly GNUNET_REF="${GNUNET_REF:-master}"
readonly TALER_CLONE_DEPTH="${TALER_CLONE_DEPTH:-1}"
readonly PODMAN_OVERRIDE_CONF="${TALER_PODMAN_OVERRIDE_CONF:-$TALER_BUILD_ROOT/podman-containers.conf}"
readonly PODMAN_SYSTEM_OVERRIDE="${TALER_PODMAN_SYSTEM_OVERRIDE:-/etc/containers/containers.conf.d/99-taler-sandcastle.conf}"
readonly TALER_DISABLE_DONAU="${TALER_DISABLE_DONAU:-1}"

apt_updated=0
apt_install() {
  if [[ $apt_updated -eq 0 ]]; then
    log "Updating apt package lists"
    sudo apt-get update -y
    apt_updated=1
  fi
  log "Installing packages: $*"
  sudo apt-get install -y "$@"
}

ensure_packages() {
  local packages=("$@")
  if [[ ${#packages[@]} -gt 0 ]]; then
    apt_install "${packages[@]}"
  fi
}

pip_install_user() {
  local package=$1
  if pip3 install --user --break-system-packages "$package"; then
    return 0
  fi

  log "pip3 failed with --break-system-packages; retrying without flag"
  pip3 install --user "$package"
}

ensure_python_module() {
  local module=$1
  local apt_package=${2:-}
  local pip_package=${3:-$module}

  if python3 -c "import ${module}" >/dev/null 2>&1; then
    return 0
  fi

  if [[ -n $apt_package ]]; then
    log "Python module ${module} missing; installing ${apt_package} via apt"
    ensure_packages "$apt_package"
    if python3 -c "import ${module}" >/dev/null 2>&1; then
      return 0
    fi
  fi

  log "Python module ${module} still missing; falling back to pip"
  ensure_packages python3-pip python3-setuptools python3-wheel
  if ! pip_install_user "${pip_package}"; then
    return 1
  fi
  if python3 -c "import ${module}" >/dev/null 2>&1; then
    log "Python module ${module} available"
    return 0
  fi

  log "Failed to ensure Python module ${module}"
  return 1
}

ensure_node_runtime() {
  local required_major=16
  local node_version=""
  local node_major=0

  if command -v node >/dev/null 2>&1; then
    node_version=$(node -v | sed 's/^v//')
    node_major=${node_version%%.*}
  fi

  local needs_nodesource=0
  if (( node_major < required_major )); then
    needs_nodesource=1
  fi

  if (( needs_nodesource == 1 )); then
    log "Installing Node.js 18.x (and npm) from NodeSource (current: ${node_version:-absent})"
    ensure_packages curl ca-certificates gnupg
    curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
    sudo apt-get install -y nodejs
    node_version=$(node -v | sed 's/^v//')
  else
    log "Node.js ${node_version} already available"
  fi

  hash -r

  if ! command -v corepack >/dev/null 2>&1 && ! command -v npm >/dev/null 2>&1; then
    log "Existing Node.js installation lacks corepack/npm; reinstalling Node.js 18.x from NodeSource"
    ensure_packages curl ca-certificates gnupg
    curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
    sudo apt-get install -y nodejs
    hash -r
  fi

  local desired_pnpm="pnpm@9.7.0"
  if ! command -v pnpm >/dev/null 2>&1; then
    if command -v corepack >/dev/null 2>&1; then
      log "Activating ${desired_pnpm} via corepack"
      if ! corepack enable >/dev/null 2>&1; then
        log "corepack enable reported an issue; continuing"
      fi
      if ! corepack prepare "${desired_pnpm}" --activate; then
        log "corepack failed to activate ${desired_pnpm}"
        return 1
      fi
    elif command -v npm >/dev/null 2>&1; then
      log "Installing ${desired_pnpm} globally via npm"
      sudo env "PATH=$PATH" npm install -g "${desired_pnpm}"
    else
      log "Failed to locate corepack or npm for pnpm installation"
      return 1
    fi
    hash -r
  fi
  if ! command -v pnpm >/dev/null 2>&1; then
    log "Failed to ensure pnpm on PATH"
    return 1
  fi

  local pnpm_path pnpm_dir
  pnpm_path=$(type -P pnpm || true)

  if [[ -z ${PNPM_HOME:-} ]]; then
    export PNPM_HOME="$HOME/.local/share/pnpm"
  fi
  if [[ -d $PNPM_HOME ]] && [[ ":$PATH:" != *":$PNPM_HOME:"* ]]; then
    log "Adding PNPM_HOME (${PNPM_HOME}) to PATH"
    export PATH="${PNPM_HOME}:$PATH"
  fi

  if [[ -n $pnpm_path ]]; then
    pnpm_dir=${pnpm_path%/*}
    if [[ -n $pnpm_dir && $pnpm_dir != "$pnpm_path" && ":$PATH:" != *":$pnpm_dir:"* ]]; then
      log "Ensuring pnpm binary directory ${pnpm_dir} is on PATH"
      export PATH="${pnpm_dir}:$PATH"
    fi
  elif [[ -x $PNPM_HOME/pnpm ]]; then
    log "Creating pnpm shim in $HOME/.local/bin"
    mkdir -p "$HOME/.local/bin"
    ln -sf "$PNPM_HOME/pnpm" "$HOME/.local/bin/pnpm"
    export PATH="$HOME/.local/bin:$PATH"
    pnpm_path="$HOME/.local/bin/pnpm"
  fi

  log "Using pnpm $(pnpm -v) with Node.js $(node -v)"
}

ensure_wallet_cli() {
  if command -v taler-wallet-cli >/dev/null 2>&1; then
    log "taler-wallet-cli already available at $(command -v taler-wallet-cli)"
    return 0
  fi

  log "Ensuring build prerequisites for taler-wallet-cli"
  ensure_packages git make zip jq
  ensure_node_runtime

  mkdir -p "$TALER_BUILD_ROOT"
  local wallet_src_dir="$TALER_BUILD_ROOT/taler-typescript-core"

  if [[ ! -d $wallet_src_dir ]]; then
    log "Cloning taler-typescript-core into ${wallet_src_dir}"
    git clone --depth "$TALER_CLONE_DEPTH" https://git.taler.net/taler-typescript-core.git "$wallet_src_dir"
  else
    log "Updating taler-typescript-core in ${wallet_src_dir}"
    (
      cd "$wallet_src_dir"
      git fetch --depth "$TALER_CLONE_DEPTH" origin HEAD
      git reset --hard FETCH_HEAD
    )
  fi

  log "Building taler-wallet-cli from sources"
  (
    cd "$wallet_src_dir"
    ./bootstrap
    ./configure
    make -j"$TALER_BUILD_JOBS"
    sudo make install
  )
  hash -r

  if command -v taler-wallet-cli >/dev/null 2>&1; then
    log "Installed taler-wallet-cli at $(command -v taler-wallet-cli)"
    return 0
  fi

  log "Failed to install taler-wallet-cli"
  return 1
}

ensure_meson_toolchain() {
  local required_meson_version="1.0.0"

  if ! command -v meson >/dev/null 2>&1; then
    log "Meson not found; attempting installation"
  fi

  local meson_version=""
  if command -v meson >/dev/null 2>&1; then
    meson_version=$(meson --version || printf '')
  fi

  if [[ -z $meson_version ]] || ! dpkg --compare-versions "$meson_version" ge "$required_meson_version"; then
    log "Ensuring Meson >= $required_meson_version via pip"
    pip3 install --user --upgrade "meson>=$required_meson_version"
    hash -r
    meson_version=$(meson --version || printf '')
  fi

  if [[ -z $meson_version ]] || ! dpkg --compare-versions "$meson_version" ge "$required_meson_version"; then
    log "Meson version requirement not satisfied (have: ${meson_version:-unknown})"
    return 1
  fi

  log "Meson version $meson_version available"
}

podman_override_ready=0
ensure_podman_override() {
  if [[ $podman_override_ready -eq 1 ]]; then
    return
  fi

  local override_target="$PODMAN_OVERRIDE_CONF"
  local override_dir
  override_dir=$(dirname "$override_target")

  if [[ ! -d $override_dir ]]; then
    if ! mkdir -p "$override_dir"; then
      sudo mkdir -p "$override_dir"
    fi
  fi

  local tmpfile
  tmpfile=$(mktemp)
  cat <<'EOF' >"$tmpfile"
[engine]
cgroup_manager="cgroupfs"
events_logger="file"
runtime="runc"
EOF

  if ! cp "$tmpfile" "$override_target"; then
    sudo cp "$tmpfile" "$override_target"
  fi
  chmod 644 "$override_target" 2>/dev/null || sudo chmod 644 "$override_target"

  local system_override="$PODMAN_SYSTEM_OVERRIDE"
  if [[ -n $system_override ]]; then
    local system_dir
    system_dir=$(dirname "$system_override")
    if [[ ! -d $system_dir ]]; then
      sudo mkdir -p "$system_dir"
    fi
    sudo cp "$tmpfile" "$system_override"
    sudo chmod 644 "$system_override"
  fi
  rm -f "$tmpfile"

  podman_override_ready=1
}

podman_cmd() {
  ensure_podman_override
  sudo env "CONTAINERS_CONF_OVERRIDE=$PODMAN_OVERRIDE_CONF" podman "$@"
}

build_project() {
  local name=$1
  local repo=$2
  local ref=$3
  local dest=$4
  shift 4
  local configure_args=("$@")

  log "Preparing $name in $dest (ref $ref)"
  if [[ ! -d $dest ]]; then
    git clone --depth "$TALER_CLONE_DEPTH" "$repo" "$dest"
  fi
  (
    cd "$dest"
    git fetch --depth "$TALER_CLONE_DEPTH" origin "$ref"
    git checkout -B ci-build FETCH_HEAD
    ./bootstrap
    ./configure --prefix="$TALER_PREFIX" "${configure_args[@]}"
    make -j"$TALER_BUILD_JOBS"
    sudo make install
  )
  sudo ldconfig
}

install_gnunet() {
  log "Falling back to building GNUnet from source"
  log "THIS IS NOT SUPPOSED TO BE USED"
  ensure_packages \
    build-essential autoconf automake libtool pkg-config gettext texinfo \
    libunistring-dev libidn2-0-dev libmicrohttpd-dev libglpk-dev libjansson-dev \
    libcurl4-gnutls-dev libgcrypt20-dev libsqlite3-dev libev-dev \
    libevent-dev libprotobuf-c-dev protobuf-c-compiler libopus-dev libogg-dev \
    libltdl-dev nettle-dev meson ninja-build python3-pip python3-setuptools \
    python3-wheel

  ensure_meson_toolchain

  mkdir -p "$TALER_BUILD_ROOT"
  mkdir -p "$TALER_LOG_DIR"

  local gnunet_args=()
  if [[ -n ${GNUNET_MESON_FLAGS:-} ]]; then
    # shellcheck disable=SC2206
    gnunet_args=(${GNUNET_MESON_FLAGS})
  elif [[ -n ${GNUNET_CONFIGURE_FLAGS:-} ]]; then
    log "GNUNET_CONFIGURE_FLAGS detected; using as Meson options (deprecated)."
    # shellcheck disable=SC2206
    gnunet_args=(${GNUNET_CONFIGURE_FLAGS})
  fi

  log "Preparing GNUnet in $TALER_BUILD_ROOT/gnunet (ref $GNUNET_REF)"
  if [[ ! -d $TALER_BUILD_ROOT/gnunet ]]; then
    git clone --depth "$TALER_CLONE_DEPTH" "$GNUNET_REPO" "$TALER_BUILD_ROOT/gnunet"
  fi
  (
    cd "$TALER_BUILD_ROOT/gnunet"
    git fetch --depth "$TALER_CLONE_DEPTH" origin "$GNUNET_REF"
    git checkout -B ci-build FETCH_HEAD
    if [[ -x ./bootstrap ]]; then
      ./bootstrap
    fi
    local build_dir="build"
    rm -rf "$build_dir"
    meson setup --prefix="$TALER_PREFIX" "$build_dir" "${gnunet_args[@]}"
    meson compile -C "$build_dir" -j "$TALER_BUILD_JOBS"
    sudo env "PATH=$PATH" meson install -C "$build_dir"
  )
  sudo ldconfig
}

podman_container_running() {
  local name=$1

  podman_cmd ps --filter "name=${name}" --filter status=running --format '{{.Names}}' | grep -q "^${name}$"
}

disable_donau_services() {
  local container_name=$1
  case "${TALER_DISABLE_DONAU}" in
    0|false|no|NO|False|No)
      log "TALER_DISABLE_DONAU=${TALER_DISABLE_DONAU}; skipping Donau shutdown"
      return 0
      ;;
  esac

  log "Disabling Donau services in sandcastle container '${container_name}'"
  local units
  units=$(podman_cmd exec "${container_name}" bash -lc \
    "systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '\\$1 ~ /donau/ {print \\$1}'" \
    || printf '')

  if [[ -z ${units//[$' \n\t']} ]]; then
    log "No Donau-related systemd services detected"
    return 0
  fi

  local unit
  for unit in $units; do
    log "Stopping and masking ${unit}"
    podman_cmd exec "${container_name}" systemctl stop "${unit}" >/dev/null 2>&1 || true
    podman_cmd exec "${container_name}" systemctl disable "${unit}" >/dev/null 2>&1 || true
    podman_cmd exec "${container_name}" systemctl mask "${unit}" >/dev/null 2>&1 || true
  done

  log "Donau services disabled"
}

wait_for_sandcastle_ready() {
  local container_name=$1
  local attempts="${SANDCASTLE_WAIT_ATTEMPTS:-60}"
  local delay="${SANDCASTLE_WAIT_DELAY:-5}"

  log "Waiting for sandcastle container '${container_name}' to report ready state"
  while (( attempts > 0 )); do
    if podman_container_running "${container_name}"; then
      local systemd_status
      systemd_status=$(podman_cmd exec "${container_name}" systemctl is-system-running 2>/dev/null || printf 'unknown')
      case "${systemd_status}" in
        running|running*|degraded|degraded*)
          log "Sandcastle systemd status: ${systemd_status}"
          return 0
          ;;
        starting|starting*|initializing|initializing*|"maintenance mode")
          log "Sandcastle still starting (systemd status: ${systemd_status}); retrying..."
          ;;
        *)
          log "Sandcastle systemd status '${systemd_status}' not ready yet; retrying..."
          ;;
      esac
    else
      log "Sandcastle container '${container_name}' not running yet; waiting..."
    fi
    sleep "${delay}"
    ((attempts--))
  done

  log "Timed out waiting for sandcastle container '${container_name}' to become ready"
  podman_cmd ps -a || true
  podman_cmd logs "${container_name}" 2>/dev/null || true
  podman_cmd exec "${container_name}" journalctl -xe 2>/dev/null | tail -n 50 || true
  return 1
}

provision_sandcastle() {
  log "Provisioning GNU Taler services via sandcastle-ng container"
  ensure_packages git podman
  ensure_podman_override

  local override_env_explicit=0
  if [[ "${SANDCASTLE_OVERRIDE_NAME+x}" == "x" ]]; then
    override_env_explicit=1
  fi

  local container_name="${SANDCASTLE_CONTAINER_NAME:-taler-sandcastle}"
  local repo="${SANDCASTLE_REPO:-https://git.taler.net/sandcastle-ng.git}"
  local ref="${SANDCASTLE_REF:-dev/bohdan-potuzhnyi/talerbarr}"
  local checkout_dir="${SANDCASTLE_ROOT:-$TALER_BUILD_ROOT/sandcastle-ng}"
  local requested_override="${SANDCASTLE_OVERRIDE_NAME:-ci}"

  # shellcheck disable=SC2206
  local build_args=(${SANDCASTLE_BUILD_ARGS:-})
  # shellcheck disable=SC2206
  local run_args=(${SANDCASTLE_RUN_ARGS:-})

  mkdir -p "$TALER_BUILD_ROOT"
  if [[ ! -d $checkout_dir ]]; then
    log "Cloning sandcastle-ng repository (${repo} @ ${ref})"
    git clone --depth "${TALER_CLONE_DEPTH}" "${repo}" "${checkout_dir}"
  fi
  (
    cd "${checkout_dir}"
    log "Updating sandcastle-ng repository"
    git fetch --depth "${TALER_CLONE_DEPTH}" origin "${ref}"
    git checkout -B ci-build FETCH_HEAD

    local head_commit head_subject
    head_commit=$(git rev-parse --short HEAD 2>/dev/null || printf 'unknown')
    head_subject=$(git show -s --format=%s HEAD 2>/dev/null || printf 'unknown commit')
    log "sandcastle-ng HEAD ${head_commit} (${head_subject}) from ref '${ref}'"

    local overrides_dir="${checkout_dir}/overrides"
    local resolved_override="${requested_override}"
    if [[ -n $resolved_override && ! -f "${overrides_dir}/${resolved_override}" ]]; then
      log "Requested sandcastle override '${resolved_override}' not found in ${overrides_dir}"
      resolved_override=""
      if (( override_env_explicit == 1 )); then
        log "Environment provided sandcastle override but file is missing; continuing without override"
      fi
    fi

    if [[ -z $resolved_override && -d "$overrides_dir" ]]; then
      if (( override_env_explicit == 0 )); then
        local candidate
        for candidate in ci demo; do
          if [[ -f "${overrides_dir}/${candidate}" ]]; then
            resolved_override="$candidate"
            break
          fi
        done
        if [[ -z $resolved_override ]]; then
          candidate=$(find "${overrides_dir}" -maxdepth 1 -type f -printf '%f\n' 2>/dev/null | sort | head -n 1 || printf '')
          if [[ -n $candidate ]]; then
            resolved_override="$candidate"
          fi
        fi

        if [[ -n $resolved_override ]]; then
          log "Falling back to sandcastle override '${resolved_override}'"
        else
          log "No sandcastle overrides found; proceeding without override"
        fi
      else
        log "Sandcastle override fallback disabled by environment; proceeding without override"
      fi
    elif [[ -n $resolved_override ]]; then
      log "Using sandcastle override '${resolved_override}'"
    fi

    if ! podman_container_running "${container_name}"; then
      log "Building sandcastle container image"
      local -a env_passthrough_build=("PATH=$PATH" "CONTAINERS_CONF_OVERRIDE=$PODMAN_OVERRIDE_CONF")
      sudo env "${env_passthrough_build[@]}" ./sandcastle-build "${build_args[@]}"

      log "Launching sandcastle container '${container_name}'"
      local -a env_passthrough=(
        "PATH=$PATH"
        "CONTAINERS_CONF_OVERRIDE=$PODMAN_OVERRIDE_CONF"
      )
      if [[ -n $resolved_override ]]; then
        env_passthrough+=("SANDCASTLE_OVERRIDE_NAME=${resolved_override}")
      fi
      if [[ -n ${SANDCASTLE_SETUP_NAME:-} ]]; then
        env_passthrough+=("SANDCASTLE_SETUP_NAME=${SANDCASTLE_SETUP_NAME}")
      fi
      if [[ -n ${EXTERNAL_PORT:-} ]]; then
        env_passthrough+=("EXTERNAL_PORT=${EXTERNAL_PORT}")
      fi
      if [[ -n ${EXTERNAL_IP:-} ]]; then
        env_passthrough+=("EXTERNAL_IP=${EXTERNAL_IP}")
      fi
      if [[ -n ${USE_INSECURE_SANDBOX_PASSWORDS:-} ]]; then
        env_passthrough+=("USE_INSECURE_SANDBOX_PASSWORDS=${USE_INSECURE_SANDBOX_PASSWORDS}")
      fi
      sudo env "${env_passthrough[@]}" ./sandcastle-run "${run_args[@]}"
    else
      log "Reusing already running sandcastle container '${container_name}'"
    fi
  )

  wait_for_sandcastle_ready "${container_name}"
  disable_donau_services "${container_name}"
  log "Sandcastle provisioning finished"
}

provision_build() {
  log "Starting GNU Taler stack bootstrap"

  ensure_packages \
    git build-essential autoconf automake libtool pkg-config gettext autopoint \
    libmicrohttpd-dev libjansson-dev libgnutls28-dev libsodium-dev \
    libcurl4-gnutls-dev libpq-dev libsqlite3-dev libqrencode-dev libgcrypt20-dev \
    libunistring-dev libidn2-0-dev libmagic-dev zlib1g-dev ca-certificates \
    libev-dev libevent-dev libprotobuf-c-dev protobuf-c-compiler python3-jinja2 \
    gcc-12 g++-12

  echo "Using gcc-12 and g++-12 for building Taler components"
  export CC=gcc-12 CXX=g++-12

  ensure_python_module jinja2 python3-jinja2 "Jinja2>=3.0"

  install_gnunet

  mkdir -p "$TALER_BUILD_ROOT"

  local exchange_args=()
  local merchant_args=()
  if [[ -n ${TALER_EXCHANGE_CONFIGURE_FLAGS:-} ]]; then
    # shellcheck disable=SC2206
    exchange_args=(${TALER_EXCHANGE_CONFIGURE_FLAGS})
  fi
  if [[ -n ${TALER_MERCHANT_CONFIGURE_FLAGS:-} ]]; then
    # shellcheck disable=SC2206
    merchant_args=(${TALER_MERCHANT_CONFIGURE_FLAGS})
  fi

  build_project "Taler Exchange" "$TALER_EXCHANGE_REPO" "$TALER_EXCHANGE_REF" \
    "$TALER_BUILD_ROOT/exchange" "${exchange_args[@]}"

  build_project "Taler Merchant" "$TALER_MERCHANT_REPO" "$TALER_MERCHANT_REF" \
    "$TALER_BUILD_ROOT/merchant" "${merchant_args[@]}"

  sudo env "PATH=$PATH" taler-merchant-dbconfig

  sudo env "PATH=$PATH" taler-merchant-rproxy-setup

  log "GNU Taler stack installation complete"
}

main() {
  ensure_wallet_cli

  local mode="${TALER_STACK_MODE:-sandcastle}"
  case "${mode}" in
    sandcastle)
      provision_sandcastle
      ;;
    build)
      provision_build
      ;;
    *)
      log "Unsupported TALER_STACK_MODE '${mode}' (expected 'build' or 'sandcastle')"
      return 1
      ;;
  esac
}

main "$@"
