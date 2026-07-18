const fs = require("fs");
const path = require("path");
const { chromium } = require("playwright");

const MARKERS = [
  "INLINE_WINS",
  "STYLESHEET_IMPORTANT_WINS",
  "INLINE_IMPORTANT_WINS",
  "Account",
  "Legal",
  "Welcome",
];

const EXPECTED_COLORS = {
  INLINE_WINS: "rgb(255, 136, 0)",
  STYLESHEET_IMPORTANT_WINS: "rgb(34, 34, 170)",
  INLINE_IMPORTANT_WINS: "rgb(0, 136, 136)",
};

function parseArgs(argv) {
  const options = {
    baseUrl: process.env.OXY_HTML_CONVERTER_BASE_URL || "",
    adminUser: process.env.OXY_HTML_CONVERTER_ADMIN_USER || "",
    adminPassword: process.env.OXY_HTML_CONVERTER_ADMIN_PASSWORD || "",
    pageId: 0,
    pageSlug: "codex-e2e-css-cascade-component-semantics",
    outputDir: path.resolve(process.cwd(), "artifacts", "staging-browser-e2e"),
    artifactSha256: process.env.OXY_HTML_CONVERTER_ARTIFACT_SHA256 || "",
    commit: process.env.OXY_HTML_CONVERTER_TEST_COMMIT || "",
  };

  for (const arg of argv) {
    if (!arg.startsWith("--")) {
      continue;
    }

    const [key, rawValue] = arg.slice(2).split("=", 2);
    const value = rawValue === undefined ? "" : rawValue;

    if (key === "base-url") {
      options.baseUrl = value;
    } else if (key === "page-id") {
      options.pageId = Number(value);
    } else if (key === "page-slug") {
      options.pageSlug = value;
    } else if (key === "output-dir") {
      options.outputDir = path.resolve(process.cwd(), value);
    } else if (key === "artifact-sha256") {
      options.artifactSha256 = value;
    } else if (key === "commit") {
      options.commit = value;
    }
  }

  options.baseUrl = String(options.baseUrl).replace(/\/+$/, "");
  return options;
}

function validateOptions(options) {
  if (!/^https?:\/\//i.test(options.baseUrl)) {
    throw new Error("A valid --base-url or OXY_HTML_CONVERTER_BASE_URL is required.");
  }

  if (!options.adminUser || !options.adminPassword) {
    throw new Error("Staging admin credentials must be supplied through environment variables.");
  }

  if (!Number.isInteger(options.pageId) || options.pageId <= 0) {
    throw new Error("A positive --page-id is required.");
  }

  if (!/^[a-z0-9-]+$/i.test(options.pageSlug)) {
    throw new Error("The staging page slug is invalid.");
  }

  if (options.artifactSha256 && !/^[a-f0-9]{64}$/i.test(options.artifactSha256)) {
    throw new Error("The artifact SHA-256 must contain exactly 64 hexadecimal characters.");
  }

  if (options.commit && !/^[a-f0-9]{7,40}$/i.test(options.commit)) {
    throw new Error("The tested commit must be a Git object ID.");
  }
}

async function login(page, options) {
  await page.goto(`${options.baseUrl}/wp-login.php`, {
    waitUntil: "load",
    timeout: 30000,
  });
  await page.fill("#user_login", options.adminUser);
  await page.fill("#user_pass", options.adminPassword);
  await page.click("#wp-submit");
  await page.waitForURL(/\/wp-admin\/?/, { timeout: 30000 });
}

async function runAdminSmoke(page, options) {
  await page.goto(
    `${options.baseUrl}/wp-admin/tools.php?page=oxy-html-converter-tool`,
    { waitUntil: "load", timeout: 30000 }
  );

  await page.click("#oxy-load-example-btn");
  await page.click("#oxy-preview-btn");
  await page.locator("#oxy-preview-result:not([hidden])").waitFor({ timeout: 30000 });
  const previewText = await page.locator("#oxy-preview-content").innerText();
  if (!/Total elements|Element types/i.test(previewText)) {
    throw new Error("The converter preview did not expose its element summary.");
  }

  await page.click("#oxy-convert-btn");
  await page.locator("#oxy-json-result:not([hidden])").waitFor({ timeout: 30000 });
  const outputLength = await page.locator("#oxy-json-output").inputValue();
  if (!outputLength.trim()) {
    throw new Error("The converter did not produce Oxygen JSON.");
  }

  return {
    preview: true,
    conversion: true,
    jsonLength: outputLength.length,
  };
}

async function assertFrontend(page, options, viewportName) {
  const response = await page.goto(`${options.baseUrl}/${options.pageSlug}/`, {
    waitUntil: "networkidle",
    timeout: 30000,
  });
  const status = response ? response.status() : 0;
  if (status !== 200) {
    throw new Error(`Frontend returned HTTP ${status}.`);
  }

  for (const marker of MARKERS) {
    await page.getByText(marker, { exact: true }).waitFor({ timeout: 15000 });
  }

  const colors = {};
  for (const [marker, expected] of Object.entries(EXPECTED_COLORS)) {
    const actual = await page
      .getByText(marker, { exact: true })
      .evaluate((element) => getComputedStyle(element).color);
    colors[marker] = actual;
    if (actual !== expected) {
      throw new Error(`${marker} resolved to ${actual}; expected ${expected}.`);
    }
  }

  const layout = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    brokenImages: Array.from(document.images).filter(
      (image) => image.complete && image.naturalWidth === 0
    ).length,
  }));
  if (layout.scrollWidth > layout.clientWidth + 1) {
    throw new Error(
      `${viewportName} frontend has horizontal overflow: ${layout.scrollWidth} > ${layout.clientWidth}.`
    );
  }
  if (layout.brokenImages > 0) {
    throw new Error(`${viewportName} frontend contains ${layout.brokenImages} broken images.`);
  }

  return { status, colors, layout };
}

