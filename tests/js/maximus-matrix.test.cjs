const assert = require("node:assert/strict");

const liveGate = require("../live/run-live-gate.cjs");
const maximusMatrix = require("../live/run-maximus-matrix.cjs");

module.exports = async function runMaximusMatrixTests() {
  const parsedMatrix = maximusMatrix.parseArgs([
    "--skip-sync",
    "--live=all",
    "--live-fixture=\\Maximus\\maximus_transformacja_domu\\code.html",
    "--modes=native,windpress",
  ]);
  assert.equal(parsedMatrix.skipSync, true);
  assert.equal(parsedMatrix.live, "all");
  assert.equal(
    parsedMatrix.liveFixture,
    "Maximus/maximus_transformacja_domu/code.html"
  );
  assert.deepEqual(parsedMatrix.modes, ["native", "windpress"]);

  const parsedLive = liveGate.parseArgs([
    "--fixture=\\Maximus\\maximus_transformacja_domu\\code.html",
    "--class-mode=windpress",
  ]);
  assert.equal(parsedLive.fixture, "Maximus/maximus_transformacja_domu/code.html");
  assert.equal(parsedLive.classMode, "windpress");
  assert.equal(
    liveGate.buildEditedProofText("Native Maximus Live Proof", "perf-maximus"),
    "Native Maximus Live Proof Edited perf-maximus"
  );
  assert.equal(
    liveGate.extractEditabilityTargetText(
      "<body><section><h1>Hero title</h1><p>Fixture-specific copy for editability proof.</p></section></body>"
    ),
    "Fixture-specific copy for editability proof."
  );
  assert.equal(
    liveGate.extractEditabilityTargetText(
      '<body><div><a href="#">Diagnoza</a><a href="#">Umów konsultację</a></div><h1>Silniejszy ruch. Jasna droga. Jeden system dla całego domu.</h1></body>'
    ),
    "Silniejszy ruch. Jasna droga. Jeden system dla całego domu."
  );
  assert.equal(
    liveGate.extractEditabilityTargetText(
      '<body><main><section><h1>Native text headline</h1><p>Readable body copy with <strong>strong</strong> and <em>emphasized</em> inline text.</p></section></main></body>'
    ),
    "Native text headline"
  );
  assert.equal(
    liveGate.extractEditabilityTargetText(
      '<body><script>const hidden = "This text must never become the editability target.";</script\t\n data-invalid><p>Fixture-specific visible copy for editability proof.</p></body>'
    ),
    "Fixture-specific visible copy for editability proof."
  );
  assert.equal(
    liveGate.decodeHtmlEntities("&amp;lt;strong&amp;gt;"),
    "&lt;strong&gt;"
  );
  assert.equal(
    liveGate.htmlToVisibleText(
      "<script>Hidden script text</script\t\n data-invalid><p>Visible frontend text</p>"
    ),
    "Visible frontend text"
  );
  const focusedProof = liveGate.buildFocusedImportProof(
    "<body><main><h1>Krótki tytuł</h1><p>Fixture-specific copy for editability proof.</p></main></body>"
  );
  assert.equal(
    focusedProof.editabilityTargetText,
    "Fixture-specific copy for editability proof."
  );
  assert.deepEqual(focusedProof.expectedTexts, [
    "Native Maximus Live Proof",
    "Fixture-specific copy for editability proof.",
  ]);
  assert.match(
    focusedProof.importHtml,
    /Native Maximus Live Proof[\s\S]*Native Maximus Live Proof Editability Anchor[\s\S]*Fixture-specific copy for editability proof\./
  );
  assert.deepEqual(focusedProof.styleRoutingExpectation, {
    targetText: "Native Maximus Live Proof",
    expectedTypeNot: "OxygenElements\\HtmlCode",
    properties: {
      "design.spacing.spacing.padding.top.style": "var(--ohc-space-10px)",
      "design.size.width.style": "var(--ohc-measure-120px)",
      "design.typography.color": "var(--ohc-color-123456)",
    },
  });
  assert.equal(
    focusedProof.fixturePresenceText,
    "Fixture-specific copy for editability proof."
  );
  assert.throws(
    () => liveGate.buildFocusedImportProof("<body><h1>Too short</h1></body>"),
    /stable text candidate/
  );
  assert.equal(
    liveGate.isConvertedAssetRuntimeError("ReferenceError: tailwind is not defined"),
    true
  );

  assert.throws(
    () =>
      liveGate.assertPersistenceProof({
        pages: [{ id: 101, hasTreeJsonString: false }],
        selectorCount: 4,
        hasBreakdanceClassesJsonString: true,
      }),
    /Missing tree_json_string/
  );
  assert.throws(
    () =>
      liveGate.assertPersistenceProof({
        pages: [{ id: 101, hasTreeJsonString: true }],
        selectorCount: 0,
        hasBreakdanceClassesJsonString: true,
      }, {
        classMode: "native",
      }),
    /No Oxygen selector records were persisted/
  );
  liveGate.assertPersistenceProof({
    pages: [{ id: 101, hasTreeJsonString: true }],
    selectorCount: 4,
    hasBreakdanceClassesJsonString: false,
  }, {
    classMode: "native",
  });
  liveGate.assertPersistenceProof({
    pages: [{ id: 101, hasTreeJsonString: true }],
    selectorCount: 0,
    hasBreakdanceClassesJsonString: false,
  }, {
    classMode: "windpress",
  });
  assert.throws(
    () =>
      liveGate.assertPersistenceProof({
        pages: [{ id: 101, hasTreeJsonString: true }],
        selectorCount: 4,
        hasBreakdanceClassesJsonString: false,
      }, {
        classMode: "windpress",
        requiresBreakdanceClassesJsonString: true,
      }),
    /breakdance_classes_json_string was not persisted/
  );

  const visualFailures = [];
  maximusMatrix.assertVisual(
    "native",
    "Maximus/maximus_transformacja_domu/code.html",
    {
      ok: true,
      captures: [{
        capture: {
          source: { width: 1440, height: 2654, settle: { settled: true } },
          frontend: { width: 1440, height: 2920, settle: { settled: true } },
        },
      }],
    },
    visualFailures
  );
  assert.deepEqual(visualFailures, []);

  const visualWidthFailures = [];
  maximusMatrix.assertVisual(
    "native",
    "Maximus/maximus_transformacja_domu/code.html",
    {
      ok: true,
      captures: [{
        capture: {
          source: { width: 1440, height: 2654, settle: { settled: true } },
          frontend: { width: 1480, height: 2920, settle: { settled: true } },
        },
      }],
    },
    visualWidthFailures
  );
  assert.deepEqual(visualWidthFailures, [
    "native visual width drift for Maximus/maximus_transformacja_domu/code.html: source 1440x2654, frontend 1480x2920",
  ]);

  const failures = [];
  maximusMatrix.assertLive(
    "native",
    "Maximus/maximus_transformacja_domu/code.html",
    {
      ok: true,
      observations: { blocking: {} },
      persistence: {
        selectorCount: 10,
        pages: [{ id: 11, hasTreeJsonString: true }],
      },
      editabilityProof: {
        ok: true,
        nativeNode: true,
        type: "OxygenElements\\Text",
        updatedText: "Edited proof",
        persistedBuilderText: true,
        persistedBuilderNodeMatch: true,
        persistedFrontendText: true,
      },
      styleRoutingProof: {
        ok: true,
        type: "OxygenElements\\Heading",
        persistedBuilderNodeMatch: true,
      },
    },
    failures
  );
  assert.deepEqual(failures, []);

  const proofFailures = [];
  maximusMatrix.assertLive(
    "windpress",
    "Maximus/maximus_transformacja_domu/code.html",
    {
      ok: true,
      observations: { blocking: {} },
      persistence: {
        selectorCount: 0,
        pages: [{ id: 11, hasTreeJsonString: false }],
      },
      editabilityProof: {
        ok: true,
        nativeNode: false,
        type: "OxygenElements\\HtmlCode",
        updatedText: "",
        persistedBuilderText: false,
        persistedBuilderNodeMatch: false,
        persistedFrontendText: false,
      },
    },
    proofFailures
  );
  assert.deepEqual(proofFailures, [
    "windpress live gate missing tree_json_string for Maximus/maximus_transformacja_domu/code.html",
    "windpress live gate editability proof resolved to HtmlCode for Maximus/maximus_transformacja_domu/code.html",
    "windpress live gate editability proof did not persist updated text for Maximus/maximus_transformacja_domu/code.html",
    "windpress live gate editability proof did not survive builder reopen for Maximus/maximus_transformacja_domu/code.html",
    "windpress live gate editability proof did not survive builder reopen on the same node for Maximus/maximus_transformacja_domu/code.html",
    "windpress live gate editability proof did not reach frontend for Maximus/maximus_transformacja_domu/code.html",
    "windpress live gate missing focused style routing proof for Maximus/maximus_transformacja_domu/code.html",
  ]);
};
