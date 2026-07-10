const fs = require("fs");
const path = require("path");
const { execFileSync } = require("child_process");

const DEFAULT_CONTAINER =
  process.env.OXY_HTML_CONVERTER_DOCKER_CONTAINER || "oxyconvo6-wordpress-1";
const DEFAULT_OUTPUT_DIR = path.resolve(process.cwd(), "artifacts", "live-gate");
const DEFAULT_ADMIN_USER = process.env.OXY_HTML_CONVERTER_ADMIN_USER || "admin";
const DEFAULT_ADMIN_PASSWORD =
  process.env.OXY_HTML_CONVERTER_ADMIN_PASSWORD || "admin";
const DEFAULT_TOOL_PATH =
  process.env.OXY_HTML_CONVERTER_TOOL_PATH ||
  "/wp-admin/tools.php?page=oxy-html-converter-tool";
const DEFAULT_DOCKER_UPLOAD_OWNER =
  process.env.OXY_HTML_CONVERTER_DOCKER_UPLOAD_OWNER || "www-data:www-data";
const DEFAULT_OXYGEN_UPLOAD_PATH =
  process.env.OXY_HTML_CONVERTER_DOCKER_OXYGEN_UPLOAD_PATH ||
  "/var/www/html/wp-content/uploads/oxygen";
const DEFAULT_LIVE_SLUG_PREFIX =
  process.env.OXY_HTML_CONVERTER_LIVE_SLUG_PREFIX || "live-gate-";
const DEFAULT_SKIP_SYNC =
  process.env.OXY_HTML_CONVERTER_SKIP_SYNC === "1" ||
  process.env.OXY_HTML_CONVERTER_SKIP_SYNC === "true";
const DEFAULT_SITE_KIT_MANIFEST =
  process.env.OXY_HTML_CONVERTER_SITE_KIT_MANIFEST || "site-kit/manifest.json";
const DEFAULT_SKIP_SITE_KIT =
  process.env.OXY_HTML_CONVERTER_SKIP_SITE_KIT === "1" ||
  process.env.OXY_HTML_CONVERTER_SKIP_SITE_KIT === "true";
const SITE_KIT_EXPECTED_TEXTS = {
  home: "M5 Site Kit Home",
  header: "M5 Site Kit Header",
  footer: "M5 Site Kit Footer",
  template: "M5 Site Kit Single Template",
};

function loadPlaywright() {
  const explicitModule = process.env.OXY_HTML_CONVERTER_PLAYWRIGHT_MODULE;

  if (explicitModule) {
    return require(explicitModule);
  }

  return require("playwright");
}

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
    baseUrl: process.env.OXY_HTML_CONVERTER_BASE_URL || "",
    adminUser: DEFAULT_ADMIN_USER,
    adminPassword: DEFAULT_ADMIN_PASSWORD,
    skipSync: DEFAULT_SKIP_SYNC,
    fixture: null,
    siteKitManifest: DEFAULT_SITE_KIT_MANIFEST,
    skipSiteKit: DEFAULT_SKIP_SITE_KIT,
    localFixtureDir: resolveDefaultLocalFixtureDir(),
    classMode: "native",
    slugPrefix: DEFAULT_LIVE_SLUG_PREFIX,
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

    if (arg === "--skip-site-kit") {
      options.skipSiteKit = true;
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
    } else if (rawKey === "base-url") {
      options.baseUrl = value;
    } else if (rawKey === "admin-user") {
      options.adminUser = value;
    } else if (rawKey === "admin-password") {
      options.adminPassword = value;
    } else if (rawKey === "fixture") {
      options.fixture = normalizeFixturePath(value);
    } else if (rawKey === "site-kit-manifest") {
      options.siteKitManifest = value;
    } else if (rawKey === "local-fixture-dir") {
      options.localFixtureDir = path.resolve(process.cwd(), value);
    } else if (rawKey === "class-mode") {
      options.classMode = value;
    } else if (rawKey === "slug-prefix") {
      options.slugPrefix = value;
    }
  }

  return options;
}

function runCommand(command, args, options = {}) {
  try {
    return execFileSync(command, args, {
      cwd: process.cwd(),
      encoding: "utf8",
      stdio: ["ignore", "pipe", "pipe"],
      ...options,
    }).trim();
  } catch (error) {
    const stdout = error.stdout ? String(error.stdout).trim() : "";
    const stderr = error.stderr ? String(error.stderr).trim() : "";
    const details = [
      error.message,
      stdout ? `stdout:\n${stdout}` : "",
      stderr ? `stderr:\n${stderr}` : "",
    ].filter(Boolean);
    const enriched = new Error(details.join("\n"));
    enriched.cause = error;
    throw enriched;
  }
}

function logStep(message) {
  process.stdout.write(`[live-gate] ${message}\n`);
}

function shellQuote(value) {
  return `'${String(value).replace(/'/g, `'\"'\"'`)}'`;
}

function isIgnorableRuntimeError(message) {
  return /angular is not defined/i.test(message);
}

function isPluginSignal(text) {
  return /oxygen-html-converter|oxy[_-]html[_-]convert|oxy[_-]html[_-]converter|\/oxygen-html-converter\//i.test(
    text
  );
}

function isConvertedAssetRuntimeError(text) {
  return /ReferenceError:\s*tailwind is not defined|tailwind is not defined/i.test(text);
}

function runNodeScript(scriptPath, args = []) {
  return runCommand("node", [scriptPath, ...args]);
}

function runDockerPhp(container, phpCode) {
  return runCommand("docker", ["exec", container, "php", "-r", phpCode], {
    timeout: 60000,
  });
}

function normalizeOxygenUploadsPermissions(container) {
  const path = shellQuote(DEFAULT_OXYGEN_UPLOAD_PATH);
  const owner = shellQuote(DEFAULT_DOCKER_UPLOAD_OWNER);
  runCommand(
    "docker",
    [
      "exec",
      container,
      "sh",
      "-lc",
      `if [ -d ${path} ]; then chown -R ${owner} ${path} && find ${path} -type d -exec chmod 775 {} + && find ${path} -type f -exec chmod 664 {} +; fi`,
    ],
    { timeout: 60000 }
  );
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

function buildSlug(baseName, slugPrefix = "perf-") {
  return `${slugPrefix}${baseName}`
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 60);
}

function getCurrentClassMode(container) {
  return runDockerPhp(
    container,
    "require '/var/www/html/wp-load.php'; echo get_option('oxy_html_converter_class_mode', 'auto');"
  );
}

function setClassMode(container, classMode) {
  return runDockerPhp(
    container,
    `require '/var/www/html/wp-load.php'; update_option('oxy_html_converter_class_mode', '${String(classMode).replace(/'/g, "\\'")}'); echo get_option('oxy_html_converter_class_mode', 'auto');`
  );
}

function loadWindPressProof(container) {
  const php = `
    require '/var/www/html/wp-load.php';
    include_once ABSPATH . 'wp-admin/includes/plugin.php';
    $pluginFile = WP_PLUGIN_DIR . '/windpress/windpress.php';
    $plugin = file_exists($pluginFile) ? get_plugin_data($pluginFile, false, false) : [];
    echo wp_json_encode([
      'active' => function_exists('is_plugin_active') && is_plugin_active('windpress/windpress.php'),
      'version' => isset($plugin['Version']) ? (string) $plugin['Version'] : '',
      'meetsTestedVersion' => isset($plugin['Version']) && version_compare((string) $plugin['Version'], '3.3.80', '>='),
    ]);
  `;

  return JSON.parse(runDockerPhp(container, php));
}

function assertWindPressProof(proof) {
  if (!proof.active) {
    throw new Error("WindPress mode was requested, but WindPress is not active.");
  }

  if (!proof.meetsTestedVersion) {
    throw new Error(
      `WindPress mode requires WindPress >= 3.3.80 for this gate; detected ${proof.version || "unknown"}.`
    );
  }
}

function cleanupSiteKitSmokeArtifacts(options) {
  const markersJson = JSON.stringify([
    ...Object.values(SITE_KIT_EXPECTED_TEXTS),
    "M5 Site Kit Live Post",
    "M5 Site Kit live template target.",
  ]);
  const php = `
    require '/var/www/html/wp-load.php';
    $markers = json_decode(${JSON.stringify(markersJson)}, true);
    $markers = is_array($markers) ? array_values(array_filter($markers, 'is_string')) : [];
    $postTypes = ['page', 'post', 'oxygen_template', 'oxygen_header', 'oxygen_footer', 'oxygen_part'];
    $posts = get_posts([
      'post_type' => $postTypes,
      'post_status' => 'any',
      'numberposts' => -1,
      'fields' => 'ids',
    ]);
    $deleted = [];
    foreach (is_array($posts) ? $posts : [] as $postId) {
      $postId = (int) $postId;
      if ($postId < 1) {
        continue;
      }
      $post = get_post($postId);
      if (!$post instanceof WP_Post) {
        continue;
      }
      $haystack = (string) $post->post_title . "\n" . (string) $post->post_content . "\n" . (string) $post->post_name;
      foreach (['_oxygen_data', '_oxygen_template_settings', '_oxy_html_converter_import_manifest'] as $metaKey) {
        $raw = get_post_meta($postId, $metaKey, true);
        if (is_scalar($raw)) {
          $haystack .= "\n" . (string) $raw;
          continue;
        }
        $encoded = function_exists('wp_json_encode') ? wp_json_encode($raw) : json_encode($raw);
        $haystack .= "\n" . (is_string($encoded) ? $encoded : '');
      }
      $matches = false;
      foreach ($markers as $marker) {
        if ($marker !== '' && strpos($haystack, $marker) !== false) {
          $matches = true;
          break;
        }
      }
      if (!$matches) {
        continue;
      }
      wp_delete_post($postId, true);
      $deleted[] = $postId;
    }
    $frontPageId = (int) get_option('page_on_front');
    if ($frontPageId > 0 && get_post($frontPageId) === null) {
      update_option('show_on_front', 'posts');
      update_option('page_on_front', 0);
    }
    if (function_exists('wp_cache_flush')) {
      wp_cache_flush();
    }
    echo wp_json_encode([
      'ok' => true,
      'deleted' => $deleted,
    ]);
  `;

  return JSON.parse(runDockerPhp(options.container, php));
}

