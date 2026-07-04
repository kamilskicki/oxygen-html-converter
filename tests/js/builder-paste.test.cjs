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

  const originalDataTransfer = global.DataTransfer;
  const originalClipboardEvent = global.ClipboardEvent;
  try {
    global.DataTransfer = class {
      constructor() {
        this.data = {};
      }

      setData(type, value) {
        this.data[type] = value;
      }
    };
    global.ClipboardEvent = class {
      constructor(type, options) {
        this.type = type;
        this.clipboardData = options.clipboardData;
      }
    };

    let cancelledPasteEvent = null;
    assert.equal(
      builderPaste.dispatchConvertedPaste(
        {
          dispatchEvent(event) {
            cancelledPasteEvent = event;
            return false;
          },
        },
        '{"element":1}'
      ),
      false
    );
    assert.equal(cancelledPasteEvent.clipboardData.data["text/plain"], '{"element":1}');
    assert.equal(cancelledPasteEvent.clipboardData.data.text, '{"element":1}');

    assert.equal(
      builderPaste.dispatchConvertedPaste(
        {
          dispatchEvent() {
            return true;
          },
        },
        '{"element":1}'
      ),
      true
    );

    assert.equal(
      await builderPaste.dispatchConvertedPasteAfterDelay(
        {
          defaultView: {
            setTimeout(callback) {
              callback();
            },
          },
          dispatchEvent() {
            return false;
          },
        },
        '{"element":1}'
      ),
      false
    );
  } finally {
    global.DataTransfer = originalDataTransfer;
    global.ClipboardEvent = originalClipboardEvent;
  }
};
