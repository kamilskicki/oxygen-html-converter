const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const DEFAULT_CONTAINER =
  process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const DEFAULT_ADMIN_USER = process.env.OXY_HTML_CONVERTER_ADMIN_USER || "admin";
const DEFAULT_ADMIN_PASSWORD =
  process.env.OXY_HTML_CONVERTER_ADMIN_PASSWORD || "admin";
const DEFAULT_PLUGIN_SLUG = "oxygen-html-converter";
const DEFAULT_PLUGIN_BASENAME = `${DEFAULT_PLUGIN_SLUG}/${DEFAULT_PLUGIN_SLUG}.php`;
const DEFAULT_PLUGIN_PATH = `/var/www/html/wp-content/plugins/${DEFAULT_PLUGIN_SLUG}`;
const DEFAULT_BACKUP_ROOT =
  process.env.OXY_HTML_CONVERTER_DOCKER_BACKUP_ROOT ||
  "/tmp/oxy-html-converter-backups";
const DEFAULT_PLUGIN_OWNER =
  process.env.OXY_HTML_CONVERTER_DOCKER_PLUGIN_OWNER || "www-data:www-data";

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
    adminUser: DEFAULT_ADMIN_USER,
    adminPassword: DEFAULT_ADMIN_PASSWORD,
    baseUrl: process.env.OXY_HTML_CONVERTER_BASE_URL || "",
    zipPath: process.env.OXY_HTML_CONVERTER_ZIP_PATH || "",
    pluginPath: DEFAULT_PLUGIN_PATH,
    pluginBasename: DEFAULT_PLUGIN_BASENAME,
    backupRoot: DEFAULT_BACKUP_ROOT,
    pluginOwner: DEFAULT_PLUGIN_OWNER,
    allowCleanInstallFallback: true,
  };

  for (const arg of argv) {
    if (arg === "--no-clean-install-fallback") {
      options.allowCleanInstallFallback = false;
      continue;
    }

    if (!arg.startsWith("--")) {
      continue;
    }

    const [rawKey, rawValue] = arg.slice(2).split("=", 2);
    const value = rawValue === undefined ? "true" : rawValue;

    if (rawKey === "container") {
      options.container = value;
    } else if (rawKey === "admin-user") {
      options.adminUser = value;
    } else if (rawKey === "admin-password") {
      options.adminPassword = value;
    } else if (rawKey === "base-url") {
      options.baseUrl = value;
    } else if (rawKey === "zip") {
      options.zipPath = path.resolve(process.cwd(), value);
    } else if (rawKey === "plugin-path") {
      options.pluginPath = value;
    } else if (rawKey === "plugin-basename") {
      options.pluginBasename = value;
    } else if (rawKey === "backup-root") {
      options.backupRoot = value;
    } else if (rawKey === "plugin-owner") {
      options.pluginOwner = value;
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

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\"'\"'`)}'`;
}

function runDockerShell(container, script) {
  return runCommand("docker", ["exec", container, "sh", "-lc", script], {
    timeout: 60000,
  });
}

function runDockerPhp(container, phpCode) {
  return runCommand("docker", ["exec", container, "php", "-r", phpCode], {
    timeout: 60000,
  });
}

function logStep(message) {
  process.stdout.write(`[install-zip] ${message}\n`);
}

function resolveZipPath(root, explicitZipPath) {
  if (explicitZipPath) {
    if (!fs.existsSync(explicitZipPath)) {
      throw new Error(`ZIP path does not exist: ${explicitZipPath}`);
    }

    return explicitZipPath;
  }

  const releaseDir = path.join(root, "artifacts", "release");
  const candidates = fs
    .readdirSync(releaseDir, { withFileTypes: true })
    .filter(
      (entry) =>
        entry.isFile() &&
        /^oxygen-html-converter-.*\.zip$/i.test(entry.name)
    )
    .map((entry) => ({
      fullPath: path.join(releaseDir, entry.name),
      mtimeMs: fs.statSync(path.join(releaseDir, entry.name)).mtimeMs,
    }))
    .sort((left, right) => right.mtimeMs - left.mtimeMs);

  if (!candidates.length) {
    throw new Error(
      `No release ZIP found in ${releaseDir}. Run npm run build:zip first.`
    );
  }

  return candidates[0].fullPath;
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

function backupPluginDirectory(container, pluginPath, backupRoot) {
  const output = runDockerShell(
    container,
    [
      `mkdir -p ${shellQuote(backupRoot)}`,
      `if [ -d ${shellQuote(pluginPath)} ]; then`,
      `  backup_path=${shellQuote(
        `${backupRoot}/${DEFAULT_PLUGIN_SLUG}`
      )}-$(date +%Y%m%d_%H%M%S)`,
      `  cp -a ${shellQuote(pluginPath)} "$backup_path"`,
      `  echo "$backup_path"`,
      `fi`,
    ].join("\n")
  );

  return output || "";
}

function normalizePluginPermissions(container, pluginPath, pluginOwner) {
  runDockerShell(
    container,
    [
      `if [ -d ${shellQuote(pluginPath)} ]; then`,
      `  chown -R ${shellQuote(pluginOwner)} ${shellQuote(pluginPath)}`,
      `  find ${shellQuote(pluginPath)} -type d -exec chmod 755 {} +`,
      `  find ${shellQuote(pluginPath)} -type f -exec chmod 644 {} +`,
      `fi`,
    ].join("\n")
  );
}

function removePluginDirectory(container, pluginPath) {
  runDockerShell(
    container,
    `rm -rf ${shellQuote(pluginPath)}`
  );
}

function getPluginState(container, pluginBasename) {
  const pluginBasenameJson = JSON.stringify(pluginBasename);
  const php = `
    require '/var/www/html/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin = ${pluginBasenameJson};
    $pluginFile = WP_PLUGIN_DIR . '/' . $plugin;
    $data = file_exists($pluginFile) ? get_plugin_data($pluginFile, false, false) : [];
    echo wp_json_encode([
      'installed' => file_exists($pluginFile),
      'active' => is_plugin_active($plugin),
      'version' => isset($data['Version']) ? $data['Version'] : '',
      'name' => isset($data['Name']) ? $data['Name'] : '',
    ]);
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

async function clickAndWait(page, selector) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: "load", timeout: 60000 }).catch(() => null),
    page.click(selector),
  ]);
  await page.waitForLoadState("load", { timeout: 60000 }).catch(() => null);
}

async function uploadZip(page, baseUrl, zipPath) {
  await page.goto(`${baseUrl}/wp-admin/plugin-install.php?tab=upload`, {
    waitUntil: "load",
    timeout: 30000,
  });
  await page.locator('input[name="pluginzip"]').setInputFiles(zipPath);
  await clickAndWait(page, "#install-plugin-submit");
}

async function maybeConfirmReplace(page) {
  const replaceButton = page.locator(
    [
      'a.update-from-upload-overwrite',
      'input[name="overwrite-plugin-upload"]',
      'button[name="overwrite-plugin-upload"]',
    ].join(", ")
  );

  if ((await replaceButton.count()) === 0) {
    return false;
  }

  await clickAndWait(
    page,
    [
      'a.update-from-upload-overwrite',
      'input[name="overwrite-plugin-upload"]',
      'button[name="overwrite-plugin-upload"]',
    ].join(", ")
  );
  return true;
}

async function maybeActivatePlugin(page) {
  const activateLink = page.getByRole("link", { name: /activate plugin/i });

  if ((await activateLink.count()) === 0) {
    return false;
  }

  await Promise.all([
    page.waitForNavigation({ waitUntil: "load", timeout: 60000 }).catch(() => null),
    activateLink.click(),
  ]);
  return true;
}

async function assertInstallSucceeded(page) {
  const bodyText = await page.locator("body").innerText();

  if (
    /could not be copied|plugin update failed|destination folder already exists|fatal error/i.test(
      bodyText
    )
  ) {
    throw new Error(bodyText);
  }

  if (!/plugin updated successfully|plugin installed successfully/i.test(bodyText)) {
    throw new Error(`Unexpected plugin upload result:\n${bodyText}`);
  }

  return bodyText;
}

async function installZipFlow(page, options) {
  await uploadZip(page, options.baseUrl, options.zipPath);
  const replaceUsed = await maybeConfirmReplace(page);
  const bodyText = await assertInstallSucceeded(page);
  const activated = await maybeActivatePlugin(page);

  return {
    replaceUsed,
    activated,
    bodyText,
  };
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  options.baseUrl = options.baseUrl || getHomeUrl(options.container);
  options.zipPath = resolveZipPath(process.cwd(), options.zipPath);

  logStep(`Using ZIP: ${options.zipPath}`);
  logStep(`Target site: ${options.baseUrl}`);

  ensureAdminPassword(options.container, options.adminPassword);
  const backupPath = backupPluginDirectory(
    options.container,
    options.pluginPath,
    options.backupRoot
  );
  normalizePluginPermissions(
    options.container,
    options.pluginPath,
    options.pluginOwner
  );

  logStep(
    backupPath
      ? `Backed up current plugin directory to ${backupPath}`
      : "No existing plugin directory was found to back up"
  );
  logStep("Normalized plugin ownership and permissions before upload");

  const { chromium } = loadPlaywright();
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1000 },
  });
  const page = await context.newPage();

  let installResult = null;
  let fallbackUsed = false;
  let initialError = null;

  try {
    await login(page, options.baseUrl, options.adminUser, options.adminPassword);

    try {
      installResult = await installZipFlow(page, options);
    } catch (error) {
      initialError = String(error && error.message ? error.message : error);

      if (!options.allowCleanInstallFallback) {
        throw error;
      }

      fallbackUsed = true;
      logStep("In-place update failed; falling back to clean reinstall");
      removePluginDirectory(options.container, options.pluginPath);
      installResult = await installZipFlow(page, options);
    }
  } finally {
    await page.close();
    await browser.close();
  }

  const pluginState = getPluginState(options.container, options.pluginBasename);
  if (!pluginState.installed || !pluginState.active) {
    throw new Error(
      `Installed plugin state is invalid after ZIP upload: ${JSON.stringify(
        pluginState
      )}`
    );
  }

  process.stdout.write(
    JSON.stringify(
      {
        ok: true,
        zipPath: options.zipPath,
        baseUrl: options.baseUrl,
        container: options.container,
        backupPath,
        pluginState,
        fallbackUsed,
        initialError,
        installResult,
      },
      null,
      2
    ) + "\n"
  );
}

main().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error) + "\n");
  process.exit(1);
});
