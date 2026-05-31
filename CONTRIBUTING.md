# Contributing to ISTA AI

Thanks for helping improve ISTA AI. The project is maintained with small, reviewable changes, explicit planning for complex work, and verification before a change is considered done.

## Working Principles

- Keep changes focused and easy to review.
- Prefer tests for behavior changes.
- Do not commit secrets, production data, private documents, database dumps, or local vector data.
- Update docs when setup, deployment, security, or maintainer workflows change.
- Follow the existing Laravel and Python structure instead of introducing broad refactors.

## Before You Start

For complex changes, create or update a markdown plan in `issue/` with:

- Background and goal.
- Scope and out-of-scope items.
- Files likely to change.
- Risks.
- Implementation steps.
- Verification plan.

Small documentation fixes do not need a full issue plan.

## Development Areas

- Laravel app: `laravel/`
- Python AI services: `python-ai/`
- Deployment and operations: `docs/`, `deploy/`, Docker Compose files
- Manual benchmarks: `benchmarks/`

## Verification

Run checks close to the area you changed.

Laravel:

```bash
cd laravel && php artisan test
```

Python:

```bash
cd python-ai && source venv/bin/activate && pytest
```

General checks:

```bash
git diff --check
```

If a full test run is too expensive for a small change, state exactly which checks were run and why they are enough.

## Pull Request Checklist

- Scope is clear and limited.
- No real env files or credentials are included.
- Relevant tests or checks ran successfully.
- Docs are updated when behavior or setup changes.
- Security-sensitive changes explain risk and verification.

## Security

See [SECURITY.md](SECURITY.md) before reporting vulnerabilities or handling secrets.
