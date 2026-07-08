# Dependency Audit

## 2026-07-07

Scope: SEC-06 publication-readiness dependency audit for the Core plugin.

| Ecosystem | Command | Result | Verdict |
| --- | --- | --- | --- |
| Composer | `composer audit` | Blocked by the Windows sandbox ACL helper before normal command output was produced. | Inconclusive; SEC-06 is not satisfied until this command exits cleanly in an environment that can run Composer. |
| npm | `npm audit --omit=dev` | Blocked by the Windows sandbox ACL helper before normal command output was produced. | Inconclusive; SEC-06 is not satisfied until this command exits cleanly in an environment that can run npm. |

Release verdict: dependency audit evidence is recorded, but unresolved because both audit commands were blocked by the local sandbox before they could query advisory data.