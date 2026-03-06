const assert = require("node:assert/strict");

const converterOptions = require("../../assets/js/lib/converter-options.js");

module.exports = function runConverterOptionsTests() {
  assert.deepEqual(converterOptions.buildConvertRequestFields({}), {
    wrapInContainer: "true",
    safeMode: "false",
  });

  assert.deepEqual(
    converterOptions.buildConvertRequestFields({
      wrapInContainer: 0,
      safeMode: "yes",
    }),
    {
      wrapInContainer: "false",
      safeMode: "true",
    }
  );

  assert.equal(converterOptions.coerceBoolean("off", true), false);
  assert.equal(converterOptions.coerceBoolean("on", false), true);
  assert.equal(converterOptions.coerceBoolean(null, true), true);
  assert.equal(converterOptions.coerceBoolean("unknown", false), false);
};
