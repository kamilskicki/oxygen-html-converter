const assert = require("node:assert/strict");

const converterOptions = require("../../assets/js/lib/converter-options.js");

module.exports = function runConverterOptionsTests() {
  assert.deepEqual(converterOptions.buildConvertRequestFields({}), {
    wrapInContainer: "true",
    includeCssElement: "false",
    inlineStyles: "true",
    safeMode: "true",
    allowExecutableCode: "false",
    strictNative: "false",
    debugMode: "false",
  });

  assert.deepEqual(
    converterOptions.buildConvertRequestFields({
      wrapInContainer: 0,
      includeCssElement: "no",
      inlineStyles: "yes",
      safeMode: "yes",
      allowExecutableCode: "yes",
      strictNative: "on",
      debugMode: 1,
    }),
    {
      wrapInContainer: "false",
      includeCssElement: "false",
      inlineStyles: "true",
      safeMode: "true",
      allowExecutableCode: "false",
      strictNative: "true",
      debugMode: "true",
    }
  );

  assert.equal(
    converterOptions.buildConvertRequestFields({
      safeMode: false,
      strictNative: false,
      allowExecutableCode: true,
    }).allowExecutableCode,
    "true"
  );

  assert.equal(
    converterOptions.buildConvertRequestFields({
      safeMode: false,
      strictNative: true,
      allowExecutableCode: true,
    }).allowExecutableCode,
    "false"
  );

  assert.equal(converterOptions.coerceBoolean("off", true), false);
  assert.equal(converterOptions.coerceBoolean("on", false), true);
  assert.equal(converterOptions.coerceBoolean(null, true), true);
  assert.equal(converterOptions.coerceBoolean("unknown", false), false);
};
