const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

module.exports = async function runConverterTests() {
  await testNativeInsertSavesSelectorsAndShowsSuccessToast();
  await testPasteFallbackShowsSuccessToastAfterStoreMutation();
  await testClipboardFallbackShowsClipboardToast();
  await testEditabilityHelperIsBridgedToParentWindow();
};

function createDocument(label, storeApp) {
  const listeners = new Map();

  return {
    label,
    readyState: "complete",
    activeElement: {
      tagName: "DIV",
      getAttribute() {
        return "false";
      },
    },
    addEventListener(type, handler) {
      const handlers = listeners.get(type) || [];
      handlers.push(handler);
      listeners.set(type, handlers);
    },
    querySelector(selector) {
      if (selector === ".v-application") {
        return storeApp || null;
      }

      return null;
    },
    getListeners(type) {
      return listeners.get(type) || [];
    },
  };
}

function createStoreTree() {
  return {
    root: {
      id: 1,
      data: {
        type: "OxygenElements\\Container",
      },
      children: [],
    },
    _lookupTable: {},
    _nextNodeId: 2,
  };
}

function createRuntime(options = {}) {
  const tree = options.tree || createStoreTree();
  tree._lookupTable[String(tree.root.id)] = tree.root;

  const documentStore = options.documentStore || {
    document: {
      tree,
    },
    addMultipleNodes({ nodes, parentId }) {
      const parentNode = tree._lookupTable[String(parentId)] || tree.root;
      for (const node of nodes) {
        parentNode.children.push(node);
        tree._lookupTable[String(node.id)] = node;
      }
    },
  };

  const store = {
    state: {
      tree,
    },
  };
  const storeApp = {
    __vue__: {
      $store: store,
    },
  };

  const parentDocument = createDocument("parent", storeApp);
  const document = createDocument("child", storeApp);

  const intervalCallbacks = [];
  const toastCalls = [];
  const clipboardWrites = [];
  const saveSelectorPayloadCalls = [];
  const dispatchCalls = [];
  const convertHtmlCalls = [];
  const modalBuildCalls = [];

  const builderClient = options.builderClient || {
    async convertHtml(config, html) {
      convertHtmlCalls.push({ config, html });
      return options.convertResult || {
        json: '{"element":1}',
        element: options.element || {
          id: 7,
          data: {
            type: "OxygenElements\\Text",
            properties: {
              content: {
                content: {
                  text: "Imported",
                },
              },
            },
          },
          children: [],
        },
      };
    },
    async saveSelectorPayload(config, payload) {
      saveSelectorPayloadCalls.push({ config, payload });
      return { saved: Array.isArray(payload?.selectors) ? payload.selectors.length : 0 };
    },
  };

  const builderPaste = options.builderPaste || {
    canInterceptPasteTarget() {
      return true;
    },
    extractClipboardHtml(clipboardData) {
      return clipboardData.getData("text/html");
    },
    async dispatchConvertedPasteAfterDelay(doc, json) {
      dispatchCalls.push({ doc: doc.label, json, delayed: true });
      if (typeof options.onDispatchPaste === "function") {
        options.onDispatchPaste({ tree, documentStore });
      }
      return true;
    },
    dispatchConvertedPaste(doc, json) {
      dispatchCalls.push({ doc: doc.label, json, delayed: false });
      if (typeof options.onDispatchPaste === "function") {
        options.onDispatchPaste({ tree, documentStore });
      }
      return true;
    },
    async fallbackToClipboard(navigatorRef, json) {
      clipboardWrites.push(json);
      if (
        navigatorRef.clipboard &&
        typeof navigatorRef.clipboard.writeText === "function"
      ) {
        await navigatorRef.clipboard.writeText(json);
        return true;
      }

      return false;
    },
  };

  const builderToast = {
    showToast(_parentWindow, message) {
      toastCalls.push(message);
    },
  };

  const builderModal = {
    createModalController() {
      return {
        build() {
          modalBuildCalls.push("build");
        },
        open() {},
      };
    },
  };

  const navigatorRef = {
    clipboard: {
      async writeText(value) {
        clipboardWrites.push(value);
      },
    },
  };

  const parentWindow = {
    document: parentDocument,
    Breakdance: {
      stores: {
        documentStore,
      },
    },
  };

  const windowObj = {
    parent: parentWindow,
    Breakdance: {
      stores: {
        documentStore,
      },
    },
    document,
    navigator: navigatorRef,
    setTimeout(callback) {
      callback();
      return 1;
    },
    clearTimeout() {},
    setInterval(callback) {
      intervalCallbacks.push(callback);
      return intervalCallbacks.length;
    },
    clearInterval() {},
    oxyHtmlConverter: {
      nonce: "nonce-1",
      ajaxUrl: "/wp-admin/admin-ajax.php",
      strings: {
        convertedAndPasted: "HTML converted and pasted.",
        fallbackClipboard: "Converted JSON was copied to the clipboard.",
        convertedReady: "HTML converted. Review the result and paste it manually.",
        converting: "Converting HTML...",
        convertFailed: "Conversion failed.",
      },
    },
    OxyHtmlConverterBuilderClient: builderClient,
    OxyHtmlConverterBuilderPaste: builderPaste,
    OxyHtmlConverterBuilderToast: builderToast,
    OxyHtmlConverterBuilderModal: builderModal,
    OxyHtmlConverterBuilderEditability: options.builderEditability || null,
    OxyHtmlConverterClipboard: null,
    OxyHtmlConverterOptions: null,
  };

  parentWindow.oxyHtmlConverterOpenModal = null;

  const context = {
    window: windowObj,
    document,
    navigator: navigatorRef,
    globalThis: windowObj,
    console,
    setTimeout(callback) {
      callback();
      return 1;
    },
    clearTimeout() {},
  };

  const source = fs.readFileSync(
    path.join(process.cwd(), "assets/js/converter.js"),
    "utf8"
  );

  vm.runInNewContext(source, context, {
    filename: "assets/js/converter.js",
  });

  for (const callback of intervalCallbacks) {
    callback();
  }

  return {
    tree,
    documentStore,
    document,
    parentDocument,
    toastCalls,
    clipboardWrites,
    saveSelectorPayloadCalls,
    dispatchCalls,
    convertHtmlCalls,
    modalBuildCalls,
    windowObj,
    parentWindow,
    async dispatchPaste(targetDocument, html) {
      const listeners = targetDocument.getListeners("paste");
      assert.equal(listeners.length > 0, true, `Expected paste listener on ${targetDocument.label}`);

      const event = {
        clipboardData: {
          getData(type) {
            return type === "text/html" ? html : "";
          },
        },
        preventDefaultCalled: false,
        stopImmediatePropagationCalled: false,
        preventDefault() {
          this.preventDefaultCalled = true;
        },
        stopImmediatePropagation() {
          this.stopImmediatePropagationCalled = true;
        },
      };

      for (const listener of listeners) {
        await listener(event);
      }

      return event;
    },
  };
}