function importSiteKitFixture(options) {
  const cleanup = cleanupSiteKitSmokeArtifacts(options);
  const manifest = ensureSiteKitManifestInContainer(options);
  const php = `
    require '/var/www/html/wp-load.php';
    $admin = get_user_by('login', ${JSON.stringify(options.adminUser)});
    $adminId = $admin instanceof WP_User ? (int) $admin->ID : 1;
    wp_set_current_user($adminId);
    $manifestPath = ${JSON.stringify(manifest.remotePath)};
    $raw = file_get_contents($manifestPath);
    $manifest = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($manifest)) {
      echo wp_json_encode([
        'ok' => false,
        'message' => 'Site-kit manifest JSON could not be decoded.',
        'manifestPath' => $manifestPath,
      ]);
      return;
    }
    if (!class_exists('\\\\OxyHtmlConverter\\\\Services\\\\OxygenPageImporter')) {
      echo wp_json_encode([
        'ok' => false,
        'message' => 'OxygenPageImporter is not loaded in WordPress.',
        'manifestPath' => $manifestPath,
      ]);
      return;
    }
    $importer = new \\OxyHtmlConverter\\Services\\OxygenPageImporter();
    $result = $importer->importSiteKit($manifest);
    if (empty($result['success'])) {
      echo wp_json_encode([
        'ok' => false,
        'message' => (string) ($result['message'] ?? 'Site-kit import failed.'),
        'result' => $result,
        'manifestPath' => $manifestPath,
      ]);
      return;
    }

    $objects = is_array($result['objects'] ?? null) ? $result['objects'] : [];
    $pageId = (int) ($objects['pages'][0]['postId'] ?? 0);
    $templateId = (int) ($objects['templates'][0]['postId'] ?? 0);
    $headerId = (int) ($objects['headers'][0]['postId'] ?? 0);
    $footerId = (int) ($objects['footers'][0]['postId'] ?? 0);
    $partId = (int) ($objects['parts'][0]['postId'] ?? 0);

    $postSlug = 'm5-site-kit-live-post-' . time() . '-' . wp_generate_password(6, false, false);
    $postId = wp_insert_post([
      'post_type' => 'post',
      'post_status' => 'publish',
      'post_title' => 'M5 Site Kit Live Post',
      'post_name' => $postSlug,
      'post_content' => 'M5 Site Kit live template target.',
    ], true);
    $postId = is_wp_error($postId) ? 0 : (int) $postId;

    $templateSettings = static function (int $postId): array {
      if ($postId < 1) {
        return [];
      }
      $raw = get_post_meta($postId, '_oxygen_template_settings', true);
      foreach (is_string($raw) && $raw !== '' ? [$raw, stripslashes($raw)] : [] as $candidate) {
        $decoded = json_decode($candidate, true);
        if (is_string($decoded) && $decoded !== '') {
          $decoded = json_decode($decoded, true);
        }
        if (is_array($decoded)) {
          return $decoded;
        }
      }
      return [];
    };
    $hasTree = static function (int $postId): bool {
      if ($postId < 1) {
        return false;
      }
      $raw = get_post_meta($postId, '_oxygen_data', true);
      if (is_array($raw)) {
        return isset($raw['tree_json_string']) && is_string($raw['tree_json_string']) && $raw['tree_json_string'] !== '';
      }
      $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
      return is_array($decoded) && isset($decoded['tree_json_string']) && is_string($decoded['tree_json_string']) && $decoded['tree_json_string'] !== '';
    };

    $locations = get_theme_mod('nav_menu_locations', []);
    $locations = is_array($locations) ? $locations : [];
    $primaryMenuId = (int) ($locations['primary'] ?? 0);
    $menuItems = $primaryMenuId > 0 ? wp_get_nav_menu_items($primaryMenuId) : [];
    $menuItems = is_array($menuItems) ? array_map(static function ($item): array {
      return [
        'id' => (int) ($item->ID ?? 0),
        'title' => (string) ($item->title ?? ''),
        'type' => (string) ($item->type ?? ''),
        'objectId' => (int) ($item->object_id ?? 0),
        'url' => (string) ($item->url ?? ''),
      ];
    }, $menuItems) : [];

    $ids = [
      'page' => $pageId,
      'template' => $templateId,
      'header' => $headerId,
      'footer' => $footerId,
      'part' => $partId,
      'post' => $postId,
    ];

    echo wp_json_encode([
      'ok' => true,
      'manifestPath' => $manifestPath,
      'localManifestPath' => ${JSON.stringify(manifest.localPath)},
      'import' => $result,
      'ids' => $ids,
      'urls' => [
        'home' => home_url('/'),
        'page' => $pageId > 0 ? get_permalink($pageId) : '',
        'post' => $postId > 0 ? get_permalink($postId) : '',
      ],
      'builderUrls' => [
        'page' => $pageId > 0 ? home_url('/?oxygen=builder&id=' . $pageId) : '',
        'template' => $templateId > 0 ? home_url('/?oxygen=builder&id=' . $templateId) : '',
        'header' => $headerId > 0 ? home_url('/?oxygen=builder&id=' . $headerId) : '',
        'footer' => $footerId > 0 ? home_url('/?oxygen=builder&id=' . $footerId) : '',
      ],
      'siteOptions' => [
        'show_on_front' => get_option('show_on_front'),
        'page_on_front' => (int) get_option('page_on_front'),
        'page_for_posts' => (int) get_option('page_for_posts'),
      ],
      'menu' => [
        'locations' => $locations,
        'primaryMenuId' => $primaryMenuId,
        'items' => array_values($menuItems),
      ],
      'templateSettings' => [
        'template' => $templateSettings($templateId),
        'header' => $templateSettings($headerId),
        'footer' => $templateSettings($footerId),
        'part' => $templateSettings($partId),
      ],
      'hasTree' => [
        'page' => $hasTree($pageId),
        'template' => $hasTree($templateId),
        'header' => $hasTree($headerId),
        'footer' => $hasTree($footerId),
        'part' => $hasTree($partId),
      ],
    ]);
  `;

  return {
    ...JSON.parse(runDockerPhp(options.container, php)),
    cleanup,
  };
}

function assertSiteKitImportProof(proof) {
  if (!proof || proof.ok !== true) {
    const result = proof?.result || {};
    const errors = [
      ...(Array.isArray(result.errors) ? result.errors : []),
      ...(Array.isArray(result.restore?.errors) ? result.restore.errors : []),
    ];
    throw new Error(
      [
        `Site-kit import failed: ${proof?.message || "unknown error"}`,
        errors.length ? `Errors: ${errors.join(" | ")}` : "",
      ].filter(Boolean).join("\n")
    );
  }

  const ids = proof.ids || {};
  for (const key of ["page", "template", "header", "footer", "part", "post"]) {
    if (!Number.isInteger(Number(ids[key])) || Number(ids[key]) < 1) {
      throw new Error(`Site-kit proof is missing ${key} ID.`);
    }
  }

  const objects = proof.import?.objects || {};
  for (const section of ["pages", "templates", "headers", "footers", "parts"]) {
    if (!Array.isArray(objects[section]) || objects[section].length < 1) {
      throw new Error(`Site-kit import did not report ${section}.`);
    }
  }

  if (proof.siteOptions?.show_on_front !== "page") {
    throw new Error("Site-kit import did not set show_on_front to page.");
  }

  if (Number(proof.siteOptions?.page_on_front) !== Number(ids.page)) {
    throw new Error("Site-kit import did not assign the imported page as homepage.");
  }

  if (Number(proof.menu?.locations?.primary) !== Number(proof.menu?.primaryMenuId)) {
    throw new Error("Site-kit import did not assign a primary menu location.");
  }

  const hasHomeMenuItem = (proof.menu?.items || []).some((item) =>
    Number(item.objectId) === Number(ids.page) && item.type === "post_type"
  );
  if (!hasHomeMenuItem) {
    throw new Error("Site-kit primary menu does not target the imported homepage.");
  }

  if (proof.templateSettings?.template?.type !== "all-singles") {
    throw new Error("Site-kit single template settings were not persisted.");
  }

  if (proof.templateSettings?.header?.type !== "everywhere") {
    throw new Error("Site-kit header settings were not persisted.");
  }

  if (proof.templateSettings?.footer?.type !== "everywhere") {
    throw new Error("Site-kit footer settings were not persisted.");
  }

  for (const key of ["page", "template", "header", "footer", "part"]) {
    if (proof.hasTree?.[key] !== true) {
      throw new Error(`Site-kit ${key} is missing persisted Oxygen tree data.`);
    }
  }

  for (const key of ["home", "page", "post"]) {
    if (typeof proof.urls?.[key] !== "string" || proof.urls[key] === "") {
      throw new Error(`Site-kit proof is missing ${key} URL.`);
    }
  }
}

function normalizeFixturePath(value) {
  return String(value || "")
    .replace(/\\/g, "/")
    .replace(/^\/+/, "")
    .replace(/\/+/g, "/")
    .trim();
}

function localFixturePath(localFixtureDir, fixture) {
  return path.join(localFixtureDir, ...normalizeFixturePath(fixture).split("/"));
}

function resolveSiteKitManifestPath(options) {
  const configured = String(options.siteKitManifest || DEFAULT_SITE_KIT_MANIFEST);
  if (path.isAbsolute(configured)) {
    return configured;
  }

  return localFixturePath(options.localFixtureDir, configured);
}

