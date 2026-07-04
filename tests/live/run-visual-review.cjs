const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const DEFAULT_CONTAINER =
  process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const DEFAULT_OUTPUT_DIR = path.resolve(
  process.cwd(),
  "artifacts",
  "visual-review"
);

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

const DEFAULT_LOCAL_FIXTURE_DIR = resolveDefaultLocalFixtureDir();

function loadPlaywright() {
  const explicitModule = process.env.OXY_HTML_CONVERTER_PLAYWRIGHT_MODULE;

  if (explicitModule) {
    return require(explicitModule);
  }

  return require("playwright");
}

function parseArgs(argv) {
  const options = {
    container: DEFAULT_CONTAINER,
    baseUrl: process.env.OXY_HTML_CONVERTER_BASE_URL || "",
    outputDir: DEFAULT_OUTPUT_DIR,
    localFixtureDir: DEFAULT_LOCAL_FIXTURE_DIR,
    fixture: null,
    refreshBaseline: process.env.OXY_HTML_CONVERTER_VISUAL_REFRESH_BASELINE !== "0",
    classMode: process.env.OXY_HTML_CONVERTER_CLASS_MODE || "native",
  };

  for (const arg of argv) {
    if (arg === "--no-refresh-baseline") {
      options.refreshBaseline = false;
      continue;
    }

    if (!arg.startsWith("--")) {
      continue;
    }

    const [rawKey, rawValue] = arg.slice(2).split("=", 2);
    const value = rawValue === undefined ? "true" : rawValue;

    if (rawKey === "container") {
      options.container = value;
    } else if (rawKey === "base-url") {
      options.baseUrl = value;
    } else if (rawKey === "output-dir") {
      options.outputDir = path.resolve(process.cwd(), value);
    } else if (rawKey === "local-fixture-dir") {
      options.localFixtureDir = path.resolve(process.cwd(), value);
    } else if (rawKey === "fixture") {
      options.fixture = normalizeFixturePath(value);
    } else if (rawKey === "refresh-baseline") {
      options.refreshBaseline = value === "true" || value === "1";
    } else if (rawKey === "class-mode") {
      options.classMode = value;
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

function runDockerPhp(container, phpCode) {
  return runCommand("docker", ["exec", container, "php", "-r", phpCode], {
    timeout: 60000,
  });
}

function logStep(message) {
  process.stdout.write(`[visual-review] ${message}\n`);
}

function getHomeUrl(container) {
  return runDockerPhp(
    container,
    "require '/var/www/html/wp-load.php'; echo home_url();"
  );
}

function buildSlug(baseName) {
  return `perf-${baseName}`
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
}

function normalizeFixturePath(value) {
  return String(value || "")
    .replace(/\\/g, "/")
    .replace(/^\/+/, "")
    .replace(/\/+/g, "/")
    .trim();
}

function fixtureNameForSlug(fixture) {
  return String(fixture)
    .replace(/\\/g, "/")
    .replace(/\/code\.html$/i, "")
    .replace(/\.html$/i, "")
    .replace(/\//g, "-");
}

function listMaintainedFixtures(localFixtureDir, focusedFixture = null) {
  if (focusedFixture) {
    const fixture = normalizeFixturePath(focusedFixture);
    const sourcePath = path.join(localFixtureDir, ...fixture.split("/"));
    if (!fs.existsSync(sourcePath)) {
      throw new Error(`Focused fixture does not exist: ${sourcePath}`);
    }

    return [fixture];
  }

  const fixtures = [];

  for (const entry of fs.readdirSync(localFixtureDir, { withFileTypes: true })) {
    if (entry.isFile() && /^design-[1-5]-.*\.html$/i.test(entry.name)) {
      fixtures.push(entry.name);
    }
  }

  const maximusDir = path.join(localFixtureDir, "Maximus");
  if (fs.existsSync(maximusDir)) {
    for (const entry of fs.readdirSync(maximusDir, { withFileTypes: true })) {
      if (!entry.isDirectory()) {
        continue;
      }

      const codePath = path.join(maximusDir, entry.name, "code.html");
      const screenPath = path.join(maximusDir, entry.name, "screen.png");
      if (fs.existsSync(codePath) && fs.existsSync(screenPath)) {
        fixtures.push(path.join("Maximus", entry.name, "code.html"));
      }
    }
  }

  return fixtures.sort();
}

function loadFixturePages(container, fixtureFiles) {
  const fixtures = fixtureFiles.map((fixture) => ({
    fixture,
    slug: buildSlug(fixtureNameForSlug(fixture)),
  }));
  const slugsJson = JSON.stringify(fixtures.map((fixture) => fixture.slug))
    .replace(/\\/g, "\\\\")
    .replace(/'/g, "\\'");
  const php = `
    require '/var/www/html/wp-load.php';
    $wanted = json_decode('${slugsJson}', true);
    $posts = get_posts(['post_type' => 'page', 'numberposts' => 50, 'post_status' => ['publish', 'draft', 'private']]);
    $matches = [];
    foreach ($posts as $post) {
      if (in_array($post->post_name, $wanted, true)) {
        $matches[$post->post_name] = [
          'id' => (int) $post->ID,
          'slug' => $post->post_name,
          'title' => $post->post_title,
          'url' => get_permalink($post),
        ];
      }
    }
    echo wp_json_encode($matches);
  `;
  const matches = JSON.parse(runDockerPhp(container, php));

  return fixtures.map((fixture) => {
    const match = matches[fixture.slug];
    if (!match) {
      throw new Error(`Missing maintained fixture page for slug ${fixture.slug}`);
    }

    return {
      fixture: fixture.fixture,
      slug: fixture.slug,
      id: match.id,
      title: match.title,
      url: match.url,
    };
  });
}

function runVisualCapture(sourcePath, frontendUrl, outSource, outFrontend) {
  return JSON.parse(
    runCommand("node", [
      path.join("tests", "live", "visual-capture.cjs"),
      sourcePath,
      frontendUrl,
      outSource,
      outFrontend,
    ])
  );
}

function refreshFixtureBaseline(options, fixture) {
  const args = [
    path.join("tests", "live", "run-fixture-baseline.cjs"),
    `--container=${options.container}`,
    `--output-dir=${path.join(options.outputDir, "baseline")}`,
    `--local-fixture-dir=${options.localFixtureDir}`,
    `--class-mode=${options.classMode}`,
    `--fixture=${fixture}`,
  ];

  runCommand("node", args, { timeout: 180000 });
}

function readPngSize(filePath) {
  if (!fs.existsSync(filePath)) {
    return null;
  }

  const buffer = fs.readFileSync(filePath);
  if (buffer.length < 24 || buffer.toString("ascii", 1, 4) !== "PNG") {
    return null;
  }

  return {
    width: buffer.readUInt32BE(16),
    height: buffer.readUInt32BE(20),
  };
}

function buildReferenceDiff(sourcePath, capture) {
  const screenPath = path.join(path.dirname(sourcePath), "screen.png");
  const referenceSize = readPngSize(screenPath);
  if (!referenceSize) {
    return null;
  }

  const frontendSize = {
    width: capture.frontend?.width ?? null,
    height: capture.frontend?.height ?? null,
  };

  return {
    referenceScreenPath: screenPath,
    referenceSize,
    frontendSize,
    dimensionDelta: {
      width: typeof frontendSize.width === "number" ? frontendSize.width - referenceSize.width : null,
      height: typeof frontendSize.height === "number" ? frontendSize.height - referenceSize.height : null,
    },
  };
}

async function runDesign1Smoke(browser, fixtureUrl) {
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 390, height: 844 },
  });
  const page = await context.newPage();
  const viewportHeight = 844;

  try {
    await page.goto(fixtureUrl, { waitUntil: "load", timeout: 60000 });
    await page.locator("#navToggle").waitFor({ timeout: 30000 });
    await page.locator("#navToggle").click({ force: true });
    await page.waitForFunction(() => {
      const navLinks = document.getElementById("navLinks");
      return navLinks && navLinks.classList.contains("open");
    });

    const targetHref = await page.locator('#navLinks a[href^="#"]').first().getAttribute("href");
    await page.click('#navLinks a[href^="#"]');
    await page.waitForTimeout(1200);

    const anchorScrollState = await page.evaluate((href) => {
      const target = href ? document.querySelector(href) : null;
      return {
        scrollY: window.scrollY,
        targetTop: target ? target.getBoundingClientRect().top : null,
      };
    }, targetHref);

    if (
      anchorScrollState.scrollY <= 0 &&
      !(
        typeof anchorScrollState.targetTop === "number" &&
        anchorScrollState.targetTop < viewportHeight
      )
    ) {
      throw new Error("Anchor navigation did not move the viewport on design-1.");
    }

    await page.evaluate(() => {
      window.scrollTo(0, document.body.scrollHeight * 0.4);
    });
    await page.waitForTimeout(1200);

    const revealVisibleCount = await page.locator(".reveal.is-visible").count();
    if (revealVisibleCount < 1) {
      throw new Error("Reveal-on-scroll markers did not become visible.");
    }

    const cta = page
      .locator('.hero__cta-row a[href="#contact"], a.btn[href="#contact"]')
      .first();
    await cta.waitFor({ timeout: 30000 });
    if (!(await cta.isVisible())) {
      throw new Error("Hero CTA is not visible on design-1.");
    }

    return {
      ok: true,
      revealVisibleCount,
      scrollY: anchorScrollState.scrollY,
      targetTop: anchorScrollState.targetTop,
    };
  } finally {
    await page.close();
    await context.close();
  }
}

async function runDesign3Smoke(browser, fixtureUrl) {
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();
  const viewportHeight = 1000;

  try {
    await page.goto(fixtureUrl, { waitUntil: "load", timeout: 60000 });
    await page.waitForFunction(() => {
      const loader = document.getElementById("loader");
      const bodyReady = !document.body.classList.contains("loading");
      const loaderDone = !loader || loader.classList.contains("done");
      const heroLine = document.getElementById("heroLine");
      const heroDescriptor = document.getElementById("heroDescriptor");
      const heroTagline = document.getElementById("heroTagline");
      const heroCta = document.getElementById("heroCta");

      return (
        bodyReady &&
        loaderDone &&
        heroLine &&
        heroLine.classList.contains("visible") &&
        heroDescriptor &&
        heroDescriptor.classList.contains("visible") &&
        heroTagline &&
        heroTagline.classList.contains("visible") &&
        heroCta &&
        heroCta.classList.contains("visible")
      );
    });

    const cta = page.locator("#heroCta");
    const targetHref = await cta.getAttribute("href");
    await cta.click({ force: true });
    await page.waitForTimeout(1200);

    const anchorScrollState = await page.evaluate((href) => {
      const target = href ? document.querySelector(href) : null;
      return {
        scrollY: window.scrollY,
        targetTop: target ? target.getBoundingClientRect().top : null,
      };
    }, targetHref);

    if (
      anchorScrollState.scrollY <= 0 &&
      !(
        typeof anchorScrollState.targetTop === "number" &&
        anchorScrollState.targetTop < viewportHeight
      )
    ) {
      throw new Error("Hero CTA did not move the viewport on design-3.");
    }

    return {
      ok: true,
      scrollY: anchorScrollState.scrollY,
      targetTop: anchorScrollState.targetTop,
    };
  } finally {
    await page.close();
    await context.close();
  }
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  options.baseUrl = options.baseUrl || getHomeUrl(options.container);
  fs.mkdirSync(options.outputDir, { recursive: true });

  const maintainedFixtures = listMaintainedFixtures(
    options.localFixtureDir,
    options.fixture
  );
  if (!maintainedFixtures.length) {
    throw new Error(
      `No maintained fixtures found in ${options.localFixtureDir}`
    );
  }

  if (options.refreshBaseline) {
    for (const fixture of maintainedFixtures) {
      logStep(`Refreshing baseline page for ${fixture}`);
      refreshFixtureBaseline(options, fixture);
    }
  }

  const fixturePages = loadFixturePages(options.container, maintainedFixtures);
  const captures = [];

  for (const fixturePage of fixturePages) {
    const sourcePath = path.join(
      options.localFixtureDir,
      ...fixturePage.fixture.replace(/\\/g, "/").split("/")
    );
    const fixtureDir = path.join(options.outputDir, fixturePage.slug);
    const outSource = path.join(fixtureDir, "source.png");
    const outFrontend = path.join(fixtureDir, "frontend.png");
    fs.mkdirSync(fixtureDir, { recursive: true });

    logStep(`Capturing ${fixturePage.fixture}`);
    const capture = runVisualCapture(
      sourcePath,
      fixturePage.url,
      outSource,
      outFrontend
    );
    captures.push({
      fixture: fixturePage.fixture,
      slug: fixturePage.slug,
      url: fixturePage.url,
      sourcePath,
      outSource,
      outFrontend,
      capture,
      referenceDiff: buildReferenceDiff(sourcePath, capture),
    });
  }

  const { chromium } = loadPlaywright();
  const browser = await chromium.launch({ headless: true });
  let interactionSmoke = {};

  try {
    const design1 = fixturePages.find((fixture) =>
      fixture.fixture.includes("design-1-noir-architect")
    );
    if (design1) {
      logStep("Running design-1 interaction smoke");
      interactionSmoke.design1 = await runDesign1Smoke(browser, design1.url);
    }

    const design3 = fixturePages.find((fixture) =>
      fixture.fixture.includes("design-3-kinetic-tokyo")
    );
    if (design3) {
      logStep("Running design-3 interaction smoke");
      interactionSmoke.design3 = await runDesign3Smoke(browser, design3.url);
    }
  } finally {
    await browser.close();
  }

  const result = {
    ok: true,
    baseUrl: options.baseUrl,
    container: options.container,
    outputDir: options.outputDir,
    maintainedFixtures,
    captures,
    interactionSmoke,
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
