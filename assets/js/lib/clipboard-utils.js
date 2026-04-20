(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterClipboard = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  function normalizeString(value) {
    return typeof value === "string" ? value.trim() : "";
  }

  function isHtmlContent(text) {
    const normalized = normalizeString(text);
    return (
      /<[a-z][\s\S]*>/i.test(normalized) &&
      !normalized.startsWith("{") &&
      !normalized.startsWith("[")
    );
  }

  function extractHtmlFromClipboard(clipboardData) {
    if (!clipboardData || typeof clipboardData.getData !== "function") {
      return "";
    }

    const html = normalizeString(clipboardData.getData("text/html"));
    if (isHtmlContent(html)) {
      return html;
    }

    const text = normalizeString(
      clipboardData.getData("text/plain") || clipboardData.getData("text")
    );

    return isHtmlContent(text) ? text : "";
  }

  return {
    isHtmlContent,
    extractHtmlFromClipboard,
  };
});
