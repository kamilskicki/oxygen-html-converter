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
const DEFAULT_SKIP_SYNC =
  process.env.OXY_HTML_CONVERTER_SKIP_SYNC === "1" ||
  process.env.OXY_HTML_CONVERTER_SKIP_SYNC === "true";

function loadPlaywright() {
  const explicitModule = process.env.OXY_HTML_CONVERTER_PLAYWRIGHT_MODULE;

  if (explicitModule) {
    return require(explicitModule);
  }

  return require("playwright");
}

function resolveDefaultLocalFixtureDir() {
  const candidates = [
    path.resolve(process.cwd(), "..", "..", "fixtures", "html"),
    path.resolve(process.cwd(), "..", "..", "..", "..", "fixtures", "html"),
  ];

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }

  return candidates[0];
}

function parseArgs(argv) {
  const options = {
    container: DEFAULT_CONTAINER,
    outputDir: DEFAULT_OUTPUT_DIR,
    baseUrl: process.env.OXY_HTML_CONVERTER_BASE_URL || "",
    adminUser: DEFAULT_ADMIN_USER,
    adminPassword: DEFAULT_ADMIN_PASSWORD,
    skipSync: DEFAULT_SKIP_SYNC,
  };

  for (const arg of argv) {
    if (arg === "--skip-sync") {
      options.skipSync = true;
      continue;
    }

    if (arg === "--sync") {
      options.skipSync = false;
      continue;
    }

    if (!arg.startsWith("--")) {
      continue;
    }

    const [rawKey, rawValue] = arg.slice(2).split("=", 2);
    const value = rawValue === undefined ? "true" : rawValue;

    if (rawKey === "container") {
      options.container = value;
    } else if (rawKey === "output-dir") {
      options.outputDir = path.resolve(process.cwd(), value);
    } else if (rawKey === "base-url") {
      options.baseUrl = value;
    } else if (rawKey === "admin-user") {
      options.adminUser = value;
    } else if (rawKey === "admin-password") {
      options.adminPassword = value;
    }
  }

  return options;
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

function isPluginSignal(text) {
  return /oxygen-html-converter|oxy[_-]html[_-]convert|oxy[_-]html[_-]converter|\/oxygen-html-converter\//i.test(
    text
  );
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
  const slugsJson = JSON.stringify(slugs)
    .replace(/\\/g, "\\\\")
    .replace(/'/g, "\\'");
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

function classifyObservation(text, blocking, ambient) {
  if (!text || isIgnorableRuntimeError(text)) {
    return;
  }

  if (isPluginSignal(text)) {
    blocking.push(text);
    return;
  }

  ambient.push(text);
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

async function runBuilderModalSmoke(page, fixtureSlug) {
  logStep(`Running builder modal smoke on ${fixtureSlug}`);
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
    const modal = document.querySelector(
      "#oxy-html-import-modal .oxy-html-modal-overlay"
    );
    return modal && modal.style.display !== "block";
  });
  await page.waitForTimeout(5000);
  await assertNoBuilderErrors(page, `Builder modal import for ${fixtureSlug}`);
}

async function writeClipboardHtml(page, html) {
  await page.evaluate(async (htmlInput) => {
    if (
      !navigator.clipboard ||
      typeof navigator.clipboard.write !== "function" ||
      typeof ClipboardItem === "undefined"
    ) {
      throw new Error("Clipboard HTML write is not available in this browser.");
    }

    const item = new ClipboardItem({
      "text/html": new Blob([htmlInput], { type: "text/html" }),
      "text/plain": new Blob([htmlInput], { type: "text/plain" }),
    });

    await navigator.clipboard.write([item]);
  }, html);
}

async function runBuilderPasteSmoke(page, fixtureSlug) {
  logStep(`Running builder paste smoke on ${fixtureSlug}`);
  const pasteHtml =
    '<section class="live-gate-paste"><h2>Paste Gate</h2><p>Builder paste smoke content.</p></section>';

  await page.locator("body").click({ position: { x: 24, y: 24 } });
  await page.evaluate(() => {
    if (
      document.activeElement &&
      typeof document.activeElement.blur === "function"
    ) {
      document.activeElement.blur();
    }
  });
  await writeClipboardHtml(page, pasteHtml);

  const toast = page.locator("#oxy-html-converter-toast");
  await page.keyboard.press("Control+V");
  await toast.waitFor({ timeout: 30000 });

  const toastText = await toast.innerText();
  if (!/converted|clipboard|ready/i.test(toastText)) {
    throw new Error(
      `Builder paste smoke did not report a converter toast for ${fixtureSlug}.`
    );
  }

  await page.waitForTimeout(5000);
  await assertNoBuilderErrors(page, `Builder paste for ${fixtureSlug}`);
}

async function runBuilderSmoke(page, baseUrl, fixtures) {
  if (!fixtures.length) {
    throw new Error("No maintained fixture pages were found for builder smoke.");
  }

  let modalSmokeRan = false;
  let pasteSmokeRan = false;

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
      await runBuilderModalSmoke(page, fixture.slug);
      modalSmokeRan = true;
    }

    if (!pasteSmokeRan) {
      await runBuilderPasteSmoke(page, fixture.slug);
      pasteSmokeRan = true;
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
  const options = parseArgs(process.argv.slice(2));
  fs.mkdirSync(options.outputDir, { recursive: true });

  let syncResult = {
    ok: true,
    skipped: options.skipSync,
  };

  if (options.skipSync) {
    logStep("Skipping source sync; using the installed plugin artifact");
  } else {
    logStep("Syncing plugin into Docker container");
    syncResult = JSON.parse(
      runNodeScript(path.join("tests", "live", "sync-docker-plugin.cjs"))
    );
  }

  const localFixtureDir = resolveDefaultLocalFixtureDir();

  logStep("Running fixture baseline parity suite");
  const fixtureBaselineResult = JSON.parse(
    runNodeScript(path.join("tests", "live", "run-fixture-baseline.cjs"), [
      `--output-dir=${path.relative(
        process.cwd(),
        path.join(options.outputDir, "fixture-baseline")
      )}`,
      `--local-fixture-dir=${localFixtureDir}`,
      `--container=${options.container}`,
    ])
  );

  const fixtureSummary = loadFixtureSummary(fixtureBaselineResult.jsonPath);
  const baseUrl = options.baseUrl || getHomeUrl(options.container);
  const fixtures = loadFixturePages(options.container, fixtureSummary);

  logStep(`Resolved base URL: ${baseUrl}`);
  logStep(
    `Resolved maintained fixtures: ${fixtures
      .map((fixture) => fixture.slug)
      .join(", ")}`
  );
  ensureAdminPassword(options.container, options.adminPassword);
  logStep("Ensured admin credentials for live gate");

  const blockingObservations = {
    pageErrors: [],
    consoleErrors: [],
    requestFailures: [],
  };
  const ambientObservations = {
    pageErrors: [],
    consoleErrors: [],
    requestFailures: [],
  };

  const { chromium } = loadPlaywright();
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1000 },
  });

  try {
    await context.grantPermissions(["clipboard-read", "clipboard-write"], {
      origin: new URL(baseUrl).origin,
    });
  } catch (error) {
    logStep(`Clipboard permissions were not granted explicitly: ${error.message}`);
  }

  const page = await context.newPage();

  page.on("pageerror", (error) => {
    const message = String(error && error.stack ? error.stack : error);
    classifyObservation(
      message,
      blockingObservations.pageErrors,
      ambientObservations.pageErrors
    );
  });

  page.on("console", (message) => {
    if (message.type() !== "error") {
      return;
    }

    const location = message.location();
    const formatted = [
      message.type(),
      message.text(),
      location && location.url
        ? `@ ${location.url}:${location.lineNumber || 0}:${
            location.columnNumber || 0
          }`
        : "",
    ]
      .filter(Boolean)
      .join(" ");

    classifyObservation(
      formatted,
      blockingObservations.consoleErrors,
      ambientObservations.consoleErrors
    );
  });

  page.on("requestfailed", (request) => {
    const postData = request.postData() || "";
    const failure = request.failure();
    const formatted = [
      request.method(),
      request.url(),
      failure && failure.errorText ? `(${failure.errorText})` : "",
      postData ? `postData=${postData}` : "",
    ]
      .filter(Boolean)
      .join(" ");

    classifyObservation(
      formatted,
      blockingObservations.requestFailures,
      ambientObservations.requestFailures
    );
  });

  try {
    logStep("Logging into local WordPress admin");
    await login(page, baseUrl, options.adminUser, options.adminPassword);
    await runAdminSmoke(page, baseUrl);
    await runBuilderSmoke(page, baseUrl, fixtures);
  } finally {
    await page.close();
    await browser.close();
  }

  const blockingEntries = Object.values(blockingObservations).flat();
  if (blockingEntries.length) {
    throw new Error(
      `Live gate captured plugin-attributable observations: ${blockingEntries.join(
        " | "
      )}`
    );
  }

  const result = {
    ok: true,
    baseUrl,
    container: options.container,
    skipSync: options.skipSync,
    sync: syncResult,
    fixtureBaseline: fixtureBaselineResult,
    fixtures,
    observations: {
      blocking: blockingObservations,
      ambient: ambientObservations,
    },
    outputDir: options.outputDir,
  };

  fs.writeFileSync(
    path.join(options.outputDir, "summary.json"),
    JSON.stringify(result, null, 2)
  );

  process.stdout.write(JSON.stringify(result, null, 2) + "\n");
}

main().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + "\n");
  process.exit(1);
});
