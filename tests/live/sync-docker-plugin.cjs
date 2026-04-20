const path = require("path");
const { execFileSync } = require("child_process");

const container = process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const pluginPath =
  process.env.OXY_HTML_CONVERTER_DOCKER_PLUGIN_PATH ||
  "/var/www/html/wp-content/plugins/oxygen-html-converter";

function run(command, args) {
  execFileSync(command, args, {
    cwd: process.cwd(),
    stdio: ["ignore", "pipe", "pipe"],
    encoding: "utf8",
  });
}

function copyIntoContainer(localPath, remotePath) {
  run("docker", ["cp", localPath, `${container}:${remotePath}`]);
}

function main() {
  const root = process.cwd();
  copyIntoContainer(path.join(root, "src"), `${pluginPath}/`);
  copyIntoContainer(path.join(root, "assets"), `${pluginPath}/`);
  copyIntoContainer(path.join(root, "oxygen-html-converter.php"), `${pluginPath}/oxygen-html-converter.php`);

  process.stdout.write(
    JSON.stringify(
      {
        ok: true,
        container,
        pluginPath,
        synced: ["src", "assets", "oxygen-html-converter.php"],
      },
      null,
      2
    ) + "\n"
  );
}

main();
