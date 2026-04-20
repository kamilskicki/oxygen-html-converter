const assert = require("node:assert/strict");

const builderPaste = require("../../assets/js/lib/builder-paste.js");

module.exports = async function runBuilderPasteTests() {
  assert.equal(
    builderPaste.extractClipboardHtml(
      {
        getData(type) {
          if (type === "text/html") {
            return "<section>HTML</section>";
          }

          return "";
        },
      },
      null
    ),
    "<section>HTML</section>"
  );

  const fakeDocument = {
    activeElement: {
      tagName: "DIV",
      getAttribute() {
        return "false";
      },
    },
  };
  assert.equal(builderPaste.canInterceptPasteTarget(fakeDocument), true);

  const fakeInputDocument = {
    activeElement: {
      tagName: "TEXTAREA",
      getAttribute() {
        return "false";
      },
    },
  };
  assert.equal(builderPaste.canInterceptPasteTarget(fakeInputDocument), false);

  const clipboardWrites = [];
  assert.equal(
    await builderPaste.fallbackToClipboard(
      {
        clipboard: {
          async writeText(value) {
            clipboardWrites.push(value);
          },
        },
      },
      '{"element":1}'
    ),
    true
  );
  assert.deepEqual(clipboardWrites, ['{"element":1}']);
};
