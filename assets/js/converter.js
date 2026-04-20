(function () {
  "use strict";

  const config = window.oxyHtmlConverter || {};
  const strings = config.strings || {};
  const converterOptions = window.OxyHtmlConverterOptions || null;
  const clipboardUtils = window.OxyHtmlConverterClipboard || null;
  const builderClient = window.OxyHtmlConverterBuilderClient || null;
  const builderPaste = window.OxyHtmlConverterBuilderPaste || null;
  const builderToast = window.OxyHtmlConverterBuilderToast || null;
  const builderModal = window.OxyHtmlConverterBuilderModal || null;

  let modalController = null;

  function getOxygenStore() {
    const el = window.parent.document.querySelector(".v-application");
    return el?.__vue__?.$store || el?.__vue_app__?.config?.globalProperties?.$store || null;
  }

  function showToast(message, duration) {
    if (builderToast && typeof builderToast.showToast === "function") {
      builderToast.showToast(window.parent, message, duration);
    }
  }

  async function convertHtml(html, options = {}) {
    return builderClient.convertHtml(config, html, options, converterOptions);
  }

  async function handleConvertedPayload(payload) {
    if (!payload || !payload.json) {
      return;
    }

    if (builderPaste.dispatchConvertedPaste(document, payload.json)) {
      showToast(strings.convertedAndPasted || "HTML converted and pasted.");
      return;
    }

    if (await builderPaste.fallbackToClipboard(navigator, payload.json)) {
      showToast(strings.fallbackClipboard || "Converted JSON was copied to the clipboard.");
      return;
    }

    showToast(strings.convertedReady || "HTML converted. Review the result and paste it manually.");
  }

  async function handlePaste(event) {
    if (
      window.__oxyHtmlConverterProcessing ||
      !builderPaste.canInterceptPasteTarget(document)
    ) {
      return;
    }

    const clipboardData = event.clipboardData || window.clipboardData;
    const html = builderPaste.extractClipboardHtml(clipboardData, clipboardUtils);

    if (!html) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    window.__oxyHtmlConverterProcessing = true;

    try {
      showToast(strings.converting || "Converting HTML…");
      const result = await convertHtml(html);
      await handleConvertedPayload(result);
    } catch (error) {
      showToast(
        (strings.convertFailed || "Conversion failed.") +
          " " +
          (error?.message || ""),
        4800
      );
    } finally {
      window.__oxyHtmlConverterProcessing = false;
    }
  }

  function buildModal() {
    if (
      !builderModal ||
      typeof builderModal.createModalController !== "function" ||
      modalController
    ) {
      return modalController;
    }
    modalController = builderModal.createModalController({
      parentWindow: window.parent,
      strings: strings,
      onSubmit: async function onSubmit(payload) {
        const result = await convertHtml(payload.html, {
          safeMode: payload.safeMode,
        });
        await handleConvertedPayload(result);
      },
    });
    modalController.build();
    window.oxyHtmlConverterOpenModal = modalController.open;
    return modalController;
  }

  function setupKeyboardShortcuts() {
    window.parent.document.addEventListener("keydown", (event) => {
      if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === "h") {
        event.preventDefault();
        if (modalController) {
          modalController.open();
        }
      }
    });
  }

  function init() {
    const checkReady = window.setInterval(() => {
      const store = getOxygenStore();
      if (!store) {
        return;
      }

      window.clearInterval(checkReady);
      document.addEventListener("paste", handlePaste, true);
      buildModal();
      setupKeyboardShortcuts();
    }, 500);

    window.setTimeout(() => window.clearInterval(checkReady), 30000);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
