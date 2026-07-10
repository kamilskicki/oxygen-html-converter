(function (root, factory) {
  if (typeof module === "object" && module.exports) {
    module.exports = factory();
    return;
  }

  root.OxyHtmlConverterBuilderEditability = factory();
})(typeof globalThis !== "undefined" ? globalThis : window, function () {
  "use strict";

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

  function collectDocuments(rootDocument, docs = []) {
    if (!rootDocument || docs.includes(rootDocument)) {
      return docs;
    }

    docs.push(rootDocument);
    const frames =
      typeof rootDocument.querySelectorAll === "function"
        ? rootDocument.querySelectorAll("iframe")
        : [];

    for (const frame of frames) {
      try {
        collectDocuments(frame.contentDocument, docs);
      } catch (error) {
        // Ignore cross-origin or unavailable frame documents.
      }
    }

    return docs;
  }

  function resolveRuntime(runtime) {
    const rootWindow =
      runtime?.rootWindow ||
      (typeof window !== "undefined" ? window : null) ||
      (typeof globalThis !== "undefined" ? globalThis : null);
    const rootDocument =
      runtime?.rootDocument ||
      rootWindow?.parent?.document ||
      rootWindow?.document ||
      null;
    const documentStore =
      runtime?.documentStore ||
      rootWindow?.Breakdance?.stores?.documentStore ||
      rootWindow?.parent?.Breakdance?.stores?.documentStore ||
      null;
    const uiStore =
      runtime?.uiStore ||
      rootWindow?.Breakdance?.stores?.uiStore ||
      rootWindow?.parent?.Breakdance?.stores?.uiStore ||
      null;
    const store =
      runtime?.store ||
      rootDocument?.querySelector?.(".v-application")?.__vue__?.$store ||
      rootDocument?.querySelector?.(".v-application")?.__vue_app__?.config?.globalProperties?.$store ||
      null;
    const tree =
      documentStore?.document?.tree ||
      runtime?.tree ||
      getStoreTreeCandidate(store);

    return {
      rootWindow,
      rootDocument,
      documentStore,
      uiStore,
      store,
      tree,
    };
  }

  function visit(node, visitor, seen = new Set()) {
    if (!node || typeof node !== "object" || seen.has(node)) {
      return;
    }

    seen.add(node);
    visitor(node);

    const children = Array.isArray(node)
      ? node
      : Array.isArray(node.children)
        ? node.children
        : Array.isArray(node.root?.children)
          ? [node.root, ...node.root.children]
          : [];

    for (const child of children) {
      visit(child, visitor, seen);
    }
  }

  function readPropertyPath(source, propertyPath) {
    return String(propertyPath || "")
      .split(".")
      .filter(Boolean)
      .reduce((value, segment) => (value && typeof value === "object" ? value[segment] : undefined), source);
  }

  function writePropertyPath(source, propertyPath, nextValue) {
    const segments = String(propertyPath || "").split(".").filter(Boolean);
    if (!source || typeof source !== "object" || segments.length === 0) {
      return false;
    }

    let cursor = source;
    for (let index = 0; index < segments.length - 1; index += 1) {
      const segment = segments[index];
      if (!cursor[segment] || typeof cursor[segment] !== "object") {
        cursor[segment] = {};
      }
      cursor = cursor[segment];
    }

    cursor[segments[segments.length - 1]] = nextValue;
    return true;
  }

  function cloneNodeWithUpdatedText(node, propertyPath, nextText) {
    const clone = JSON.parse(JSON.stringify(node));
    writePropertyPath(clone?.data?.properties || {}, propertyPath, nextText);
    return clone;
  }

  function cloneTreeWithUpdatedText(tree, nodeId, propertyPath, nextText) {
    const clone = JSON.parse(JSON.stringify(tree));
    const node = findNodeById(clone, nodeId);
    if (!node) {
      return null;
    }

    writePropertyPath(node?.data?.properties || {}, propertyPath, nextText);
    return clone;
  }

  function markUnsavedChanges(runtime) {
    try {
      if (typeof runtime.uiStore?.setUnsavedChangesPresent === "function") {
        runtime.uiStore.setUnsavedChangesPresent(true);
      }
    } catch (error) {
      // The mutation itself is still valid; save-button state is best effort.
    }
  }

  function invokeDocumentStoreMethod(documentStore, methodName, variants, verifier) {
    if (
      !documentStore ||
      typeof documentStore[methodName] !== "function" ||
      !Array.isArray(variants)
    ) {
      return false;
    }

    for (const variant of variants) {
      try {
        documentStore[methodName](...variant);
      } catch (error) {
        continue;
      }

      if (typeof verifier !== "function" || verifier()) {
        return true;
      }
    }

    return false;
  }

  function findNodeById(tree, nodeId) {
    let match = null;
    visit(tree, (node) => {
      if (!match && Number(node?.id) === Number(nodeId)) {
        match = node;
      }
    });
    return match;
  }

  function findTextMatches(tree, targetText) {
    const matches = [];
    visit(tree, (node) => {
      const type = String(node?.data?.type || "");
      const properties = node?.data?.properties || {};
      const candidatePaths = [
        "content.content.text",
        "content.content.html_code",
        "content.code.html",
        "code.html",
      ];

      for (const path of candidatePaths) {
        const value = readPropertyPath(properties, path);
        if (typeof value !== "string" || !value.includes(targetText)) {
          continue;
        }

        matches.push({
          id: Number(node.id) || 0,
          type,
          path,
          currentText: value,
        });
      }
    });

    return matches;
  }

  function getNativeTextMatch(tree, targetText) {
    const matches = findTextMatches(tree, targetText);
    const nativeMatches = matches.filter(
      (match) => match.path === "content.content.text"
    );
    const exactNativeMatch =
      nativeMatches
        .filter((match) => match.currentText === targetText)
        .sort((left, right) => right.id - left.id)[0] || null;
    const nativeMatch =
      exactNativeMatch ||
      nativeMatches.sort((left, right) => right.id - left.id)[0] ||
      null;

    return {
      matches,
      nativeMatch,
    };
  }

  function tryDocumentStoreMutation(runtime, nativeMatch, targetText, nextText) {
    const documentStore = runtime.documentStore;
    if (!documentStore || typeof documentStore !== "object") {
      return null;
    }

    const initialNode = findNodeById(runtime.tree, nativeMatch.id);
    if (!initialNode) {
      return null;
    }

    function didPersistMutation() {
      const updatedNode = findNodeById(resolveRuntime(runtime).tree, nativeMatch.id);
      const updatedValue = readPropertyPath(updatedNode?.data?.properties || {}, nativeMatch.path);
      return updatedValue === nextText;
    }

    const candidateCalls = [
      {
        name: "documentStore.updateNodeProperty",
        invoke() {
          return invokeDocumentStoreMethod(
            documentStore,
            "updateNodeProperty",
            [
              [
                {
                  id: nativeMatch.id,
                  nodeId: nativeMatch.id,
                  path: nativeMatch.path,
                  propertyPath: nativeMatch.path,
                  value: nextText,
                  nextValue: nextText,
                },
              ],
              [nativeMatch.id, nativeMatch.path, nextText],
              [nativeMatch.id, nativeMatch.path, nextText, nativeMatch],
            ],
            didPersistMutation
          );
        },
      },
      {
        name: "documentStore.setNodeProperty",
        invoke() {
          return invokeDocumentStoreMethod(
            documentStore,
            "setNodeProperty",
            [
              [
                {
                  id: nativeMatch.id,
                  nodeId: nativeMatch.id,
                  path: nativeMatch.path,
                  propertyPath: nativeMatch.path,
                  value: nextText,
                  nextValue: nextText,
                },
              ],
              [nativeMatch.id, nativeMatch.path, nextText],
              [nativeMatch.id, nativeMatch.path, nextText, nativeMatch],
            ],
            didPersistMutation
          );
        },
      },
      {
        name: "documentStore.patchNodeProperty",
        invoke() {
          return invokeDocumentStoreMethod(
            documentStore,
            "patchNodeProperty",
            [
              [
                {
                  id: nativeMatch.id,
                  nodeId: nativeMatch.id,
                  path: nativeMatch.path,
                  propertyPath: nativeMatch.path,
                  value: nextText,
                  nextValue: nextText,
                },
              ],
              [nativeMatch.id, nativeMatch.path, nextText],
              [nativeMatch.id, nativeMatch.path, nextText, nativeMatch],
            ],
            didPersistMutation
          );
        },
      },
      {
        name: "documentStore.updateNode",
        invoke() {
          const nextNode = cloneNodeWithUpdatedText(initialNode, nativeMatch.path, nextText);
          return invokeDocumentStoreMethod(
            documentStore,
            "updateNode",
            [
              [
                {
                  id: nativeMatch.id,
                  nodeId: nativeMatch.id,
                  node: nextNode,
                  nextNode,
                  value: nextText,
                  propertyPath: nativeMatch.path,
                },
              ],
              [nativeMatch.id, nextNode],
              [nextNode],
            ],
            didPersistMutation
          );
        },
      },
      {
        name: "documentStore.patchNode",
        invoke() {
          const nextNode = cloneNodeWithUpdatedText(initialNode, nativeMatch.path, nextText);
          return invokeDocumentStoreMethod(
            documentStore,
            "patchNode",
            [
              [
                {
                  id: nativeMatch.id,
                  nodeId: nativeMatch.id,
                  node: nextNode,
                  nextNode,
                  value: nextText,
                  propertyPath: nativeMatch.path,
                },
              ],
              [nativeMatch.id, nextNode],
              [nextNode],
            ],
            didPersistMutation
          );
        },
      },
      {
        name: "documentStore.setDocument",
        invoke() {
          const currentDocument = documentStore.document;
          if (!currentDocument || typeof currentDocument !== "object") {
            return false;
          }

          const nextTree = cloneTreeWithUpdatedText(
            currentDocument.tree || runtime.tree,
            nativeMatch.id,
            nativeMatch.path,
            nextText
          );
          if (!nextTree) {
            return false;
          }

          const nextDocument = {
            ...currentDocument,
            tree: nextTree,
          };

          return invokeDocumentStoreMethod(
            documentStore,
            "setDocument",
            [[nextDocument]],
            didPersistMutation
          );
        },
      },
    ];

    for (const candidate of candidateCalls) {
      const methodName = candidate.name.split(".").pop();
      if (typeof documentStore[methodName] !== "function") {
        continue;
      }

      try {
        candidate.invoke();
      } catch (error) {
        continue;
      }

      if (didPersistMutation()) {
        markUnsavedChanges(runtime);
        return candidate.name;
      }
    }

    return null;
  }

  function tryInlineContentEditableMutation(runtime, nativeMatch, targetText, nextText) {
    const rootDocument = runtime.rootDocument;
    if (!rootDocument) {
      return null;
    }

    const documents = collectDocuments(rootDocument);
    for (const doc of documents) {
      const candidates =
        typeof doc.querySelectorAll === "function"
          ? doc.querySelectorAll(
              '[data-content-editable-property-path="content.content.text"]'
            )
          : [];

      for (const candidate of candidates) {
        const currentText = String(candidate?.textContent || "");
        if (!currentText.includes(targetText)) {
          continue;
        }

        const view = doc.defaultView || runtime.rootWindow || null;

        try {
          candidate.dispatchEvent(
            new view.MouseEvent("dblclick", {
              bubbles: true,
              cancelable: true,
              view,
            })
          );
          candidate.textContent = currentText.replace(targetText, nextText);
          candidate.dispatchEvent(
            new view.InputEvent("input", {
              bubbles: true,
              inputType: "insertText",
              data: nextText,
            })
          );
          candidate.dispatchEvent(
            new view.FocusEvent("blur", {
              bubbles: true,
            })
          );
        } catch (error) {
          continue;
        }

        const updatedNode = findNodeById(resolveRuntime(runtime).tree, nativeMatch.id);
        const updatedValue = readPropertyPath(
          updatedNode?.data?.properties || {},
          nativeMatch.path
        );

        if (updatedValue === currentText.replace(targetText, nextText)) {
          return "contenteditable-inline";
        }
      }
    }

    return null;
  }

  function mutateNativeTextNode(options, runtime) {
    const targetText = String(options?.targetText || "");
    const nextText = String(options?.nextText || "");
    const allowDirectMutation = options?.allowDirectMutation !== false;
    const resolvedRuntime = resolveRuntime(runtime);

    if (!resolvedRuntime.tree) {
      return {
        ok: false,
        reason: "missing-tree",
      };
    }

    const { matches, nativeMatch } = getNativeTextMatch(resolvedRuntime.tree, targetText);
    if (matches.length === 0) {
      return {
        ok: false,
        reason: "missing-target",
      };
    }

    if (!nativeMatch) {
      return {
        ok: false,
        reason: "htmlcode-only",
        matches,
      };
    }

    const mutationStrategy =
      tryDocumentStoreMutation(resolvedRuntime, nativeMatch, targetText, nextText) ||
      tryInlineContentEditableMutation(
        resolvedRuntime,
        nativeMatch,
        targetText,
        nextText
      ) ||
      "tree-direct";
    const treeAfterStoreMutation = resolveRuntime(resolvedRuntime).tree;
    const nativeNode = findNodeById(treeAfterStoreMutation, nativeMatch.id);
    const currentText = readPropertyPath(
      nativeNode?.data?.properties || {},
      nativeMatch.path
    );

    if (mutationStrategy === "tree-direct") {
      if (!allowDirectMutation) {
        return {
          ok: false,
          reason: "direct-mutation-disabled",
          matches,
        };
      }

      if (typeof currentText !== "string" || !currentText.includes(targetText)) {
        return {
          ok: false,
          reason: "mutation-failed",
          matches,
        };
      }

      writePropertyPath(
        nativeNode?.data?.properties || {},
        nativeMatch.path,
        currentText.replace(targetText, nextText)
      );
    }

    const updatedNode = findNodeById(resolveRuntime(resolvedRuntime).tree, nativeMatch.id);
    const updatedText = readPropertyPath(
      updatedNode?.data?.properties || {},
      nativeMatch.path
    );

    if (typeof updatedText !== "string" || !updatedText.includes(nextText)) {
      return {
        ok: false,
        reason: "mutation-failed",
        matches,
      };
    }

    return {
      ok: true,
      nativeNode: true,
      id: nativeMatch.id,
      type: nativeMatch.type,
      propertyPath: nativeMatch.path,
      originalText: nativeMatch.currentText,
      updatedText,
      matchCount: matches.length,
      mutationStrategy,
    };
  }

  return {
    getStoreTreeCandidate,
    findTextMatches,
    mutateNativeTextNode,
  };
});
