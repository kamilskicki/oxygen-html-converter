<?php

declare(strict_types=1);

use OxyHtmlConverter\Services\ConversionAuditBuilder;
use OxyHtmlConverter\Services\ConvertPayloadBuilder;
use OxyHtmlConverter\Services\OxygenDocumentTree;
use OxyHtmlConverter\Services\OxygenPageImporter;
use OxyHtmlConverter\Services\OxygenStorageAdapterFactory;
use OxyHtmlConverter\Services\RequestOptions;
use OxyHtmlConverter\Services\TreeBuilderFactory;
use OxyHtmlConverter\Validation\OutputValidator;

// WP-loaded CLI only. Default: import, read back, render, rollback and verify restoration.
// --keep retains validated draft pages, disabled templates and their component for Builder QA; the report
// provides the secondary page ID and rollback ID for OxygenPageImporter::rollback().
if (PHP_SAPI !== 'cli') {
    exit(2);
}
$cli = getopt('', ['fixtures:', 'output:', 'keep']);
$bufferLevel = ob_get_level();
ob_start();
$report = ['status' => 'failed', 'generatedAt' => gmdate('c'), 'objects' => [], 'failures' => []];
$importer = null;
$adapter = null;
$import = [];
$beforeIds = [];
$beforeOptions = [];
$beforeRegistry = [];
$storeNames = [];
$importAttempted = false;
$validated = false;
$keep = isset($cli['keep']);
$secondaryPageId = 0;
$createdIds = [];