async function testNativeInsertSavesSelectorsAndShowsSuccessToast() {
  const runtime = createRuntime({
    convertResult: {
      json: '{"type":"native"}',
      selectorPayload: {
        selectors: [{ id: "selector-1", name: "card" }],
      },
      element: {
        id: 10,
        data: {
          type: "OxygenElements\\Text",
          properties: {
            content: {
              content: {
                text: "Imported natively",
              },
            },
          },
        },
        children: [],
      },
    },
  });

  const event = await runtime.dispatchPaste(runtime.parentDocument, "<section>Imported</section>");

  assert.equal(event.preventDefaultCalled, true);
  assert.equal(event.stopImmediatePropagationCalled, true);
  assert.equal(runtime.saveSelectorPayloadCalls.length, 1);
  assert.deepEqual(runtime.saveSelectorPayloadCalls[0].payload.selectors, [
    { id: "selector-1", name: "card" },
  ]);
  assert.equal(runtime.dispatchCalls.length, 0);
  assert.equal(runtime.tree.root.children.length, 1);
  assert.equal(runtime.tree.root.children[0].data.type, "OxygenElements\\Text");
  assert.deepEqual(runtime.toastCalls, ["Converting HTML...", "HTML converted and pasted."]);
  assert.equal(runtime.modalBuildCalls.length, 1);
}

async function testPasteFallbackShowsSuccessToastAfterStoreMutation() {
  const runtime = createRuntime({
    convertResult: {
      json: '{"type":"paste"}',
      selectorPayload: {
        selectors: [],
      },
      element: null,
    },
    onDispatchPaste({ tree }) {
      tree.root.children.push({
        id: 22,
        data: {
          type: "OxygenElements\\Text",
          properties: {},
        },
        children: [],
      });
    },
  });

  await runtime.dispatchPaste(runtime.document, "<section>Paste fallback</section>");

  assert.equal(runtime.saveSelectorPayloadCalls.length, 0);
  assert.equal(runtime.dispatchCalls.length, 1);
  assert.equal(runtime.dispatchCalls[0].delayed, true);
  assert.equal(runtime.tree.root.children.length, 1);
  assert.deepEqual(runtime.toastCalls, ["Converting HTML...", "HTML converted and pasted."]);
  assert.deepEqual(runtime.clipboardWrites, []);
}

async function testClipboardFallbackShowsClipboardToast() {
  const dispatchCalls = [];
  const runtime = createRuntime({
    convertResult: {
      json: '{"type":"clipboard"}',
      selectorPayload: {
        selectors: [],
      },
      element: null,
    },
    builderPaste: {
      canInterceptPasteTarget() {
        return true;
      },
      extractClipboardHtml(clipboardData) {
        return clipboardData.getData("text/html");
      },
      async dispatchConvertedPasteAfterDelay(doc, json) {
        dispatchCalls.push({ doc: doc.label, json });
        return false;
      },
      dispatchConvertedPaste() {
        return false;
      },
      async fallbackToClipboard(navigatorRef, json) {
        await navigatorRef.clipboard.writeText(json);
        return true;
      },
    },
  });

  await runtime.dispatchPaste(runtime.parentDocument, "<section>Clipboard fallback</section>");

  assert.deepEqual(runtime.toastCalls, [
    "Converting HTML...",
    "Converted JSON was copied to the clipboard.",
  ]);
  assert.deepEqual(dispatchCalls, [
    { doc: "child", json: '{"type":"clipboard"}' },
  ]);
  assert.deepEqual(runtime.clipboardWrites, ['{"type":"clipboard"}']);
}

async function testEditabilityHelperIsBridgedToParentWindow() {
  const helper = {
    mutateNativeTextNode() {
      return { ok: true };
    },
  };

  const runtime = createRuntime({
    builderEditability: helper,
  });

  assert.equal(runtime.windowObj.OxyHtmlConverterBuilderEditability, helper);
  assert.equal(runtime.parentWindow.OxyHtmlConverterBuilderEditability, helper);
  assert.equal(typeof runtime.parentWindow.oxyHtmlConverterOpenModal, "function");
}