function isBuilderSaveRequest(request) {
  if (request.method().toUpperCase() !== "POST") {
    return false;
  }

  const payload = request.postData() || "";
  return (
    /\/wp-admin\/admin-ajax\.php\b/i.test(request.url()) &&
    /(?:^|[?&])action=breakdance_save(?:&|$)|name="action"\s*\r?\n\r?\nbreakdance_save\b/i.test(
      payload
    )
  );
}

async function saveBuilder(page) {
  const saveButton = page.getByRole("button", { name: /^Save$/ }).first();
  await saveButton.waitFor({ timeout: 60000 });
  const responsePromise = page.waitForResponse(
    (response) => isBuilderSaveRequest(response.request()),
    { timeout: 30000 }
  );
  await saveButton.click({ timeout: 15000 });
  const response = await responsePromise;
  const payload = await response.text().catch(() => "");
  if (response.status() >= 400 || /IO-TS decoding failed|"success"\s*:\s*false/i.test(payload)) {
    throw new Error(`Builder save failed with HTTP ${response.status()}: ${payload.slice(0, 500)}`);
  }

  return { status: response.status(), payloadLength: payload.length };
}

async function assertBuilder(page, options) {
  const url = `${options.baseUrl}/?oxygen=builder&id=${options.pageId}`;
  await page.goto(url, { waitUntil: "domcontentloaded", timeout: 30000 });
  await page.getByRole("button", { name: /^Save$/ }).waitFor({ timeout: 60000 });
  const canvas = page.frameLocator("iframe");
  for (const marker of MARKERS) {
    await canvas.getByText(marker, { exact: true }).waitFor({ timeout: 60000 });
  }

  const bodyBeforeSave = await page.locator("body").innerText();
  if (/Validation Error|IO-TS decoding failed/i.test(bodyBeforeSave)) {
    throw new Error("Oxygen Builder exposed a document validation error.");
  }

  const save = await saveBuilder(page);
  await page.reload({ waitUntil: "domcontentloaded", timeout: 30000 });
  await page.getByRole("button", { name: /^Save$/ }).waitFor({ timeout: 60000 });
  for (const marker of MARKERS) {
    await page.frameLocator("iframe").getByText(marker, { exact: true }).waitFor({
      timeout: 60000,
    });
  }

  return { url, save, persistedAfterReload: true };
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  validateOptions(options);
  fs.mkdirSync(options.outputDir, { recursive: true });

  const observations = { pageErrors: [], consoleErrors: [], requestFailures: [] };
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();

  page.on("pageerror", (error) => observations.pageErrors.push(String(error)));
  page.on("console", (message) => {
    if (message.type() === "error") {
      observations.consoleErrors.push(message.text());
    }
  });
  page.on("requestfailed", (request) => {
    const failure = request.failure();
    const text = `${request.method()} ${request.url()} ${failure?.errorText || ""}`;
    if (!/ERR_ABORTED|Request canceled/i.test(text)) {
      observations.requestFailures.push(text);
    }
  });

  const result = {
    ok: false,
    generatedAt: new Date().toISOString(),
    baseUrl: options.baseUrl,
    pageId: options.pageId,
    pageSlug: options.pageSlug,
    testedCommit: options.commit || null,
    artifactSha256: options.artifactSha256 || null,
    admin: null,
    frontend: {},
    builder: null,
    observations,
  };

  try {
    await login(page, options);
    result.admin = await runAdminSmoke(page, options);

    await page.setViewportSize({ width: 1440, height: 1000 });
    result.frontend.desktop = await assertFrontend(page, options, "desktop");
    await page.screenshot({
      path: path.join(options.outputDir, "frontend-desktop.png"),
      fullPage: true,
    });

    await page.setViewportSize({ width: 390, height: 844 });
    result.frontend.mobile = await assertFrontend(page, options, "mobile");
    await page.screenshot({
      path: path.join(options.outputDir, "frontend-mobile.png"),
      fullPage: true,
    });

    await page.setViewportSize({ width: 1440, height: 1000 });
    result.builder = await assertBuilder(page, options);
    await page.screenshot({
      path: path.join(options.outputDir, "builder-after-reload.png"),
      fullPage: true,
    });

    const blocking = Object.values(observations).flat();
    if (blocking.length > 0) {
      throw new Error(`Browser observations were not clean: ${blocking.join(" | ")}`);
    }

    result.ok = true;
    fs.writeFileSync(
      path.join(options.outputDir, "result.json"),
      JSON.stringify(result, null, 2)
    );
    process.stdout.write(JSON.stringify(result, null, 2) + "\n");
  } catch (error) {
    result.error = String(error && error.stack ? error.stack : error);
    await page
      .screenshot({
        path: path.join(options.outputDir, "failure.png"),
        fullPage: true,
      })
      .catch(() => {});
    fs.writeFileSync(
      path.join(options.outputDir, "failure.json"),
      JSON.stringify(result, null, 2)
    );
    throw error;
  } finally {
    await page.close();
    await browser.close();
  }
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(String(error && error.stack ? error.stack : error) + "\n");
    process.exit(1);
  });
}

module.exports = {
  parseArgs,
  validateOptions,
  isBuilderSaveRequest,
};
