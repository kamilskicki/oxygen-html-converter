<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\Ajax;
use OxyHtmlConverter\Services\OxygenPageImporter;
use OxyHtmlConverter\Services\ConversionAuditBuilder;
use OxyHtmlConverter\Services\ConvertPayloadBuilder;
use OxyHtmlConverter\Services\OxygenDocumentTree;
use OxyHtmlConverter\Services\RequestOptions;
use OxyHtmlConverter\Services\TreeBuilderFactory;
use OxyHtmlConverter\Validation\OutputValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ImportSecurityRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        remove_all_filters();
        $_POST = [];
        $GLOBALS['__wp_send_json_last'] = null;
        $GLOBALS['__wp_check_ajax_referer'] = true;
        $GLOBALS['__wp_current_user_can'] = true;
        $GLOBALS['__wp_posts'] = [];
        $GLOBALS['__wp_post_meta'] = [];
        $GLOBALS['__wp_options'] = [];
        $GLOBALS['__wp_next_post_id'] = 1;
        $GLOBALS['__wp_apply_unslash'] = true;
    }

    protected function tearDown(): void
    {
        $GLOBALS['__wp_apply_unslash'] = false;
        $_POST = [];
        parent::tearDown();
    }

    public function testMalformedHtmlReturnsClientErrorForBothEndpoints(): void
    {
        foreach (['handleConvert', 'handlePreview'] as $method) {
            $_POST = ['html' => ['<p>invalid shape</p>']];
            (new Ajax())->{$method}();
            $this->assertFalse($GLOBALS['__wp_send_json_last']['success']);
            $this->assertSame(400, $GLOBALS['__wp_send_json_last']['status_code']);
        }
    }

    public function testBatchDoesNotStripSourceBackslashesTwice(): void
    {
        $html = '<p>Path C:\\tools\\file and literal \\"quoted\\"</p>';
        $_POST = ['html' => addslashes($html), 'wrapInContainer' => false];
        (new Ajax())->handleConvert();
        $single = $GLOBALS['__wp_send_json_last']['data']['element'];

        $_POST = ['batch' => [addslashes($html)]];
        (new Ajax())->handleBatchConvert();
        $batch = $GLOBALS['__wp_send_json_last']['data']['results'][0]['element'];
        $this->assertSame($single, $batch);
    }

    #[DataProvider('executableMarkupProvider')]
    public function testRawHtmlImportRejectsExecutableMarkup(string $type, string $html): void
    {
        $field = $type === 'HtmlCode' ? 'html_code' : 'text';
        $payload = $this->payload($type, [$field => $html]);
        $_POST = ['importPayload' => addslashes(json_encode($payload))];
        (new Ajax())->handleImportPage();
        $this->assertFalse($GLOBALS['__wp_send_json_last']['success']);
        $this->assertSame(403, $GLOBALS['__wp_send_json_last']['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);

        $result = (new OxygenPageImporter())->import($payload);
        $this->assertFalse($result['success']);
        $this->assertSame(403, $result['status']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public static function executableMarkupProvider(): array
    {
        return [
            'slash event attribute' => ['HtmlCode', '<img src=x /onerror=alert(1)>'],
            'framework directive' => ['HtmlCode', '<div x-init="alert(1)">text</div>'],
            'native text script' => ['Text', '<script>alert(1)</script>'],
            'native heading event' => ['Heading', '<img src=x onerror=alert(1)>'],
        ];
    }

    public function testStyleElementBreakoutRejectedBeforePersistence(): void
    {
        $payload = $this->payload('Container', []);
        $payload['pageScopedCss'] = '/* </STYLE><img src=x onerror=alert(1)> */';
        $_POST = ['importPayload' => addslashes(json_encode($payload))];
        (new Ajax())->handleImportPage();
        $this->assertSame(403, $GLOBALS['__wp_send_json_last']['status_code']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
        $result = (new OxygenPageImporter())->import($payload);
        $this->assertSame(403, $result['status']);
        $this->assertSame([], $GLOBALS['__wp_posts']);
    }

    public function testRejectedSourceDiagnosticsDoNotBlockSafeImport(): void
    {
        $source = 'data:image/svg+xml,<svg onload="alert(1)"></svg>';
        $payload = $this->payload('Container', []);
        $payload['stats']['unsupportedItems'] = [['sourceSnippet' => $source]];
        $payload['audit']['preserved']['assetNormalization']['assets'] = [['source' => $source]];
        $payload['assetNormalization']['assets'] = [['source' => $source, 'status' => 'rejected']];
        $payload['importPlan']['fallbacks'] = [['sourceSnippet' => $source]];
        $result = (new OxygenPageImporter())->import($payload);
        $this->assertTrue($result['success'], json_encode($result));
    }

    public function testDiagnosticFieldNamesCannotHideRenderedUnsafeUrls(): void
    {
        foreach (['audit', 'stats', 'sourceSnippet', 'assetNormalization'] as $key) {
            $payload = $this->payload('Container', []);
            $payload['element']['data']['properties']['design'][$key] = 'javascript:alert(1)';
            $result = (new OxygenPageImporter())->import($payload);
            $this->assertFalse($result['success']);
            $this->assertSame(403, $result['status']);
            $this->assertSame([], $GLOBALS['__wp_posts']);
        }
    }

    #[DataProvider('rejectedAssetFixtureProvider')]
    public function testRejectedAssetFixturesPassConversionAndSafePersistence(string $fixture): void
    {
        $html = file_get_contents(__DIR__ . '/../../fixtures/html/' . $fixture);
        $options = (new RequestOptions())->normalizeConvert(['safeMode' => true, 'allowExecutableCode' => false]);
        $conversion = (new TreeBuilderFactory())->createForConvert($options, $html)->convert($html);
        $this->assertTrue($conversion['success']);
        foreach (['element', 'selectorPayload', 'cssElement'] as $field) {
            $renderable = json_encode($conversion[$field] ?? []);
            $this->assertStringNotContainsString('data:image/svg+xml', $renderable, $field);
        }
        $built = (new ConvertPayloadBuilder(new OxygenDocumentTree(), new ConversionAuditBuilder(), new OutputValidator()))
            ->build($conversion, $options, $html);
        $this->assertTrue($built['success'], json_encode($built));
        $result = (new OxygenPageImporter())->import($built['data']);
        $this->assertTrue($result['success'], json_encode($result));
    }

    public static function rejectedAssetFixtureProvider(): array
    {
        return [
            ['design1.html'],
            ['design5.html'],
            ['parser-edge-cases/semicolons-data-url.html'],
        ];
    }

    private function payload(string $type, array $content): array
    {
        return [
            'title' => 'Security regression',
            'element' => [
                'id' => 1,
                'data' => [
                    'type' => 'OxygenElements\\' . $type,
                    'properties' => ['content' => ['content' => $content]],
                ],
                'children' => [],
            ],
            'importPlan' => ['status' => 'ready', 'canImport' => true],
        ];
    }
}
