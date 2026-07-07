(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterAdminClient = factory();
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

  function buildRequestFields(options, optionUtils) {
    if (optionUtils && typeof optionUtils.buildConvertRequestFields === "function") {
      return optionUtils.buildConvertRequestFields(options || {});
    }

    const normalized = options || {};
    return {
      wrapInContainer: coerceBoolean(normalized.wrapInContainer, true),
      includeCssElement: coerceBoolean(normalized.includeCssElement, false),
      inlineStyles: coerceBoolean(normalized.inlineStyles, true),
      safeMode: coerceBoolean(normalized.safeMode, true),
      allowExecutableCode:
        !coerceBoolean(normalized.safeMode, true) &&
        !coerceBoolean(normalized.strictNative, false) &&
        coerceBoolean(normalized.allowExecutableCode, false),
      strictNative: coerceBoolean(normalized.strictNative, false),
      debugMode: coerceBoolean(normalized.debugMode, false),
    };
  }

  function buildRequestPayload(action, nonce, html, options, optionUtils) {
    return Object.assign(
      {
        action: action,
        nonce: nonce,
        html: html,
      },
      buildRequestFields(options, optionUtils)
    );
  }

  function createRequestClient(config) {
    const $ = config.$;

    return {
      request: function request(action, html, options) {
        return new Promise(function (resolve, reject) {
          $.ajax({
            url: config.ajaxUrl,
            type: "POST",
            data: buildRequestPayload(action, config.nonce, html, options, config.optionUtils),
            success: function success(response) {
              resolve(response);
            },
            error: function error(xhr) {
              reject(xhr);
            },
          });
        });
      },
    };
  }

  return {
    buildRequestFields: buildRequestFields,
    buildRequestPayload: buildRequestPayload,
    createRequestClient: createRequestClient,
  };
});
