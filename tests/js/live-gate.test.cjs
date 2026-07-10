const assert = require("node:assert/strict");

const liveGate = require("../live/run-live-gate.cjs");

module.exports = async function runLiveGateTests() {
  assert.equal(liveGate.parseArgs([]).slugPrefix, "live-gate-");

  assert.equal(
    liveGate.parseArgs(["--slug-prefix=qa-live-"]).slugPrefix,
    "qa-live-"
  );

  assert.equal(
    liveGate.parseArgs([]).siteKitManifest,
    "site-kit/manifest.json"
  );

  assert.equal(
    liveGate.parseArgs(["--skip-site-kit"]).skipSiteKit,
    true
  );

  assert.equal(
    liveGate.buildSlug("Maximus-maximus_transformacja_domu"),
    "perf-maximus-maximus-transformacja-domu"
  );

  assert.equal(
    liveGate.buildSlug("Maximus-maximus_transformacja_domu", "live-gate-"),
    "live-gate-maximus-maximus-transformacja-domu"
  );

  assert.deepEqual(
    liveGate.sortFixturePagesBySlugOrder(
      [
        { slug: "live-gate-native-no-code-08-fallback-css" },
        { slug: "live-gate-native-no-code-01-text" },
        { slug: "live-gate-native-no-code-02-image" },
      ],
      [
        "live-gate-native-no-code-01-text",
        "live-gate-native-no-code-02-image",
        "live-gate-native-no-code-08-fallback-css",
      ]
    ).map((fixture) => fixture.slug),
    [
      "live-gate-native-no-code-01-text",
      "live-gate-native-no-code-02-image",
      "live-gate-native-no-code-08-fallback-css",
    ]
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
    liveGate.isCanceledNavigationRequestFailure(
      "GET http://oxyconvo6.localhost/wp-content/plugins/oxygen-html-converter/assets/js/lib/builder-editability.js?ver=0.9.0-beta (net::ERR_ABORTED)"
    ),
    true
  );

  {
    const blocking = [];
    const ambient = [];
    liveGate.classifyObservation(
      "GET http://oxyconvo6.localhost/wp-content/plugins/oxygen-html-converter/assets/js/lib/builder-editability.js?ver=0.9.0-beta (net::ERR_ABORTED)",
      blocking,
      ambient
    );
    assert.deepEqual(blocking, []);
    assert.equal(ambient.length, 1);
  }

  {
    const blocking = [];
    const ambient = [];
    liveGate.classifyObservation(
      "POST http://oxyconvo6.localhost/wp-content/plugins/oxygen-html-converter/assets/js/converter.js (net::ERR_FAILED)",
      blocking,
      ambient
    );
    assert.equal(blocking.length, 1);
    assert.deepEqual(ambient, []);
  }

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

  const siteKitProof = {
    ok: true,
    ids: {
      page: 11,
      template: 12,
      header: 13,
      footer: 14,
      part: 15,
      post: 16,
    },
    import: {
      objects: {
        pages: [{}],
        templates: [{}],
        headers: [{}],
        footers: [{}],
        parts: [{}],
      },
    },
    siteOptions: {
      show_on_front: "page",
      page_on_front: 11,
    },
    menu: {
      locations: {
        primary: 3,
      },
      primaryMenuId: 3,
      items: [
        {
          type: "post_type",
          objectId: 11,
        },
      ],
    },
    templateSettings: {
      template: {
        type: "all-singles",
      },
      header: {
        type: "everywhere",
      },
      footer: {
        type: "everywhere",
      },
    },
    hasTree: {
      page: true,
      template: true,
      header: true,
      footer: true,
      part: true,
    },
    urls: {
      home: "http://oxyconvo6.localhost/",
      page: "http://oxyconvo6.localhost/home/",
      post: "http://oxyconvo6.localhost/post/",
    },
  };

  assert.doesNotThrow(() => liveGate.assertSiteKitImportProof(siteKitProof));
  assert.throws(
    () =>
      liveGate.assertSiteKitImportProof({
        ...siteKitProof,
        siteOptions: { show_on_front: "posts", page_on_front: 11 },
      }),
    /show_on_front/
  );
};
