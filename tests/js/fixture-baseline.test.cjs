const assert = require("node:assert/strict");

const fixtureBaseline = require("../live/run-fixture-baseline.cjs");

module.exports = async function runFixtureBaselineTests() {
  const parityArgs = fixtureBaseline.buildParityDockerArgs(
    {
      container: "wordpress-test",
      dockerPhpUser: "www-data:www-data",
      remoteArtifactsDir: "/tmp/parity output",
    },
    "/var/www/html/Import Tests/fixture.html",
    "fixture-slug",
    "Fixture title"
  );

  assert.deepEqual(parityArgs.slice(0, 4), [
    "exec",
    "--user",
    "www-data:www-data",
    "wordpress-test",
  ]);
  assert.equal(parityArgs[4], "php");
  assert.equal(parityArgs[5], "/var/www/html/fixture-page-parity.php");
  assert.ok(parityArgs.includes("--page-slug=fixture-slug"));
  assert.ok(parityArgs.includes("--page-title=Fixture title"));

  const fixtureIndex = {
    failures: [],
    stableHtmlFixtures: [
      {
        relativeFixture: "fixture-a.html",
        expected: {
          codeBlocks: {
            total: 0,
            html: 0,
            css: 0,
            javascript: 0,
          },
          fallbackCount: 0,
          unsupportedCount: 0,
        },
      },
    ],
  };

  assert.deepEqual(
    fixtureBaseline.buildFixtureIndexFailures(
      [
        {
          fixture: "fixture-a.html",
          codeBlocks: {
            total: 0,
            html: 0,
            css: 0,
            javascript: 0,
          },
          fallbackCount: 0,
          unsupportedCount: 0,
        },
      ],
      fixtureIndex,
      {}
    ),
    []
  );

  const failures = fixtureBaseline.buildFixtureIndexFailures(
    [
      {
        fixture: "fixture-a.html",
        codeBlocks: {
          total: 1,
          html: 0,
          css: 1,
          javascript: 0,
        },
        fallbackCount: 1,
        unsupportedCount: 0,
      },
    ],
    fixtureIndex,
    {}
  );
  const messages = failures.map((failure) => failure.message).join("\n");

  assert.match(messages, /total code block count mismatch/);
  assert.match(messages, /css code block count mismatch/);
  assert.match(messages, /fallback count mismatch/);
};
