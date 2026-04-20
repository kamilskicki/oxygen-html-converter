const assert = require("node:assert/strict");

const clipboardUtils = require("../../assets/js/lib/clipboard-utils.js");

module.exports = function runClipboardUtilsTests() {
  assert.equal(clipboardUtils.isHtmlContent("<div>Hello</div>"), true);
  assert.equal(clipboardUtils.isHtmlContent('{"element":1}'), false);

  const htmlFirstClipboard = {
    getData(type) {
      if (type === "text/html") {
        return "<section><h1>Hello</h1></section>";
      }

      if (type === "text/plain" || type === "text") {
        return "Hello";
      }

      return "";
    },
  };

  const textFallbackClipboard = {
    getData(type) {
      if (type === "text/html") {
        return "";
      }

      if (type === "text/plain" || type === "text") {
        return "<div>Fallback</div>";
      }

      return "";
    },
  };

  assert.equal(
    clipboardUtils.extractHtmlFromClipboard(htmlFirstClipboard),
    "<section><h1>Hello</h1></section>"
  );
  assert.equal(
    clipboardUtils.extractHtmlFromClipboard(textFallbackClipboard),
    "<div>Fallback</div>"
  );
  assert.equal(clipboardUtils.extractHtmlFromClipboard(null), "");
};
