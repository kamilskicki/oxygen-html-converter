# Performance and Process Safety

The maintained Core performance gate is available through either package manager:

```text
composer benchmark:conversion
npm run benchmark:conversion
```

The runner creates a deterministic workload of approximately 500 sections, 5,000 HTML elements, and exactly 1 MiB of CSS. It loads the same lightweight test bootstrap used by the unit suite, calls `TreeBuilder::convert()` without WordPress, measures wall time and PHP allocator peak memory for each stage, and writes the current evidence to `artifacts/perf/benchmark-2026-07-10.md`.

The publication thresholds are:

- conversion of the 1 MiB CSS workload in less than 30 seconds;
- process peak memory below 512 MiB;
- a successful conversion reporting at least 5,000 converted elements.

The command exits nonzero when any threshold fails. Use `--output=relative/or/absolute/path.md` to write a separate comparison run.

## Release subprocess controls

`scripts/release_common.php` runs release commands with nonblocking stdout and stderr capture. Each stream is capped independently, and timeout cleanup targets the entire process tree (`taskkill /PID <pid> /T /F` on Windows; deepest descendants first on Unix) before falling back to terminating the parent handle.

Defaults are a 900-second timeout and a 4 MiB capture cap per stream. Configure them with either:

```text
php scripts/release_verify.php --timeout=1200 --output-cap=8388608
```

or the `OXY_HTML_CONVERTER_COMMAND_TIMEOUT` and `OXY_HTML_CONVERTER_OUTPUT_CAP_BYTES` environment variables. A timed-out command returns exit code 124 and reports its configured deadline, captured diagnostic output, and whether that output was truncated.
