# Definition of Done — 0.9.1-beta

Required before accepting the staging candidate:

- Composer install/validation, npm check, advisory audits and the canonical fixture audit pass.
- Installed-source contracts pass for Oxygen 6.1.3 and experimental 6.2.0-beta.8.
- All indexed HTML fixtures pass the production import/readback/render/rollback gate on both runtimes.
- Site-kit import covers pages, templates, headers, footers and components, including exact rollback restoration.
- Chrome confirms native component instance editing, Save/reload, modal paste/import, and representative desktop/mobile computed styles.
- The installed file manifest matches the allowlisted release ZIP; staging ends on Oxygen 6.1.3 with recovery backups retained.

Final counts, artifact SHA-256 and any limitations are recorded in workspace
`knowledge/KBAI/2026-09-06-upgrade-quality-gate.md`. This checklist does not replace
that evidence or certify a public release. Legacy tests and uninstalled optional
Oxygen add-ons are outside this matrix.
