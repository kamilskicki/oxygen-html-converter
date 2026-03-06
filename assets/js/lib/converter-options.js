(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterOptions = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

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

  function buildConvertRequestFields(options) {
    const safeMode = coerceBoolean(options && options.safeMode, false);
    const wrapInContainer = coerceBoolean(
      options && options.wrapInContainer,
      true
    );

    return {
      safeMode: safeMode ? "true" : "false",
      wrapInContainer: wrapInContainer ? "true" : "false",
    };
  }

  return {
    coerceBoolean,
    buildConvertRequestFields,
  };
});
