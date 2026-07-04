(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterBuilderClient = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  function buildRequestFields(options, converterOptions) {
    const defaults = {
      wrapInContainer: true,
      includeCssElement: true,
      inlineStyles: true,
      safeMode: true,
      strictNative: false,
      debugMode: false,
    };
    const merged = Object.assign({}, defaults, options || {});

    return converterOptions &&
      typeof converterOptions.buildConvertRequestFields === "function"
      ? converterOptions.buildConvertRequestFields(merged)
      : merged;
  }

  async function convertHtml(config, html, options, converterOptions) {
    const formData = new FormData();
    formData.append("action", "oxy_html_convert");
    formData.append("nonce", config.nonce);
    formData.append("html", html);

    const requestFields = buildRequestFields(options, converterOptions);
    Object.keys(requestFields).forEach(function (key) {
      formData.append(key, requestFields[key]);
    });

    const response = await fetch(config.ajaxUrl, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
    const data = await response.json();

    if (!data.success) {
      const error = new Error(data.data?.message || "Conversion failed.");
      error.payload = data.data || {};
      throw error;
    }

    return data.data;
  }

  async function saveSelectorPayload(config, selectorPayload) {
    const selectors = Array.isArray(selectorPayload?.selectors)
      ? selectorPayload.selectors
      : [];

    if (selectors.length === 0) {
      return { saved: 0 };
    }

    const formData = new FormData();
    formData.append("action", "oxy_html_save_selectors");
    formData.append("nonce", config.nonce);
    formData.append("selectorPayload", JSON.stringify(selectorPayload));

    const response = await fetch(config.ajaxUrl, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });
    const data = await response.json();

    if (!data.success) {
      const error = new Error(data.data?.message || "Saving selectors failed.");
      error.payload = data.data || {};
      throw error;
    }

    return data.data;
  }

  return {
    buildRequestFields: buildRequestFields,
    convertHtml: convertHtml,
    saveSelectorPayload: saveSelectorPayload,
  };
});
