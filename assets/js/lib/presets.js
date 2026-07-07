(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterPresets = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  const PRESET_VALUES = {
    balanced: {
      wrapInContainer: true,
      includeCssElement: false,
      inlineStyles: true,
      safeMode: true,
      allowExecutableCode: false,
      strictNative: false,
    },
    safe: {
      wrapInContainer: true,
      includeCssElement: false,
      inlineStyles: false,
      safeMode: true,
      allowExecutableCode: false,
      strictNative: false,
    },
    strict: {
      wrapInContainer: true,
      includeCssElement: false,
      inlineStyles: true,
      safeMode: true,
      allowExecutableCode: false,
      strictNative: true,
    },
    fidelity: {
      wrapInContainer: false,
      includeCssElement: true,
      inlineStyles: true,
      safeMode: false,
      allowExecutableCode: false,
      strictNative: false,
    },
  };

  function coerceBoolean(value, defaultValue) {
    if (typeof value === "boolean") {
      return value;
    }

    if (value === null || typeof value === "undefined") {
      return defaultValue;
    }

    if (typeof value === "number") {
      return value !== 0;
    }

    const normalized = String(value).trim().toLowerCase();
    if (["1", "true", "yes", "on"].includes(normalized)) {
      return true;
    }
    if (["0", "false", "no", "off", ""].includes(normalized)) {
      return false;
    }

    return defaultValue;
  }

  function getPresetValues(presetName) {
    const key = String(presetName || "").toLowerCase();
    const preset = PRESET_VALUES[key] || PRESET_VALUES.balanced;
    return {
      wrapInContainer: preset.wrapInContainer,
      includeCssElement: preset.includeCssElement,
      inlineStyles: preset.inlineStyles,
      safeMode: preset.safeMode,
      allowExecutableCode: preset.allowExecutableCode,
      strictNative: preset.strictNative,
    };
  }

  function resolvePresetFromOptions(options) {
    const normalized = {
      wrapInContainer: coerceBoolean(options && options.wrapInContainer, true),
      includeCssElement: coerceBoolean(options && options.includeCssElement, false),
      inlineStyles: coerceBoolean(options && options.inlineStyles, true),
      safeMode: coerceBoolean(options && options.safeMode, true),
      allowExecutableCode: coerceBoolean(options && options.allowExecutableCode, false),
      strictNative: coerceBoolean(options && options.strictNative, false),
    };

    const names = Object.keys(PRESET_VALUES);
    for (const name of names) {
      const preset = PRESET_VALUES[name];
      if (
        preset.wrapInContainer === normalized.wrapInContainer &&
        preset.includeCssElement === normalized.includeCssElement &&
        preset.inlineStyles === normalized.inlineStyles &&
        preset.safeMode === normalized.safeMode &&
        preset.allowExecutableCode === normalized.allowExecutableCode &&
        preset.strictNative === normalized.strictNative
      ) {
        return name;
      }
    }

    return "custom";
  }

  return {
    PRESET_VALUES,
    coerceBoolean,
    getPresetValues,
    resolvePresetFromOptions,
  };
});
