# Security Policy

## Supported Versions

Security fixes are applied to the active development line and the latest public beta line.

| Version | Supported |
|---|---|
| `0.9.x-beta` | Yes |
| `0.8.x-beta` | Best effort |
| Older versions | No |

## Reporting a Vulnerability

Do not open a public GitHub issue for security vulnerabilities.

Preferred path:

1. Use GitHub Private Vulnerability Reporting for this repository if it is enabled.
2. If private reporting is unavailable, contact the repository owner through the contact details on the GitHub profile and include `Security` in the subject.

Please include:

- affected plugin version or commit hash
- exact reproduction steps
- proof of concept or reduced sample
- impact assessment
- whether exploitation requires authentication

## Response Goals

Target response times:

- initial acknowledgment: within `7` days
- triage decision: within `14` days
- fix or mitigation timeline: depends on severity and reproducibility

## Scope

This policy covers the public `Core` plugin in this repository.

Out of scope:

- issues caused only by modified local environments
- third-party plugins or themes unless Core is the direct cause
- unsupported custom forks
