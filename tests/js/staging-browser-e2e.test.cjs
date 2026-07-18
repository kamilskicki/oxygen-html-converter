const assert = require("node:assert/strict");

const stagingGate = require("../live/run-staging-browser-e2e.cjs");

module.exports = async function runStagingBrowserE2eTests() {
  const options = stagingGate.parseArgs([
    "--base-url=https://staging.example.test/",
    "--page-id=42",
    "--page-slug=codex-e2e-p0",
    `--artifact-sha256=${"a".repeat(64)}`,
    "--commit=1234567890abcdef",
  ]);

  options.adminUser = "ci-user";
  options.adminPassword = "ci-secret";
  stagingGate.validateOptions(options);

  assert.equal(options.baseUrl, "https://staging.example.test");
  assert.equal(options.pageId, 42);
  assert.equal(options.pageSlug, "codex-e2e-p0");

  assert.equal(
    stagingGate.isBuilderSaveRequest({
      method: () => "POST",
      url: () => "https://staging.example.test/wp-admin/admin-ajax.php",
      postData: () => "action=breakdance_save",
    }),
    true
  );

  assert.equal(
    stagingGate.isBuilderSaveRequest({
      method: () => "GET",
      url: () => "https://staging.example.test/wp-admin/admin-ajax.php",
      postData: () => "action=breakdance_save",
    }),
    false
  );

  assert.throws(
    () =>
      stagingGate.validateOptions({
        ...options,
        artifactSha256: "not-a-sha",
      }),
    /SHA-256/
  );
};
