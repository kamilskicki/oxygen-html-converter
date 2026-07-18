const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const DEFAULT_CONTAINER =
  process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const DEFAULT_OUTPUT_DIR = path.resolve(process.cwd(), "artifacts", "site-build");
const DEFAULT_REMOTE_FIXTURES = "/tmp/ohc-new-maximus-fixtures";
const DEFAULT_REMOTE_SCRIPT = "/tmp/ohc-import-maximus-v2.php";
const DEFAULT_REMOTE_LIB = "/tmp/ohc-build-maximus-site.php";
const DEFAULT_REMOTE_REPORT = "/tmp/ohc-maximus-v2-import-report.json";

function parseArgs(argv) {
  const options = {
    container: DEFAULT_CONTAINER,
    outputDir: DEFAULT_OUTPUT_DIR,
    localFixtureDir: resolveDefaultFixtureDir(),
    remoteFixtureDir: DEFAULT_REMOTE_FIXTURES,
    remoteScript: DEFAULT_REMOTE_SCRIPT,
    remoteLib: DEFAULT_REMOTE_LIB,
    remoteReport: DEFAULT_REMOTE_REPORT,
    skipSync: false,
  };

  for (const arg of argv) {
    if (arg === "--skip-sync") {
      options.skipSync = true;
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
    } else if (rawKey === "remote-fixture-dir") {
      options.remoteFixtureDir = value;
    }
  }

  return options;
}

function resolveDefaultFixtureDir() {
  return path.resolve(process.cwd(), "fixtures", "html", "Maximus", "New Maximus");
}

function run(command, args, options = {}) {
  return execFileSync(command, args, {
    cwd: process.cwd(),
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
    ...options,
  }).trim();
}

function log(message) {
  process.stdout.write(`[maximus-v2] ${message}\n`);
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\"'\"'`)}'`;
}

function syncPlugin() {
  log("Syncing plugin into Docker WordPress container");
  run("node", [path.join("tests", "live", "sync-docker-plugin.cjs")], {
    timeout: 120000,
  });
}

function normalizeOxygenUploads(options) {
  log("Normalizing Oxygen upload/cache permissions");
  run(
    "docker",
    [
      "exec",
      options.container,
      "sh",
      "-lc",
      "mkdir -p /var/www/html/wp-content/uploads/oxygen/css && chown -R www-data:www-data /var/www/html/wp-content/uploads/oxygen && find /var/www/html/wp-content/uploads/oxygen -type d -exec chmod 775 {} + && find /var/www/html/wp-content/uploads/oxygen -type f -exec chmod 664 {} +",
    ],
    { timeout: 120000 }
  );
}

function copyInputs(options) {
  if (!fs.existsSync(options.localFixtureDir)) {
    throw new Error(`Fixture directory not found: ${options.localFixtureDir}`);
  }

  log("Copying additive importer, library, and New Maximus fixtures into container");
  run("docker", [
    "exec",
    options.container,
    "sh",
    "-lc",
    `rm -rf ${shellQuote(options.remoteFixtureDir)} ${shellQuote(options.remoteScript)} ${shellQuote(options.remoteLib)} ${shellQuote(options.remoteReport)}`,
  ]);
  run("docker", [
    "cp",
    path.join(process.cwd(), "tests", "live", "build-maximus-site.php"),
    `${options.container}:${options.remoteLib}`,
  ]);
  run("docker", [
    "cp",
    path.join(process.cwd(), "tests", "live", "import-maximus-v2.php"),
    `${options.container}:${options.remoteScript}`,
  ]);
  run("docker", ["cp", options.localFixtureDir, `${options.container}:${options.remoteFixtureDir}`], {
    timeout: 120000,
  });
}

function runImport(options) {
  log("Importing New Maximus fixtures additively");
  const output = run(
    "docker",
    [
      "exec",
      "-e",
      `OHC_MAXIMUS_BUILD_LIB=${options.remoteLib}`,
      options.container,
      "php",
      options.remoteScript,
      options.remoteFixtureDir,
      `--report=${options.remoteReport}`,
    ],
    { timeout: 600000 }
  );
  return JSON.parse(output);
}

function copyReport(options) {
  fs.mkdirSync(options.outputDir, { recursive: true });
  const stamp = new Date().toISOString().replace(/[-:]/g, "").replace(/\..+/, "").replace("T", "-");
  const localPath = path.join(options.outputDir, `maximus-v2-import-${stamp}.json`);
  run("docker", ["cp", `${options.container}:${options.remoteReport}`, localPath]);
  return localPath;
}

function main() {
  const options = parseArgs(process.argv.slice(2));
  if (!options.skipSync) {
    syncPlugin();
  }

  normalizeOxygenUploads(options);
  copyInputs(options);
  const report = runImport(options);
  normalizeOxygenUploads(options);
  const reportPath = copyReport(options);

  process.stdout.write(
    JSON.stringify(
      {
        ok: report.ok === true,
        reportPath,
        acceptance: report.acceptance,
        dataSafety: report.dataSafety,
        globals: report.globals,
        designSystem: report.designSystem,
      },
      null,
      2
    ) + "\n"
  );
}

main();
