const tests = [
  ["presets", require("./presets.test.cjs")],
  ["converter-options", require("./converter-options.test.cjs")],
  ["clipboard-utils", require("./clipboard-utils.test.cjs")],
  ["admin-request-client", require("./admin-request-client.test.cjs")],
  ["admin-renderers", require("./admin-renderers.test.cjs")],
  ["builder-paste", require("./builder-paste.test.cjs")],
];

let passed = 0;

async function main() {
  for (const [name, run] of tests) {
    try {
      await run();
      passed += 1;
      process.stdout.write(`ok - ${name}\n`);
    } catch (error) {
      process.stderr.write(`not ok - ${name}\n`);
      process.stderr.write(String(error && error.stack ? error.stack : error) + "\n");
      process.exit(1);
    }
  }

  process.stdout.write(`\n${passed}/${tests.length} JS suites passed\n`);
}

main().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + "\n");
  process.exit(1);
});
