(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterBuilderPaste = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  function extractClipboardHtml(clipboardData, clipboardUtils) {
    if (
      clipboardUtils &&
      typeof clipboardUtils.extractHtmlFromClipboard === "function"
    ) {
      return clipboardUtils.extractHtmlFromClipboard(clipboardData);
    }

    if (!clipboardData || typeof clipboardData.getData !== "function") {
      return "";
    }

    return (
      clipboardData.getData("text/html") ||
      clipboardData.getData("text/plain") ||
      clipboardData.getData("text") ||
      ""
    ).trim();
  }

  function canInterceptPasteTarget(doc) {
    const activeElement = doc.activeElement;
    if (!activeElement) {
      return true;
    }

    const tagName = (activeElement.tagName || "").toLowerCase();
    if (["input", "textarea", "select"].includes(tagName)) {
      return false;
    }

    if (activeElement.getAttribute("contenteditable") === "true") {
      return false;
    }

    return true;
  }

  function dispatchConvertedPaste(doc, json) {
    if (!json) {
      return false;
    }

    try {
      const dt = new DataTransfer();
      dt.setData("text/plain", json);

      const pasteEvent = new ClipboardEvent("paste", {
        clipboardData: dt,
        bubbles: true,
        cancelable: true,
      });

      doc.dispatchEvent(pasteEvent);
      return true;
    } catch (error) {
      return false;
    }
  }

  async function fallbackToClipboard(navigatorRef, json) {
    if (
      !navigatorRef.clipboard ||
      typeof navigatorRef.clipboard.writeText !== "function"
    ) {
      return false;
    }

    try {
      await navigatorRef.clipboard.writeText(json);
      return true;
    } catch (error) {
      return false;
    }
  }

  return {
    extractClipboardHtml: extractClipboardHtml,
    canInterceptPasteTarget: canInterceptPasteTarget,
    dispatchConvertedPaste: dispatchConvertedPaste,
    fallbackToClipboard: fallbackToClipboard,
  };
});
