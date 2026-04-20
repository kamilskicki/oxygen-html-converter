(function () {
  "use strict";

  const config = window.oxyHtmlConverter || {};
  const strings = config.strings || {};
  const converterOptions = window.OxyHtmlConverterOptions || null;
  const clipboardUtils = window.OxyHtmlConverterClipboard || null;

  let modalState = null;
  let lastFocusedElement = null;

  function showToast(message, duration = 3200) {
    const parentDoc = window.parent.document;
    let toast = parentDoc.getElementById("oxy-html-converter-toast");

    if (!toast) {
      toast = parentDoc.createElement("div");
      toast.id = "oxy-html-converter-toast";
      toast.style.cssText = [
        "position:fixed",
        "top:24px",
        "right:24px",
        "z-index:999999",
        "max-width:360px",
        "padding:14px 16px",
        "background:#112033",
        "color:#fff",
        "border-radius:12px",
        "font-size:13px",
        "line-height:1.5",
        "box-shadow:0 18px 40px rgba(17,32,51,0.25)",
        "opacity:0",
        "transform:translateY(-8px)",
        "transition:opacity .2s ease, transform .2s ease",
        "pointer-events:none",
      ].join(";");
      parentDoc.body.appendChild(toast);
    }

    toast.textContent = message;
    toast.style.opacity = "1";
    toast.style.transform = "translateY(0)";

    window.setTimeout(() => {
      toast.style.opacity = "0";
      toast.style.transform = "translateY(-8px)";
    }, duration);
  }

  function getOxygenStore() {
    const el = window.parent.document.querySelector(".v-application");
    return el?.__vue__?.$store || el?.__vue_app__?.config?.globalProperties?.$store || null;
  }

  function extractClipboardHtml(clipboardData) {
    if (clipboardUtils && typeof clipboardUtils.extractHtmlFromClipboard === "function") {
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

  function buildRequestFields(options) {
    const defaults = {
      wrapInContainer: true,
      includeCssElement: true,
      inlineStyles: true,
      safeMode: false,
      debugMode: false,
    };
    const merged = Object.assign({}, defaults, options || {});

    return converterOptions
      ? converterOptions.buildConvertRequestFields(merged)
      : merged;
  }

  async function convertHtml(html, options = {}) {
    const formData = new FormData();
    formData.append("action", "oxy_html_convert");
    formData.append("nonce", config.nonce);
    formData.append("html", html);

    const requestFields = buildRequestFields(options);
    Object.keys(requestFields).forEach((key) => {
      formData.append(key, requestFields[key]);
    });

    const response = await fetch(config.ajaxUrl, {
      method: "POST",
      body: formData,
      credentials: "same-origin",
    });

    const data = await response.json();

    if (!data.success) {
      const error = new Error(data.data?.message || strings.convertFailed || "Conversion failed.");
      error.payload = data.data || {};
      throw error;
    }

    return data.data;
  }

  function canInterceptPasteTarget() {
    const activeElement = document.activeElement;
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

  function dispatchConvertedPaste(json) {
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

      document.dispatchEvent(pasteEvent);
      return true;
    } catch (error) {
      return false;
    }
  }

  async function fallbackToClipboard(json) {
    if (!navigator.clipboard || typeof navigator.clipboard.writeText !== "function") {
      return false;
    }

    try {
      await navigator.clipboard.writeText(json);
      return true;
    } catch (error) {
      return false;
    }
  }

  async function handleConvertedPayload(payload) {
    if (!payload || !payload.json) {
      return;
    }

    if (dispatchConvertedPaste(payload.json)) {
      showToast(strings.convertedAndPasted || "HTML converted and pasted.");
      return;
    }

    if (await fallbackToClipboard(payload.json)) {
      showToast(strings.fallbackClipboard || "Converted JSON was copied to the clipboard.");
      return;
    }

    showToast(strings.convertedReady || "HTML converted. Review the result and paste it manually.");
  }

  async function handlePaste(event) {
    if (window.__oxyHtmlConverterProcessing || !canInterceptPasteTarget()) {
      return;
    }

    const clipboardData = event.clipboardData || window.clipboardData;
    const html = extractClipboardHtml(clipboardData);

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

  function focusModalFirstField() {
    if (modalState?.input) {
      modalState.input.focus();
    }
  }

  function trapFocus(event) {
    if (!modalState || modalState.overlay.style.display !== "block" || event.key !== "Tab") {
      return;
    }

    const focusable = modalState.dialog.querySelectorAll(
      'button, [href], input, textarea, select, [tabindex]:not([tabindex="-1"])'
    );
    const items = Array.from(focusable).filter((element) => !element.disabled);
    if (!items.length) {
      return;
    }

    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
      return;
    }

    if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  function closeModal() {
    if (!modalState) {
      return;
    }

    modalState.overlay.style.display = "none";
    modalState.input.value = "";
    modalState.error.textContent = "";
    modalState.safeMode.checked = false;

    if (lastFocusedElement && typeof lastFocusedElement.focus === "function") {
      lastFocusedElement.focus();
    }
  }

  function openModal() {
    if (!modalState) {
      return;
    }

    lastFocusedElement = window.parent.document.activeElement;
    modalState.overlay.style.display = "block";
    modalState.error.textContent = "";
    focusModalFirstField();
  }

  function buildModal() {
    const parentDoc = window.parent.document;
    if (parentDoc.getElementById("oxy-html-import-modal")) {
      return;
    }

    const container = parentDoc.createElement("div");
    container.id = "oxy-html-import-modal";
    container.innerHTML = `
      <div class="oxy-html-modal-overlay" style="position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999998;display:none;">
        <div class="oxy-html-modal-content" role="dialog" aria-modal="true" aria-labelledby="oxy-html-import-title" style="position:absolute;top:50%;left:50%;transform:translate(-50%, -50%);width:min(720px, 92vw);max-height:86vh;overflow:auto;padding:24px;border-radius:16px;background:#fff;box-shadow:0 28px 56px rgba(15,23,42,.28);">
          <div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;">
            <div>
              <p style="margin:0 0 6px;color:#1263d1;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Builder import</p>
              <h2 id="oxy-html-import-title" style="margin:0;">${strings.modalTitle || "Import HTML"}</h2>
              <p style="margin:8px 0 0;color:#506174;">Paste source HTML and import it directly into the current builder session.</p>
            </div>
            <button type="button" id="oxy-html-import-close" aria-label="${strings.cancelButton || "Cancel"}" style="border:none;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:#506174;">&times;</button>
          </div>
          <label for="oxy-html-import-input" style="display:block;margin-top:18px;font-weight:600;">HTML</label>
          <textarea id="oxy-html-import-input" placeholder="Paste your HTML here…" style="width:100%;min-height:320px;margin-top:8px;padding:14px 16px;border:1px solid #d7dee7;border-radius:12px;box-sizing:border-box;resize:vertical;font-family:Consolas, 'SFMono-Regular', Menlo, monospace;font-size:13px;"></textarea>
          <label style="display:flex;align-items:flex-start;gap:10px;margin-top:14px;padding:12px 14px;border:1px solid #d7dee7;border-radius:12px;background:#f6f8fb;">
            <input type="checkbox" id="oxy-html-import-safe-mode" style="margin-top:2px;">
            <span>${strings.safeModeLabel || "Safe mode: strip scripts, event handlers, and external head assets"}</span>
          </label>
          <div id="oxy-html-import-error" aria-live="polite" style="min-height:24px;margin-top:12px;color:#b42318;font-size:13px;"></div>
          <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
            <button type="button" id="oxy-html-import-cancel" style="padding:10px 16px;border:1px solid #d7dee7;background:#fff;border-radius:10px;cursor:pointer;">${strings.cancelButton || "Cancel"}</button>
            <button type="button" id="oxy-html-import-submit" style="padding:10px 16px;border:none;background:#1263d1;color:#fff;border-radius:10px;cursor:pointer;">${strings.importButton || "Import into Builder"}</button>
          </div>
        </div>
      </div>
    `;

    parentDoc.body.appendChild(container);

    modalState = {
      root: container,
      overlay: container.querySelector(".oxy-html-modal-overlay"),
      dialog: container.querySelector(".oxy-html-modal-content"),
      input: container.querySelector("#oxy-html-import-input"),
      error: container.querySelector("#oxy-html-import-error"),
      submit: container.querySelector("#oxy-html-import-submit"),
      cancel: container.querySelector("#oxy-html-import-cancel"),
      close: container.querySelector("#oxy-html-import-close"),
      safeMode: container.querySelector("#oxy-html-import-safe-mode"),
    };

    modalState.overlay.addEventListener("click", (event) => {
      if (event.target === modalState.overlay) {
        closeModal();
      }
    });

    modalState.cancel.addEventListener("click", closeModal);
    modalState.close.addEventListener("click", closeModal);

    modalState.submit.addEventListener("click", async () => {
      const html = String(modalState.input.value || "").trim();
      if (!html) {
        modalState.error.textContent = strings.emptyHtml || "Paste HTML before importing.";
        focusModalFirstField();
        return;
      }

      modalState.submit.disabled = true;
      modalState.submit.textContent = strings.converting || "Converting HTML…";
      modalState.error.textContent = "";

      try {
        const result = await convertHtml(html, {
          safeMode: modalState.safeMode.checked,
        });

        closeModal();
        await handleConvertedPayload(result);
      } catch (error) {
        const followUp = error?.payload?.audit?.followUp || [];
        const detail = followUp.length ? " " + followUp.join(" ") : "";
        modalState.error.textContent =
          (strings.modalErrorPrefix || "Import error:") +
          " " +
          (error?.message || strings.convertFailed || "Conversion failed.") +
          detail;
      } finally {
        modalState.submit.disabled = false;
        modalState.submit.textContent = strings.importButton || "Import into Builder";
      }
    });

    parentDoc.addEventListener("keydown", (event) => {
      if (!modalState || modalState.overlay.style.display !== "block") {
        return;
      }

      if (event.key === "Escape") {
        event.preventDefault();
        closeModal();
        return;
      }

      trapFocus(event);
    });

    window.oxyHtmlConverterOpenModal = openModal;
  }

  function setupKeyboardShortcuts() {
    window.parent.document.addEventListener("keydown", (event) => {
      if ((event.ctrlKey || event.metaKey) && event.shiftKey && event.key.toLowerCase() === "h") {
        event.preventDefault();
        openModal();
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
