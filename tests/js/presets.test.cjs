const assert = require("node:assert/strict");

const presets = require("../../assets/js/lib/presets.js");

module.exports = function runPresetsTests() {
  assert.deepEqual(presets.getPresetValues("balanced"), {
    wrapInContainer: true,
    includeCssElement: true,
    inlineStyles: true,
    safeMode: false,
  });

  assert.deepEqual(presets.getPresetValues("unknown-value"), {
    wrapInContainer: true,
    includeCssElement: true,
    inlineStyles: true,
    safeMode: false,
  });

  assert.equal(
    presets.resolvePresetFromOptions({
      wrapInContainer: true,
      includeCssElement: true,
      inlineStyles: false,
      safeMode: true,
    }),
    "safe"
  );

  assert.equal(
    presets.resolvePresetFromOptions({
      wrapInContainer: false,
      includeCssElement: true,
      inlineStyles: true,
      safeMode: false,
    }),
    "fidelity"
  );

  assert.equal(
    presets.resolvePresetFromOptions({
      wrapInContainer: false,
      includeCssElement: false,
      inlineStyles: true,
      safeMode: false,
    }),
    "custom"
  );

  assert.equal(presets.coerceBoolean("true", false), true);
  assert.equal(presets.coerceBoolean("0", true), false);
  assert.equal(presets.coerceBoolean(1, false), true);
  assert.equal(presets.coerceBoolean(undefined, true), true);
  assert.equal(presets.coerceBoolean("invalid", true), true);
};
