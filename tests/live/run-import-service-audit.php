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

// Usage: php run-import-service-audit.php --fixtures=/path/to/fixtures/html --output=/tmp/report.json
// Optional: --fixture=native-no-code/01-text.html --keep-page=native-no-code/01-text.html
// Only the explicitly retained fixture is published. Other disposable drafts are rolled back immediately.
if (PHP_SAPI !== 'cli') {
    exit(2);
}
$cli = getopt('', ['fixtures:', 'output:', 'fixture:', 'keep-page:']);
$bufferLevel = ob_get_level();
ob_start();
$report = ['status' => 'failed', 'generatedAt' => gmdate('c'), 'fixtures' => [], 'failures' => []];
try {
    $wpRoot = rtrim((string) (getenv('OXY_HTML_CONVERTER_WP_ROOT') ?: '/var/www/wordpress'), '/\\');
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'oxyconvo.lab';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    require_once $wpRoot . '/wp-load.php';
    if (!class_exists(OxygenPageImporter::class)) {
        throw new RuntimeException('Activate the installed oxygen-html-converter plugin before running this gate.');
    }
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
    if ($admins === []) {
        throw new RuntimeException('No existing administrator is available for the import capability check.');
    }
    wp_set_current_user((int) $admins[0]->ID);
    $fixtureRoot = realpath((string) ($cli['fixtures'] ?? __DIR__ . '/../../fixtures/html'));
    if ($fixtureRoot === false || !is_file($fixtureRoot . '/fixture-index.json')) {
        throw new RuntimeException('Canonical fixture-index.json was not found. Pass --fixtures.');
    }
    $index = json_decode((string) file_get_contents($fixtureRoot . '/fixture-index.json'), true, 512, JSON_THROW_ON_ERROR);
    $fixtures = $index['stableHtmlFixtures'] ?? [];
    $focus = (string) ($cli['fixture'] ?? '');
    $keep = (string) ($cli['keep-page'] ?? '');
    $known = array_column($fixtures, 'fixture');
    foreach ([$focus, $keep] as $requested) {
        if ($requested !== '' && !in_array($requested, $known, true)) {
            throw new RuntimeException('Requested fixture is outside the stable index: ' . $requested);
        }
    }
    if ($focus !== '') {
        $fixtures = array_values(array_filter($fixtures, static fn(array $fixture): bool => $fixture['fixture'] === $focus));
    }
    // Retain last so later rollback probes cannot disturb its shared global resources.
    usort($fixtures, static fn(array $a, array $b): int => (int) ($a['fixture'] === $keep) <=> (int) ($b['fixture'] === $keep));
    $adapter = (new OxygenStorageAdapterFactory())->create();
    $report['oxygenVersion'] = OxygenStorageAdapterFactory::detectRuntimeOxygenVersion();
    $report['experimentalRuntime'] = str_contains((string) $report['oxygenVersion'], 'beta');
    $report['contractBaseline'] = $adapter->getContractVersion();
    $report['wordpressVersion'] = get_bloginfo('version');
    $report['phpVersion'] = PHP_VERSION;
    $report['classMode'] = get_option('oxy_html_converter_class_mode', 'native');
    $report['scope'] = $focus === '' ? 'all-stable-html-fixtures' : 'focused-fixture';
    $options = (new RequestOptions())->normalizeConvert(['safeMode' => true, 'allowExecutableCode' => false]);
    $payloadBuilder = new ConvertPayloadBuilder(new OxygenDocumentTree(), new ConversionAuditBuilder(), new OutputValidator());
    $runId = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
    foreach ($fixtures as $position => $fixture) {
        $relative = (string) $fixture['fixture'];
        $started = microtime(true);
        $entry = ['fixture' => $relative, 'success' => false, 'kept' => false];
        $importer = new OxygenPageImporter();
        $postId = 0;
        $importSucceeded = false;
        try {
            $path = realpath($fixtureRoot . '/' . $relative);
            if ($path === false || !str_starts_with($path, $fixtureRoot . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Fixture path is missing or escapes the canonical fixture root.');
            }
            $html = file_get_contents($path);
            if (!is_string($html) || trim($html) === '') {
                throw new RuntimeException('Fixture is unreadable or empty.');
            }
            $conversion = (new TreeBuilderFactory())->createForConvert($options, $html)->convert($html);
            if (empty($conversion['success'])) {
                throw new RuntimeException('Conversion failed: ' . json_encode($conversion['errors'] ?? $conversion['error'] ?? []));
            }
            $converted = $payloadBuilder->build($conversion, $options, $html);
            if (empty($converted['success'])) {
                throw new RuntimeException('Payload validation failed: ' . json_encode($converted['data']));
            }
            $payload = array_merge($converted['data'], [
                'title' => 'OHC QA ' . $runId . ' ' . ($fixture['title'] ?? $relative),
                'slug' => 'ohc-qa-' . $runId . '-' . $position,
                'postStatus' => $relative === $keep ? 'publish' : 'draft',
                'sourceHash' => hash('sha256', $html),
            ]);
            $import = $importer->import($payload, false);
            $entry['import'] = $import;
            $postId = (int) ($import['postId'] ?? 0);
            if (empty($import['success'])) {
                throw new RuntimeException('Import service failed: ' . json_encode($import['errors'] ?? $import));
            }
            $importSucceeded = true;
            if (($import['postAction'] ?? '') !== 'created') {
                throw new RuntimeException('QA import unexpectedly updated an existing page.');
            }
            $stored = $adapter->readPageDocument($postId);
            if (empty($stored['success']) || !$adapter->validateDocumentTree($stored['tree'] ?? [])['valid']) {
                throw new RuntimeException('Stored document failed adapter readback validation.');
            }
            $manifest = json_decode((string) get_post_meta($postId, OxygenPageImporter::MANIFEST_META_KEY, true), true);
            if (!is_array($manifest) || empty($manifest['rollback']['available'])) {
                throw new RuntimeException('Persisted importer manifest does not expose rollback.');
            }
            if (($stored['treeHash'] ?? '') !== ($manifest['treeHash'] ?? '')) {
                throw new RuntimeException('Readback tree hash differs from importer manifest.');
            }
            if (!is_callable('Breakdance\\Render\\getRenderedNodes') || !is_callable('Breakdance\\Render\\generateCacheForPost')) {
                throw new RuntimeException('Oxygen render API is unavailable.');
            }
            \Breakdance\Render\generateCacheForPost($postId);
            $rendered = \Breakdance\Render\getRenderedNodes($postId, false);
            $renderedHtml = is_array($rendered) ? ($rendered['html'] ?? '') : '';
            if (!is_string($renderedHtml) || trim($renderedHtml) === '') {
                throw new RuntimeException('Oxygen generated empty rendered HTML.');
            }
            $entry['readback'] = ['treeHash' => $stored['treeHash'], 'valid' => true];
            $entry['render'] = ['htmlBytes' => strlen($renderedHtml), 'htmlSha256' => hash('sha256', $renderedHtml)];
            $entry['url'] = get_permalink($postId);
            $entry['builderUrl'] = admin_url('post.php?post=' . $postId . '&action=edit');
            $entry['kept'] = $relative === $keep;
            $entry['success'] = true;
        } catch (Throwable $error) {
            $entry['error'] = $error->getMessage();
            $report['failures'][] = ['fixture' => $relative, 'error' => $error->getMessage()];
        } finally {
            if ($importSucceeded && !$entry['kept']) {
                $rollback = $importer->rollback($postId);
                $entry['rollback'] = $rollback;
                if (empty($rollback['success']) || get_post($postId) !== null) {
                    $entry['success'] = false;
                    $report['failures'][] = ['fixture' => $relative, 'error' => 'Service rollback failed or left the disposable page.', 'rollback' => $rollback];
                }
            }
            $entry['elapsedMs'] = round((microtime(true) - $started) * 1000, 1);
            $report['fixtures'][] = $entry;
        }
    }
    $report['fixtureCount'] = count($report['fixtures']);
    $report['status'] = $report['failures'] === [] && $report['fixtureCount'] > 0 ? 'passed' : 'failed';
} catch (Throwable $error) {
    $report['failures'][] = ['error' => $error->getMessage()];
}
while (ob_get_level() > $bufferLevel) {
    ob_end_clean();
}
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
if (isset($cli['output']) && file_put_contents((string) $cli['output'], $json) === false) {
    fwrite(STDERR, "Failed to write the audit report.\n");
    exit(2);
}
echo $json;
exit($report['status'] === 'passed' ? 0 : 1);
