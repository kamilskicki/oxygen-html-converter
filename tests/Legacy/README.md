# Legacy Tests

This folder stores historical/regression tests that currently do not pass
against the active Core implementation.

Use these tests when intentionally working on backward compatibility or
restoring deprecated service paths.

Run them explicitly:

```bash
composer test:legacy
```

Default quality gate remains `composer test`.
