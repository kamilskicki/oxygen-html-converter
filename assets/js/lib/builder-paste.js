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
      const win = doc.defaultView || (typeof window !== "undefined" ? window : null);
      const DataTransferConstructor =
        (win && win.DataTransfer) ||
        (typeof DataTransfer !== "undefined" ? DataTransfer : null);
      const ClipboardEventConstructor =
        (win && win.ClipboardEvent) ||
        (typeof ClipboardEvent !== "undefined" ? ClipboardEvent : null);

      if (!DataTransferConstructor || !ClipboardEventConstructor) {
        return false;
      }

      const dt = new DataTransferConstructor();
      dt.setData("text/plain", json);
      dt.setData("text", json);

      const pasteEvent = new ClipboardEventConstructor("paste", {
        clipboardData: dt,
        bubbles: true,
        cancelable: true,
      });

      return doc.dispatchEvent(pasteEvent) !== false;
    } catch (error) {
      return false;
    }
  }

  function dispatchConvertedPasteAfterDelay(doc, json, delay) {
    const win = doc.defaultView || (typeof window !== "undefined" ? window : null);
    const timeout = win && typeof win.setTimeout === "function"
      ? function (callback, milliseconds) {
          win.setTimeout(callback, milliseconds);
        }
      : setTimeout;

    return new Promise(function (resolve) {
      timeout(function () {
        resolve(dispatchConvertedPaste(doc, json));
      }, typeof delay === "number" ? delay : 50);
    });
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
    dispatchConvertedPasteAfterDelay: dispatchConvertedPasteAfterDelay,
    fallbackToClipboard: fallbackToClipboard,
  };
});
