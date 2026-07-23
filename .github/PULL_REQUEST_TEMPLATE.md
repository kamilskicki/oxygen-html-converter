## Summary

Describe what changed and why.

## User and developer impact

Explain visible behavior changes, compatibility implications, and any migration
or operational requirements.

## Validation

- [ ] `npm run check`
- [ ] `npm run test:fixtures:local`
- [ ] Relevant live or browser smoke test, or a written reason it is not needed
- [ ] Release ZIP verification when packaging behavior changes

List the exact commands run and their results:

```text
command — result
```

## Scope and safety

- [ ] The change belongs in public Core.
- [ ] No credentials, cookies, database dumps, generated reports, or local-only artifacts are included.
- [ ] Security-sensitive changes preserve nonce, capability, validation, escaping, and Safe Mode boundaries.
- [ ] Documentation and tests reflect the changed behavior.

## Screenshots or artifacts

Attach focused evidence for UI, Builder, visual parity, or packaging changes.
