const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const DEFAULT_CONTAINER = process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const DEFAULT_SSH_HOST = process.env.OXY_HTML_CONVERTER_SSH_HOST || "";
const DEFAULT_WORDPRESS_ROOT = process.env.OXY_HTML_CONVERTER_WORDPRESS_ROOT || "/var/www/html";
const DEFAULT_FIXTURE_DIR =
  process.env.OXY_HTML_CONVERTER_FIXTURE_DIR ||
  (DEFAULT_SSH_HOST ? "/tmp/oxy-html-converter-fixtures" : "/var/www/html/Import_Tests");
const DEFAULT_REMOTE_ARTIFACTS = process.env.OXY_HTML_CONVERTER_REMOTE_ARTIFACTS || "/tmp/oxy-parity-suite";
const DEFAULT_REMOTE_RUNNER =
  process.env.OXY_HTML_CONVERTER_REMOTE_RUNNER || "/tmp/fixture-page-parity.php";
const DEFAULT_OUTPUT_DIR = path.resolve(process.cwd(), "artifacts", "fixture-baseline");
const DEFAULT_SLUG_PREFIX = process.env.OXY_HTML_CONVERTER_BASELINE_SLUG_PREFIX || "perf-";
const DEFAULT_DOCKER_PHP_USER =
  process.env.OXY_HTML_CONVERTER_DOCKER_PHP_USER || "www-data:www-data";

function resolveDefaultLocalFixtureDir() {
  return path.resolve(process.cwd(), "fixtures", "html");
}

const DEFAULT_LOCAL_FIXTURE_DIR = resolveDefaultLocalFixtureDir();

function parseArgs(argv) {
  const options = {
    container: DEFAULT_CONTAINER,
    sshHost: DEFAULT_SSH_HOST,
    wordpressRoot: DEFAULT_WORDPRESS_ROOT,
    remoteRunnerPath: DEFAULT_REMOTE_RUNNER,
    fixtureDir: DEFAULT_FIXTURE_DIR,
    remoteArtifactsDir: DEFAULT_REMOTE_ARTIFACTS,
    outputDir: DEFAULT_OUTPUT_DIR,
    localFixtureDir: DEFAULT_LOCAL_FIXTURE_DIR,
    classMode: null,
    includeNested: false,
    fixture: null,
    slugPrefix: DEFAULT_SLUG_PREFIX,
    dockerPhpUser: DEFAULT_DOCKER_PHP_USER,
  };

  for (const arg of argv) {
    if (arg === "--include-nested" || arg === "--recursive") {
      options.includeNested = true;
      continue;
    }

    if (!arg.startsWith("--")) {
      continue;
    }

    const [rawKey, rawValue] = arg.slice(2).split("=", 2);
    const value = rawValue === undefined ? "true" : rawValue;

    if (rawKey === "container") {
      options.container = value;
    } else if (rawKey === "ssh-host") {
      options.sshHost = value;
    } else if (rawKey === "wordpress-root") {
      options.wordpressRoot = value;
    } else if (rawKey === "remote-runner") {
      options.remoteRunnerPath = value;
    } else if (rawKey === "fixture-dir") {
      options.fixtureDir = value;
    } else if (rawKey === "remote-artifacts-dir") {
      options.remoteArtifactsDir = value;
    } else if (rawKey === "output-dir") {
      options.outputDir = path.resolve(process.cwd(), value);
    } else if (rawKey === "local-fixture-dir") {
      options.localFixtureDir = path.resolve(process.cwd(), value);
    } else if (rawKey === "class-mode") {
      options.classMode = value;
    } else if (rawKey === "include-nested" || rawKey === "recursive") {
      options.includeNested = value === "true" || value === "1";
    } else if (rawKey === "fixture") {
      options.fixture = normalizeFixturePath(value);
    } else if (rawKey === "slug-prefix") {
      options.slugPrefix = value;
    } else if (rawKey === "docker-php-user") {
      options.dockerPhpUser = value;
    }
  }

  return options;
}

function runDocker(args) {
  return execFileSync("docker", args, {
    cwd: process.cwd(),
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  });
}

function isSshTarget(options) {
  return typeof options.sshHost === "string" && options.sshHost.trim() !== "";
}

function runSsh(options, command) {
  return execFileSync("ssh", [options.sshHost, command], {
    cwd: process.cwd(),
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  });
}

function runRemoteShell(options, command) {
  if (isSshTarget(options)) {
    return runSsh(options, command);
  }

  return runDocker(["exec", options.container, "sh", "-lc", command]);
}

