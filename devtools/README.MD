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
