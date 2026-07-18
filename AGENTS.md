# Oxygen HTML Converter Core Agent Notes

The workspace-level instructions from `D:\WordPress\Html to Oxygen\AGENTS.md`
remain authoritative for this public Core repository.

## Canonical boundaries

- Core product code, tests, release scripts, and reproducible fixtures live in this repository.
- Canonical fixture root: `fixtures/html`.
- Canonical stable fixture contract: `fixtures/html/fixture-index.json`.
- Generated reports, screenshots, ZIP files, caches, credentials, and database dumps are not source fixtures.
- Premium-only code remains outside this repository.

## Stable gate

```powershell
composer install
npm run check
```

Legacy tests under `tests/Legacy` are intentionally outside the default gate.

## HomeLab WordPress staging

- URL: `https://oxyconvo6.mylab`
- SSH alias: `oxyconvo6.mylab`
- WordPress root: `/var/www/wordpress`
- Never commit SSH identities, WordPress credentials, cookies, database dumps, or staging backups.
- Do not reset or destroy the staging VM without explicit approval.