function copyToRemote(options, localPath, remotePath) {
  if (isSshTarget(options)) {
    return execFileSync("scp", [localPath, `${options.sshHost}:${remotePath}`], {
      cwd: process.cwd(),
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    });
  }

  return runDocker(["cp", localPath, `${options.container}:${remotePath}`]);
}

function runDockerPhp(options, code) {
  if (isSshTarget(options)) {
    const wordpressRoot = String(options.wordpressRoot || DEFAULT_WORDPRESS_ROOT).replace(/\/+$/, "");
    const normalizedCode = String(code).replaceAll("/var/www/html", wordpressRoot);
    const encoded = Buffer.from(normalizedCode, "utf8").toString("base64");
    return runSsh(
      options,
      `sudo -u www-data php -r ${shellQuote(`eval(base64_decode('${encoded}'));`)}`
    ).trim();
  }

  return runDocker(["exec", options.container, "php", "-r", code]).trim();
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\"'\"'`)}'`;
}

function normalizeFixturePath(value) {
  return String(value || "")
    .replace(/\\/g, "/")
    .replace(/^\/+/, "")
    .replace(/\/+/g, "/")
    .trim();
}

function localFixturePath(options, relativeFixture) {
  return path.join(options.localFixtureDir, ...relativeFixture.split("/"));
}

function remoteFixturePath(options, relativeFixture) {
  return `${String(options.fixtureDir).replace(/\/+$/, "")}/${relativeFixture}`;
}

function ensureFixtureInContainer(options, relativeFixture) {
  const localPath = localFixturePath(options, relativeFixture);
  if (!fs.existsSync(localPath)) {
    throw new Error(`Focused local fixture does not exist: ${localPath}`);
  }

  const remotePath = remoteFixturePath(options, relativeFixture);
  const remoteDir = path.posix.dirname(remotePath);
  runRemoteShell(options, `mkdir -p ${shellQuote(remoteDir)}`);
  copyToRemote(options, localPath, remotePath);

  return remotePath;
}

function ensureParityScriptInContainer(options) {
  const localPath = path.join(process.cwd(), "tests", "live", "fixture-page-parity.php");
  if (!fs.existsSync(localPath)) {
    throw new Error(`Local parity script does not exist: ${localPath}`);
  }

  copyToRemote(options, localPath, options.remoteRunnerPath);
}

function listFixtures(options, nativeNoCodeContract = null, fixtureIndex = null) {
  if (options.fixture) {
    return [ensureFixtureInContainer(options, options.fixture)];
  }

  const maxDepth = options.includeNested ? "" : "-maxdepth 1 ";
  const output = runRemoteShell(
    options,
    `find ${shellQuote(options.fixtureDir)} ${maxDepth}-type f -name '*.html' | sort`
  );

  const fixtures = output
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);

  if (nativeNoCodeContract && Array.isArray(nativeNoCodeContract.fixtures)) {
    for (const fixture of nativeNoCodeContract.fixtures) {
      fixtures.push(ensureFixtureInContainer(options, fixture.relativeFixture));
    }
  }

  if (fixtureIndex && Array.isArray(fixtureIndex.stableHtmlFixtures)) {
    for (const fixture of fixtureIndex.stableHtmlFixtures) {
      fixtures.push(ensureFixtureInContainer(options, fixture.relativeFixture));
    }
  }

  return Array.from(new Set(fixtures)).sort();
}

function relativeFixturePath(options, fixturePath) {
  const root = String(options.fixtureDir).replace(/\/+$/, "");
  const normalized = String(fixturePath).replace(/\\/g, "/");

  if (normalized.startsWith(`${root}/`)) {
    return normalized.slice(root.length + 1);
  }

  return path.posix.basename(normalized);
}

