const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const DEFAULT_CONTAINER =
  process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const DEFAULT_OUTPUT_DIR = path.resolve(
  process.cwd(),
  "artifacts",
  "maximus-matrix"
);
const DEFAULT_LIVE_FIXTURE =
  "Maximus/maximus_transformacja_domu/code.html";
const MAX_VISUAL_WIDTH_DELTA_PX = 24;
const MAX_VISUAL_HEIGHT_DELTA_PX = 320;

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
    localFixtureDir: resolveDefaultLocalFixtureDir(),
    modes: ["native", "windpress"],
    skipSync: false,
    live: "focused",
    liveFixture: DEFAULT_LIVE_FIXTURE,
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
    } else if (rawKey === "local-fixture-dir") {
      options.localFixtureDir = path.resolve(process.cwd(), value);
    } else if (rawKey === "modes") {
      options.modes = value
        .split(",")
        .map((mode) => mode.trim())
        .filter(Boolean);
    } else if (rawKey === "live") {
      options.live = value;
    } else if (rawKey === "live-fixture") {
      options.liveFixture = normalizeFixturePath(value);
    }
  }

  return options;
}

function normalizeFixturePath(value) {
  return String(value || "")
    .replace(/\\/g, "/")
    .replace(/^\/+/, "")
    .replace(/\/+/g, "/")
    .trim();
}

