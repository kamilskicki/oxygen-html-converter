const assert = require("node:assert/strict");

const builderEditability = require("../../assets/js/lib/builder-editability.js");

function createRuntimeTree(text) {
  return {
    id: 1,
    children: [
      {
        id: 11,
        data: {
          type: "OxygenElements\\Text",
          properties: {
            content: {
              content: {
                text,
              },
            },
          },
        },
        children: [],
      },
    ],
  };
}

function createInlineEditingRuntime(text) {
  const tree = createRuntimeTree(text);
  const candidate = {
    textContent: text,
    events: [],
    dispatchEvent(event) {
      this.events.push(event.type);
    },
  };
  const win = {
    MouseEvent: class MouseEvent {
      constructor(type) {
        this.type = type;
      }
    },
    InputEvent: class InputEvent {
      constructor(type) {
        this.type = type;
      }
    },
    FocusEvent: class FocusEvent {
      constructor(type) {
        this.type = type;
      }
    },
  };
  const doc = {
    defaultView: win,
    querySelectorAll(selector) {
      if (
        selector ===
        '[data-content-editable-property-path="content.content.text"]'
      ) {
        return [candidate];
      }

      if (selector === "iframe") {
        return [];
      }

      return [];
    },
  };

  candidate.dispatchEvent = function dispatchEvent(event) {
    this.events.push(event.type);
    if (event.type === "blur") {
      tree.children[0].data.properties.content.content.text = this.textContent;
    }
  };

  return {
    tree,
    candidate,
    rootDocument: doc,
    rootWindow: win,
  };
}

