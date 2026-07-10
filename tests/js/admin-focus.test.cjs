const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");

module.exports = async function runAdminFocusTests() {
  const elements = new Map();

  function recordFor(selector) {
    if (!elements.has(selector)) {
      elements.set(selector, {
        selector,
        value: selector === "#oxy-html-input" ? "<div>Keyboard test</div>" : "",
        props: {},
        handlers: {},
        text: "",
        focusCount: 0,
        focus() {
          this.focusCount += 1;
        },
      });
    }
    return elements.get(selector);
  }

  class FakeCollection {
    constructor(record) {
      this.record = record;
      this[0] = record;
      this.length = 1;
    }

    on(event, handler) { this.record.handlers[event] = handler; return this; }
    addClass() { return this; }
    removeClass() { return this; }
    empty() { this.record.text = ""; return this; }
    append() { return this; }
    children() { return this; }
    remove() { return this; }
    is(query) { return query === ":checked" && !!this.record.props.checked; }
    prop(name, value) {
      if (arguments.length === 1) return this.record.props[name];
      this.record.props[name] = value;
      return this;
    }
    val(value) {
      if (arguments.length === 0) return this.record.value;
      this.record.value = value;
      return this;
    }
    text(value) {
      if (arguments.length === 0) return this.record.text;
      this.record.text = String(value);
      return this;
    }
    trigger(eventName) {
      if (this.record.handlers[eventName]) this.record.handlers[eventName]({ type: eventName });
      return this;
    }
  }

  function $(selector) {
    if (typeof selector === "string" && selector.startsWith("<")) {
      return new FakeCollection(recordFor(`generated-${elements.size}`));
    }
    return new FakeCollection(recordFor(String(selector)));
  }

  const requests = [];
  const window = {
    oxyHtmlConverterAdmin: {
      ajaxUrl: "/admin-ajax.php",
      nonce: "nonce",
      ui: {},
      strings: {
        previewReady: "Preview results ready.",
        conversionReady: "Conversion results ready.",
        resultError: "The request needs attention.",
      },
    },
    OxyHtmlConverterAdminClient: {
      createRequestClient() {
        return {
          request(action) {
            requests.push(action);
            return Promise.resolve({
              success: true,
              data: action === "oxy_html_convert_preview"
                ? { elementCount: 1, summary: { byType: {} }, audit: {} }
                : { json: "{}", stats: {}, audit: {} },
            });
          },
        };
      },
    },
    OxyHtmlConverterAdminRenderers: {
      buildAuditLists() { return { preserved: [], transformed: [], stripped: [], followUp: [] }; },
      buildJsonStatus() { return "1 element"; },
    },
    OxyHtmlConverterAdminState: {
      createAdminState() {
        let json = null;
        return {
          clearLastConvertedJson() { json = null; },
          setLastConvertedJson(value) { json = value; },
          hasLastConvertedJson() { return !!json; },
          getLastConvertedJson() { return json; },
        };
      },
    },
    setTimeout,
  };

  const source = fs.readFileSync(path.join(__dirname, "../../assets/js/admin.js"), "utf8");
  vm.runInNewContext(source, {
    window,
    document: { execCommand() {} },
    navigator: { clipboard: { writeText: () => Promise.resolve() } },
    jQuery: $,
    console,
  });

  elements.get("#oxy-preview-btn").handlers.click({ type: "click", detail: 0 });
  await new Promise((resolve) => setImmediate(resolve));

  assert.equal(requests[0], "oxy_html_convert_preview");
  assert.equal(elements.get("#oxy-preview-result").focusCount, 1);
  assert.equal(elements.get("#oxy-result-announcement").text, "Preview results ready.");

  elements.get("#oxy-convert-btn").handlers.click({ type: "click", detail: 1 });
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(elements.get("#oxy-json-result").focusCount, 0, "mouse activation must not move focus");

  elements.get("#oxy-html-input").handlers.keydown({
    type: "keydown",
    ctrlKey: true,
    metaKey: false,
    key: "Enter",
    preventDefault() {},
  });
  await new Promise((resolve) => setImmediate(resolve));

  assert.equal(requests[2], "oxy_html_convert");
  assert.equal(elements.get("#oxy-json-result").focusCount, 1);
  assert.equal(elements.get("#oxy-result-announcement").text, "Conversion results ready.");
};