function fixtureNameForSlug(fixture) {
  return normalizeFixturePath(fixture)
    .replace(/\/code\.html$/i, "")
    .replace(/\.html$/i, "")
    .replace(/\//g, "-");
}

function buildSlug(baseName) {
  return `perf-${baseName}`
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
}

function fixtureSlug(fixture) {
  return buildSlug(fixtureNameForSlug(fixture));
}

function logStep(message) {
  process.stdout.write(`[maximus-matrix] ${message}\n`);
}

function runCommand(command, args, options = {}) {
  return execFileSync(command, args, {
    cwd: process.cwd(),
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    timeout: 900000,
    ...options,
  }).trim();
}

function runNodeScript(scriptPath, args = []) {
  return runCommand("node", [scriptPath, ...args]);
}

function parseJsonOutput(output) {
  const starts = [];
  for (let index = 0; index < output.length; index++) {
    if (output[index] === "{") {
      starts.push(index);
    }
  }

  for (const start of starts) {
    const jsonText = extractBalancedJsonObject(output, start);
    if (jsonText === null) {
      continue;
    }

    try {
      return JSON.parse(jsonText);
    } catch (error) {
      // Keep scanning; live scripts print progress before and after their JSON payload.
    }
  }

  throw new Error(`Command did not emit parseable JSON: ${output.slice(0, 500)}`);
}

function extractBalancedJsonObject(text, start) {
  let depth = 0;
  let inString = false;
  let escaped = false;

  for (let index = start; index < text.length; index++) {
    const char = text[index];

    if (inString) {
      if (escaped) {
        escaped = false;
      } else if (char === "\\") {
        escaped = true;
      } else if (char === '"') {
        inString = false;
      }
      continue;
    }

    if (char === '"') {
      inString = true;
      continue;
    }

    if (char === "{") {
      depth += 1;
    } else if (char === "}") {
      depth -= 1;
      if (depth === 0) {
        return text.slice(start, index + 1);
      }
    }
  }

  return null;
}

function listMaximusFixtures(localFixtureDir) {
  const maximusDir = path.join(localFixtureDir, "Maximus");
  if (!fs.existsSync(maximusDir)) {
    throw new Error(`Maximus fixture directory does not exist: ${maximusDir}`);
  }

  const fixtures = [];
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

  return fixtures.sort();
}

function runBaseline(options, mode, fixture) {
  const outDir = path.join(
    options.outputDir,
    "baseline",
    mode,
    fixtureSlug(fixture)
  );
  logStep(`Baseline ${mode}: ${fixture}`);

  return parseJsonOutput(
    runNodeScript(path.join("tests", "live", "run-fixture-baseline.cjs"), [
      `--container=${options.container}`,
      `--local-fixture-dir=${options.localFixtureDir}`,
      `--output-dir=${outDir}`,
      `--class-mode=${mode}`,
      `--fixture=${fixture}`,
    ])
  );
}

function runVisual(options, mode, fixture) {
  const outDir = path.join(
    options.outputDir,
    "visual",
    mode,
    fixtureSlug(fixture)
  );
  logStep(`Visual ${mode}: ${fixture}`);

  return parseJsonOutput(
    runNodeScript(path.join("tests", "live", "run-visual-review.cjs"), [
      `--container=${options.container}`,
      `--local-fixture-dir=${options.localFixtureDir}`,
      `--output-dir=${outDir}`,
      `--class-mode=${mode}`,
      `--fixture=${fixture}`,
      "--no-refresh-baseline",
    ])
  );
}

function runLive(options, mode, fixture) {
  const outDir = path.join(
    options.outputDir,
    "live",
    mode,
    fixtureSlug(fixture)
  );
  logStep(`Live ${mode}: ${fixture}`);

  return parseJsonOutput(
    runNodeScript(path.join("tests", "live", "run-live-gate.cjs"), [
      "--skip-sync",
      `--container=${options.container}`,
      `--output-dir=${outDir}`,
      `--class-mode=${mode}`,
      `--fixture=${fixture}`,
      `--local-fixture-dir=${options.localFixtureDir}`,
    ])
  );
}

function assertBaseline(mode, fixture, result, failures) {
  if (!result.ok) {
    failures.push(`${mode} baseline failed for ${fixture}`);
    return;
  }

  const summary = JSON.parse(fs.readFileSync(result.jsonPath, "utf8"));
  const entry = summary.fixtures?.[0] || {};
  const renderFailures = summary.aggregate?.renderProbeFailures || [];

  if (renderFailures.length > 0 || !entry.renderProbeOk) {
    failures.push(`${mode} baseline render probe failed for ${fixture}`);
  }

  if (mode === "native" && (!entry.selectorCount || entry.selectorCount < 1)) {
    failures.push(`${mode} baseline emitted no selectors for ${fixture}`);
  }

  if (
    mode === "native" &&
    Number(entry.unmirroredNativeResidualClassCount || 0) > 0
  ) {
    failures.push(
      `${mode} baseline left unmirrored residual native classes for ${fixture}: ${entry.unmirroredNativeResidualClassCount}`
    );
  }

  if ((entry.htmlCode || 0) > 8) {
    failures.push(`${mode} baseline HtmlCode budget exceeded for ${fixture}: ${entry.htmlCode}`);
  }

  if ((entry.cssCode || 0) > 2) {
    failures.push(`${mode} baseline CssCode budget exceeded for ${fixture}: ${entry.cssCode}`);
  }

  if (mode === "native" && (entry.mappedUtilityResidualCount || 0) > 0) {
    failures.push(
      `native baseline has mapped utility residuals for ${fixture}: ${entry.mappedUtilityResidualCount}`
    );
  }
}

function assertVisual(mode, fixture, result, failures) {
  if (!result.ok) {
    failures.push(`${mode} visual failed for ${fixture}`);
    return;
  }

  const capture = result.captures?.[0]?.capture;
  const source = capture?.source || {};
  const frontend = capture?.frontend || {};

  if (!source.settle?.settled) {
    failures.push(`${mode} source visual did not settle for ${fixture}`);
  }

  if (!frontend.settle?.settled) {
    failures.push(`${mode} frontend visual did not settle for ${fixture}`);
  }

  const widthDelta = Math.abs(Number(source.width || 0) - Number(frontend.width || 0));
  const heightDelta = Math.abs(Number(source.height || 0) - Number(frontend.height || 0));

  if (widthDelta > MAX_VISUAL_WIDTH_DELTA_PX) {
    failures.push(
      `${mode} visual width drift for ${fixture}: source ${source.width}x${source.height}, frontend ${frontend.width}x${frontend.height}`
    );
  }

  if (heightDelta > MAX_VISUAL_HEIGHT_DELTA_PX) {
    failures.push(
      `${mode} visual height drift for ${fixture}: source ${source.width}x${source.height}, frontend ${frontend.width}x${frontend.height}`
    );
  }
}

function assertLive(mode, fixture, result, failures) {
  if (!result.ok) {
    failures.push(`${mode} live gate failed for ${fixture}`);
    return;
  }

  const blocking = result.observations?.blocking || {};
  const blockingCount = Object.values(blocking).flat().length;
  if (blockingCount > 0) {
    failures.push(`${mode} live gate captured blocking observations for ${fixture}`);
  }

  if (
    mode === "native" &&
    (!result.persistence?.selectorCount || result.persistence.selectorCount < 1)
  ) {
    failures.push(`${mode} live gate persisted no selectors for ${fixture}`);
  }

  const missingTree = (result.persistence?.pages || []).filter(
    (page) => !page.hasTreeJsonString
  );
  if (missingTree.length > 0) {
    failures.push(`${mode} live gate missing tree_json_string for ${fixture}`);
  }

  if (!result.editabilityProof || result.editabilityProof.ok !== true) {
    failures.push(`${mode} live gate missing editability proof for ${fixture}`);
    return;
  }

  if (
    !result.editabilityProof.nativeNode ||
    result.editabilityProof.type === "OxygenElements\\HtmlCode"
  ) {
    failures.push(`${mode} live gate editability proof resolved to HtmlCode for ${fixture}`);
  }

  if (
    typeof result.editabilityProof.updatedText !== "string" ||
    result.editabilityProof.updatedText.trim() === ""
  ) {
    failures.push(`${mode} live gate editability proof did not persist updated text for ${fixture}`);
  }

  if (!result.editabilityProof.persistedBuilderText) {
    failures.push(`${mode} live gate editability proof did not survive builder reopen for ${fixture}`);
  }

  if (!result.editabilityProof.persistedBuilderNodeMatch) {
    failures.push(`${mode} live gate editability proof did not survive builder reopen on the same node for ${fixture}`);
  }

  if (!result.editabilityProof.persistedFrontendText) {
    failures.push(`${mode} live gate editability proof did not reach frontend for ${fixture}`);
  }

  const styleRoutingProof =
    result.styleRoutingProof || result.editabilityProof.styleRoutingProof || null;
  if (!styleRoutingProof || styleRoutingProof.ok !== true) {
    failures.push(`${mode} live gate missing focused style routing proof for ${fixture}`);
  } else if (
    styleRoutingProof.type === "OxygenElements\\HtmlCode" ||
    !styleRoutingProof.persistedBuilderNodeMatch
  ) {
    failures.push(`${mode} live gate focused style routing proof did not persist on a native node for ${fixture}`);
  }
}

function liveFixturesForMode(options, fixtures) {
  if (options.live === "none" || options.live === "false" || options.live === "0") {
    return [];
  }

  if (options.live === "all") {
    return fixtures;
  }

  return [normalizeFixturePath(options.liveFixture)];
}

function main() {
  const options = parseArgs(process.argv.slice(2));
  fs.mkdirSync(options.outputDir, { recursive: true });

  if (!fs.existsSync(options.localFixtureDir)) {
    throw new Error(`Local fixture directory does not exist: ${options.localFixtureDir}`);
  }

  if (!options.skipSync) {
    logStep("Syncing plugin into Docker");
    parseJsonOutput(runNodeScript(path.join("tests", "live", "sync-docker-plugin.cjs")));
  }

  const fixtures = listMaximusFixtures(options.localFixtureDir);
  if (fixtures.length === 0) {
    throw new Error("No Maximus fixtures with code.html and screen.png were found.");
  }

  const summary = {
    ok: true,
    generatedAt: new Date().toISOString(),
    container: options.container,
    outputDir: options.outputDir,
    localFixtureDir: options.localFixtureDir,
    modes: options.modes,
    fixtures,
    live: options.live,
    results: {},
    failures: [],
  };

  for (const mode of options.modes) {
    summary.results[mode] = {
      baseline: {},
      visual: {},
      live: {},
    };

    for (const fixture of fixtures) {
      const baseline = runBaseline(options, mode, fixture);
      summary.results[mode].baseline[fixture] = baseline;
      assertBaseline(mode, fixture, baseline, summary.failures);

      const visual = runVisual(options, mode, fixture);
      summary.results[mode].visual[fixture] = visual;
      assertVisual(mode, fixture, visual, summary.failures);
    }

    for (const fixture of liveFixturesForMode(options, fixtures)) {
      const liveResult = runLive(options, mode, fixture);
      summary.results[mode].live[fixture] = liveResult;
      assertLive(mode, fixture, liveResult, summary.failures);
    }
  }

  summary.ok = summary.failures.length === 0;
  fs.writeFileSync(
    path.join(options.outputDir, "summary.json"),
    JSON.stringify(summary, null, 2)
  );

  process.stdout.write(JSON.stringify(summary, null, 2) + "\n");

  if (!summary.ok) {
    throw new Error(`Maximus matrix failed: ${summary.failures.join(" | ")}`);
  }
}

if (require.main === module) {
  main();
}

module.exports = {
  parseArgs,
  normalizeFixturePath,
  fixtureNameForSlug,
  buildSlug,
  fixtureSlug,
  assertBaseline,
  assertVisual,
  assertLive,
  liveFixturesForMode,
};
