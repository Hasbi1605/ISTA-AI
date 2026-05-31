# Security Policy

## Supported Versions

Security fixes are currently handled on the `main` branch. If releases are added later, this policy should be updated with supported version ranges.

## Reporting a Vulnerability

Please do not open a public issue for vulnerabilities that could expose secrets, private documents, user data, tokens, or infrastructure details.

For now, report security concerns by contacting the repository maintainer through the GitHub profile linked from this repository. Include:

- A short summary of the issue.
- Affected component or path.
- Safe reproduction steps using local/test data only.
- Impact and suggested mitigation if known.

Do not include real API keys, service account JSON, production documents, database exports, session cookies, or private user data in the report.

## Secret Handling

ISTA AI uses environment variables for provider keys, internal service tokens, database passwords, OAuth secrets, and document editor secrets.

Never commit:

- Real `.env` files.
- Database dumps or local SQLite files.
- Chroma vector data.
- Google service account JSON files.
- OAuth client secret files.
- Private keys, certificates, or deployment credentials.

If a secret is committed or shared outside a trusted local machine, revoke and rotate it immediately. Removing the file from the latest commit is not enough if it already reached git history or a remote.

## Local Security Checks

Recommended checks before publishing a branch:

```bash
git status --short --ignored .env.droplet laravel/.env laravel/.env.backup laravel/.env.production python-ai/.env
git ls-files | rg -i '(^|/)(\.env|.*secret.*|.*credential.*|.*token.*|.*key.*|.*pem|.*p12|.*pfx|.*sql|.*sqlite|.*db)$'
git diff --check
```

Use a dedicated secret scanner such as Gitleaks or TruffleHog when available, especially before making a repository public or submitting it for external review.
