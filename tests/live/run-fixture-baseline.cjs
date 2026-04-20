const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const DEFAULT_CONTAINER = process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const DEFAULT_FIXTURE_DIR = process.env.OXY_HTML_CONVERTER_FIXTURE_DIR || "/var/www/html/Import_Tests";
const DEFAULT_REMOTE_ARTIFACTS = process.env.OXY_HTML_CONVERTER_REMOTE_ARTIFACTS || "/tmp/oxy-parity-suite";
const DEFAULT_OUTPUT_DIR = path.resolve(process.cwd(), "artifacts", "fixture-baseline");

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

function parseArgs(argv) {
  const options = {
    container: DEFAULT_CONTAINER,
    fixtureDir: DEFAULT_FIXTURE_DIR,
    remoteArtifactsDir: DEFAULT_REMOTE_ARTIFACTS,
    outputDir: DEFAULT_OUTPUT_DIR,
    localFixtureDir: DEFAULT_LOCAL_FIXTURE_DIR,
    classMode: null,
  };

  for (const arg of argv) {
    if (!arg.startsWith("--")) {
      continue;
    }

    const [rawKey, rawValue] = arg.slice(2).split("=", 2);
    const value = rawValue === undefined ? "true" : rawValue;

    if (rawKey === "container") {
      options.container = value;
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

function runDockerPhp(options, code) {
  return runDocker(["exec", options.container, "php", "-r", code]).trim();
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\"'\"'`)}'`;
}

function listFixtures(options) {
  const output = runDocker([
    "exec",
    options.container,
    "sh",
    "-lc",
    `find ${shellQuote(options.fixtureDir)} -maxdepth 1 -type f -name '*.html' | sort`,
  ]);

  return output
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
}

function buildSlug(baseName) {
  return `perf-${baseName}`
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
}

function loadReport(options, fixturePath) {
  const baseName = path.basename(fixturePath, ".html");
  const slug = buildSlug(baseName);
  const title = `Fixture ${baseName}`;

  const output = runDocker([
    "exec",
    options.container,
    "sh",
    "-lc",
    [
      `mkdir -p ${shellQuote(options.remoteArtifactsDir)}`,
      `php /var/www/html/fixture-page-parity.php ${shellQuote(fixturePath)} ${shellQuote(options.remoteArtifactsDir)} --keep-post --replace-post --page-slug=${slug} --page-title=${shellQuote(title)}`,
    ].join(" && "),
  ]);

  const result = JSON.parse(output);
  const reportJson = runDocker([
    "exec",
    options.container,
    "sh",
    "-lc",
    `cat ${shellQuote(result.reportPath)}`,
  ]);

  return {
    fixturePath,
    benchmark: loadBenchmark(options, fixturePath),
    cli: result,
    report: JSON.parse(reportJson),
  };
}

function loadBenchmark(options, fixturePath) {
  const localFixturePath = path.join(options.localFixtureDir, path.basename(fixturePath));
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

function getCurrentClassMode(options) {
  return runDockerPhp(
    options,
    "require '/var/www/html/wp-load.php'; echo get_option('oxy_html_converter_class_mode', 'auto');"
  );
}

function setClassMode(options, classMode) {
  runDockerPhp(
    options,
    `require '/var/www/html/wp-load.php'; update_option('oxy_html_converter_class_mode', '${String(classMode).replace(/'/g, "\\'")}'); echo get_option('oxy_html_converter_class_mode', 'auto');`
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
  const delta = entry.report.delta || {};

  return {
    fixture: path.basename(entry.fixturePath),
    convertTimeMs: entry.benchmark.convertTimeMs ?? null,
    renderProbeOk: Boolean(render.ok),
    domToElementRatio: delta.domToElementRatio ?? null,
    residualRatio: delta.residualClassRatio ?? null,
    renderedClassInflation: render.delta?.renderedClassToSourceClassRatio ?? null,
    topExtraStructureDelta: Array.isArray(structure)
      ? structure.filter((item) => Number(item.delta || 0) > 0).slice(0, 5)
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

  const originalClassMode = getCurrentClassMode(options);

  try {
    if (options.classMode) {
      setClassMode(options, options.classMode);
    }

    const fixtures = listFixtures(options);
    if (fixtures.length === 0) {
      throw new Error(`No fixtures found in ${options.fixtureDir}`);
    }

    const reports = fixtures.map((fixturePath) => summarizeEntry(loadReport(options, fixturePath)));
    const summary = {
      generatedAt: new Date().toISOString(),
      container: options.container,
      fixtureDir: options.fixtureDir,
      localFixtureDir: options.localFixtureDir,
      remoteArtifactsDir: options.remoteArtifactsDir,
      originalClassMode,
      effectiveClassMode: options.classMode || originalClassMode,
      fixtures: reports,
      aggregate: buildAggregate(reports),
    };

    const jsonPath = path.join(options.outputDir, "summary.json");
    const markdownPath = path.join(options.outputDir, "summary.md");
    fs.writeFileSync(jsonPath, JSON.stringify(summary, null, 2));
    fs.writeFileSync(markdownPath, buildMarkdown(summary));

    process.stdout.write(
      JSON.stringify(
        {
          ok: true,
          fixtureCount: reports.length,
          outputDir: options.outputDir,
          jsonPath,
          markdownPath,
          originalClassMode,
          effectiveClassMode: summary.effectiveClassMode,
          aggregate: summary.aggregate,
        },
        null,
        2
      ) + "\n"
    );
  } finally {
    if (options.classMode && originalClassMode !== options.classMode) {
      setClassMode(options, originalClassMode);
    }
  }
}

main();
