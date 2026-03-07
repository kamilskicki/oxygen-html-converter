const path = require("path");
const { pathToFileURL } = require("url");

function loadPlaywright() {
  const explicitModule = process.env.OXY_HTML_CONVERTER_PLAYWRIGHT_MODULE;

  if (explicitModule) {
    return require(explicitModule);
  }

  try {
    return require("playwright");
  } catch (error) {
    throw new Error(
      "Playwright is required for live visual capture. Install it with `npm install playwright` " +
        "or set OXY_HTML_CONVERTER_PLAYWRIGHT_MODULE to a resolvable module path."
    );
  }
}

function parseArgs(argv) {
  const positional = [];
  const options = {
    waitMs: 2500,
    settleTimeoutMs: 6000,
    quietWindowMs: 400,
  };

  for (const arg of argv) {
    if (!arg.startsWith("--")) {
      positional.push(arg);
      continue;
    }

    const [rawKey, rawValue] = arg.slice(2).split("=", 2);
    const value = rawValue === undefined ? "true" : rawValue;

    if (rawKey === "wait-ms") {
      options.waitMs = Math.max(0, Number.parseInt(value, 10) || 0);
    } else if (rawKey === "settle-timeout-ms") {
      options.settleTimeoutMs = Math.max(0, Number.parseInt(value, 10) || 0);
    } else if (rawKey === "quiet-window-ms") {
      options.quietWindowMs = Math.max(0, Number.parseInt(value, 10) || 0);
    }
  }

  if (positional.length < 4) {
    throw new Error(
      "usage: node tests/live/visual-capture.cjs <sourcePath> <frontendUrl> <outSource> <outFrontend> " +
        "[--wait-ms=2500] [--settle-timeout-ms=6000] [--quiet-window-ms=400]"
    );
  }

  return {
    sourcePath: positional[0],
    frontendUrl: positional[1],
    outSource: positional[2],
    outFrontend: positional[3],
    options,
  };
}

async function evaluateVisualState(page) {
  return page.evaluate(() => {
    const body = document.body;
    const loader = document.querySelector(".loader");
    const letters = Array.from(document.querySelectorAll(".letter"));
    const heroMarkers = [
      "heroLine",
      "heroDescriptor",
      "heroTagline",
      "heroCta",
    ];

    const loaderStyle = loader ? window.getComputedStyle(loader) : null;
    const loaderDone =
      !loader ||
      loader.classList.contains("done") ||
      loader.hidden ||
      loader.getAttribute("aria-hidden") === "true" ||
      loaderStyle.display === "none" ||
      loaderStyle.visibility === "hidden" ||
      loaderStyle.opacity === "0";

    const lettersReady =
      letters.length === 0 ||
      letters.every((el) => el.classList.contains("visible"));

    const markersReady = heroMarkers.every((id) => {
      const el = document.getElementById(id);
      return !el || el.classList.contains("visible");
    });

    return {
      title: document.title,
      bodyClass: body ? body.className : "",
      bodyReady: !body || !body.classList.contains("loading"),
      loaderPresent: !!loader,
      loaderDone,
      lettersCount: letters.length,
      lettersReady,
      heroMarkersReady: markersReady,
    };
  });
}

async function waitForVisualSettle(page, options) {
  if (options.waitMs > 0) {
    await page.waitForTimeout(options.waitMs);
  }

  const startedAt = Date.now();
  let lastState = await evaluateVisualState(page);

  while (Date.now() - startedAt < options.settleTimeoutMs) {
    if (
      lastState.bodyReady &&
      lastState.loaderDone &&
      lastState.lettersReady &&
      lastState.heroMarkersReady
    ) {
      if (options.quietWindowMs > 0) {
        await page.waitForTimeout(options.quietWindowMs);
      }

      return {
        settled: true,
        state: await evaluateVisualState(page),
      };
    }

    await page.waitForTimeout(200);
    lastState = await evaluateVisualState(page);
  }

  return {
    settled: false,
    state: lastState,
  };
}

async function capture(page, url, outFile, options) {
  await page.goto(url, { waitUntil: "load", timeout: 60000 });
  const settle = await waitForVisualSettle(page, options);
  await page.screenshot({ path: outFile, fullPage: true });

  const meta = await page.evaluate(() => ({
    title: document.title,
    width: document.documentElement.scrollWidth,
    height: document.documentElement.scrollHeight,
    bodyClasses: document.body.className,
  }));

  return {
    ...meta,
    settle,
  };
}

(async () => {
  const { chromium } = loadPlaywright();
  const { sourcePath, frontendUrl, outSource, outFrontend, options } = parseArgs(
    process.argv.slice(2)
  );

  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1800 },
    deviceScaleFactor: 1,
  });

  const sourcePage = await context.newPage();
  const frontendPage = await context.newPage();

  try {
    const sourceUrl = pathToFileURL(path.resolve(sourcePath)).href;
    const source = await capture(sourcePage, sourceUrl, outSource, options);
    const frontend = await capture(frontendPage, frontendUrl, outFrontend, options);

    process.stdout.write(
      JSON.stringify(
        { sourceUrl, frontendUrl, outSource, outFrontend, source, frontend },
        null,
        2
      ) + "\n"
    );
  } finally {
    await sourcePage.close();
    await frontendPage.close();
    await browser.close();
  }
})().catch((error) => {
  process.stderr.write(
    String(error && error.stack ? error.stack : error) + "\n"
  );
  process.exit(1);
});
