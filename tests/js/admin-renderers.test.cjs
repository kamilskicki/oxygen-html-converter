const assert = require("node:assert/strict");

const renderers = require("../../assets/js/lib/admin-renderers.js");

module.exports = function runAdminRenderersTests() {
  const lists = renderers.buildAuditLists({
    summary: {
      elements: 4,
      hasExtractedCss: true,
    },
    preserved: {
      customClasses: ["hero", "cta"],
      iconLibraries: ["lucide"],
      headAssets: {
        links: 2,
        scripts: 1,
        iconScripts: 1,
      },
    },
    transformed: {
      wrapInContainer: true,
      includeCssElement: false,
      inlineStyles: true,
      info: ["Converted counters to native elements."],
    },
    stripped: ["Inline scripts removed."],
    followUp: ["Check icon sizes."],
  });

  assert.ok(lists.preserved.some((entry) => entry.includes("custom class token")));
  assert.ok(lists.transformed.some((entry) => entry.includes("Elements converted: 4")));
  assert.deepEqual(lists.stripped, ["Inline scripts removed."]);
  assert.deepEqual(lists.followUp, ["Check icon sizes."]);

  assert.equal(
    renderers.formatErrorMessage(
      {
        message: "Conversion failed",
        errors: ["invalid node"],
        audit: {
          followUp: ["Try Safe Mode"],
        },
      },
      "fallback"
    ),
    "Conversion failed\n\nDetails: invalid node\n\nNext steps: Try Safe Mode"
  );

  assert.equal(
    renderers.buildJsonStatus({
      customClasses: ["hero", "cta"],
      audit: {
        summary: {
          elements: 9,
        },
      },
    }),
    "9 elements, 2 custom classes"
  );
};
