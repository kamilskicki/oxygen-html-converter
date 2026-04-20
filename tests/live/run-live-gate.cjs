const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const DEFAULT_CONTAINER =
  process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const DEFAULT_OUTPUT_DIR = path.resolve(process.cwd(), "artifacts", "live-gate");
const DEFAULT_ADMIN_USER = process.env.OXY_HTML_CONVERTER_ADMIN_USER || "admin";
const DEFAULT_ADMIN_PASSWORD =
  process.env.OXY_HTML_CONVERTER_ADMIN_PASSWORD || "admin";
const DEFAULT_TOOL_PATH =
  process.env.OXY_HTML_CONVERTER_TOOL_PATH ||
  "/wp-admin/tools.php?page=oxy-html-converter-tool";

function loadPlaywright() {
  const explicitModule = process.env.OXY_HTML_CONVERTER_PLAYWRIGHT_MODULE;

  if (explicitModule) {
    return require(explicitModule);
  }

  return require("playwright");
}

function runCommand(command, args, options = {}) {
  return execFileSync(command, args, {
    cwd: process.cwd(),
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    ...options,
  }).trim();
}

function logStep(message) {
  process.stdout.write(`[live-gate] ${message}\n`);
}

function isIgnorableRuntimeError(message) {
  return /angular is not defined/i.test(message);
}

function runNodeScript(scriptPath, args = []) {
  return runCommand("node", [scriptPath, ...args]);
}

function runDockerPhp(container, phpCode) {
  return runCommand("docker", ["exec", container, "php", "-r", phpCode], {
    timeout: 60000,
  });
}

function getHomeUrl(container) {
  return runDockerPhp(
    container,
    "require '/var/www/html/wp-load.php'; echo home_url();"
  );
}

function ensureAdminPassword(container, password) {
  runDockerPhp(
    container,
    `require '/var/www/html/wp-load.php'; wp_set_password(${JSON.stringify(
      password
    )}, 1); echo 'ok';`
  );
}

function buildSlug(baseName) {
  return `perf-${baseName}`
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
}

function loadFixtureSummary(jsonPath) {
  return JSON.parse(fs.readFileSync(jsonPath, "utf8"));
}