try {
    $wpRoot = rtrim((string) (getenv('OXY_HTML_CONVERTER_WP_ROOT') ?: '/var/www/wordpress'), '/\\');
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'oxyconvo.lab';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require_once $wpRoot . '/wp-load.php';
    if (!class_exists(OxygenPageImporter::class)) {
        throw new RuntimeException('Activate Oxygen HTML Converter before running this gate.');
    }
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
    if ($admins === []) {
        throw new RuntimeException('No existing administrator is available.');
    }
    wp_set_current_user((int) $admins[0]->ID);
    $fixtureRoot = realpath((string) ($cli['fixtures'] ?? __DIR__ . '/../../fixtures/html'));
    if ($fixtureRoot === false) {
        throw new RuntimeException('Canonical fixture directory missing; pass --fixtures.');
    }
    $source = file_get_contents($fixtureRoot . '/site-kit/service-audit.html');
    $fixture = json_decode((string) file_get_contents($fixtureRoot . '/site-kit/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_string($source) || !is_array($fixture)) {
        throw new RuntimeException('Site-kit HTML or native manifest fixture is missing.');
    }
    $adapter = (new OxygenStorageAdapterFactory())->create();
    if (!is_callable('Breakdance\\Render\\getRenderedNodes') || !is_callable('Breakdance\\Render\\generateCacheForPost') || !is_callable('Breakdance\\Themeless\\doesTemplateApply')) {
        throw new RuntimeException('Oxygen render or template isolation API is unavailable.');
    }
    $runId = 'ohc-qa-kit-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    $report['runId'] = $runId;
    $report['oxygenVersion'] = OxygenStorageAdapterFactory::detectRuntimeOxygenVersion();
    $report['phpVersion'] = PHP_VERSION;
    $report['scope'] = 'Two draft pages, natively disabled template/header/footer and one reusable component; no menus or homepage changes.';
    $report['expectedNativeTemplateState'] = ['postStatus' => 'publish', 'disabled' => true];
    $report['limitations'] = ['Oxygen forces templates, headers and footers to publish; isolation uses its native disabled setting, verified through doesTemplateApply(). Direct rendering is checked. Public template placement and header/footer composition are intentionally not activated.'];
    $options = (new RequestOptions())->normalizeConvert(['safeMode' => true, 'allowExecutableCode' => false]);
    $builder = new ConvertPayloadBuilder(new OxygenDocumentTree(), new ConversionAuditBuilder(), new OutputValidator());
    $manifest = ['version' => 1, 'id' => $runId, 'pages' => []];
    $markers = [];
    for ($index = 1; $index <= 2; $index++) {
        $marker = $runId . '-page-' . $index;
        $html = str_replace(['OHC_SITE_KIT_PAGE', 'ohc-site-kit-card'], [$marker, $runId . '-card'], $source);
        $conversion = (new TreeBuilderFactory())->createForConvert($options, $html)->convert($html);
        if (empty($conversion['success'])) {
            throw new RuntimeException('Site-kit page conversion failed.');
        }
        $payload = $builder->build($conversion, $options, $html);
        if (empty($payload['success'])) {
            throw new RuntimeException('Site-kit public payload validation failed: ' . wp_json_encode($payload['data']['errors'] ?? []));
        }
        $manifest['pages'][] = ['id' => 'page-' . $index, 'title' => $marker, 'slug' => $marker, 'postStatus' => 'draft', 'documentTree' => $payload['data']['documentTree']];
        $markers['pages']['page-' . $index] = $marker;
        if ($index === 1) {
            $candidates = array_values(array_filter($payload['data']['designDocument']['componentCandidates'] ?? [], static fn(array $candidate): bool => !empty($candidate['eligible'])));
            if (count($candidates) !== 1) {
                throw new RuntimeException('Expected exactly one public native component candidate from three repeated cards.');
            }
            $candidates[0]['suggestedName'] = $runId . '-card';
            $candidates[0]['title'] = $runId . '-card';
            $candidates[0]['slug'] = $runId . '-card';
            $manifest['designDocument'] = ['componentCandidates' => $candidates];
        }
    }
    // Oxygen intentionally forces template/header/footer posts to publish through
    // wp_insert_post_data. Native disabled settings provide the supported isolation.
    // Retain the unregistered post-type condition as a second independent guard.
    $settings = $fixture['templates'][0]['templateSettings'];
    $settings['disabled'] = true;
    $settings['ruleGroups'][0][0]['value'] = [$runId];
    if (post_type_exists($runId)) {
        throw new RuntimeException('Generated template isolation post type unexpectedly exists.');
    }
    foreach (['templates', 'headers', 'footers'] as $section) {
        $record = $fixture[$section][0];
        $record['id'] = $section;
        $record['title'] = $runId . '-' . $section;
        $record['slug'] = $record['title'];
        $record['postStatus'] = 'publish';
        $record['templateSettings'] = $settings;
        $record['documentTree'] = json_decode(str_replace('M5 Site Kit', $runId, wp_json_encode($record['documentTree'])), true, 512, JSON_THROW_ON_ERROR);
        unset($record['postId'], $record['post_id'], $record['ID']);
        $manifest[$section] = [$record];
        $markers[$section][$section] = $runId;
    }
    $storeNames = ['selectors', 'variables', 'global_settings', 'global_styles', 'brand_library', 'option:show_on_front', 'option:page_on_front', 'option:page_for_posts', 'option:theme_mods_' . get_stylesheet()];
    $beforeOptions = $adapter->captureRollbackSnapshot($storeNames)['stores'];
    $beforeRegistry = get_option(OxygenPageImporter::SITE_KIT_MANIFESTS_OPTION, []);
    global $wpdb;
    $beforeIds = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} ORDER BY ID"));
    $importer = new OxygenPageImporter();
    $importAttempted = true;
    $import = $importer->importSiteKit($manifest, false);
    $report['import'] = ['success' => !empty($import['success']), 'rollbackId' => $import['rollbackId'] ?? '', 'objectCounts' => $import['manifest']['objectCounts'] ?? [], 'componentCreated' => $import['componentPersistence']['created'] ?? 0, 'componentInstances' => $import['componentInstances']['replaced'] ?? 0];
    if (empty($import['success'])) {
        throw new RuntimeException('Site-kit import failed: ' . wp_json_encode($import['errors'] ?? []));
    }
    $secondaryPageId = (int) ($import['objects']['pages'][1]['postId'] ?? 0);
    if ($secondaryPageId < 1 || (int) ($import['componentPersistence']['created'] ?? 0) !== 1 || (int) ($import['componentInstances']['replaced'] ?? 0) !== 6) {
        throw new RuntimeException('Expected two pages and one reusable block replacing six card instances.');
    }
    $records = [];
    foreach (['pages' => 2, 'templates' => 1, 'headers' => 1, 'footers' => 1] as $section => $expectedCount) {
        $sectionRecords = $import['objects'][$section] ?? [];
        if (count($sectionRecords) !== $expectedCount) {
            throw new RuntimeException('Unexpected imported object count for ' . $section);
        }
        foreach ($sectionRecords as $record) {
            $record['section'] = $section;
            $record['marker'] = $markers[$section][$record['id']];
            $records[] = $record;
        }
    }
    foreach ($import['componentPersistence']['createdBlocks'] ?? [] as $record) {
        $record['section'] = 'components';
        $record['marker'] = 'First card';
        $records[] = $record;
    }
    foreach ($records as $record) {
        $postId = (int) ($record['postId'] ?? 0);
        if ($postId < 1 || in_array($postId, $beforeIds, true)) {
            throw new RuntimeException('Site-kit unexpectedly touched a pre-existing post.');
        }
        $createdIds[] = $postId;
        if ($record['section'] === 'pages' && get_post_status($postId) !== 'draft') {
            throw new RuntimeException('A disposable page was not saved as draft.');
        }
        $stored = $adapter->readPageDocument($postId);
        if (empty($stored['success']) || !$adapter->validateDocumentTree($stored['tree'] ?? [])['valid']) {
            throw new RuntimeException('Adapter readback failed for post ' . $postId);
        }
        if (in_array($record['section'], ['templates', 'headers', 'footers'], true)) {
            if (get_post_status($postId) !== 'publish') {
                throw new RuntimeException('Oxygen template status differs from the expected native publish contract.');
            }
            $storedSettings = $adapter->readTemplateSettings($postId);
            if (empty($storedSettings['success']) || ($storedSettings['settings']['disabled'] ?? null) !== true || ($storedSettings['settings']['ruleGroups'][0][0]['value'] ?? null) !== [$runId]) {
                throw new RuntimeException('Template isolation settings failed readback.');
            }
            if (\Breakdance\Themeless\doesTemplateApply(['settings' => $storedSettings['settings']]) !== false) {
                throw new RuntimeException('Oxygen native template evaluator did not honor disabled isolation.');
            }
        }
        \Breakdance\Render\generateCacheForPost($postId);
        $rendered = \Breakdance\Render\getRenderedNodes($postId, false);
        $renderedHtml = is_array($rendered) ? (string) ($rendered['html'] ?? '') : '';
        if (!str_contains($renderedHtml, $record['marker'])) {
            throw new RuntimeException('Oxygen rendering lost expected source content for ' . $record['section']);
        }
        $report['objects'][] = ['section' => $record['section'], 'postId' => $postId, 'treeHash' => $stored['treeHash'], 'htmlBytes' => strlen($renderedHtml), 'renderHash' => hash('sha256', $renderedHtml), 'builderUrl' => admin_url('post.php?post=' . $postId . '&action=edit')];
    }
    $validated = true;
} catch (Throwable $error) {
    $report['failures'][] = $error->getMessage();
} finally {
    if ($importAttempted && $adapter !== null && $importer !== null) {
        if (!empty($import['success']) && !($keep && $validated)) {
            try {
                $rollback = $importer->rollback($secondaryPageId, (string) ($import['rollbackId'] ?? ''));
                $report['rollback'] = $rollback;
                if (empty($rollback['success'])) {
                    $report['failures'][] = 'Plugin site-kit rollback failed.';
                }
            } catch (Throwable $error) {
                $report['failures'][] = 'Rollback exception: ' . $error->getMessage();
            }
        }
        $report['kept'] = $keep && $validated;
        $report['rollbackTarget'] = ['postId' => $secondaryPageId, 'rollbackId' => $import['rollbackId'] ?? ''];
        if (!$report['kept']) {
            $afterIds = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} ORDER BY ID"));
            $removedExistingIds = array_values(array_diff($beforeIds, $afterIds));
            $remainingNewIds = array_values(array_diff($afterIds, $beforeIds));
            $report['postRestoration'] = ['createdIds' => $createdIds, 'removedExistingIds' => $removedExistingIds, 'remainingNewIds' => $remainingNewIds];
            if ($removedExistingIds !== [] || $remainingNewIds !== []) {
                $report['failures'][] = 'Rollback changed the existing post inventory or left new posts.';
            }
            $afterOptions = $adapter->captureRollbackSnapshot($storeNames)['stores'];
            $optionFailures = [];
            foreach ($beforeOptions as $index => $before) {
                $after = $afterOptions[$index] ?? [];
                if (($after['key'] ?? null) !== $before['key'] || ($after['oldExists'] ?? null) !== $before['oldExists'] || ($after['oldValue'] ?? null) !== $before['oldValue']) {
                    $optionFailures[] = $before['key'];
                }
            }
            $report['optionRestoration'] = ['checked' => count($beforeOptions), 'mismatches' => $optionFailures];
            if ($optionFailures !== []) {
                $report['failures'][] = 'Adapter-owned or site-configuration options were not restored exactly.';
            }
            // The plugin deliberately retains an inactive rollback audit record.
            $registry = get_option(OxygenPageImporter::SITE_KIT_MANIFESTS_OPTION, []);
            foreach (($beforeRegistry['manifests'] ?? []) as $id => $entry) {
                if (($registry['manifests'][$id] ?? null) !== $entry) {
                    $report['failures'][] = 'A pre-existing site-kit audit manifest changed.';
                }
            }
            foreach (($beforeRegistry['postIndex'] ?? []) as $id => $entry) {
                if (($registry['postIndex'][$id] ?? null) !== $entry) {
                    $report['failures'][] = 'A pre-existing site-kit post index changed.';
                }
            }
            if (!empty($import['success']) && !empty($registry['manifests'][$import['rollbackId']]['rollback']['available'])) {
                $report['failures'][] = 'Completed site-kit rollback is still marked available.';
            }
        }
    }
}
$report['status'] = $validated && $report['failures'] === [] ? ($keep ? 'kept-for-builder-qa' : 'passed') : 'failed';
while (ob_get_level() > $bufferLevel) {
    ob_end_clean();
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
if (isset($cli['output']) && file_put_contents((string) $cli['output'], $json) === false) {
    fwrite(STDERR, "Failed to write the audit report.\n");
    exit(2);
}
echo $json;
exit($report['status'] === 'failed' ? 1 : 0);
