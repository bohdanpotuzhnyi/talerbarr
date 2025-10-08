# Development Tools for TalerBarr

This folder contains configuration and helper tools for maintaining code quality in the TalerBarr module.

## Pre-commit Hooks

We use [pre-commit](https://pre-commit.com) to enforce consistent style and quality before changes are committed.

### Setup

Run the following commands once after cloning from the `talerbarr` folder:

```bash
# Install PHP dev dependencies (phpcs, phpcbf, etc.)
composer install --dev

# Install Node dependencies (cspell, etc.)
npm install

# Install and activate pre-commit hooks
pre-commit install --install-hooks --hook-type pre-commit --hook-type commit-msg --hook-type pre-push
```

## GNU Taler Sandcastle Provisioning

The CI helper script at `devtools/ci/install_taler_stack.sh` supports two bootstrap modes:

- `TALER_STACK_MODE=sandcastle` (default) provisions services via the [`sandcastle-ng`](https://git.taler.net/sandcastle-ng.git) container, building the image with Podman and waiting for systemd inside the container to become ready.
- `TALER_STACK_MODE=build` compiles the GNU Taler exchange/merchant stack from source.

When using sandcastle in CI environments without systemd for cgroup management, set `TALER_PODMAN_OVERRIDE_CONF` to a writable path (Travis uses `/tmp/taler-podman-containers.conf`). The helper script writes a minimal Podman override there—as well as a root drop-in at `/etc/containers/containers.conf.d/99-taler-sandcastle.conf` (override path tunable via `TALER_PODMAN_SYSTEM_OVERRIDE`)—to force `cgroupfs`, switch to the `runc` runtime, and use a file-based events logger so builds succeed under Podman.

Additional environment variables customise the container workflow:

- `SANDCASTLE_OVERRIDE_NAME` selects a configuration override (defaults to `ci`).
- `SANDCASTLE_BUILD_ARGS` / `SANDCASTLE_RUN_ARGS` supply extra arguments to `sandcastle-build` and `sandcastle-run`.
- `SANDCASTLE_REPO`, `SANDCASTLE_REF`, and `SANDCASTLE_ROOT` control where the repository is cloned.
- `TALER_PODMAN_SYSTEM_OVERRIDE` customises the root-level containers.conf drop-in created alongside the user override.

The Travis configuration enables sandcastle mode by default so module integration tests run against a self-contained GNU Taler deployment.
