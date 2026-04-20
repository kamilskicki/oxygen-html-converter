(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterAdminClient = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  function buildRequestFields(options, optionUtils) {
    if (optionUtils && typeof optionUtils.buildConvertRequestFields === "function") {
      return optionUtils.buildConvertRequestFields(options || {});
    }

    const normalized = options || {};
    return {
      wrapInContainer: !!normalized.wrapInContainer,
      includeCssElement: !!normalized.includeCssElement,
      inlineStyles: !!normalized.inlineStyles,
      safeMode: !!normalized.safeMode,
      debugMode: !!normalized.debugMode,
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
