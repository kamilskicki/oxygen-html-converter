(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterBuilderModal = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

  function createModalController(config) {
    const parentWindow = config.parentWindow;
    const parentDoc = parentWindow.document;
    const strings = config.strings || {};
    let modalState = null;
    let lastFocusedElement = null;

    function focusFirstField() {
      if (modalState && modalState.input) {
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
      const items = Array.from(focusable).filter(function (element) {
        return !element.disabled;
      });
      if (!items.length) {
        return;
      }

      const first = items[0];
      const last = items[items.length - 1];

      if (event.shiftKey && parentDoc.activeElement === first) {
        event.preventDefault();
        last.focus();
        return;
      }

      if (!event.shiftKey && parentDoc.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    function close() {
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

    function open() {
      if (!modalState) {
        build();
      }

      lastFocusedElement = parentDoc.activeElement;
      modalState.overlay.style.display = "block";
      modalState.error.textContent = "";
      focusFirstField();
    }

    function build() {
      if (modalState || parentDoc.getElementById("oxy-html-import-modal")) {
        modalState = modalState || {
          root: parentDoc.getElementById("oxy-html-import-modal"),
          overlay: parentDoc.querySelector("#oxy-html-import-modal .oxy-html-modal-overlay"),
          dialog: parentDoc.querySelector("#oxy-html-import-modal .oxy-html-modal-content"),
          input: parentDoc.querySelector("#oxy-html-import-input"),
          error: parentDoc.querySelector("#oxy-html-import-error"),
          submit: parentDoc.querySelector("#oxy-html-import-submit"),
          cancel: parentDoc.querySelector("#oxy-html-import-cancel"),
          close: parentDoc.querySelector("#oxy-html-import-close"),
          safeMode: parentDoc.querySelector("#oxy-html-import-safe-mode"),
        };
        return modalState;
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

      modalState.overlay.addEventListener("click", function (event) {
        if (event.target === modalState.overlay) {
          close();
        }
      });

      modalState.cancel.addEventListener("click", close);
      modalState.close.addEventListener("click", close);
      modalState.submit.addEventListener("click", async function () {
        const html = String(modalState.input.value || "").trim();
        if (!html) {
          modalState.error.textContent = strings.emptyHtml || "Paste HTML before importing.";
          focusFirstField();
          return;
        }

        modalState.submit.disabled = true;
        modalState.submit.textContent = strings.converting || "Converting HTML…";
        modalState.error.textContent = "";

        try {
          await config.onSubmit({
            html: html,
            safeMode: modalState.safeMode.checked,
          });
          close();
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

      parentDoc.addEventListener("keydown", function (event) {
        if (!modalState || modalState.overlay.style.display !== "block") {
          return;
        }

        if (event.key === "Escape") {
          event.preventDefault();
          close();
          return;
        }

        trapFocus(event);
      });

      return modalState;
    }

    return {
      build: build,
      open: open,
      close: close,
    };
  }

  return {
    createModalController: createModalController,
  };
});