function ensureSiteKitManifestInContainer(options) {
  const localPath = resolveSiteKitManifestPath(options);
  if (!fs.existsSync(localPath)) {
    throw new Error(`Site-kit manifest does not exist: ${localPath}`);
  }

  const remotePath = `/tmp/oxy-html-converter-site-kit-${Date.now()}-${process.pid}.json`;
  runCommand("docker", ["cp", localPath, `${options.container}:${remotePath}`]);

  return {
    localPath,
    remotePath,
  };
}

function fixtureNameForSlug(fixture) {
  return String(fixture)
    .replace(/\\/g, "/")
    .replace(/\/code\.html$/i, "")
    .replace(/\.html$/i, "")
    .replace(/\//g, "-");
}

function loadFixtureSummary(jsonPath) {
  return JSON.parse(fs.readFileSync(jsonPath, "utf8"));
}

function sortFixturePagesBySlugOrder(pages, orderedSlugs) {
  const order = new Map();
  orderedSlugs.forEach((slug, index) => {
    order.set(slug, index);
  });

  return [...pages].sort((left, right) => {
    const leftOrder = order.has(left.slug) ? order.get(left.slug) : Number.MAX_SAFE_INTEGER;
    const rightOrder = order.has(right.slug) ? order.get(right.slug) : Number.MAX_SAFE_INTEGER;

    if (leftOrder !== rightOrder) {
      return leftOrder - rightOrder;
    }

    return String(left.slug || "").localeCompare(String(right.slug || ""));
  });
}

function loadFixturePages(container, summary, slugPrefix = "perf-") {
  const slugs = summary.fixtures.map((fixture) =>
    buildSlug(fixtureNameForSlug(fixture.fixture), slugPrefix)
  );
  const slugsJson = JSON.stringify(slugs)
    .replace(/\\/g, "\\\\")
    .replace(/'/g, "\\'");
  const php = `
    require '/var/www/html/wp-load.php';
    $wanted = json_decode('${slugsJson}', true);
    $posts = get_posts(['post_type' => 'page', 'numberposts' => 50, 'post_status' => ['publish', 'draft', 'private']]);
    $matches = [];
    foreach ($posts as $post) {
      if (in_array($post->post_name, $wanted, true)) {
        $matches[] = [
          'id' => (int) $post->ID,
          'slug' => $post->post_name,
          'title' => $post->post_title,
          'url' => get_permalink($post),
        ];
      }
    }
    echo wp_json_encode($matches);
  `;

  return sortFixturePagesBySlugOrder(JSON.parse(runDockerPhp(container, php)), slugs);
}

function classifyObservation(text, blocking, ambient) {
  if (!text || isIgnorableRuntimeError(text)) {
    return;
  }

  if (isCanceledNavigationRequestFailure(text)) {
    ambient.push(text);
    return;
  }

  if (isPluginSignal(text) || isConvertedAssetRuntimeError(text)) {
    blocking.push(text);
    return;
  }

  ambient.push(text);
}

function isCanceledNavigationRequestFailure(text) {
  const normalized = String(text || "");
  return (
    /\bGET\b/i.test(normalized) &&
    /(?:net::ERR_ABORTED|NS_BINDING_ABORTED|Request canceled|request cancelled)/i.test(normalized)
  );
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

async function runAdminSmoke(page, baseUrl) {
  logStep("Running admin converter smoke");
  await page.goto(`${baseUrl}${DEFAULT_TOOL_PATH}`, {
    waitUntil: "load",
    timeout: 30000,
  });

  await page.click("#oxy-load-example-btn");
  await page.click("#oxy-preview-btn");
  await page.waitForFunction(() => {
    const preview = document.getElementById("oxy-preview-result");
    return preview && !preview.hidden;
  });

  const previewText = await page.locator("#oxy-preview-content").innerText();
  if (!/Total elements|Element types/i.test(previewText)) {
    throw new Error("Admin preview smoke did not render preview content.");
  }

  await page.click("#oxy-convert-btn");
  await page.waitForFunction(() => {
    const panel = document.getElementById("oxy-json-result");
    const output = document.getElementById("oxy-json-output");
    return panel && !panel.hidden && output && output.value.trim().length > 0;
  });

  const auditText = await page.locator("#oxy-audit-summary").innerText();
  if (!/Conversion audit/i.test(auditText)) {
    throw new Error("Admin convert smoke did not render audit output.");
  }
}

function isBuilderReadySnapshot(snapshot) {
  if (!snapshot || typeof snapshot !== "object") {
    return false;
  }

  return (
    snapshot.hasSaveButton === true &&
    snapshot.saveButtonIdle === true &&
    snapshot.hasDocumentTree === true &&
    snapshot.hasEditabilityHelper === true
  );
}

function resolveBuilderEditabilityHelperFromWindow(rootWindow) {
  const candidates = [rootWindow];

  try {
    if (
      rootWindow &&
      rootWindow.parent &&
      rootWindow.parent !== rootWindow
    ) {
      candidates.push(rootWindow.parent);
    }
  } catch (error) {
    // Ignore cross-origin parent access.
  }

  try {
    if (
      rootWindow &&
      rootWindow.top &&
      !candidates.includes(rootWindow.top)
    ) {
      candidates.push(rootWindow.top);
    }
  } catch (error) {
    // Ignore cross-origin top access.
  }

  for (const candidate of candidates) {
    const helper = candidate?.OxyHtmlConverterBuilderEditability || null;
    if (helper && typeof helper.mutateNativeTextNode === "function") {
      return helper;
    }
  }

  return null;
}

async function waitForBuilderReady(page) {
  await page.getByRole("button", { name: /^Save$/ }).waitFor({
    timeout: 60000,
  });
  await page
    .waitForFunction(
      () => {
        const rootWindow = window.parent || window;
        const rootDocument = rootWindow.document || document;
        const saveButton = Array.from(rootDocument.querySelectorAll("button")).find(
          (button) => /^save$/i.test(String(button?.innerText || "").trim())
        );
        const documentStore =
          window.Breakdance?.stores?.documentStore ||
          rootWindow.Breakdance?.stores?.documentStore ||
          null;
        const state =
          rootDocument.querySelector(".v-application")?.__vue__?.$store?.state ||
          rootDocument.querySelector(".v-application")?.__vue_app__?.config?.globalProperties?.$store?.state ||
          {};
        const treeCandidates = [
          documentStore?.document?.tree,
          state.tree,
          state.elements,
          state.documentTree,
          state.document?.document?.tree,
          state.breakdance?.tree,
          state.breakdanceState?.tree,
          state.oxygen?.tree,
          state.builder?.tree,
          state.document?.tree,
        ];
        const helperCandidates = [window, rootWindow];
        try {
          if (
            rootWindow &&
            rootWindow.top &&
            !helperCandidates.includes(rootWindow.top)
          ) {
            helperCandidates.push(rootWindow.top);
          }
        } catch (error) {
          // Cross-origin top windows are irrelevant for the local builder proof.
        }
        const helper = helperCandidates
          .map((candidate) => candidate?.OxyHtmlConverterBuilderEditability || null)
          .find(
            (candidate) =>
              candidate &&
              typeof candidate.mutateNativeTextNode === "function"
          );

        return (
          saveButton instanceof HTMLElement &&
          !saveButton.disabled &&
          saveButton.getAttribute("aria-disabled") !== "true" &&
          treeCandidates.some((candidate) => candidate && typeof candidate === "object") &&
          helper &&
          typeof helper.mutateNativeTextNode === "function"
        );
      },
      null,
      { timeout: 60000 }
    )
    .catch(() => {
      throw new Error("Oxygen builder did not reach a ready runtime state.");
    });
}

async function clickBuilderSave(page, label) {
  const saveButton = page.getByRole("button", { name: /^Save$/ }).first();
  await saveButton.waitFor({ state: "attached", timeout: 30000 });

  try {
    await saveButton.click({ timeout: 10000 });
    return { strategy: "role-click" };
  } catch (error) {
    const reason = String(error?.message || error);
    const usedFallback = await saveButton.evaluate((button) => {
      if (!(button instanceof HTMLElement)) {
        return false;
      }

      button.scrollIntoView({ block: "center", inline: "center" });
      button.click();
      return true;
    });

    if (!usedFallback) {
      throw new Error(`${label} could not click the Oxygen save button: ${reason}`);
    }

    return {
      strategy: "dom-click",
      reason: reason.split("\n").slice(0, 2).join(" "),
    };
  }
}

function isBuilderSaveRequestDetails(url, method, postData) {
  const normalizedUrl = String(url || "");
  const normalizedMethod = String(method || "").toUpperCase();
  const normalizedPostData = String(postData || "");

  if (normalizedMethod !== "POST") {
    return false;
  }

  if (!/\/wp-admin\/admin-ajax\.php\b/i.test(normalizedUrl)) {
    return false;
  }

  return (
    /(?:^|[?&])action=breakdance_save(?:&|$)/i.test(normalizedUrl) ||
    /name="action"\s*\r?\n\r?\nbreakdance_save\b/i.test(normalizedPostData) ||
    /(?:^|[?&])action=breakdance_save(?:&|$)/i.test(normalizedPostData)
  );
}

function isBuilderSaveResponsePayloadSuccessful(payloadText) {
  const normalizedPayload = String(payloadText || "").trim();
  if (!normalizedPayload) {
    return true;
  }

  try {
    const parsed = JSON.parse(normalizedPayload);
    if (parsed && typeof parsed === "object") {
      if (parsed.success === false) {
        return false;
      }

      if (
        typeof parsed.status === "string" &&
        /error|failed|failure/i.test(parsed.status)
      ) {
        return false;
      }
    }
  } catch (error) {
    // Non-JSON responses still count as successful unless they expose a known builder error.
  }

  return !/Validation Error|IO-TS decoding failed|\"success\"\s*:\s*false/i.test(
    normalizedPayload
  );
}

async function waitForBuilderSaveButtonIdle(page, label) {
  await page.getByRole("button", { name: /^Save$/ }).first().waitFor({
    state: "attached",
    timeout: 30000,
  });

  await page
    .waitForFunction(() => {
      const buttons = Array.from(document.querySelectorAll("button"));
      const saveButton = buttons.find((button) =>
        /^save$/i.test(String(button?.innerText || "").trim())
      );

      if (!(saveButton instanceof HTMLElement)) {
        return false;
      }

      return (
        !saveButton.disabled &&
        saveButton.getAttribute("aria-disabled") !== "true"
      );
    }, null, { timeout: 30000 })
    .catch(() => {
      throw new Error(`${label} did not return to an idle Save state.`);
    });
}

async function waitForBuilderSaveCompletion(page, label) {
  const saveRequestPromise = page
    .waitForRequest(
      (request) =>
        isBuilderSaveRequestDetails(
          request.url(),
          request.method(),
          request.postData() || ""
        ),
      { timeout: 30000 }
    )
    .catch(() => null);

  const saveResponsePromise = page
    .waitForResponse(
      (response) =>
        isBuilderSaveRequestDetails(
          response.url(),
          response.request().method(),
          response.request().postData() || ""
        ),
      { timeout: 30000 }
    )
    .catch(() => null);

  const saveResult = await clickBuilderSave(page, label);
  const saveRequest = await saveRequestPromise;
  if (!saveRequest) {
    throw new Error(`${label} did not issue a breakdance_save request.`);
  }

  const saveResponse = await saveResponsePromise;
  if (!saveResponse) {
    throw new Error(`${label} did not receive a breakdance_save response.`);
  }

  const responseStatus =
    typeof saveResponse.status === "function" ? saveResponse.status() : 0;
  if (responseStatus >= 400) {
    throw new Error(
      `${label} received HTTP ${responseStatus} from breakdance_save.`
    );
  }

  const responsePayload =
    typeof saveResponse.text === "function"
      ? await saveResponse.text().catch(() => "")
      : "";

  if (!isBuilderSaveResponsePayloadSuccessful(responsePayload)) {
    throw new Error(`${label} returned an unsuccessful breakdance_save payload.`);
  }

  await waitForBuilderSaveButtonIdle(page, label);

  return {
    ...saveResult,
    responseStatus,
  };
}

async function assertNoBuilderErrors(page, label) {
  const bodyText = await page.locator("body").innerText();
  if (/Validation Error|IO-TS decoding failed/i.test(bodyText)) {
    throw new Error(`${label} surfaced a builder validation error.`);
  }
}

async function getBuilderStoreNodeCount(page) {
  return page.evaluate(() => {
    const directTree =
      window.Breakdance?.stores?.documentStore?.document?.tree ||
      window.parent?.Breakdance?.stores?.documentStore?.document?.tree ||
      null;
    const rootDocument = window.parent?.document || document;
    const app = rootDocument.querySelector(".v-application");
    const store =
      app?.__vue__?.$store ||
      app?.__vue_app__?.config?.globalProperties?.$store ||
      null;
    const state = store?.state || {};
    const candidates = [
      state.tree,
      state.elements,
      state.documentTree,
      state.document?.document?.tree,
      state.breakdance?.tree,
      state.breakdanceState?.tree,
      state.oxygen?.tree,
      state.builder?.tree,
      state.document?.tree,
    ];
    const tree =
      (directTree && typeof directTree === "object" ? directTree : null) ||
      candidates.find((candidate) => candidate && typeof candidate === "object") ||
      null;

    if (!tree) {
      return null;
    }

    function countNodes(node, seen = new Set()) {
      if (!node || typeof node !== "object" || seen.has(node)) {
        return 0;
      }
      seen.add(node);

      let count = node.id || node.data?.type ? 1 : 0;
      const children = Array.isArray(node)
        ? node
        : Array.isArray(node.children)
          ? node.children
          : Array.isArray(node.root?.children)
            ? [node.root, ...node.root.children]
            : Object.values(node);

      for (const child of children) {
        count += countNodes(child, seen);
      }

      return count;
    }

    return countNodes(tree);
  });
}

async function waitForBuilderStoreNodeIncrease(page, beforeCount, label) {
  if (beforeCount === null) {
    throw new Error(`${label} could not read the Oxygen builder store node count before import.`);
  }

  const afterCount = await page
    .waitForFunction(
      (initialCount) => {
        const directTree =
          window.Breakdance?.stores?.documentStore?.document?.tree ||
          window.parent?.Breakdance?.stores?.documentStore?.document?.tree ||
          null;
        const app = document.querySelector(".v-application");
        const store =
          app?.__vue__?.$store ||
          app?.__vue_app__?.config?.globalProperties?.$store ||
          null;
        const state = store?.state || {};
        const candidates = [
          state.tree,
          state.elements,
          state.documentTree,
          state.document?.document?.tree,
          state.breakdance?.tree,
          state.breakdanceState?.tree,
          state.oxygen?.tree,
          state.builder?.tree,
          state.document?.tree,
        ];
        const tree =
          (directTree && typeof directTree === "object" ? directTree : null) ||
          candidates.find((candidate) => candidate && typeof candidate === "object") ||
          null;

        if (!tree) {
          return false;
        }

        function countNodes(node, seen = new Set()) {
          if (!node || typeof node !== "object" || seen.has(node)) {
            return 0;
          }
          seen.add(node);

          let count = node.id || node.data?.type ? 1 : 0;
          const children = Array.isArray(node)
            ? node
            : Array.isArray(node.children)
              ? node.children
              : Array.isArray(node.root?.children)
                ? [node.root, ...node.root.children]
                : Object.values(node);

          for (const child of children) {
            count += countNodes(child, seen);
          }

          return count;
        }

        const nextCount = countNodes(tree);
        return nextCount > initialCount ? nextCount : false;
      },
      beforeCount,
      { timeout: 30000 }
    )
    .then((handle) => handle.jsonValue())
    .catch(() => null);

  if (afterCount === null) {
    throw new Error(`${label} did not increase the Oxygen builder store node count.`);
  }

  return afterCount;
}

async function assertBuilderTextPresent(page, text, label) {
  await page
    .waitForFunction(
      (expectedText) => {
        function documentHasText(doc) {
          return Boolean(doc?.body?.innerText?.includes(expectedText));
        }

        function collectDocuments(rootDocument, docs = []) {
          if (!rootDocument || docs.includes(rootDocument)) {
            return docs;
          }

          docs.push(rootDocument);
          for (const frame of rootDocument.querySelectorAll("iframe")) {
            try {
              collectDocuments(frame.contentDocument, docs);
            } catch (error) {
              // Cross-origin frames are irrelevant for the local builder proof.
            }
          }

          return docs;
        }

        function valueContainsText(value, seen = new Set(), budget = { count: 0 }) {
          if (budget.count > 30000) {
            return false;
          }
          budget.count += 1;

          if (typeof value === "string") {
            return value.includes(expectedText);
          }

          if (!value || typeof value !== "object" || seen.has(value)) {
            return false;
          }

          seen.add(value);
          if (Array.isArray(value)) {
            return value.some((item) => valueContainsText(item, seen, budget));
          }

          return Object.values(value).some((item) =>
            valueContainsText(item, seen, budget)
          );
        }

        const rootDocument = window.parent?.document || document;
        const docs = collectDocuments(rootDocument);
        if (docs.some(documentHasText)) {
          return true;
        }

        if (
          valueContainsText(window.Breakdance?.stores?.documentStore?.document?.tree || {}) ||
          valueContainsText(window.parent?.Breakdance?.stores?.documentStore?.document?.tree || {})
        ) {
          return true;
        }

        const app = rootDocument.querySelector(".v-application");
        const store =
          app?.__vue__?.$store ||
          app?.__vue_app__?.config?.globalProperties?.$store ||
          null;

        return valueContainsText(store?.state || {});
      },
      text,
      { timeout: 30000 }
    )
    .catch(() => {
      throw new Error(`${label} did not find expected builder text: ${text}`);
    });
}

async function assertBuilderVisibleTextPresent(page, text, label) {
  await page
    .waitForFunction(
      (expectedText) => {
        function collectDocuments(rootDocument, docs = []) {
          if (!rootDocument || docs.includes(rootDocument)) {
            return docs;
          }

          docs.push(rootDocument);
          for (const frame of rootDocument.querySelectorAll("iframe")) {
            try {
              collectDocuments(frame.contentDocument, docs);
            } catch (error) {
              // Cross-origin frames are irrelevant for the local builder proof.
            }
          }

          return docs;
        }

        function isVisibleElement(element) {
          const view = element?.ownerDocument?.defaultView || window;
          if (!(element instanceof view.HTMLElement)) {
            return false;
          }

          const tagName = String(element.tagName || "").toUpperCase();
          if (["SCRIPT", "STYLE", "NOSCRIPT", "TEMPLATE", "HEAD"].includes(tagName)) {
            return false;
          }

          const style = view.getComputedStyle(element);
          if (
            style.display === "none" ||
            style.visibility === "hidden" ||
            Number(style.opacity || "1") === 0
          ) {
            return false;
          }

          const rect = element.getBoundingClientRect();
          return rect.width > 1 && rect.height > 1;
        }

        const rootDocument = window.parent?.document || document;
        const docs = collectDocuments(rootDocument);
        for (const doc of docs) {
          const elements = Array.from(doc.querySelectorAll("body, body *")).reverse();
          if (
            elements.some((element) => {
              if (!isVisibleElement(element)) {
                return false;
              }

              return String(element.innerText || "").includes(expectedText);
            })
          ) {
            return true;
          }
        }

        return false;
      },
      text,
      { timeout: 30000 }
    )
    .catch(() => {
      throw new Error(`${label} did not render visible Builder canvas text: ${text}`);
    });
}

async function loadBuilderNodePropertyProof(page, nodeId, propertyPath) {
  return page.evaluate(
    ({ targetNodeId, targetPropertyPath }) => {
      function getRootDocument() {
        return window.parent?.document || document;
      }

      function getDocumentStore() {
        return (
          window.Breakdance?.stores?.documentStore ||
          window.parent?.Breakdance?.stores?.documentStore ||
          null
        );
      }

      function getStore() {
        const rootDocument = getRootDocument();
        const app = rootDocument.querySelector(".v-application");
        return (
          app?.__vue__?.$store ||
          app?.__vue_app__?.config?.globalProperties?.$store ||
          null
        );
      }

      function getTree() {
        const documentStore = getDocumentStore();
        const directTree = documentStore?.document?.tree || null;
        if (directTree && typeof directTree === "object") {
          return directTree;
        }

        const state = getStore()?.state || {};
        const candidates = [
          state.tree,
          state.elements,
          state.documentTree,
          state.document?.document?.tree,
          state.breakdance?.tree,
          state.breakdanceState?.tree,
          state.oxygen?.tree,
          state.builder?.tree,
          state.document?.tree,
        ];

        return candidates.find((candidate) => candidate && typeof candidate === "object") || null;
      }

      function visit(node, visitor, seen = new Set()) {
        if (!node || typeof node !== "object" || seen.has(node)) {
          return;
        }

        seen.add(node);
        visitor(node);

        const children = Array.isArray(node)
          ? node
          : Array.isArray(node.children)
            ? node.children
            : Array.isArray(node.root?.children)
              ? [node.root, ...node.root.children]
              : [];

        for (const child of children) {
          visit(child, visitor, seen);
        }
      }

      function readPropertyPath(source, path) {
        return String(path || "")
          .split(".")
          .filter(Boolean)
          .reduce((value, segment) => (value && typeof value === "object" ? value[segment] : undefined), source);
      }

      const tree = getTree();
      if (!tree) {
        return {
          ok: false,
          reason: "missing-tree",
        };
      }

      let match = null;
      visit(tree, (node) => {
        if (match || Number(node?.id) !== Number(targetNodeId)) {
          return;
        }

        match = {
          id: Number(node.id) || 0,
          type: String(node?.data?.type || ""),
          value: readPropertyPath(node?.data?.properties || {}, targetPropertyPath),
        };
      });

      if (!match) {
        return {
          ok: false,
          reason: "missing-node",
        };
      }

      return {
        ok: true,
        ...match,
      };
    },
    { targetNodeId: nodeId, targetPropertyPath: propertyPath }
  );
}

const FOCUSED_IMPORT_MARKER_TEXT = "Native Maximus Live Proof";
const FOCUSED_IMPORT_EDITABILITY_TEXT =
  "Native Maximus Live Proof Editability Anchor";
const FOCUSED_STYLE_ROUTING_EXPECTATION = {
  targetText: FOCUSED_IMPORT_MARKER_TEXT,
  expectedTypeNot: "OxygenElements\\HtmlCode",
  properties: {
    "design.spacing.spacing.padding.top.style": "var(--ohc-space-10px)",
    "design.size.width.style": "var(--ohc-measure-120px)",
    "design.typography.color": "var(--ohc-color-123456)",
  },
};

function decodeHtmlEntities(value) {
  return String(value || "")
    .replace(/&nbsp;/gi, " ")
    .replace(/&amp;/gi, "&")
    .replace(/&lt;/gi, "<")
    .replace(/&gt;/gi, ">")
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'");
}

function normalizeCandidateText(value) {
  return decodeHtmlEntities(value)
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

function minimumEditabilityCandidateLength(tagName) {
  return /^h[1-3]$/i.test(String(tagName || "")) ? 16 : 24;
}

function extractEditabilityTargetText(fixtureHtml) {
  const sanitizedHtml = String(fixtureHtml || "")
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, " ")
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, " ")
    .replace(/<noscript\b[^>]*>[\s\S]*?<\/noscript>/gi, " ");
  const candidates = [];

  function collectCandidates(pattern, { requireLeaf = false } = {}) {
    let match = null;

    while ((match = pattern.exec(sanitizedHtml))) {
      const innerHtml = match[2] || "";
      if (requireLeaf && /<[a-z][\w:-]*(?:\s|>)/i.test(innerHtml)) {
        continue;
      }

      const text = normalizeCandidateText(innerHtml);
      const tagName = match[1] || "";
      if (
        text.length < minimumEditabilityCandidateLength(tagName) ||
        text === FOCUSED_IMPORT_MARKER_TEXT ||
        !/[\p{L}\p{N}]/u.test(text)
      ) {
        continue;
      }

      candidates.push(text);
    }
  }

  collectCandidates(/<(h1|h2|h3|p|li|a|span|button)\b[^>]*>([\s\S]*?)<\/\1>/gi, {
    requireLeaf: true,
  });

  if (candidates.length === 0) {
    collectCandidates(/<(h1|h2|h3|p|li|a|span|div|section)\b[^>]*>([\s\S]*?)<\/\1>/gi);
  }

  return candidates[0] || "";
}

function buildFocusedImportProof(fixtureHtml) {
  const fixturePresenceText = extractEditabilityTargetText(fixtureHtml);
  if (!fixturePresenceText) {
    throw new Error("Focused fixture did not contain a stable text candidate for editability proof.");
  }
  const marker = `<section class="maximus-live-proof-marker"><h2 style="padding: 10px; width: 120px; color: #123456;">${FOCUSED_IMPORT_MARKER_TEXT}</h2><p>${FOCUSED_IMPORT_EDITABILITY_TEXT}</p><p>Builder imported Maximus fixture through the plugin.</p></section>`;
  const editabilityTargetText = fixturePresenceText;
  const expectedTexts = Array.from(new Set([
    FOCUSED_IMPORT_MARKER_TEXT,
    editabilityTargetText,
    fixturePresenceText,
  ]));
  let importHtml = "";

  if (/<body\b[^>]*>/i.test(fixtureHtml)) {
    importHtml = fixtureHtml.replace(/(<body\b[^>]*>)/i, `$1\n${marker}`);
  } else {
    importHtml = [marker, fixtureHtml].join("\n");
  }

  return {
    importHtml,
    expectedTexts,
    editabilityTargetText,
    fixturePresenceText,
    styleRoutingExpectation: FOCUSED_STYLE_ROUTING_EXPECTATION,
  };
}

function loadFocusedImportProof(options) {
  if (!options.fixture) {
    return null;
  }

  const fixturePath = localFixturePath(options.localFixtureDir, options.fixture);
  if (!fs.existsSync(fixturePath)) {
    throw new Error(`Focused fixture does not exist: ${fixturePath}`);
  }

  return buildFocusedImportProof(fs.readFileSync(fixturePath, "utf8"));
}

function buildEditedProofText(sourceText, fixtureSlug) {
  return `${sourceText} Edited ${fixtureSlug}`;
}

async function loadBuilderStyleRoutingProof(page, expectation, label) {
  const proof = await page.evaluate((expected) => {
    function getRootDocument() {
      return window.parent?.document || document;
    }

    function getDocumentStore() {
      return (
        window.Breakdance?.stores?.documentStore ||
        window.parent?.Breakdance?.stores?.documentStore ||
        null
      );
    }

    function getStore() {
      const rootDocument = getRootDocument();
      const app = rootDocument.querySelector(".v-application");
      return (
        app?.__vue__?.$store ||
        app?.__vue_app__?.config?.globalProperties?.$store ||
        null
      );
    }

    function getTree() {
      const documentStore = getDocumentStore();
      const directTree = documentStore?.document?.tree || null;
      if (directTree && typeof directTree === "object") {
        return directTree;
      }

      const state = getStore()?.state || {};
      const candidates = [
        state.tree,
        state.elements,
        state.documentTree,
        state.document?.document?.tree,
        state.breakdance?.tree,
        state.breakdanceState?.tree,
        state.oxygen?.tree,
        state.builder?.tree,
        state.document?.tree,
      ];

      return candidates.find((candidate) => candidate && typeof candidate === "object") || null;
    }

    function visit(node, visitor, seen = new Set()) {
      if (!node || typeof node !== "object" || seen.has(node)) {
        return;
      }

      seen.add(node);
      visitor(node);

      const children = Array.isArray(node)
        ? node
        : Array.isArray(node.children)
          ? node.children
          : Array.isArray(node.root?.children)
            ? [node.root, ...node.root.children]
            : [];

      for (const child of children) {
        visit(child, visitor, seen);
      }
    }

    function valueContainsText(value, targetText, seen = new Set()) {
      if (typeof value === "string") {
        return value.includes(targetText);
      }

      if (!value || typeof value !== "object" || seen.has(value)) {
        return false;
      }

      seen.add(value);

      if (Array.isArray(value)) {
        return value.some((item) => valueContainsText(item, targetText, seen));
      }

      return Object.values(value).some((item) =>
        valueContainsText(item, targetText, seen)
      );
    }

    function readPropertyPath(source, propertyPath) {
      return String(propertyPath || "")
        .split(".")
        .filter(Boolean)
        .reduce(
          (value, segment) =>
            value && typeof value === "object" ? value[segment] : undefined,
          source
        );
    }

    const tree = getTree();
    if (!tree) {
      return {
        ok: false,
        reason: "missing-tree",
      };
    }

    const matches = [];
    visit(tree, (node) => {
      const properties = node?.data?.properties || {};
      if (!valueContainsText(properties, expected.targetText)) {
        return;
      }

      const propertyValues = {};
      for (const propertyPath of Object.keys(expected.properties || {})) {
        propertyValues[propertyPath] = readPropertyPath(properties, propertyPath);
      }

      matches.push({
        id: Number(node.id) || 0,
        type: String(node?.data?.type || ""),
        propertyValues,
      });
    });

    const nativeMatches = matches.filter(
      (match) => match.type !== expected.expectedTypeNot
    );
    const routedMatch = nativeMatches.find((match) =>
      Object.entries(expected.properties || {}).every(
        ([propertyPath, expectedValue]) =>
          match.propertyValues[propertyPath] === expectedValue
      )
    );

    return {
      ok: Boolean(routedMatch),
      reason: routedMatch ? "" : "missing-native-style-routed-node",
      targetText: expected.targetText,
      expectedTypeNot: expected.expectedTypeNot,
      expectedProperties: expected.properties || {},
      id: routedMatch?.id || 0,
      type: routedMatch?.type || "",
      propertyValues: routedMatch?.propertyValues || {},
      matches,
    };
  }, expectation);

  if (!proof || proof.ok !== true) {
    const matches = Array.isArray(proof?.matches)
      ? proof.matches
          .map((match) => `${match.type || "unknown"}#${match.id || "?"}`)
          .join(", ")
      : "none";
    throw new Error(
      `${label} did not route focused inline styles to a native Oxygen node. Matches: ${matches}`
    );
  }

  if (proof.type === expectation.expectedTypeNot) {
    throw new Error(`${label} resolved focused style routing to ${proof.type}.`);
  }

  return proof;
}

async function mutateBuilderTextNode(page, expectedText, replacementText, label) {
  const proof = await page.evaluate(
    ({ targetText, nextText }) => {
      const helperCandidates = [window];
      try {
        if (window.parent && window.parent !== window) {
          helperCandidates.push(window.parent);
        }
      } catch (error) {
        // Ignore cross-origin parent access.
      }
      try {
        if (window.top && !helperCandidates.includes(window.top)) {
          helperCandidates.push(window.top);
        }
      } catch (error) {
        // Ignore cross-origin top access.
      }

      const helper = helperCandidates
        .map((candidate) => candidate?.OxyHtmlConverterBuilderEditability || null)
        .find(
          (candidate) =>
            candidate &&
            typeof candidate.mutateNativeTextNode === "function"
        );

      if (!helper || typeof helper.mutateNativeTextNode !== "function") {
        return {
          ok: false,
          reason: "missing-editability-helper",
        };
      }

      return helper.mutateNativeTextNode({
        targetText,
        nextText,
        allowDirectMutation: false,
      });
    },
    { targetText: expectedText, nextText: replacementText }
  );

  if (!proof || proof.ok !== true) {
    throw new Error(
      `${label} could not mutate a native builder text node for "${expectedText}". Reason: ${proof?.reason || "unknown"}`
    );
  }

  if (proof.type === "OxygenElements\\HtmlCode") {
    throw new Error(`${label} resolved the editability target to HtmlCode.`);
  }

  if (proof.mutationStrategy === "tree-direct") {
    throw new Error(
      `${label} fell back to direct tree mutation instead of a builder store/API path.`
    );
  }

  await assertBuilderTextPresent(page, replacementText, label);

  return proof;
}

async function runBuilderModalSmoke(page, fixtureSlug, importHtml = null, expectedTexts = ["Live Gate"]) {
  logStep(`Running builder modal smoke on ${fixtureSlug}`);
  const beforeCount = await getBuilderStoreNodeCount(page);
  const html =
    importHtml ||
    '<section class="live-gate-smoke"><h2>Live Gate</h2><p>Builder modal smoke content.</p></section>';

  const modalHookReady = await page
    .waitForFunction(
      () => typeof window.oxyHtmlConverterOpenModal === "function",
      null,
      { timeout: 60000 }
    )
    .then(() => true)
    .catch(() => false);

  if (modalHookReady) {
    await page.evaluate(() => {
      window.oxyHtmlConverterOpenModal();
    });
  } else {
    await page.keyboard.press("Control+Shift+H");
  }

  await page.locator("#oxy-html-import-input").waitFor({ timeout: 30000 });
  await page.fill("#oxy-html-import-input", html);
  await page.click("#oxy-html-import-submit");
  await page.waitForFunction(() => {
    const modal = document.querySelector(
      "#oxy-html-import-modal .oxy-html-modal-overlay"
    );
    return modal && modal.style.display !== "block";
  });
  await waitForBuilderStoreNodeIncrease(page, beforeCount, `Builder modal import for ${fixtureSlug}`);
  for (const expectedText of expectedTexts) {
    await assertBuilderTextPresent(page, expectedText, `Builder modal import for ${fixtureSlug}`);
  }
  await assertNoBuilderErrors(page, `Builder modal import for ${fixtureSlug}`);

  return expectedTexts;
}

async function writeClipboardHtml(page, html) {
  await page.evaluate(async (htmlInput) => {
    if (
      !navigator.clipboard ||
      typeof navigator.clipboard.write !== "function" ||
      typeof ClipboardItem === "undefined"
    ) {
      throw new Error("Clipboard HTML write is not available in this browser.");
    }

    const item = new ClipboardItem({
      "text/html": new Blob([htmlInput], { type: "text/html" }),
      "text/plain": new Blob([htmlInput], { type: "text/plain" }),
    });

    await navigator.clipboard.write([item]);
  }, html);
}

async function runBuilderPasteSmoke(page, fixtureSlug) {
  logStep(`Running builder paste smoke on ${fixtureSlug}`);
  const beforeCount = await getBuilderStoreNodeCount(page);
  const pasteHtml =
    '<section class="live-gate-paste"><h2>Paste Gate</h2><p>Builder paste smoke content.</p></section>';

  await page.locator("body").click({ position: { x: 24, y: 24 } });
  await page.evaluate(() => {
    if (
      document.activeElement &&
      typeof document.activeElement.blur === "function"
    ) {
      document.activeElement.blur();
    }
  });
  await writeClipboardHtml(page, pasteHtml);

  const toast = page.locator("#oxy-html-converter-toast");
  await page.keyboard.press("Control+V");
  await page.waitForFunction(() => {
    const toastElement = document.querySelector("#oxy-html-converter-toast");
    const text = toastElement?.innerText || "";
    return /converted|failed|unavailable|manual/i.test(text) && !/converting/i.test(text);
  }, null, { timeout: 60000 });

  const toastText = await toast.innerText();
  if (!/converted/i.test(toastText)) {
    throw new Error(
      `Builder paste smoke did not confirm native paste for ${fixtureSlug}. Toast: ${toastText}`
    );
  }

  await waitForBuilderStoreNodeIncrease(page, beforeCount, `Builder paste for ${fixtureSlug}`);
  await assertBuilderTextPresent(page, "Paste Gate", `Builder paste for ${fixtureSlug}`);
  await assertNoBuilderErrors(page, `Builder paste for ${fixtureSlug}`);

  return ["Paste Gate"];
}

function htmlToVisibleText(html) {
  return decodeHtmlEntities(String(html || ""))
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, " ")
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, " ")
    .replace(/<noscript\b[^>]*>[\s\S]*?<\/noscript>/gi, " ")
    .replace(/<[^>]+>/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

async function assertFrontendTextPresent(page, url, text, label) {
  const deadline = Date.now() + 45000;
  let lastFrontendText = "";
  let lastStatus = "no response";
  let attempt = 0;

  while (Date.now() < deadline) {
    const proofUrl = new URL(url);
    proofUrl.searchParams.set("_oxy_live_gate", `${Date.now()}_${attempt}`);
    attempt += 1;

    const response = await fetch(proofUrl.toString(), {
      headers: {
        "Cache-Control": "no-cache",
        Pragma: "no-cache",
      },
    }).catch(() => null);
    lastStatus = response
      ? `${response.status} ${response.statusText || ""}`.trim()
      : "no response";
    const html = response ? await response.text().catch(() => "") : "";
    lastFrontendText = htmlToVisibleText(html);

    if (response && response.ok && lastFrontendText.includes(text)) {
      return;
    }

    await page.waitForTimeout(1000);
  }

  throw new Error(
    `${label} did not find expected frontend text: ${text}. Last status: ${lastStatus}. Last frontend text sample: ${lastFrontendText.slice(0, 240)}`
  );
}

async function assertSiteKitBuilderTarget(page, target) {
  logStep(`Opening site-kit Builder target ${target.label} (#${target.id})`);
  await page.goto(target.url, {
    waitUntil: "domcontentloaded",
    timeout: 60000,
  });
  await waitForBuilderReady(page);
  await assertBuilderVisibleTextPresent(page, target.expectedText, `Site-kit Builder ${target.label}`);
  await assertBuilderTextPresent(page, target.expectedText, `Site-kit Builder ${target.label}`);
  await assertNoBuilderErrors(page, `Site-kit Builder ${target.label}`);

  const saveResult = await waitForBuilderSaveCompletion(
    page,
    `Site-kit Builder save ${target.label}`
  );
  logStep(`Site-kit Builder ${target.label} save HTTP status: ${saveResult.responseStatus}`);

  await page.goto(target.url, {
    waitUntil: "domcontentloaded",
    timeout: 60000,
  });
  await waitForBuilderReady(page);
  await assertBuilderVisibleTextPresent(page, target.expectedText, `Site-kit Builder reopen ${target.label}`);
  await assertBuilderTextPresent(page, target.expectedText, `Site-kit Builder reopen ${target.label}`);
  await assertNoBuilderErrors(page, `Site-kit Builder reopen ${target.label}`);

  return {
    label: target.label,
    id: target.id,
    url: target.url,
    expectedText: target.expectedText,
    initialVisibleText: true,
    initialStoreText: true,
    saveResponseStatus: saveResult.responseStatus,
    reopenedVisibleText: true,
    reopenedStoreText: true,
  };
}

async function runSiteKitSmoke(page, options) {
  logStep("Importing site-kit manifest for live site configuration smoke");
  const failureArtifact = path.join(options.outputDir, "site-kit-failure.json");
  let proof = null;
  try {
    proof = importSiteKitFixture(options);
    assertSiteKitImportProof(proof);
    normalizeOxygenUploadsPermissions(options.container);

    await assertFrontendTextPresent(
      page,
      proof.urls.home,
      SITE_KIT_EXPECTED_TEXTS.home,
      "Site-kit homepage content"
    );
    await assertFrontendTextPresent(
      page,
      proof.urls.home,
      SITE_KIT_EXPECTED_TEXTS.header,
      "Site-kit homepage header"
    );
    await assertFrontendTextPresent(
      page,
      proof.urls.home,
      SITE_KIT_EXPECTED_TEXTS.footer,
      "Site-kit homepage footer"
    );
    await assertFrontendTextPresent(
      page,
      proof.urls.post,
      SITE_KIT_EXPECTED_TEXTS.template,
      "Site-kit single post template"
    );
    await assertFrontendTextPresent(
      page,
      proof.urls.post,
      SITE_KIT_EXPECTED_TEXTS.header,
      "Site-kit single post header"
    );
    await assertFrontendTextPresent(
      page,
      proof.urls.post,
      SITE_KIT_EXPECTED_TEXTS.footer,
      "Site-kit single post footer"
    );

    const builderTargets = [
      {
        label: "page",
        id: proof.ids.page,
        url: proof.builderUrls.page,
        expectedText: SITE_KIT_EXPECTED_TEXTS.home,
      },
      {
        label: "header",
        id: proof.ids.header,
        url: proof.builderUrls.header,
        expectedText: SITE_KIT_EXPECTED_TEXTS.header,
      },
      {
        label: "footer",
        id: proof.ids.footer,
        url: proof.builderUrls.footer,
        expectedText: SITE_KIT_EXPECTED_TEXTS.footer,
      },
      {
        label: "template",
        id: proof.ids.template,
        url: proof.builderUrls.template,
        expectedText: SITE_KIT_EXPECTED_TEXTS.template,
      },
    ];

    const builderProofs = [];
    for (const target of builderTargets) {
      if (!target.url) {
        throw new Error(`Site-kit Builder URL is missing for ${target.label}.`);
      }
      builderProofs.push(await assertSiteKitBuilderTarget(page, target));
    }
    proof.builderProofs = builderProofs;
  } catch (error) {
    const screenshotPath = path.join(options.outputDir, "site-kit-failure.png");
    await page.screenshot({ path: screenshotPath, fullPage: true }).catch(() => null);
    const observations = options.observations || {};
    fs.writeFileSync(
      failureArtifact,
      JSON.stringify(
        {
          error: String(error && error.stack ? error.stack : error),
          screenshotPath,
          proof: proof || null,
          observations: {
            blocking: observations.blocking || {},
            ambient: observations.ambient || {},
          },
        },
        null,
        2
      )
    );
    throw error;
  }

  return proof;
}

async function writeLiveGateFailureArtifact(page, options, error, context = {}) {
  const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
  const screenshotPath = path.join(
    options.outputDir,
    `live-gate-failure-${timestamp}.png`
  );
  const artifactPath = path.join(
    options.outputDir,
    `live-gate-failure-${timestamp}.json`
  );
  const pageSnapshot = {
    url: "",
    title: "",
    bodyTextSample: "",
  };

  if (page && typeof page.screenshot === "function") {
    await page.screenshot({ path: screenshotPath, fullPage: true }).catch(() => null);

    pageSnapshot.url = typeof page.url === "function" ? page.url() : "";
    pageSnapshot.title = await page.title().catch(() => "");
    pageSnapshot.bodyTextSample = await page
      .locator("body")
      .innerText({ timeout: 2000 })
      .then((text) => String(text || "").slice(0, 2000))
      .catch(() => "");
  }

  fs.writeFileSync(
    artifactPath,
    JSON.stringify(
      {
        error: String(error && error.stack ? error.stack : error),
        screenshotPath,
        page: pageSnapshot,
        context,
      },
      null,
      2
    )
  );

  return {
    artifactPath,
    screenshotPath,
  };
}

async function runBuilderSmoke(page, baseUrl, fixtures, options = {}) {
  if (!fixtures.length) {
    throw new Error("No maintained fixture pages were found for builder smoke.");
  }

  let modalSmokeRan = false;
  let pasteSmokeRan = false;
  let editabilityProof = null;
  let styleRoutingProof = null;

  for (const fixture of fixtures) {
    const importedMarkers = [];
    const focusedImportProof = options.focusedImportProof || null;

    logStep(`Opening builder for ${fixture.slug} (#${fixture.id})`);
    const builderUrl = `${baseUrl}/?oxygen=builder&id=${fixture.id}`;
    await page.goto(builderUrl, {
      waitUntil: "domcontentloaded",
      timeout: 60000,
    });
    await waitForBuilderReady(page);
    await assertNoBuilderErrors(page, `Builder open for ${fixture.slug}`);

    if (!modalSmokeRan) {
      importedMarkers.push(...await runBuilderModalSmoke(
        page,
        fixture.slug,
        focusedImportProof?.importHtml || null,
        focusedImportProof?.expectedTexts || ["Live Gate"]
      ));

      if (focusedImportProof?.styleRoutingExpectation) {
        styleRoutingProof = await loadBuilderStyleRoutingProof(
          page,
          focusedImportProof.styleRoutingExpectation,
          `Builder style routing proof for ${fixture.slug}`
        );
        styleRoutingProof.fixtureSlug = fixture.slug;
        styleRoutingProof.persistedBuilderNodeMatch = false;
      }

      const editabilityTargetText =
        focusedImportProof?.editabilityTargetText || importedMarkers[0];
      const replacementText = buildEditedProofText(
        editabilityTargetText,
        fixture.slug
      );
      editabilityProof = await mutateBuilderTextNode(
        page,
        editabilityTargetText,
        replacementText,
        `Builder editability proof for ${fixture.slug}`
      );
      editabilityProof.fixtureSlug = fixture.slug;
      editabilityProof.persistedBuilderText = false;
      editabilityProof.persistedBuilderNodeMatch = false;
      editabilityProof.persistedFrontendText = false;
      const proofMarkerIndex = importedMarkers.findIndex(
        (marker) => marker === editabilityTargetText
      );
      if (proofMarkerIndex !== -1) {
        importedMarkers[proofMarkerIndex] = replacementText;
      }
      modalSmokeRan = true;
    }

    if (!pasteSmokeRan) {
      importedMarkers.push(...await runBuilderPasteSmoke(page, fixture.slug));
      pasteSmokeRan = true;
    }

    logStep(`Saving builder document for ${fixture.slug}`);
    const saveResult = await waitForBuilderSaveCompletion(
      page,
      `Save ${fixture.slug}`
    );
    logStep(`Builder save click strategy: ${saveResult.strategy}`);
    logStep(`Builder save HTTP status: ${saveResult.responseStatus}`);
    logStep(`Reopening builder document for ${fixture.slug}`);
    await page.goto(builderUrl, {
      waitUntil: "domcontentloaded",
      timeout: 60000,
    });
    await waitForBuilderReady(page);
    await assertNoBuilderErrors(page, `Builder reopen for ${fixture.slug}`);

    const editabilityProofApplies = editabilityProof?.fixtureSlug === fixture.slug;
    const styleRoutingProofApplies = styleRoutingProof?.fixtureSlug === fixture.slug;

    for (const marker of importedMarkers) {
      await assertBuilderTextPresent(page, marker, `Builder reopen for ${fixture.slug}`);
      if (editabilityProofApplies && marker === editabilityProof.updatedText) {
        editabilityProof.persistedBuilderText = true;
      }
    }

    if (editabilityProofApplies) {
      const persistedNode = await loadBuilderNodePropertyProof(
        page,
        editabilityProof.id,
        editabilityProof.propertyPath
      );

      editabilityProof.reopenedNode = persistedNode;
      editabilityProof.persistedBuilderNodeMatch =
        persistedNode?.ok === true &&
        persistedNode.type === editabilityProof.type &&
        persistedNode.value === editabilityProof.updatedText;

      if (!editabilityProof.persistedBuilderNodeMatch) {
        throw new Error(
          `Builder reopen for ${fixture.slug} did not preserve editability proof on node ${editabilityProof.id}.`
        );
      }
    }

    if (styleRoutingProofApplies && focusedImportProof?.styleRoutingExpectation) {
      const reopenedStyleProof = await loadBuilderStyleRoutingProof(
        page,
        focusedImportProof.styleRoutingExpectation,
        `Builder style routing reopen proof for ${fixture.slug}`
      );
      styleRoutingProof.reopenedNode = reopenedStyleProof;
      styleRoutingProof.persistedBuilderNodeMatch =
        reopenedStyleProof.ok === true &&
        reopenedStyleProof.id === styleRoutingProof.id &&
        reopenedStyleProof.type === styleRoutingProof.type;

      if (!styleRoutingProof.persistedBuilderNodeMatch) {
        throw new Error(
          `Builder reopen for ${fixture.slug} did not preserve focused style routing proof on node ${styleRoutingProof.id}.`
        );
      }
    }

    if (fixture.url) {
      for (const marker of importedMarkers) {
        await assertFrontendTextPresent(
          page,
          fixture.url,
          marker,
          `Frontend proof for ${fixture.slug}`
        );
        if (editabilityProofApplies && marker === editabilityProof.updatedText) {
          editabilityProof.persistedFrontendText = true;
        }
      }
    }
  }

  if (editabilityProof) {
    editabilityProof.styleRoutingProof = styleRoutingProof;
  }

  return editabilityProof;
}

function loadPersistenceProof(container, fixtures) {
  const ids = fixtures.map((fixture) => Number(fixture.id)).filter(Boolean);
  const idsJson = JSON.stringify(ids).replace(/'/g, "\\'");
  const php = `
    require '/var/www/html/wp-load.php';
    $ids = json_decode('${idsJson}', true);
    $metaKey = function_exists('\\Breakdance\\BreakdanceOxygen\\Strings\\__bdox')
      ? \\Breakdance\\BreakdanceOxygen\\Strings\\__bdox('_meta_prefix') . 'data'
      : '_oxygen_data';
    $selectorCount = 0;
    if (function_exists('\\Breakdance\\BreakdanceOxygen\\Selectors\\getOxySelectors')) {
      $selectors = \\Breakdance\\BreakdanceOxygen\\Selectors\\getOxySelectors();
      $selectorCount = is_array($selectors) ? count($selectors) : 0;
    } elseif (function_exists('\\Breakdance\\Data\\get_global_option')) {
      $selectors = \\Breakdance\\Data\\get_global_option('oxy_selectors_json_string');
      if (is_string($selectors)) {
        $selectors = json_decode($selectors, true);
      }
      $selectorCount = is_array($selectors) ? count($selectors) : 0;
    }
    $breakdanceClasses = function_exists('\\Breakdance\\Data\\get_global_option')
      ? \\Breakdance\\Data\\get_global_option('breakdance_classes_json_string')
      : get_option('breakdance_classes_json_string', '');
    $pages = [];
    foreach ($ids as $id) {
      $raw = get_post_meta((int) $id, $metaKey, true);
      $hasTree = false;
      if (is_array($raw)) {
        $hasTree = isset($raw['tree_json_string']) && is_string($raw['tree_json_string']) && $raw['tree_json_string'] !== '';
      } elseif (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        $hasTree = is_array($decoded) && isset($decoded['tree_json_string']) && is_string($decoded['tree_json_string']) && $decoded['tree_json_string'] !== '';
      }
      $pages[] = [
        'id' => (int) $id,
        'hasTreeJsonString' => $hasTree,
      ];
    }
    echo wp_json_encode([
      'metaKey' => $metaKey,
      'pages' => $pages,
      'selectorCount' => $selectorCount,
      'hasBreakdanceClassesJsonString' => is_string($breakdanceClasses) ? trim($breakdanceClasses) !== '' : !empty($breakdanceClasses),
    ]);
  `;

  return JSON.parse(runDockerPhp(container, php));
}

function assertPersistenceProof(proof, options = {}) {
  const classMode = String(options.classMode || "native");
  const requiresBreakdanceClassesJsonString =
    options.requiresBreakdanceClassesJsonString === true;
  const missingTree = (proof.pages || []).filter((page) => !page.hasTreeJsonString);
  if (missingTree.length > 0) {
    throw new Error(`Missing tree_json_string for page IDs: ${missingTree.map((page) => page.id).join(", ")}`);
  }

  if (classMode === "native" && (!proof.selectorCount || proof.selectorCount < 1)) {
    throw new Error("No Oxygen selector records were persisted.");
  }

  if (requiresBreakdanceClassesJsonString && !proof.hasBreakdanceClassesJsonString) {
    throw new Error("breakdance_classes_json_string was not persisted.");
  }
}

async function main() {
  const options = parseArgs(process.argv.slice(2));
  fs.mkdirSync(options.outputDir, { recursive: true });
  const originalClassMode = getCurrentClassMode(options.container);

  let syncResult = {
    ok: true,
    skipped: options.skipSync,
  };
  let windPressProof = null;

  try {
    if (options.skipSync) {
      logStep("Skipping source sync; using the installed plugin artifact");
    } else {
      logStep("Syncing plugin into Docker container");
      syncResult = JSON.parse(
        runNodeScript(path.join("tests", "live", "sync-docker-plugin.cjs"))
      );
    }

    if (options.classMode) {
      logStep(`Forcing converter class mode to ${options.classMode}`);
      setClassMode(options.container, options.classMode);
    }

    if (options.classMode === "windpress") {
      windPressProof = loadWindPressProof(options.container);
      assertWindPressProof(windPressProof);
    }

  const localFixtureDir = options.localFixtureDir;
  const focusedImportProof = loadFocusedImportProof(options);

  logStep("Running fixture baseline parity suite");
  const baselineArgs = [
      `--output-dir=${path.relative(
        process.cwd(),
        path.join(options.outputDir, "fixture-baseline")
      )}`,
      `--local-fixture-dir=${localFixtureDir}`,
      `--container=${options.container}`,
      `--slug-prefix=${options.slugPrefix}`,
  ];
  if (options.classMode) {
    baselineArgs.push(`--class-mode=${options.classMode}`);
  }
  if (options.fixture) {
    baselineArgs.push(`--fixture=${options.fixture}`);
  }
  const fixtureBaselineResult = JSON.parse(
    runNodeScript(path.join("tests", "live", "run-fixture-baseline.cjs"), baselineArgs)
  );
  logStep("Normalizing Oxygen upload ownership after CLI fixture import");
  normalizeOxygenUploadsPermissions(options.container);

  const fixtureSummary = loadFixtureSummary(fixtureBaselineResult.jsonPath);
  const baseUrl = options.baseUrl || getHomeUrl(options.container);
  const fixtures = loadFixturePages(
    options.container,
    fixtureSummary,
    options.slugPrefix
  );

  logStep(`Resolved base URL: ${baseUrl}`);
  logStep(
    `Resolved maintained fixtures: ${fixtures
      .map((fixture) => fixture.slug)
      .join(", ")}`
  );
  ensureAdminPassword(options.container, options.adminPassword);
  logStep("Ensured admin credentials for live gate");

  const blockingObservations = {
    pageErrors: [],
    consoleErrors: [],
    requestFailures: [],
  };
  const ambientObservations = {
    pageErrors: [],
    consoleErrors: [],
    requestFailures: [],
  };

  const { chromium } = loadPlaywright();
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1000 },
  });

  try {
    await context.grantPermissions(["clipboard-read", "clipboard-write"], {
      origin: new URL(baseUrl).origin,
    });
  } catch (error) {
    logStep(`Clipboard permissions were not granted explicitly: ${error.message}`);
  }

  const page = await context.newPage();

  page.on("pageerror", (error) => {
    const message = String(error && error.stack ? error.stack : error);
    classifyObservation(
      message,
      blockingObservations.pageErrors,
      ambientObservations.pageErrors
    );
  });

  page.on("console", (message) => {
    if (message.type() !== "error") {
      return;
    }

    const location = message.location();
    const formatted = [
      message.type(),
      message.text(),
      location && location.url
        ? `@ ${location.url}:${location.lineNumber || 0}:${
            location.columnNumber || 0
          }`
        : "",
    ]
      .filter(Boolean)
      .join(" ");

    classifyObservation(
      formatted,
      blockingObservations.consoleErrors,
      ambientObservations.consoleErrors
    );
  });

  page.on("requestfailed", (request) => {
    const postData = request.postData() || "";
    const failure = request.failure();
    const formatted = [
      request.method(),
      request.url(),
      failure && failure.errorText ? `(${failure.errorText})` : "",
      postData ? `postData=${postData}` : "",
    ]
      .filter(Boolean)
      .join(" ");

    classifyObservation(
      formatted,
      blockingObservations.requestFailures,
      ambientObservations.requestFailures
    );
  });

  let siteKitProof = null;
  let editabilityProof = null;

  try {
    logStep("Logging into local WordPress admin");
    await login(page, baseUrl, options.adminUser, options.adminPassword);
    await runAdminSmoke(page, baseUrl);
    if (options.skipSiteKit) {
      logStep("Skipping site-kit live smoke");
    } else {
      siteKitProof = await runSiteKitSmoke(page, {
        ...options,
        observations: {
          blocking: blockingObservations,
          ambient: ambientObservations,
        },
      });
    }
    editabilityProof = await runBuilderSmoke(page, baseUrl, fixtures, {
      focusedImportProof,
    });
  } catch (error) {
    const failure = await writeLiveGateFailureArtifact(page, options, error, {
      baseUrl,
      container: options.container,
      fixtureBaseline: fixtureBaselineResult,
      fixtures,
      siteKit: siteKitProof || null,
      editabilityProof: editabilityProof || null,
      observations: {
        blocking: blockingObservations,
        ambient: ambientObservations,
      },
    });
    process.stderr.write(
      `[live-gate] Failure artifact: ${failure.artifactPath}\n`
    );
    throw error;
  } finally {
    await page.close();
    await browser.close();
  }

  const blockingEntries = Object.values(blockingObservations).flat();
  if (blockingEntries.length) {
    throw new Error(
      `Live gate captured plugin-attributable observations: ${blockingEntries.join(
        " | "
      )}`
    );
  }

  const result = {
    ok: true,
    baseUrl,
    container: options.container,
    skipSync: options.skipSync,
    sync: syncResult,
    fixtureBaseline: fixtureBaselineResult,
    slugPrefix: options.slugPrefix,
    fixtures,
    siteKit: siteKitProof || null,
    editabilityProof: editabilityProof || null,
    styleRoutingProof: editabilityProof?.styleRoutingProof || null,
    persistence: loadPersistenceProof(options.container, fixtures),
    windPress: windPressProof,
    originalClassMode,
    effectiveClassMode: options.classMode || originalClassMode,
    observations: {
      blocking: blockingObservations,
      ambient: ambientObservations,
    },
    outputDir: options.outputDir,
  };

  assertPersistenceProof(result.persistence, {
    classMode: result.effectiveClassMode,
  });

  fs.writeFileSync(
    path.join(options.outputDir, "summary.json"),
    JSON.stringify(result, null, 2)
  );

  process.stdout.write(JSON.stringify(result, null, 2) + "\n");
  } finally {
    if (options.classMode && originalClassMode !== options.classMode) {
      logStep(`Restoring converter class mode to ${originalClassMode}`);
      setClassMode(options.container, originalClassMode);
    }
  }
}

if (require.main === module) {
  main().catch((error) => {
    process.stderr.write(String(error && error.stack ? error.stack : error) + "\n");
    process.exit(1);
  });
}

module.exports = {
  parseArgs,
  normalizeFixturePath,
  buildSlug,
  isConvertedAssetRuntimeError,
  classifyObservation,
  isCanceledNavigationRequestFailure,
  isBuilderReadySnapshot,
  resolveBuilderEditabilityHelperFromWindow,
  isBuilderSaveRequestDetails,
  isBuilderSaveResponsePayloadSuccessful,
  extractEditabilityTargetText,
  buildFocusedImportProof,
  buildEditedProofText,
  SITE_KIT_EXPECTED_TEXTS,
  resolveSiteKitManifestPath,
  assertSiteKitImportProof,
  loadBuilderStyleRoutingProof,
  sortFixturePagesBySlugOrder,
  assertPersistenceProof,
};