function fixtureNameForSlug(relativeFixture) {
  return relativeFixture
    .replace(/\\/g, "/")
    .replace(/\/code\.html$/i, "")
    .replace(/\.html$/i, "")
    .replace(/\//g, "-");
}

function buildSlug(baseName, slugPrefix = DEFAULT_SLUG_PREFIX) {
  return `${slugPrefix}${baseName}`
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
}

function prepareRemoteArtifactsDir(options) {
  const owner = isSshTarget(options) ? "www-data:www-data" : options.dockerPhpUser;
  runRemoteShell(
    options,
    [
      `mkdir -p ${shellQuote(options.remoteArtifactsDir)}`,
      `chown -R ${shellQuote(owner)} ${shellQuote(options.remoteArtifactsDir)}`,
      `chmod 775 ${shellQuote(options.remoteArtifactsDir)}`,
    ].join(" && ")
  );
}

function buildParityDockerArgs(options, fixturePath, slug, title) {
  return [
    "exec",
    "--user",
    options.dockerPhpUser,
    options.container,
    "php",
    "/var/www/html/fixture-page-parity.php",
    fixturePath,
    options.remoteArtifactsDir,
    "--keep-post",
    "--replace-post",
    `--page-slug=${slug}`,
    `--page-title=${title}`,
  ];
}

function buildParitySshCommand(options, fixturePath, slug, title) {
  const args = [
    options.remoteRunnerPath,
    fixturePath,
    options.remoteArtifactsDir,
    "--keep-post",
    "--replace-post",
    `--page-slug=${slug}`,
    `--page-title=${title}`,
  ];

  return [
    "sudo -u www-data",
    `OXY_HTML_CONVERTER_WP_ROOT=${shellQuote(options.wordpressRoot)}`,
    "php",
    ...args.map(shellQuote),
  ].join(" ");
}

function loadReport(options, fixturePath) {
  const relativeFixture = relativeFixturePath(options, fixturePath);
  const fixtureName = fixtureNameForSlug(relativeFixture);
  const slug = buildSlug(fixtureName, options.slugPrefix);
  const title = `Fixture ${fixtureName}`;

  prepareRemoteArtifactsDir(options);
  const output = isSshTarget(options)
    ? runSsh(options, buildParitySshCommand(options, fixturePath, slug, title))
    : runDocker(buildParityDockerArgs(options, fixturePath, slug, title));

  const result = JSON.parse(output);
  const reportJson = runRemoteShell(options, `cat ${shellQuote(result.reportPath)}`);

  return {
    fixturePath,
    relativeFixture,
    fixtureName,
    benchmark: loadBenchmark(options, fixturePath),
    cli: result,
    report: JSON.parse(reportJson),
  };
}

function loadBenchmark(options, fixturePath) {
  const relativeFixture = relativeFixturePath(options, fixturePath);
  const localFixturePath = path.join(
    options.localFixtureDir,
    ...relativeFixture.split("/")
  );
  const output = execFileSync(
    "php",
    [path.join("tests", "live", "benchmark-fixture.php"), localFixturePath],
    {
      cwd: process.cwd(),
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
    }
  );

  return JSON.parse(output);
}

function loadNativeNoCodeContract(localFixtureDir) {
  const manifestCandidates = [
    path.join(localFixtureDir, "native-no-code", "manifest.json"),
    path.join(localFixtureDir, "manifest.json"),
  ];
  const manifestPath = manifestCandidates.find((candidate) => fs.existsSync(candidate));

  if (!manifestPath) {
    return {
      manifestPath: null,
      fixtures: [],
      fixturesByRelativePath: new Map(),
      failures: [
        {
          fixture: "native-no-code",
          message: "Native no-code fixture manifest is missing.",
        },
      ],
    };
  }

  const manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8"));
  const manifestDir = path.dirname(manifestPath);
  const fixtures = [];
  const fixturesByRelativePath = new Map();
  const coverage = new Set();
  const failures = [];

  for (const fixture of Array.isArray(manifest.fixtures) ? manifest.fixtures : []) {
    const file = normalizeFixturePath(fixture.file || "");
    if (!file) {
      failures.push({
        fixture: "native-no-code",
        message: "Native no-code fixture entry is missing file.",
      });
      continue;
    }

    const absolutePath = path.join(manifestDir, ...file.split("/"));
    const relativeFixture = normalizeFixturePath(path.relative(localFixtureDir, absolutePath));
    if (!fs.existsSync(absolutePath)) {
      failures.push({
        fixture: relativeFixture,
        message: "Native no-code fixture file is missing.",
      });
      continue;
    }

    for (const item of Array.isArray(fixture.coverage) ? fixture.coverage : []) {
      coverage.add(String(item));
    }

    const normalized = {
      ...fixture,
      file,
      absolutePath,
      relativeFixture,
      expected: fixture.expected && typeof fixture.expected === "object" ? fixture.expected : {},
    };
    fixtures.push(normalized);
    fixturesByRelativePath.set(relativeFixture, normalized);
  }

  for (const item of Array.isArray(manifest.requiredCoverage) ? manifest.requiredCoverage : []) {
    if (!coverage.has(String(item))) {
      failures.push({
        fixture: "native-no-code",
        message: `Native no-code fixture coverage is missing: ${String(item)}.`,
      });
    }
  }

  return {
    manifestPath,
    fixtures,
    fixturesByRelativePath,
    failures,
  };
}

function loadFixtureIndex(localFixtureDir) {
  const manifestPath = path.join(localFixtureDir, "fixture-index.json");
  if (!fs.existsSync(manifestPath)) {
    return {
      manifestPath: null,
      stableHtmlFixtures: [],
      supportingFixtures: [],
      requiredGapIds: [],
      failures: [
        {
          fixture: "fixture-index.json",
          message: "M8 fixture index is missing.",
        },
      ],
    };
  }

  const manifest = JSON.parse(fs.readFileSync(manifestPath, "utf8"));
  const stableHtmlFixtures = [];
  const supportingFixtures = [];
  const requiredGapIds = Array.isArray(manifest.requiredGapIds)
    ? manifest.requiredGapIds.map(String)
    : [];
  const coveredGapIds = new Set();
  const failures = [];
  const coreRoot = path.resolve(localFixtureDir, "..", "..");

  for (const fixture of Array.isArray(manifest.stableHtmlFixtures) ? manifest.stableHtmlFixtures : []) {
    const relativeFixture = normalizeFixturePath(fixture.fixture || "");
    if (!relativeFixture) {
      failures.push({
        fixture: "fixture-index.json",
        message: "M8 fixture index stable entry is missing fixture.",
      });
      continue;
    }

    const absolutePath = path.join(localFixtureDir, ...relativeFixture.split("/"));
    if (!fs.existsSync(absolutePath)) {
      failures.push({
        fixture: relativeFixture,
        message: "M8 indexed stable HTML fixture file is missing.",
      });
      continue;
    }

    for (const gapId of Array.isArray(fixture.gapIds) ? fixture.gapIds : []) {
      coveredGapIds.add(String(gapId));
    }

    stableHtmlFixtures.push({
      ...fixture,
      relativeFixture,
      absolutePath,
      expected: fixture.expected && typeof fixture.expected === "object" ? fixture.expected : {},
    });
  }

  for (const fixture of Array.isArray(manifest.supportingFixtures) ? manifest.supportingFixtures : []) {
    const relativeFixture = normalizeFixturePath(fixture.fixture || "");
    if (!relativeFixture) {
      failures.push({
        fixture: "fixture-index.json",
        message: "M8 fixture index supporting entry is missing fixture.",
      });
      continue;
    }

    const absolutePath = path.join(coreRoot, ...relativeFixture.split("/"));
    if (!fs.existsSync(absolutePath)) {
      failures.push({
        fixture: relativeFixture,
        message: "M8 indexed supporting fixture file is missing.",
      });
    }

    for (const gapId of Array.isArray(fixture.gapIds) ? fixture.gapIds : []) {
      coveredGapIds.add(String(gapId));
    }

    supportingFixtures.push({
      ...fixture,
      relativeFixture,
      absolutePath,
    });
  }

  for (const gapId of requiredGapIds) {
    if (!coveredGapIds.has(gapId)) {
      failures.push({
        fixture: "fixture-index.json",
        message: `M8 fixture index coverage is missing: ${gapId}.`,
      });
    }
  }

  return {
    manifestPath,
    stableHtmlFixtures,
    supportingFixtures,
    requiredGapIds,
    failures,
  };
}

function getCurrentClassMode(options) {
  return runDockerPhp(
    options,
    "require '/var/www/html/wp-load.php'; echo get_option('oxy_html_converter_class_mode', 'auto');"
  );
}

function setClassMode(options, classMode) {
  const encodedClassMode = Buffer.from(String(classMode), "utf8").toString("base64");
  runDockerPhp(
    options,
    `require '/var/www/html/wp-load.php'; update_option('oxy_html_converter_class_mode', base64_decode('${encodedClassMode}', true)); echo get_option('oxy_html_converter_class_mode', 'auto');`
  );
}

function safeRatio(numerator, denominator) {
  if (!denominator) {
    return null;
  }

  return Number((numerator / denominator).toFixed(3));
}

function summarizeEntry(entry) {
  const render = entry.report.renderProbe || {};
  const parity = render.parity || {};
  const structure = parity.topStructureDeltas || [];
  const residual = entry.report.output || {};
  const conversionSummary = entry.report.conversionSummary || {};
  const codeBlocks = conversionSummary.codeBlocks || {};
  const delta = entry.report.delta || {};

  return {
    fixture: entry.relativeFixture || path.basename(entry.fixturePath),
    convertTimeMs: entry.benchmark.convertTimeMs ?? null,
    renderProbeOk: Boolean(render.ok),
    domToElementRatio: delta.domToElementRatio ?? null,
    residualRatio: delta.residualClassRatio ?? null,
    renderedClassInflation: render.delta?.renderedClassToSourceClassRatio ?? null,
    topExtraStructureDelta: Array.isArray(structure)
      ? structure.filter((item) => Number(item.delta || 0) > 0).slice(0, 5)
      : [],
    selectorCount: delta.selectorCount ?? entry.report.summary?.selectorCount ?? null,
    metaClassRefCount: residual.metaClassRefCount ?? null,
    nativeResidualClassCount: residual.nativeResidualClassCount ?? null,
    unmirroredNativeResidualClassCount:
      residual.unmirroredNativeResidualClassCount ?? null,
    codeBlocks: {
      total: Number(codeBlocks.total ?? 0),
      html: Number(codeBlocks.html ?? residual.elementTypes?.["OxygenElements\\HtmlCode"] ?? 0),
      css: Number(codeBlocks.css ?? residual.elementTypes?.["OxygenElements\\CssCode"] ?? 0),
      javascript: Number(codeBlocks.javascript ?? residual.elementTypes?.["OxygenElements\\JavaScriptCode"] ?? 0),
    },
    htmlCode: Number(codeBlocks.html ?? residual.elementTypes?.["OxygenElements\\HtmlCode"] ?? 0),
    cssCode: Number(codeBlocks.css ?? residual.elementTypes?.["OxygenElements\\CssCode"] ?? 0),
    javascriptCode: Number(codeBlocks.javascript ?? residual.elementTypes?.["OxygenElements\\JavaScriptCode"] ?? 0),
    fallbackCount: Number(conversionSummary.fallbackCount ?? 0),
    hasFallbackCss: Boolean(conversionSummary.hasFallbackCss),
    mappedUtilityResidualCount: residual.mappedUtilityResidualCount ?? 0,
    selectorPersistence: entry.report.selectorPersistence || null,
    unsupportedCount: Array.isArray(entry.report.stats?.unsupportedItems)
      ? entry.report.stats.unsupportedItems.length
      : 0,
    unsupportedCategories: Array.isArray(entry.report.stats?.unsupportedItems)
      ? entry.report.stats.unsupportedItems
          .map((item) => (item && typeof item === "object" ? String(item.fallbackCategory || "") : ""))
          .filter(Boolean)
      : [],
    styleCategoryDeltas: parity.styleCategoryDeltas || {},
    topResidualClasses: Array.isArray(entry.report.topResidualClasses)
      ? entry.report.topResidualClasses.slice(0, 10)
      : Array.isArray(residual.topResidualClasses)
        ? residual.topResidualClasses.slice(0, 10)
        : [],
    warningCount: Array.isArray(entry.report.stats?.warnings)
      ? entry.report.stats.warnings.length
      : 0,
    reportPath: entry.cli.reportPath,
  };
}

function buildNativeNoCodeFailures(fixtures, nativeNoCodeContract, options) {
  const focusedFixture = options.fixture ? normalizeFixturePath(options.fixture) : null;
  const expectedFixtures = focusedFixture
    ? nativeNoCodeContract.fixtures.filter((fixture) => fixture.relativeFixture === focusedFixture)
    : nativeNoCodeContract.fixtures;
  const failures = focusedFixture && !focusedFixture.startsWith("native-no-code/")
    ? []
    : [...nativeNoCodeContract.failures];
  const entriesByRelativePath = new Map(fixtures.map((fixture) => [fixture.fixture, fixture]));

  for (const expectedFixture of expectedFixtures) {
    const entry = entriesByRelativePath.get(expectedFixture.relativeFixture);
    if (!entry) {
      failures.push({
        fixture: expectedFixture.relativeFixture,
        message: "Native no-code fixture was not included in the baseline run.",
      });
      continue;
    }

    failures.push(...assertNativeNoCodeEntry(entry, expectedFixture));
  }

  return failures;
}

function buildFixtureIndexFailures(fixtures, fixtureIndex, options) {
  const focusedFixture = options.fixture ? normalizeFixturePath(options.fixture) : null;
  const failures = focusedFixture ? [] : [...fixtureIndex.failures];
  const entriesByRelativePath = new Map(fixtures.map((fixture) => [fixture.fixture, fixture]));
  const expectedFixtures = focusedFixture
    ? fixtureIndex.stableHtmlFixtures.filter((fixture) => fixture.relativeFixture === focusedFixture)
    : fixtureIndex.stableHtmlFixtures;

  for (const expectedFixture of expectedFixtures) {
    const entry = entriesByRelativePath.get(expectedFixture.relativeFixture);
    if (!entry) {
      failures.push({
        fixture: expectedFixture.relativeFixture,
        message: "M8 indexed stable fixture was not included in the baseline run.",
      });
      continue;
    }

    const expected = expectedFixture.expected || {};
    const expectedCodeBlocks = expected.codeBlocks || {};
    const actualCodeBlocks = entry.codeBlocks || {
      total: Number(entry.htmlCode || 0) + Number(entry.cssCode || 0) + Number(entry.javascriptCode || 0),
      html: Number(entry.htmlCode || 0),
      css: Number(entry.cssCode || 0),
      javascript: Number(entry.javascriptCode || 0),
    };

    for (const key of ["total", "html", "css", "javascript"]) {
      if (!Object.prototype.hasOwnProperty.call(expectedCodeBlocks, key)) {
        continue;
      }

      if (Number(actualCodeBlocks[key] || 0) !== Number(expectedCodeBlocks[key])) {
        failures.push({
          fixture: expectedFixture.relativeFixture,
          message: `M8 fixture index ${key} code block count mismatch: ${Number(actualCodeBlocks[key] || 0)} !== ${Number(expectedCodeBlocks[key])}.`,
        });
      }
    }

    if (Object.prototype.hasOwnProperty.call(expected, "fallbackCount") && Number(entry.fallbackCount || 0) !== Number(expected.fallbackCount)) {
      failures.push({
        fixture: expectedFixture.relativeFixture,
        message: `M8 fixture index fallback count mismatch: ${Number(entry.fallbackCount || 0)} !== ${Number(expected.fallbackCount)}.`,
      });
    }

    if (Object.prototype.hasOwnProperty.call(expected, "unsupportedCount") && Number(entry.unsupportedCount || 0) !== Number(expected.unsupportedCount)) {
      failures.push({
        fixture: expectedFixture.relativeFixture,
        message: `M8 fixture index unsupported count mismatch: ${Number(entry.unsupportedCount || 0)} !== ${Number(expected.unsupportedCount)}.`,
      });
    }
  }

  return failures;
}

function assertNativeNoCodeEntry(entry, fixture) {
  const failures = [];
  const expected = fixture.expected || {};
  const expectedCodeBlocks = expected.visibleCodeBlocks || {};
  const actualCodeBlocks = entry.codeBlocks || {
    total: Number(entry.htmlCode || 0) + Number(entry.cssCode || 0) + Number(entry.javascriptCode || 0),
    html: Number(entry.htmlCode || 0),
    css: Number(entry.cssCode || 0),
    javascript: Number(entry.javascriptCode || 0),
  };
  const fallbackCssExpected = Boolean(expected.fallbackCss);

  for (const key of ["total", "html", "css", "javascript"]) {
    if (!Object.prototype.hasOwnProperty.call(expectedCodeBlocks, key)) {
      continue;
    }

    if (actualCodeBlocks[key] !== Number(expectedCodeBlocks[key])) {
      failures.push({
        fixture: entry.fixture,
        message: `Visible ${key} code block count mismatch: ${actualCodeBlocks[key]} !== ${Number(expectedCodeBlocks[key])}.`,
      });
    }
  }

  if (fixture.supported && (actualCodeBlocks.html > 0 || actualCodeBlocks.javascript > 0 || (!fallbackCssExpected && actualCodeBlocks.css > 0))) {
    failures.push({
      fixture: entry.fixture,
      message: "Supported native no-code fixture emitted a visible code block.",
    });
  }

  const expectedUnsupportedCount = Number(expected.unsupportedCount || 0);
  if (Number(entry.unsupportedCount || 0) !== expectedUnsupportedCount) {
    failures.push({
      fixture: entry.fixture,
      message: `Unsupported item count mismatch: ${Number(entry.unsupportedCount || 0)} !== ${expectedUnsupportedCount}.`,
    });
  }

  const expectedCategories = Array.isArray(expected.fallbackCategories)
    ? expected.fallbackCategories.map(String).sort()
    : [];
  const actualCategories = Array.isArray(entry.unsupportedCategories)
    ? entry.unsupportedCategories.map(String).sort()
    : [];
  if (JSON.stringify(actualCategories) !== JSON.stringify(expectedCategories)) {
    failures.push({
      fixture: entry.fixture,
      message: `Unsupported fallback categories mismatch: ${JSON.stringify(actualCategories)} !== ${JSON.stringify(expectedCategories)}.`,
    });
  }

  const actualFallbackCss = Boolean(entry.hasFallbackCss);
  if (actualFallbackCss !== fallbackCssExpected) {
    failures.push({
      fixture: entry.fixture,
      message: `Fallback CSS expectation mismatch: ${String(actualFallbackCss)} !== ${String(fallbackCssExpected)}.`,
    });
  }

  const minSelectors = Number(expected.minSelectors || 0);
  if (Number(entry.selectorCount || 0) < minSelectors) {
    failures.push({
      fixture: entry.fixture,
      message: `Selector count below expectation: ${Number(entry.selectorCount || 0)} < ${minSelectors}.`,
    });
  }

  return failures;
}

function buildAggregate(fixtures) {
  const aggregate = {
    fixtureCount: fixtures.length,
    renderProbeFailures: fixtures.filter((fixture) => !fixture.renderProbeOk).map((fixture) => fixture.fixture),
    averageConvertTimeMs: null,
    averageResidualRatio: null,
    averageRenderedClassInflation: null,
    worstResidualRatio: null,
    worstRenderedClassInflation: null,
  };

  const convertTimes = fixtures.map((fixture) => fixture.convertTimeMs).filter((value) => typeof value === "number");
  const residualRatios = fixtures.map((fixture) => fixture.residualRatio).filter((value) => typeof value === "number");
  const inflations = fixtures.map((fixture) => fixture.renderedClassInflation).filter((value) => typeof value === "number");

  if (convertTimes.length > 0) {
    aggregate.averageConvertTimeMs = Number((convertTimes.reduce((sum, value) => sum + value, 0) / convertTimes.length).toFixed(1));
  }

  if (residualRatios.length > 0) {
    aggregate.averageResidualRatio = Number((residualRatios.reduce((sum, value) => sum + value, 0) / residualRatios.length).toFixed(3));
    aggregate.worstResidualRatio = fixtures.reduce((worst, fixture) => {
      if (typeof fixture.residualRatio !== "number") {
        return worst;
      }
      if (!worst || fixture.residualRatio > worst.residualRatio) {
        return fixture;
      }
      return worst;
    }, null);
  }

  if (inflations.length > 0) {
    aggregate.averageRenderedClassInflation = Number((inflations.reduce((sum, value) => sum + value, 0) / inflations.length).toFixed(3));
    aggregate.worstRenderedClassInflation = fixtures.reduce((worst, fixture) => {
      if (typeof fixture.renderedClassInflation !== "number") {
        return worst;
      }
      if (!worst || fixture.renderedClassInflation > worst.renderedClassInflation) {
        return fixture;
      }
      return worst;
    }, null);
  }

  return aggregate;
}

function buildMarkdown(summary) {
  const lines = [
    "# Fixture Baseline Summary",
    "",
    `Generated at: ${summary.generatedAt}`,
    `Container: \`${summary.container}\``,
    `Fixture dir: \`${summary.fixtureDir}\``,
    "",
    "## Aggregate",
    "",
    `- Fixtures: ${summary.aggregate.fixtureCount}`,
    `- Average convert time: ${summary.aggregate.averageConvertTimeMs ?? "n/a"} ms`,
    `- Average residual ratio: ${summary.aggregate.averageResidualRatio ?? "n/a"}`,
    `- Average rendered class inflation: ${summary.aggregate.averageRenderedClassInflation ?? "n/a"}`,
    `- Render probe failures: ${summary.aggregate.renderProbeFailures.length === 0 ? "none" : summary.aggregate.renderProbeFailures.join(", ")}`,
    `- Fixture index stable HTML: ${summary.fixtureIndex?.stableHtmlCount ?? "n/a"}`,
    `- Fixture index failures: ${summary.fixtureIndex?.failures?.length ? summary.fixtureIndex.failures.map((item) => `${item.fixture}: ${item.message}`).join("; ") : "none"}`,
    "",
    "## Fixtures",
    "",
  ];

  for (const fixture of summary.fixtures) {
    lines.push(`### ${fixture.fixture}`);
    lines.push(`- convert time: ${fixture.convertTimeMs ?? "n/a"} ms`);
    lines.push(`- render probe ok: ${fixture.renderProbeOk}`);
    lines.push(`- dom to element ratio: ${fixture.domToElementRatio ?? "n/a"}`);
    lines.push(`- residual ratio: ${fixture.residualRatio ?? "n/a"}`);
    lines.push(`- rendered class inflation: ${fixture.renderedClassInflation ?? "n/a"}`);
    lines.push(`- selectors: ${fixture.selectorCount ?? "n/a"}`);
    lines.push(`- meta class refs: ${fixture.metaClassRefCount ?? "n/a"}`);
    lines.push(`- native residual classes: ${fixture.nativeResidualClassCount ?? "n/a"}`);
    lines.push(
      `- unmirrored native residual classes: ${fixture.unmirroredNativeResidualClassCount ?? "n/a"}`
    );
    lines.push(`- HtmlCode/CssCode/JavaScriptCode: ${fixture.htmlCode ?? 0}/${fixture.cssCode ?? 0}/${fixture.javascriptCode ?? 0}`);
    lines.push(`- fallback routes: ${fixture.fallbackCount ?? 0}`);
    lines.push(`- has fallback CSS: ${fixture.hasFallbackCss ? "yes" : "no"}`);
    lines.push(`- unsupported: ${fixture.unsupportedCount ?? 0}`);
    lines.push(`- mapped utility residuals: ${fixture.mappedUtilityResidualCount ?? 0}`);
    lines.push(`- warnings: ${fixture.warningCount}`);
    lines.push(`- top extra structure delta: ${fixture.topExtraStructureDelta.map((item) => `${item.tag}:${item.delta}`).join(", ") || "none"}`);
    lines.push(`- top residual classes: ${fixture.topResidualClasses.map((item) => `${item.class}:${item.count}`).join(", ") || "none"}`);
    lines.push("");
  }

  return lines.join("\n");
}

function main() {
  const options = parseArgs(process.argv.slice(2));
  fs.mkdirSync(options.outputDir, { recursive: true });

  if (!fs.existsSync(options.localFixtureDir)) {
    throw new Error(`Local fixture directory does not exist: ${options.localFixtureDir}`);
  }

  const nativeNoCodeContract = loadNativeNoCodeContract(options.localFixtureDir);
  const fixtureIndex = loadFixtureIndex(options.localFixtureDir);
  const originalClassMode = getCurrentClassMode(options);

  try {
    ensureParityScriptInContainer(options);

    if (options.classMode) {
      setClassMode(options, options.classMode);
    }

    const fixtures = listFixtures(options, nativeNoCodeContract, fixtureIndex);
    if (fixtures.length === 0) {
      throw new Error(`No fixtures found in ${options.fixtureDir}`);
    }

    const reports = fixtures.map((fixturePath) => summarizeEntry(loadReport(options, fixturePath)));
    const nativeNoCodeFailures = buildNativeNoCodeFailures(reports, nativeNoCodeContract, options);
    const fixtureIndexFailures = buildFixtureIndexFailures(reports, fixtureIndex, options);
    const summary = {
      generatedAt: new Date().toISOString(),
      container: options.container,
      fixtureDir: options.fixtureDir,
      localFixtureDir: options.localFixtureDir,
      remoteArtifactsDir: options.remoteArtifactsDir,
      includeNested: options.includeNested,
      fixture: options.fixture,
      slugPrefix: options.slugPrefix,
      originalClassMode,
      effectiveClassMode: options.classMode || originalClassMode,
      fixtures: reports,
      aggregate: buildAggregate(reports),
      nativeNoCode: {
        manifestPath: nativeNoCodeContract.manifestPath,
        fixtureCount: nativeNoCodeContract.fixtures.length,
        failures: nativeNoCodeFailures,
      },
      fixtureIndex: {
        manifestPath: fixtureIndex.manifestPath,
        stableHtmlCount: fixtureIndex.stableHtmlFixtures.length,
        supportingFixtureCount: fixtureIndex.supportingFixtures.length,
        requiredGapCount: fixtureIndex.requiredGapIds.length,
        failures: fixtureIndexFailures,
      },
    };

    const jsonPath = path.join(options.outputDir, "summary.json");
    const markdownPath = path.join(options.outputDir, "summary.md");
    fs.writeFileSync(jsonPath, JSON.stringify(summary, null, 2));
    fs.writeFileSync(markdownPath, buildMarkdown(summary));

    process.stdout.write(
      JSON.stringify(
        {
          ok: nativeNoCodeFailures.length === 0 && fixtureIndexFailures.length === 0,
          fixtureCount: reports.length,
          outputDir: options.outputDir,
          jsonPath,
          markdownPath,
          originalClassMode,
          effectiveClassMode: summary.effectiveClassMode,
          aggregate: summary.aggregate,
          nativeNoCode: summary.nativeNoCode,
          fixtureIndex: summary.fixtureIndex,
        },
        null,
        2
      ) + "\n"
    );

    if (nativeNoCodeFailures.length > 0 || fixtureIndexFailures.length > 0) {
      process.exitCode = 1;
    }
  } finally {
    if (options.classMode && originalClassMode !== options.classMode) {
      setClassMode(options, originalClassMode);
    }
  }
}

if (require.main === module) {
  main();
} else {
  module.exports = {
    buildParityDockerArgs,
    buildParitySshCommand,
    buildFixtureIndexFailures,
    summarizeEntry,
  };
}
