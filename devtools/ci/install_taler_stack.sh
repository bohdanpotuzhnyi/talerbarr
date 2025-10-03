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
readonly TALER_BUILD_JOBS="${TALER_BUILD_JOBS:-$(command -v nproc >/dev/null 2>&1 && nproc || printf '2')}"
readonly TALER_MERCHANT_REPO="${TALER_MERCHANT_REPO:-https://git.taler.net/taler-merchant.git}"
readonly TALER_EXCHANGE_REPO="${TALER_EXCHANGE_REPO:-https://git.taler.net/exchange.git}"
readonly TALER_MERCHANT_REF="${TALER_MERCHANT_REF:-master}"
readonly TALER_EXCHANGE_REF="${TALER_EXCHANGE_REF:-master}"
readonly GNUNET_REPO="${GNUNET_REPO:-https://git.gnunet.org/gnunet.git}"
readonly GNUNET_REF="${GNUNET_REF:-master}"
readonly TALER_CLONE_DEPTH="${TALER_CLONE_DEPTH:-1}"

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
  log "Attempting to install GNUnet from packages"
  if apt_install gnunet libgnunet-dev; then
    return 0
  fi

  log "Falling back to building GNUnet from source"
  ensure_packages \
    build-essential autoconf automake libtool pkg-config gettext texinfo \
    libunistring-dev libidn2-0-dev libmicrohttpd-dev libglpk-dev libjansson-dev \
    libcurl4-gnutls-dev libgcrypt20-dev libsqlite3-dev libev-dev \
    libevent-dev libprotobuf-c-dev protobuf-c-compiler libopus-dev libogg-dev \
    libltdl-dev nettle-dev meson ninja-build python3-pip python3-setuptools \
    python3-wheel

  ensure_meson_toolchain

  mkdir -p "$TALER_BUILD_ROOT"

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

main() {
  log "Starting GNU Taler stack bootstrap"

  ensure_packages \
    git build-essential autoconf automake libtool pkg-config gettext autopoint \
    libmicrohttpd-dev libjansson-dev libgnutls28-dev libsodium-dev \
    libcurl4-gnutls-dev libpq-dev libsqlite3-dev libqrencode-dev libgcrypt20-dev \
    libunistring-dev libidn2-0-dev libmagic-dev zlib1g-dev ca-certificates \
    libev-dev libevent-dev libprotobuf-c-dev protobuf-c-compiler

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
    "$TALER_BUILD_ROOT/taler-merchant" "${merchant_args[@]}"

  if command -v taler-merchant-dbconfig >/dev/null 2>&1; then
    log "Ensuring merchant database helpers are available"
    sudo taler-merchant-dbconfig --help >/dev/null 2>&1 || true
  fi

  if command -v taler-merchant-rproxy >/dev/null 2>&1; then
    # OF COURSE IT WILL FAIL ON THIS STEP
    log "taler-merchant-rproxy installed"
  fi

  log "GNU Taler stack installation complete"
}

main "$@"
