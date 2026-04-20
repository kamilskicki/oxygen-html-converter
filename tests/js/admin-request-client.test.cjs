const assert = require("node:assert/strict");

const adminClient = require("../../assets/js/lib/admin-request-client.js");

module.exports = function runAdminRequestClientTests() {
  assert.deepEqual(
    adminClient.buildRequestPayload(
      "oxy_html_convert",
      "nonce-1",
      "<div>Hello</div>",
      {
        wrapInContainer: true,
        includeCssElement: false,
        inlineStyles: true,
        safeMode: false,
        debugMode: false,
      },
      {
        buildConvertRequestFields(options) {
          return {
            wrapInContainer: options.wrapInContainer ? "true" : "false",
            includeCssElement: options.includeCssElement ? "true" : "false",
            inlineStyles: options.inlineStyles ? "true" : "false",
            safeMode: options.safeMode ? "true" : "false",
            debugMode: options.debugMode ? "true" : "false",
          };
        },
      }
    ),
    {
      action: "oxy_html_convert",
      nonce: "nonce-1",
      html: "<div>Hello</div>",
      wrapInContainer: "true",
      includeCssElement: "false",
      inlineStyles: "true",
      safeMode: "false",
      debugMode: "false",
    }
  );

  assert.deepEqual(
    adminClient.buildRequestFields(
      {
        wrapInContainer: 1,
        includeCssElement: 0,
        inlineStyles: "x",
        safeMode: "",
        debugMode: null,
      },
      null
    ),
    {
      wrapInContainer: true,
      includeCssElement: false,
      inlineStyles: true,
      safeMode: false,
      debugMode: false,
    }
  );
};
