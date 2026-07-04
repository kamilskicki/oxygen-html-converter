const assert = require("node:assert/strict");

const liveGate = require("../live/run-live-gate.cjs");

module.exports = async function runLiveGateTests() {
  assert.equal(liveGate.parseArgs([]).slugPrefix, "live-gate-");

  assert.equal(
    liveGate.parseArgs(["--slug-prefix=qa-live-"]).slugPrefix,
    "qa-live-"
  );

  assert.equal(
    liveGate.buildSlug("Maximus-maximus_transformacja_domu"),
    "perf-maximus-maximus-transformacja-domu"
  );

  assert.equal(
    liveGate.buildSlug("Maximus-maximus_transformacja_domu", "live-gate-"),
    "live-gate-maximus-maximus-transformacja-domu"
  );

  assert.equal(
    liveGate.isBuilderReadySnapshot({
      hasSaveButton: true,
      saveButtonIdle: true,
      hasDocumentTree: true,
      hasEditabilityHelper: true,
    }),
    true
  );

  assert.equal(
    liveGate.isBuilderReadySnapshot({
      hasSaveButton: true,
      saveButtonIdle: true,
      hasDocumentTree: true,
      hasEditabilityHelper: false,
    }),
    false
  );

  assert.equal(
    liveGate.isBuilderSaveRequestDetails(
      "http://oxyconvo6.localhost/wp-admin/admin-ajax.php?_breakdance_doing_ajax=yes&_ajax_nonce=abc123",
      "POST",
      '------WebKitFormBoundary\r\nContent-Disposition: form-data; name="action"\r\n\r\nbreakdance_save\r\n------WebKitFormBoundary--'
    ),
    true
  );

  assert.equal(
    liveGate.isBuilderSaveRequestDetails(
      "http://oxyconvo6.localhost/wp-admin/admin-ajax.php?action=breakdance_save",
      "POST",
      ""
    ),
    true
  );

  assert.equal(
    liveGate.isBuilderSaveRequestDetails(
      "http://oxyconvo6.localhost/wp-admin/admin-ajax.php?_breakdance_doing_ajax=yes",
      "GET",
      'name="action"\r\n\r\nbreakdance_save'
    ),
    false
  );

  {
    const helper = {
      mutateNativeTextNode() {
        return { ok: true };
      },
    };
    const childWindow = {};
    const parentWindow = {
      OxyHtmlConverterBuilderEditability: helper,
    };
    childWindow.parent = parentWindow;
    childWindow.top = parentWindow;

    assert.equal(
      liveGate.resolveBuilderEditabilityHelperFromWindow(childWindow),
      helper
    );
  }

  {
    const helper = {
      mutateNativeTextNode() {
        return { ok: true };
      },
    };
    const rootWindow = {
      OxyHtmlConverterBuilderEditability: helper,
    };
    rootWindow.parent = rootWindow;
    rootWindow.top = rootWindow;

    assert.equal(
      liveGate.resolveBuilderEditabilityHelperFromWindow(rootWindow),
      helper
    );
  }

  assert.equal(
    liveGate.isBuilderSaveRequestDetails(
      "http://oxyconvo6.localhost/wp-admin/admin-ajax.php?_breakdance_doing_ajax=yes",
      "POST",
      'name="action"\r\n\r\nbreakdance_load_builder_elements'
    ),
    false
  );

  assert.equal(
    liveGate.isBuilderSaveResponsePayloadSuccessful(""),
    true
  );

  assert.equal(
    liveGate.isBuilderSaveResponsePayloadSuccessful(
      JSON.stringify({ success: true, status: "saved" })
    ),
    true
  );

  assert.equal(
    liveGate.isBuilderSaveResponsePayloadSuccessful(
      JSON.stringify({ success: false, message: "Save failed" })
    ),
    false
  );

  assert.equal(
    liveGate.isBuilderSaveResponsePayloadSuccessful(
      "Validation Error: malformed builder payload"
    ),
    false
  );
};
