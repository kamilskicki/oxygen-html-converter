const assert = require("node:assert/strict");

const builderClient = require("../../assets/js/lib/builder-client.js");
const converterOptions = require("../../assets/js/lib/converter-options.js");

module.exports = async function runBuilderClientTests() {
  assert.deepEqual(builderClient.buildRequestFields({}, converterOptions), {
    wrapInContainer: "true",
    includeCssElement: "false",
    inlineStyles: "true",
    safeMode: "true",
    allowExecutableCode: "false",
    strictNative: "false",
    debugMode: "false",
  });

  const originalFormData = global.FormData;
  const originalFetch = global.fetch;

  class FakeFormData {
    constructor() {
      this.fields = [];
    }

    append(key, value) {
      this.fields.push([key, value]);
    }
  }

  try {
    global.FormData = FakeFormData;

    let fetchCalls = 0;
    let lastBody = null;
    global.fetch = async function fetchStub(url, options) {
      fetchCalls += 1;
      assert.equal(url, "/wp-admin/admin-ajax.php");
      assert.equal(options.method, "POST");
      assert.equal(options.credentials, "same-origin");
      lastBody = options.body;

      return {
        async json() {
          return {
            success: true,
            data: { saved: 1, total: 3 },
          };
        },
      };
    };

    const result = await builderClient.saveSelectorPayload(
      {
        ajaxUrl: "/wp-admin/admin-ajax.php",
        nonce: "nonce-1",
      },
      {
        selectors: [{ id: "selector-1", name: "card" }],
        collections: ["Imported HTML"],
      }
    );

    assert.deepEqual(result, { saved: 1, total: 3 });
    assert.equal(fetchCalls, 1);
    assert.deepEqual(lastBody.fields.slice(0, 2), [
      ["action", "oxy_html_save_selectors"],
      ["nonce", "nonce-1"],
    ]);
    assert.equal(lastBody.fields[2][0], "selectorPayload");
    assert.deepEqual(JSON.parse(lastBody.fields[2][1]).selectors, [
      { id: "selector-1", name: "card" },
    ]);

    const emptyResult = await builderClient.saveSelectorPayload(
      {
        ajaxUrl: "/wp-admin/admin-ajax.php",
        nonce: "nonce-1",
      },
      { selectors: [] }
    );
    assert.deepEqual(emptyResult, { saved: 0 });
    assert.equal(fetchCalls, 1);
  } finally {
    global.FormData = originalFormData;
    global.fetch = originalFetch;
  }
};
