const assert = require("node:assert/strict");
const builderModal = require("../../assets/js/lib/builder-modal.js");

function createElement(documentRef, id) {
  const listeners = new Map();

  return {
    id,
    style: {},
    disabled: false,
    value: "",
    checked: false,
    textContent: "",
    addEventListener(type, handler) {
      const handlers = listeners.get(type) || [];
      handlers.push(handler);
      listeners.set(type, handlers);
    },
    async dispatch(type, event = {}) {
      for (const handler of listeners.get(type) || []) {
        await handler({ target: this, ...event });
      }
    },
    focus() {
      documentRef.activeElement = this;
    },
  };
}

function createRuntime(onSubmit = async () => {}) {
  const documentRef = {
    activeElement: null,
    keydownListeners: [],
    body: {
      appendChild() {},
    },
    getElementById() {
      return null;
    },
    querySelector() {
      return null;
    },
    addEventListener(type, handler, options) {
      if (type === "keydown") {
        this.keydownListeners.push({ handler, options });
      }
    },
  };

  const elements = {
    overlay: createElement(documentRef, "overlay"),
    dialog: createElement(documentRef, "dialog"),
    input: createElement(documentRef, "oxy-html-import-input"),
    error: createElement(documentRef, "oxy-html-import-error"),
    status: createElement(documentRef, "oxy-html-import-status"),
    submit: createElement(documentRef, "oxy-html-import-submit"),
    cancel: createElement(documentRef, "oxy-html-import-cancel"),
    close: createElement(documentRef, "oxy-html-import-close"),
    safeMode: createElement(documentRef, "oxy-html-import-safe-mode"),
  };
  elements.overlay.style.display = "none";
  elements.dialog.querySelectorAll = () => [
    elements.close,
    elements.input,
    elements.safeMode,
    elements.cancel,
    elements.submit,
  ];

  const selectorMap = new Map([
    [".oxy-html-modal-overlay", elements.overlay],
    [".oxy-html-modal-content", elements.dialog],
    ["#oxy-html-import-input", elements.input],
    ["#oxy-html-import-error", elements.error],
    ["#oxy-html-import-status", elements.status],
    ["#oxy-html-import-submit", elements.submit],
    ["#oxy-html-import-cancel", elements.cancel],
    ["#oxy-html-import-close", elements.close],
    ["#oxy-html-import-safe-mode", elements.safeMode],
  ]);

  documentRef.createElement = () => ({
    id: "",
    innerHTML: "",
    querySelector(selector) {
      return selectorMap.get(selector) || null;
    },
  });

  const invoker = createElement(documentRef, "builder-invoker");
  invoker.focus();

  const controller = builderModal.createModalController({
    parentWindow: { document: documentRef },
    strings: {
      importing: "Importing HTML…",
      importComplete: "HTML import completed.",
    },
    onSubmit,
  });
  controller.build();

  function dispatchKeydown(key, shiftKey = false) {
    const event = {
      key,
      shiftKey,
      preventDefaultCalled: false,
      preventDefault() {
        this.preventDefaultCalled = true;
      },
    };
    const capture = documentRef.keydownListeners.filter(
      (listener) => listener.options === true || listener.options?.capture === true
    );
    const bubble = documentRef.keydownListeners.filter(
      (listener) => listener.options !== true && listener.options?.capture !== true
    );
    for (const listener of capture) listener.handler(event);
    // Oxygen may stop the event before document bubble listeners run.
    if (key !== "Escape") {
      for (const listener of bubble) listener.handler(event);
    }
    return event;
  }

  return { controller, documentRef, elements, invoker, dispatchKeydown };
}

module.exports = async function runBuilderModalTests() {
  await testFocusTrapCyclesInBothDirections();
  await testEscapeUsesCaptureAndRestoresInvokerFocus();
  await testAsyncSuccessUsesPersistentStatusRegion();
};

async function testFocusTrapCyclesInBothDirections() {
  const runtime = createRuntime();
  runtime.controller.open();

  runtime.elements.submit.focus();
  const forward = runtime.dispatchKeydown("Tab");
  assert.equal(forward.preventDefaultCalled, true);
  assert.equal(runtime.documentRef.activeElement, runtime.elements.close);

  const backward = runtime.dispatchKeydown("Tab", true);
  assert.equal(backward.preventDefaultCalled, true);
  assert.equal(runtime.documentRef.activeElement, runtime.elements.submit);
}

async function testEscapeUsesCaptureAndRestoresInvokerFocus() {
  const runtime = createRuntime();
  runtime.controller.open();

  const escape = runtime.dispatchKeydown("Escape");
  assert.equal(escape.preventDefaultCalled, true);
  assert.equal(runtime.elements.overlay.style.display, "none");
  assert.equal(runtime.documentRef.activeElement, runtime.invoker);
  assert.equal(
    runtime.documentRef.keydownListeners.some(
      (listener) => listener.options === true || listener.options?.capture === true
    ),
    true,
    "modal key handling must run in capture phase before Oxygen consumes Escape"
  );
}

async function testAsyncSuccessUsesPersistentStatusRegion() {
  const runtime = createRuntime(async () => {});
  runtime.controller.open();
  runtime.elements.input.value = "<section>Imported</section>";

  await runtime.elements.submit.dispatch("click");

  assert.equal(runtime.elements.status.textContent, "HTML import completed.");
  assert.equal(runtime.elements.overlay.style.display, "none");
}
