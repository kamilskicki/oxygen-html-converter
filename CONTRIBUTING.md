# Contributing

Thanks for contributing to `oxygen-html-converter`.

This project is still in beta. The highest-value contributions are:

- reproducible bug reports with source HTML
- fixture cases that expose visual or builder-editability regressions
- focused fixes with regression tests
- documentation improvements that clarify supported scope and limitations

## Before Opening an Issue

Please include:

- WordPress version
- Oxygen version
- PHP version
- plugin version or commit hash
- the exact HTML or a reduced reproduction fixture
- expected result
- actual result
- whether the problem is in conversion output, frontend parity, or Builder editability

## Development Setup

1. Clone the repository.
2. Install PHP dependencies with `composer install`.
3. Install JS dependencies with `npm install`.
4. Run the test suite before submitting changes.

## Test Commands

- PHP unit tests: `composer test`
- JS tests: `npm run test:js`
- Combined gate: `npm test`

If your change affects builder serialization, import flows, or frontend parity, include the exact manual verification you performed.

## Pull Request Expectations

- keep changes scoped
- explain the user-facing problem being fixed
- add or update regression tests where practical
- do not include local artifacts, ZIPs, screenshots, or temporary debug files
- do not mix unrelated refactors into a bug-fix PR

## Scope Boundary

This repository is the public `Core` plugin.

Core changes should improve:

- HTML to Oxygen conversion
- builder-safe document persistence
- supported parity behavior
- public extension hooks and API stability

Premium workflow automation and commercial add-on behavior belong in `Pro`, not in this repo.
