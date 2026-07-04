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
  const builderEditability =
    window.OxyHtmlConverterBuilderEditability ||
    window.parent?.OxyHtmlConverterBuilderEditability ||
    null;

  let modalController = null;

  function getOxygenStore() {
    try {
      const el = window.parent.document.querySelector(".v-application");
      return el?.__vue__?.$store || el?.__vue_app__?.config?.globalProperties?.$store || null;
    } catch (error) {
      return null;
    }
  }

  function getBuilderDocumentStore() {
    return (
      window.parent?.Breakdance?.stores?.documentStore ||
      window.Breakdance?.stores?.documentStore ||
      null
    );
  }

  function getStoreTreeCandidate(store) {
    const state = store?.state || {};
    const candidates = [
      state.tree,
      state.elements,
      state.documentTree,
      state.document?.document?.tree,
      state.breakdance?.tree,
      state.breakdanceState?.tree,
      state.oxygen?.tree,
      state.builder?.tree,
      state.document?.tree,
    ];

    return candidates.find((candidate) => candidate && typeof candidate === "object") || null;
  }

  function getDocumentTree() {
    const documentStore = getBuilderDocumentStore();
    return (
      documentStore?.document?.tree ||
      getStoreTreeCandidate(getOxygenStore())
    );
  }

  function countStoreNodes(node, seen = new Set()) {
    if (!node || typeof node !== "object" || seen.has(node)) {
      return 0;
    }
    seen.add(node);

    let count = node.id || node.data?.type ? 1 : 0;
    const children = Array.isArray(node)
      ? node
      : Array.isArray(node.children)
        ? node.children
        : Array.isArray(node.root?.children)
          ? [node.root, ...node.root.children]
          : Object.values(node);

    for (const child of children) {
      count += countStoreNodes(child, seen);
    }

    return count;
  }

  function getOxygenStoreSnapshot() {
    const tree = getDocumentTree();
    return tree ? countStoreNodes(tree) : null;
  }

  function findMaxNodeId(node, seen = new Set()) {
    if (!node || typeof node !== "object" || seen.has(node)) {
      return 0;
    }
    seen.add(node);

    let maxId = Number.isFinite(Number(node.id)) ? Number(node.id) : 0;
    const children = Array.isArray(node)
      ? node
      : Array.isArray(node.children)
        ? node.children
        : Array.isArray(node.root?.children)
          ? [node.root, ...node.root.children]
          : [];

    for (const child of children) {
      maxId = Math.max(maxId, findMaxNodeId(child, seen));
    }

    return maxId;
  }

  function reindexElementTree(element, nextId) {
    if (!element || typeof element !== "object") {
      return nextId;
    }

    element.id = nextId;
    nextId += 1;

    if (Array.isArray(element.children)) {
      for (const child of element.children) {
        nextId = reindexElementTree(child, nextId);
      }
    }

    return nextId;
  }

  function registerElementTree(documentTree, element, parentId, nextId) {
    if (!documentTree._lookupTable || typeof documentTree._lookupTable !== "object") {
      documentTree._lookupTable = {};
    }

    element.id = nextId;
    element._parentId = parentId;
    documentTree._lookupTable[String(element.id)] = element;
    nextId += 1;

    if (!Array.isArray(element.children)) {
      element.children = [];
    }

    for (const child of element.children) {
      nextId = registerElementTree(documentTree, child, element.id, nextId);
    }

    return nextId;
  }

  function appendElementTree(documentTree, parentNode, element, nextId) {
    nextId = registerElementTree(documentTree, element, parentNode.id, nextId);
    parentNode.children.push(element);
    documentTree._nextNodeId = Math.max(Number(documentTree._nextNodeId) || 0, nextId);

    return nextId;
  }

  function insertNativeElement(element) {
    const documentStore = getBuilderDocumentStore();
    const documentTree = getDocumentTree();
    const rootNode = documentTree?.root;
    const rootId = rootNode?.id;

    if (
      !documentStore ||
      !element ||
      typeof element !== "object" ||
      !rootNode ||
      !Array.isArray(rootNode.children) ||
      rootId === undefined ||
      rootId === null
    ) {
      return false;
    }

    const beforeSnapshot = getOxygenStoreSnapshot();
    const elementToInsert = JSON.parse(JSON.stringify(element));
    reindexElementTree(elementToInsert, findMaxNodeId(documentTree) + 1);

    if (typeof documentStore.addMultipleNodes === "function") {
      documentStore.addMultipleNodes({
        nodes: [elementToInsert],
        parentId: rootId,
      });

      const afterActionSnapshot = getOxygenStoreSnapshot();
      if (
        beforeSnapshot !== null &&
        afterActionSnapshot !== null &&
        afterActionSnapshot > beforeSnapshot
      ) {
        return true;
      }
    }

    const fallbackElement = JSON.parse(JSON.stringify(element));
    const nextId = Math.max(
      Number(documentTree._nextNodeId) || 0,
      findMaxNodeId(documentTree) + 1
    );
    appendElementTree(documentTree, rootNode, fallbackElement, nextId);

    return true;
  }

  function waitForStoreMutation(beforeSnapshot, timeout = 1200) {
    if (beforeSnapshot === null) {
      return Promise.resolve(false);
    }

    const startedAt = Date.now();
    return new Promise((resolve) => {
      const poll = () => {
        const nextSnapshot = getOxygenStoreSnapshot();
        if (nextSnapshot !== null && nextSnapshot > beforeSnapshot) {
          resolve(true);
          return;
        }

        if (Date.now() - startedAt >= timeout) {
          resolve(false);
          return;
        }

        window.setTimeout(poll, 80);
      };

      poll();
    });
  }

  function showToast(message, duration) {
    if (builderToast && typeof builderToast.showToast === "function") {
      builderToast.showToast(window.parent, message, duration);
    }
  }

  function bridgeBuilderGlobals() {
    const bridges = [
      ["OxyHtmlConverterBuilderEditability", builderEditability],
      ["oxyHtmlConverterOpenModal", modalController?.open || null],
    ];

    for (const [key, value] of bridges) {
      if (!value) {
        continue;
      }

      try {
        window[key] = value;
      } catch (error) {
        // Ignore unavailable same-origin contexts in tests or unusual embeds.
      }

      try {
        if (window.parent) {
          window.parent[key] = value;
        }
      } catch (error) {
        // Ignore unavailable same-origin contexts in tests or unusual embeds.
      }
    }
  }

  async function convertHtml(html, options = {}) {
    return builderClient.convertHtml(config, html, options, converterOptions);
  }

  async function handleConvertedPayload(payload) {
    if (!payload || !payload.json) {
      return;
    }

    if (
      payload.selectorPayload &&
      Array.isArray(payload.selectorPayload.selectors) &&
      payload.selectorPayload.selectors.length > 0 &&
      builderClient &&
      typeof builderClient.saveSelectorPayload === "function"
    ) {
      await builderClient.saveSelectorPayload(config, payload.selectorPayload);
    }

    const beforeSnapshot = getOxygenStoreSnapshot();
    if (beforeSnapshot !== null && insertNativeElement(payload.element)) {
      if (await waitForStoreMutation(beforeSnapshot, 3000)) {
        showToast(strings.convertedAndPasted || "HTML converted and pasted.");
        return;
      }
    }

    const dispatched =
      typeof builderPaste.dispatchConvertedPasteAfterDelay === "function"
        ? await builderPaste.dispatchConvertedPasteAfterDelay(document, payload.json, 50)
        : builderPaste.dispatchConvertedPaste(document, payload.json);

    if (dispatched && await waitForStoreMutation(beforeSnapshot)) {
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
    bridgeBuilderGlobals();
    return modalController;
  }

  function attachPasteListeners() {
    const documents = [document];
    try {
      if (window.parent && window.parent.document !== document) {
        documents.push(window.parent.document);
      }
    } catch (error) {
      // The builder runs same-origin locally; ignore unavailable parent contexts.
    }

    for (const doc of documents) {
      doc.addEventListener("paste", handlePaste, true);
    }
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
      attachPasteListeners();
      buildModal();
      bridgeBuilderGlobals();
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
