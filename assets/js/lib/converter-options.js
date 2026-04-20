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
    const wrapInContainer = coerceBoolean(
      options && options.wrapInContainer,
      true
    );
    const includeCssElement = coerceBoolean(
      options && options.includeCssElement,
      true
    );
    const inlineStyles = coerceBoolean(options && options.inlineStyles, true);
    const safeMode = coerceBoolean(options && options.safeMode, false);
    const debugMode = coerceBoolean(options && options.debugMode, false);

    return {
      wrapInContainer: wrapInContainer ? "true" : "false",
      includeCssElement: includeCssElement ? "true" : "false",
      inlineStyles: inlineStyles ? "true" : "false",
      safeMode: safeMode ? "true" : "false",
      debugMode: debugMode ? "true" : "false",
    };
  }

  return {
    coerceBoolean,
    buildConvertRequestFields,
  };
});
