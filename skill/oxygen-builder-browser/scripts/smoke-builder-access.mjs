#!/usr/bin/env node

import { execFile } from "node:child_process";
import { promisify } from "node:util";

const DEFAULT_BASE_URL = "http://oxyconvo6.localhost/";
const DEFAULT_TIMEOUT_MS = 5000;
const WINDOWS_CURL_PATH = "C:\\Windows\\System32\\curl.exe";
const execFileAsync = promisify(execFile);

function parseArgs(argv) {
  const options = {
    baseUrl: DEFAULT_BASE_URL,
    timeoutMs: DEFAULT_TIMEOUT_MS,
  };

  for (let index = 0; index < argv.length; index += 1) {
    const token = argv[index];

    if (token === "--base-url") {
      options.baseUrl = argv[index + 1];
      index += 1;
      continue;
    }

    if (token === "--post-id") {
      options.postId = argv[index + 1];
      index += 1;
      continue;
    }

    if (token === "--open-url") {
      options.openUrl = argv[index + 1];
      index += 1;
      continue;
    }

    if (token === "--return-url") {
      options.returnUrl = argv[index + 1];
      index += 1;
      continue;
    }

    if (token === "--timeout-ms") {
      options.timeoutMs = Number(argv[index + 1]);
      index += 1;
      continue;
    }

    if (token === "--help" || token === "-h") {
      options.help = true;
      continue;
    }

    throw new Error(`Unknown argument: ${token}`);
  }

  return options;
}

function printHelp() {
  console.log(
    [
      "Usage: node .\\scripts\\smoke-builder-access.mjs [options]",
      "",
      "Options:",
      "  --base-url <url>      Override the local site base URL",
      "  --post-id <id>        Emit and probe the canonical document builder URL",
      "  --open-url <url>      Emit and probe a browse-mode URL with browseModeOpenUrl",
      "  --return-url <url>    Add returnUrl to the browse-mode URL",
      "  --timeout-ms <ms>     Request timeout in milliseconds (default: 5000)",
      "  --help                Show this help",
    ].join("\n"),
  );
}

function normalizeBaseUrl(input) {
  const url = new URL(input);
  if (!url.pathname || url.pathname === "") {
    url.pathname = "/";
  }
  return url;
}

function buildDocumentBuilderUrl(baseUrl, postId) {
  const url = new URL(baseUrl);
  url.searchParams.set("oxygen", "builder");
  url.searchParams.set("id", String(postId));
  return url.toString();
}

function buildBrowseModeUrl(baseUrl, openUrl, returnUrl) {
  const url = new URL(baseUrl);
  url.searchParams.set("oxygen", "builder");
  url.searchParams.set("mode", "browse");

  if (openUrl) {
    url.searchParams.set("browseModeOpenUrl", encodeURIComponent(openUrl));
  }

  if (returnUrl) {
    url.searchParams.set("returnUrl", encodeURIComponent(returnUrl));
  }

  return url.toString();
}

async function probeUrl(label, rawUrl, timeoutMs) {
  if (process.platform === "win32") {
    const curlFirst = await probeWithCurl(label, rawUrl, timeoutMs);
    if (curlFirst) {
      return curlFirst;
    }
  }

  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(rawUrl, {
      method: "HEAD",
      redirect: "manual",
      signal: controller.signal,
    });

    const headers = {};
    for (const [name, value] of response.headers.entries()) {
      const lower = name.toLowerCase();
      if (lower === "location" || lower === "content-type" || lower === "server") {
        headers[lower] = value;
      }
    }

    return {
      label,
      ok: response.ok,
      status: response.status,
      statusText: response.statusText,
      url: rawUrl,
      transport: "fetch",
      headers,
    };
  } catch (error) {
    const curlFallback = process.platform === "win32"
      ? null
      : await probeWithCurl(label, rawUrl, timeoutMs);
    if (curlFallback) {
      return curlFallback;
    }

    return {
      label,
      ok: false,
      status: null,
      statusText: error.name === "AbortError" ? "timeout" : "error",
      url: rawUrl,
      transport: "fetch",
      error: error.message,
    };
  } finally {
    clearTimeout(timeout);
  }
}

async function probeWithCurl(label, rawUrl, timeoutMs) {
  try {
    const seconds = Math.max(1, Math.ceil(timeoutMs / 1000));
    const { stdout } = await execFileAsync(
      process.platform === "win32" ? WINDOWS_CURL_PATH : "curl",
      ["-sS", "-I", "--max-time", String(seconds), rawUrl],
      { timeout: timeoutMs + 1000 },
    );

    const lines = stdout
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean);
    const statusLine = lines.find((line) => line.startsWith("HTTP/"));

    if (!statusLine) {
      return {
        label,
        ok: false,
        status: null,
        statusText: "curl-no-status",
        url: rawUrl,
        transport: "curl-head",
        error: "curl.exe returned no HTTP status line",
      };
    }

    const statusMatch = statusLine.match(/^HTTP\/\S+\s+(\d+)\s*(.*)$/);
    const status = statusMatch ? Number(statusMatch[1]) : null;
    const statusText = statusMatch ? statusMatch[2] : "";
    const headers = {};

    for (const line of lines) {
      const separatorIndex = line.indexOf(":");
      if (separatorIndex === -1) {
        continue;
      }

      const name = line.slice(0, separatorIndex).trim().toLowerCase();
      const value = line.slice(separatorIndex + 1).trim();
      if (name === "location" || name === "content-type" || name === "server") {
        headers[name] = value;
      }
    }

    return {
      label,
      ok: status !== null && status >= 200 && status < 400,
      status,
      statusText,
      url: rawUrl,
      transport: "curl-head",
      headers,
    };
  } catch (error) {
    return null;
  }
}

async function main() {
  const options = parseArgs(process.argv.slice(2));

  if (options.help) {
    printHelp();
    return;
  }

  const baseUrl = normalizeBaseUrl(options.baseUrl);
  const loginUrl = new URL("wp-login.php", baseUrl).toString();
  const adminUrl = new URL("wp-admin/", baseUrl).toString();

  const targets = [
    { label: "frontend", url: baseUrl.toString() },
    { label: "login", url: loginUrl },
    { label: "admin", url: adminUrl },
  ];

  const derived = {
    frontend: baseUrl.toString(),
    login: loginUrl,
    admin: adminUrl,
  };

  if (options.postId) {
    derived.documentBuilder = buildDocumentBuilderUrl(baseUrl, options.postId);
    targets.push({ label: "documentBuilder", url: derived.documentBuilder });
  }

  if (options.openUrl || options.returnUrl) {
    derived.browseMode = buildBrowseModeUrl(baseUrl, options.openUrl, options.returnUrl);
    targets.push({ label: "browseMode", url: derived.browseMode });
  }

  const probes = [];
  for (const target of targets) {
    probes.push(await probeUrl(target.label, target.url, options.timeoutMs));
  }

  console.log(
    JSON.stringify(
      {
        baseUrl: baseUrl.toString(),
        timeoutMs: options.timeoutMs,
        inputs: {
          postId: options.postId ?? null,
          openUrl: options.openUrl ?? null,
          returnUrl: options.returnUrl ?? null,
        },
        derived,
        probes,
      },
      null,
      2,
    ),
  );
}

main().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
