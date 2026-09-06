# 0.9.1-beta release notes

This staging candidate repairs conversion, component, storage and Builder UI
failures found through actual imports on WordPress 7.1 and Oxygen 6.1.3. It adds
experimental compatibility with Oxygen 6.2.0-beta.8. PHP remains 8.2+; staging
validation uses PHP 8.4.25.

See CHANGELOG.md for the changes and RELEASE_CHECKLIST.md for reproducible gates.
The workspace evidence and final deployment matrix live in
`../../knowledge/KBAI/2026-09-06-upgrade-quality-gate.md` relative to Core.

Back up existing installations before upgrading. Existing malformed global
components are not automatically rewritten: use the saved importer rollback
and reimport from source. Safe Mode retains its existing unsupported-content
policy; external fonts/scripts may be removed and arbitrary source applications
are not promised pixel-perfect conversion.

The native Oxygen paste API can emit an inferred history snapshot-title warning.
The snapshot and saved content persist; no private history-manager integration
is used to suppress this vendor warning.

No public GitHub release or WordPress.org upload is part of this staging run.