module.exports = async function runBuilderEditabilityTests() {
  {
    const tree = createRuntimeTree("Live Gate");
    const calls = [];
    const runtime = {
      tree,
      documentStore: {
        updateNodeProperty(payload) {
          calls.push(payload);
          tree.children[0].data.properties.content.content.text = payload.value;
        },
      },
    };

    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Live Gate",
        nextText: "Live Gate Edited",
      },
      runtime
    );

    assert.equal(result.ok, true);
    assert.equal(result.mutationStrategy, "documentStore.updateNodeProperty");
    assert.equal(result.updatedText, "Live Gate Edited");
    assert.equal(
      tree.children[0].data.properties.content.content.text,
      "Live Gate Edited"
    );
    assert.equal(calls.length, 1);
    assert.equal(calls[0].path, "content.content.text");
  }

  {
    const tree = createRuntimeTree("Legacy Store Gate");
    const calls = [];
    const runtime = {
      tree,
      documentStore: {
        updateNodeProperty(nodeId, propertyPath, value) {
          calls.push([nodeId, propertyPath, value]);
          tree.children[0].data.properties.content.content.text = value;
        },
      },
    };

    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Legacy Store Gate",
        nextText: "Legacy Store Gate Edited",
      },
      runtime
    );

    assert.equal(result.ok, true);
    assert.equal(result.mutationStrategy, "documentStore.updateNodeProperty");
    assert.equal(result.updatedText, "Legacy Store Gate Edited");
    assert.deepEqual(
      calls[calls.length - 1],
      [11, "content.content.text", "Legacy Store Gate Edited"]
    );
  }

  {
    const tree = createRuntimeTree("Legacy Node Gate");
    const calls = [];
    const runtime = {
      tree,
      documentStore: {
        updateNode(nodeId, nextNode) {
          calls.push([nodeId, nextNode.id]);
          tree.children[0] = nextNode;
        },
      },
    };

    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Legacy Node Gate",
        nextText: "Legacy Node Gate Edited",
      },
      runtime
    );

    assert.equal(result.ok, true);
    assert.equal(result.mutationStrategy, "documentStore.updateNode");
    assert.equal(result.updatedText, "Legacy Node Gate Edited");
    assert.deepEqual(calls[calls.length - 1], [11, 11]);
    assert.equal(
      tree.children[0].data.properties.content.content.text,
      "Legacy Node Gate Edited"
    );
  }

  {
    const tree = createRuntimeTree("Fallback Gate");
    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Fallback Gate",
        nextText: "Fallback Gate Edited",
      },
      { tree }
    );

    assert.equal(result.ok, true);
    assert.equal(result.mutationStrategy, "tree-direct");
    assert.equal(result.updatedText, "Fallback Gate Edited");
  }

  {
    const tree = createRuntimeTree("Blocked Gate");
    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Blocked Gate",
        nextText: "Blocked Gate Edited",
        allowDirectMutation: false,
      },
      { tree }
    );

    assert.equal(result.ok, false);
    assert.equal(result.reason, "direct-mutation-disabled");
    assert.equal(
      tree.children[0].data.properties.content.content.text,
      "Blocked Gate"
    );
  }

  {
    const runtime = createInlineEditingRuntime("Inline Gate");
    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Inline Gate",
        nextText: "Inline Gate Edited",
        allowDirectMutation: false,
      },
      runtime
    );

    assert.equal(result.ok, true);
    assert.equal(result.mutationStrategy, "contenteditable-inline");
    assert.equal(result.updatedText, "Inline Gate Edited");
    assert.deepEqual(runtime.candidate.events, ["dblclick", "input", "blur"]);
  }

  {
    const tree = {
      id: 1,
      children: [
        {
          id: 11,
          data: {
            type: "OxygenElements\\Text",
            properties: {
              content: {
                content: {
                  text: "Fixture-specific copy for editability proof.",
                },
              },
            },
          },
          children: [],
        },
        {
          id: 12,
          data: {
            type: "OxygenElements\\Text",
            properties: {
              content: {
                content: {
                  text: "Fixture-specific copy for editability proof. Extended suffix",
                },
              },
            },
          },
          children: [],
        },
      ],
    };
    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Fixture-specific copy for editability proof.",
        nextText: "Fixture-specific copy for editability proof. Edited",
      },
      { tree }
    );

    assert.equal(result.ok, true);
    assert.equal(result.id, 11);
    assert.equal(
      tree.children[0].data.properties.content.content.text,
      "Fixture-specific copy for editability proof. Edited"
    );
    assert.equal(
      tree.children[1].data.properties.content.content.text,
      "Fixture-specific copy for editability proof. Extended suffix"
    );
  }

  {
    const tree = {
      id: 1,
      children: [
        {
          id: 31,
          data: {
            type: "OxygenElements\\Text",
            properties: {
              content: {
                content: {
                  text: "Duplicate fixture proof text",
                },
              },
            },
          },
          children: [],
        },
        {
          id: 48,
          data: {
            type: "OxygenElements\\Text",
            properties: {
              content: {
                content: {
                  text: "Duplicate fixture proof text",
                },
              },
            },
          },
          children: [],
        },
      ],
    };

    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Duplicate fixture proof text",
        nextText: "Duplicate fixture proof text Edited",
      },
      { tree }
    );

    assert.equal(result.ok, true);
    assert.equal(result.id, 48);
    assert.equal(
      tree.children[0].data.properties.content.content.text,
      "Duplicate fixture proof text"
    );
    assert.equal(
      tree.children[1].data.properties.content.content.text,
      "Duplicate fixture proof text Edited"
    );
  }

  {
    const tree = {
      id: 1,
      children: [
        {
          id: 21,
          data: {
            type: "OxygenElements\\HtmlCode",
            properties: {
              content: {
                content: {
                  html_code:
                    "<section><h2>Native Maximus Live Proof</h2></section>",
                },
              },
            },
          },
          children: [],
        },
      ],
    };

    const result = builderEditability.mutateNativeTextNode(
      {
        targetText: "Native Maximus Live Proof",
        nextText: "Native Maximus Live Proof Edited",
        allowDirectMutation: false,
      },
      { tree }
    );

    assert.equal(result.ok, false);
    assert.equal(result.reason, "htmlcode-only");
    assert.equal(result.matches.length, 1);
    assert.equal(result.matches[0].type, "OxygenElements\\HtmlCode");
    assert.equal(result.matches[0].path, "content.content.html_code");
  }
};
