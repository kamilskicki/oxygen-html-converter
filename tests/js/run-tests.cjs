const tests = [
  ["presets", require("./presets.test.cjs")],
  ["converter-options", require("./converter-options.test.cjs")],
];

let passed = 0;

for (const [name, run] of tests) {
  try {
    run();
    passed += 1;
    process.stdout.write(`ok - ${name}\n`);
  } catch (error) {
    process.stderr.write(`not ok - ${name}\n`);
    process.stderr.write(String(error && error.stack ? error.stack : error) + "\n");
    process.exit(1);
  }
}

process.stdout.write(`\n${passed}/${tests.length} JS suites passed\n`);