function loadFixturePages(container, summary) {
  const slugs = summary.fixtures.map((fixture) =>
    buildSlug(path.basename(fixture.fixture, ".html"))
  );
  const slugsJson = JSON.stringify(slugs).replace(/\\/g, "\\\\").replace(/'/g, "\\'");
  const php = `
    require '/var/www/html/wp-load.php';
    $wanted = json_decode('${slugsJson}', true);
    $posts = get_posts(['post_type' => 'page', 'numberposts' => 50, 'post_status' => ['publish', 'draft', 'private']]);
    $matches = [];
    foreach ($posts as $post) {
      if (in_array($post->post_name, $wanted, true)) {
        $matches[] = [
          'id' => (int) $post->ID,
          'slug' => $post->post_name,
          'title' => $post->post_title,
        ];
      }
    }
    echo wp_json_encode($matches);
  `;

  return JSON.parse(runDockerPhp(container, php));
}

async function login(page, baseUrl, username, password) {
  await page.goto(`${baseUrl}/wp-login.php`, {
    waitUntil: "load",
    timeout: 30000,
  });
  await page.fill("#user_login", username);
  await page.fill("#user_pass", password);
  await page.click("#wp-submit");
  await page.waitForURL(/\/wp-admin\/?/, { timeout: 30000 });
}

async function runAdminSmoke(page, baseUrl) {
  logStep("Running admin converter smoke");
  await page.goto(`${baseUrl}${DEFAULT_TOOL_PATH}`, {
    waitUntil: "load",
    timeout: 30000,
  });

  await page.click("#oxy-load-example-btn");
  await page.click("#oxy-preview-btn");
  await page.waitForFunction(() => {
    const preview = document.getElementById("oxy-preview-result");
    return preview && !preview.hidden;
  });

  const previewText = await page.locator("#oxy-preview-content").innerText();
  if (!/Total elements|Element types/i.test(previewText)) {
    throw new Error("Admin preview smoke did not render preview content.");
  }

  await page.click("#oxy-convert-btn");
  await page.waitForFunction(() => {
    const panel = document.getElementById("oxy-json-result");
    const output = document.getElementById("oxy-json-output");
    return panel && !panel.hidden && output && output.value.trim().length > 0;
  });

  const auditText = await page.locator("#oxy-audit-summary").innerText();
  if (!/Conversion audit/i.test(auditText)) {
    throw new Error("Admin convert smoke did not render audit output.");
  }
}

async function waitForBuilderReady(page) {
  await page.getByRole("button", { name: /^Save$/ }).waitFor({
    timeout: 60000,
  });
  await page.waitForTimeout(4000);
}

async function assertNoBuilderErrors(page, label) {
  const bodyText = await page.locator("body").innerText();
  if (/Validation Error|IO-TS decoding failed/i.test(bodyText)) {
    throw new Error(`${label} surfaced a builder validation error.`);
  }
}

async function runBuilderSmoke(page, baseUrl, fixtures) {
  if (!fixtures.length) {
    throw new Error("No maintained fixture pages were found for builder smoke.");
  }

  let modalSmokeRan = false;

  for (const fixture of fixtures) {
    logStep(`Opening builder for ${fixture.slug} (#${fixture.id})`);
    const builderUrl = `${baseUrl}/?oxygen=builder&id=${fixture.id}`;
    await page.goto(builderUrl, {
      waitUntil: "domcontentloaded",
      timeout: 60000,
    });
    await waitForBuilderReady(page);
    await assertNoBuilderErrors(page, `Builder open for ${fixture.slug}`);

    if (!modalSmokeRan) {
      logStep(`Running builder modal smoke on ${fixture.slug}`);
      const modalHookReady = await page
        .waitForFunction(
          () => typeof window.oxyHtmlConverterOpenModal === "function",
          null,
          { timeout: 60000 }
        )
        .then(() => true)
        .catch(() => false);

      if (modalHookReady) {
        await page.evaluate(() => {
          window.oxyHtmlConverterOpenModal();
        });
      } else {
        await page.keyboard.press("Control+Shift+H");
      }

      await page.locator("#oxy-html-import-input").waitFor({ timeout: 30000 });
      await page.fill(
        "#oxy-html-import-input",
        '<section class="live-gate-smoke"><h2>Live Gate</h2><p>Builder modal smoke content.</p></section>'
      );
      await page.click("#oxy-html-import-submit");
      await page.waitForFunction(() => {
        const modal = document.querySelector("#oxy-html-import-modal .oxy-html-modal-overlay");
        return modal && modal.style.display !== "block";
      });
      await page.waitForTimeout(5000);
      await assertNoBuilderErrors(page, `Builder modal import for ${fixture.slug}`);
      modalSmokeRan = true;
    }

    logStep(`Saving builder document for ${fixture.slug}`);
    await page.getByRole("button", { name: /^Save$/ }).click();
    await page.waitForTimeout(5000);
    logStep(`Reopening builder document for ${fixture.slug}`);
    await page.goto(builderUrl, {
      waitUntil: "domcontentloaded",
      timeout: 60000,
    });
    await waitForBuilderReady(page);
    await assertNoBuilderErrors(page, `Builder reopen for ${fixture.slug}`);
  }
}

async function main() {
  fs.mkdirSync(DEFAULT_OUTPUT_DIR, { recursive: true });

  logStep("Syncing plugin into Docker container");
  const syncResult = JSON.parse(
    runNodeScript(path.join("tests", "live", "sync-docker-plugin.cjs"))
  );
  const localFixtureDir = path.resolve(
    process.cwd(),
    "..",
    "..",
    "..",
    "..",
    "fixtures",
    "html"
  );

  logStep("Running fixture baseline parity suite");
  const fixtureBaselineResult = JSON.parse(
    runNodeScript(path.join("tests", "live", "run-fixture-baseline.cjs"), [
      `--output-dir=${path.relative(process.cwd(), path.join(DEFAULT_OUTPUT_DIR, "fixture-baseline"))}`,
      `--local-fixture-dir=${localFixtureDir}`,
    ])
  );

  const fixtureSummary = loadFixtureSummary(fixtureBaselineResult.jsonPath);
  const baseUrl = process.env.OXY_HTML_CONVERTER_BASE_URL || getHomeUrl(DEFAULT_CONTAINER);
  const fixtures = loadFixturePages(DEFAULT_CONTAINER, fixtureSummary);

  logStep(`Resolved base URL: ${baseUrl}`);
  logStep(`Resolved maintained fixtures: ${fixtures.map((fixture) => fixture.slug).join(", ")}`);
  ensureAdminPassword(DEFAULT_CONTAINER, DEFAULT_ADMIN_PASSWORD);
  logStep("Ensured admin credentials for live gate");

  const { chromium } = loadPlaywright();
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();
  const runtimeErrors = [];

  page.on("pageerror", (error) => {
    const message = String(error && error.message ? error.message : error);
    if (!isIgnorableRuntimeError(message)) {
      runtimeErrors.push(message);
    }
  });

  try {
    logStep("Logging into local WordPress admin");
    await login(page, baseUrl, DEFAULT_ADMIN_USER, DEFAULT_ADMIN_PASSWORD);
    await runAdminSmoke(page, baseUrl);
    await runBuilderSmoke(page, baseUrl, fixtures);
  } finally {
    await page.close();
    await browser.close();
  }

  if (runtimeErrors.length) {
    throw new Error(
      `Live gate captured runtime errors: ${runtimeErrors.join(" | ")}`
    );
  }

  const result = {
    ok: true,
    baseUrl,
    container: DEFAULT_CONTAINER,
    sync: syncResult,
    fixtureBaseline: fixtureBaselineResult,
    fixtures: fixtures,
    outputDir: DEFAULT_OUTPUT_DIR,
  };

  fs.writeFileSync(
    path.join(DEFAULT_OUTPUT_DIR, "summary.json"),
    JSON.stringify(result, null, 2)
  );

  process.stdout.write(JSON.stringify(result, null, 2) + "\n");
}

main().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + "\n");
  process.exit(1);
});
