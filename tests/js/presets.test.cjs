const assert = require("node:assert/strict");

const presets = require("../../assets/js/lib/presets.js");

module.exports = function runPresetsTests() {
  assert.deepEqual(presets.getPresetValues("balanced"), {
    wrapInContainer: true,
    includeCssElement: false,
    inlineStyles: true,
    safeMode: true,
    allowExecutableCode: false,
    strictNative: false,
  });

  assert.deepEqual(presets.getPresetValues("unknown-value"), {
    wrapInContainer: true,
    includeCssElement: false,
    inlineStyles: true,
    safeMode: true,
    allowExecutableCode: false,
    strictNative: false,
  });

  assert.deepEqual(presets.getPresetValues("strict"), {
    wrapInContainer: true,
    includeCssElement: false,
    inlineStyles: true,
    safeMode: true,
    allowExecutableCode: false,
    strictNative: true,
  });

  assert.equal(
    presets.resolvePresetFromOptions({
      wrapInContainer: true,
      includeCssElement: false,
      inlineStyles: false,
      safeMode: true,
      allowExecutableCode: false,
      strictNative: false,
    }),
    "safe"
  );

  assert.equal(
    presets.resolvePresetFromOptions({
      wrapInContainer: true,
      includeCssElement: false,
      inlineStyles: true,
      safeMode: true,
      allowExecutableCode: false,
      strictNative: true,
    }),
    "strict"
  );

  assert.equal(
    presets.resolvePresetFromOptions({
      wrapInContainer: false,
      includeCssElement: true,
      inlineStyles: true,
      safeMode: false,
      allowExecutableCode: false,
      strictNative: false,
    }),
    "fidelity"
  );

  assert.equal(
    presets.resolvePresetFromOptions({
      wrapInContainer: false,
      includeCssElement: false,
      inlineStyles: true,
      safeMode: false,
      allowExecutableCode: false,
      strictNative: false,
    }),
    "custom"
  );

  assert.equal(presets.coerceBoolean("true", false), true);
  assert.equal(presets.coerceBoolean("0", true), false);
  assert.equal(presets.coerceBoolean(1, false), true);
  assert.equal(presets.coerceBoolean(undefined, true), true);
  assert.equal(presets.coerceBoolean("invalid", true), true);
};
