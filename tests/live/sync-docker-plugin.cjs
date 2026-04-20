const path = require("path");
const { execFileSync } = require("child_process");

const container = process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const pluginPath =
  process.env.OXY_HTML_CONVERTER_DOCKER_PLUGIN_PATH ||
  "/var/www/html/wp-content/plugins/oxygen-html-converter";
const pluginOwner =
  process.env.OXY_HTML_CONVERTER_DOCKER_PLUGIN_OWNER || "www-data:www-data";

function run(command, args) {
  return execFileSync(command, args, {
    cwd: process.cwd(),
    stdio: ["ignore", "pipe", "pipe"],
    encoding: "utf8",
  }).trim();
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\"'\"'`)}'`;
}

function copyIntoContainer(localPath, remotePath) {
  run("docker", ["cp", localPath, `${container}:${remotePath}`]);
}

function normalizePluginPermissions() {
  const quotedPluginPath = shellQuote(pluginPath);
  const quotedOwner = shellQuote(pluginOwner);
  const script = [
    `chown -R ${quotedOwner} ${quotedPluginPath}`,
    `find ${quotedPluginPath} -type d -exec chmod 755 {} +`,
    `find ${quotedPluginPath} -type f -exec chmod 644 {} +`,
  ].join(" && ");

  run("docker", ["exec", container, "sh", "-lc", script]);
}

function main() {
  const root = process.cwd();
  copyIntoContainer(path.join(root, "src"), `${pluginPath}/`);
  copyIntoContainer(path.join(root, "assets"), `${pluginPath}/`);
  copyIntoContainer(path.join(root, "oxygen-html-converter.php"), `${pluginPath}/oxygen-html-converter.php`);
  normalizePluginPermissions();

  process.stdout.write(
    JSON.stringify(
      {
        ok: true,
        container,
        pluginPath,
        pluginOwner,
        synced: ["src", "assets", "oxygen-html-converter.php"],
      },
      null,
      2
    ) + "\n"
  );
}

main();
