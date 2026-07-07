<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use OxyHtmlConverter\Report\ConversionReport;

/**
 * Owns fallback and unsupported-operation reporting for the conversion pipeline.
 */
final class ConversionFallbackReporter
{
    private bool $safeMode = true;
    private bool $canEmitExecutableCodeFallback = false;

    public function __construct(
        private readonly ConversionReport $report,
        private readonly HtmlCodeSanitizer $htmlCodeSanitizer
    ) {
    }

    public function configure(bool $safeMode, bool $canEmitExecutableCodeFallback): void
    {
        $this->safeMode = $safeMode;
        $this->canEmitExecutableCodeFallback = $canEmitExecutableCodeFallback;
    }

    /**
     * Sanitize HtmlCode payloads in safe mode.
     *
     * @param array<string, mixed> $element
     */
    public function sanitizeHtmlCodeElement(array &$element): bool
    {
        if (!$this->htmlCodeSanitizer->sanitizeElement($element)) {
            $this->report->addWarning('Safe mode removed an HtmlCode block because no safe markup remained.');
            return false;
        }

        return true;
    }

    public function reportUnsupportedNode(
        DOMElement $node,
        string $reason,
        string $severity,
        string $safeModeImpact,
        string $remediation
    ): void {
        $this->report->addUnsupportedItem(
            $this->domNodeLocation($node),
            $reason,
            $severity,
            'Core native profile',
            $remediation,
            [
                'sourceSnippet' => $this->safeNodeSnippet($node),
                'selector' => $this->nodeSelectorLabel($node),
                'fallbackCategory' => $this->fallbackCategoryForNode($node),
                'safeModeImpact' => $safeModeImpact,
            ]
        );
    }

    public function reportHeadAssetNodes(DOMDocument $document, string $tag, bool $safeModeRemoved): void
    {
        $tag = strtolower($tag);
        if (!in_array($tag, ['link', 'script'], true)) {
            return;
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query('//head//' . $tag);
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if ($tag === 'link' && strtolower($node->getAttribute('rel')) !== 'stylesheet') {
                continue;
            }

            $category = $tag === 'script'
                ? ($safeModeRemoved ? 'removed_head_script' : 'unsafe_head_script_fallback')
                : ($safeModeRemoved ? 'removed_head_stylesheet' : 'unsafe_head_stylesheet_fallback');

            $reason = $safeModeRemoved
                ? 'Head ' . $tag . ' asset is removed in Safe Mode.'
                : 'Head ' . $tag . ' asset requires an unsafe visible HtmlCode fallback.';

            $safeModeImpact = $safeModeRemoved
                ? 'Safe Mode removed this head asset and no HtmlCode fallback was created.'
                : 'Safe Mode would remove this head asset; unsafe mode preserves it as visible HtmlCode.';

            $this->report->addUnsupportedItem(
                $this->domNodeLocation($node),
                $reason,
                'blocking',
                'Core native profile',
                'Persist supported assets through owned style/script stores or explicitly approve unsafe fallback.',
                [
                    'sourceSnippet' => $this->safeNodeSnippet($node),
                    'selector' => $this->nodeSelectorLabel($node),
                    'fallbackCategory' => $category,
                    'safeModeImpact' => $safeModeImpact,
                ]
            );
        }
    }

    /**
     * @param array<string, array<string, mixed>> $libraries
     */
    public function reportDetectedIconLibraries(array $libraries, bool $safeModeRemoved): void
    {
        foreach ($libraries as $key => $library) {
            if (!is_array($library)) {
                continue;
            }

            $name = trim((string) ($library['name'] ?? $key));
            $cdn = trim((string) ($library['cdn'] ?? ''));
            $type = (string) ($library['type'] ?? 'js');
            $tag = $type === 'css' ? 'link' : 'script';
            $snippet = $tag === 'link'
                ? '<link rel="stylesheet" href="' . $this->htmlCodeSanitizer->escapePlainText($cdn) . '">'
                : '<script src="' . $this->htmlCodeSanitizer->escapePlainText($cdn) . '"></script>';
            $category = $safeModeRemoved ? 'removed_icon_asset' : 'unsafe_icon_asset_fallback';

            $this->report->addUnsupportedItem(
                'document icon library: ' . $name,
                $safeModeRemoved
                    ? 'Detected icon library asset is removed in Safe Mode.'
                    : 'Detected icon library asset requires an unsafe visible HtmlCode fallback.',
                'blocking',
                'Core native profile',
                'Replace with native SVG/icon elements, persist a reviewed local asset, or explicitly approve unsafe fallback.',
                [
                    'sourceSnippet' => $snippet,
                    'selector' => 'icon-library[' . (string) $key . ']',
                    'fallbackCategory' => $category,
                    'safeModeImpact' => $safeModeRemoved
                        ? 'Safe Mode removed this icon library asset and no HtmlCode fallback was created.'
                        : 'Safe Mode would remove this icon library asset; unsafe mode preserves it as visible HtmlCode.',
                ]
            );
        }
    }

    public function reportExecutableAttributes(DOMElement $node): void
    {
        if ($this->canEmitExecutableCodeFallback) {
            return;
        }

        foreach ($node->attributes as $attribute) {
            $name = strtolower(trim((string) $attribute->name));
            $isExecutable = $this->isExecutableAttributeName($name);
            $isFormAssociated = $this->isFormAssociatedAttributeName($name);
            if ($isFormAssociated && $this->isFormControlNode($node)) {
                continue;
            }
            if (!$isExecutable && !$isFormAssociated) {
                continue;
            }

            $this->report->addUnsupportedItem(
                $this->domNodeLocation($node) . ' @' . $name,
                $isFormAssociated
                    ? 'Form-associated submission attribute was stripped from native output.'
                    : 'Executable or framework behavior attribute was stripped from native output.',
                'blocking',
                'Core native profile',
                $isFormAssociated
                    ? 'Use an approved WordPress/form integration, rebuild the form interaction natively, or explicitly approve unsafe HtmlCode fallback after reviewing submission behavior.'
                    : 'Replace this behavior with a verified native interaction or explicitly approve unsafe executable fallback.',
                [
                    'sourceSnippet' => $this->safeNodeSnippet($node),
                    'selector' => $this->nodeSelectorLabel($node) . '[' . $name . ']',
                    'fallbackCategory' => $isFormAssociated ? 'unsupported_form' : 'removed_inline_behavior',
                    'safeModeImpact' => $isFormAssociated
                        ? ($this->safeMode
                            ? 'Safe Mode removed the form-associated submission attribute and no native form integration was created.'
                            : 'Unsafe form submission preservation was not explicitly approved, so the attribute was removed.')
                        : ($this->safeMode
                            ? 'Safe Mode removed the behavior attribute and no JavaScript interaction was created.'
                            : 'Unsafe preservation was not explicitly approved, so the behavior attribute was removed.'),
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $assetNormalization
     */
    public function reportAssetNormalization(array $assetNormalization): void
    {
        $summary = is_array($assetNormalization['summary'] ?? null) ? $assetNormalization['summary'] : [];
        $manualFollowUp = (int) ($summary['manualFollowUp'] ?? 0);
        $rejected = (int) ($summary['rejected'] ?? 0);

        if ($manualFollowUp > 0) {
            $this->report->addInfo('Asset normalization flagged ' . $manualFollowUp . ' external asset(s) for manual localization, license, or cache review.');
        }

        if ($rejected > 0) {
            $this->report->addWarning('Asset normalization rejected ' . $rejected . ' temporary or unsafe asset URL(s).');
        }

        $assets = is_array($assetNormalization['assets'] ?? null) ? $assetNormalization['assets'] : [];
        foreach ($assets as $asset) {
            if (!is_array($asset) || ($asset['status'] ?? '') !== 'rejected') {
                continue;
            }

            $this->report->addUnsupportedItem(
                $this->assetStringField($asset, 'location', 'asset'),
                $this->assetStringField($asset, 'reason', 'Asset URL was rejected.'),
                'review',
                'Core asset normalization',
                'Replace with a stable local WordPress/Oxygen asset before production import.',
                [
                    'sourceSnippet' => $this->assetStringField($asset, 'source', 'not captured'),
                    'selector' => $this->assetStringField($asset, 'selector', 'asset'),
                    'fallbackCategory' => 'rejected_asset_url',
                    'safeModeImpact' => 'Temporary or unsafe asset URL was not persisted into Oxygen output.',
                ]
            );
        }
    }

    public function unsupportedHtmlReason(DOMElement $node, bool $unsafeApproved): string
    {
        if ($this->isFormControlNode($node)) {
            return $unsafeApproved
                ? 'Form markup requires unsafe visible HtmlCode fallback and is not imported as native editable controls.'
                : ($this->safeMode
                    ? 'Forms are not imported as editable native controls in Core; form markup is unsupported and sanitized as a non-executable HtmlCode fallback.'
                    : 'Forms require explicit unsafe code-fallback opt-in and are not imported as editable native controls by default.');
        }

        return $unsafeApproved
            ? 'Unsupported HTML structure requires visible HtmlCode fallback.'
            : ($this->safeMode
                ? 'Unsupported HTML structure requires HtmlCode fallback.'
                : 'Unsupported HTML structure requires explicit code-fallback opt-in.');
    }

    public function unsupportedHtmlSafeModeImpact(DOMElement $node, bool $unsafeApproved): string
    {
        if ($this->isFormControlNode($node)) {
            return $unsafeApproved
                ? 'Safe Mode would remove or sanitize form action, method, target, event, and field attributes; unsafe mode preserves raw form markup because executable-code fallback was explicitly approved.'
                : ($this->safeMode
                    ? 'Safe Mode removes form containers, action/formaction/target/event handlers, unsafe URL schemes, and unsupported field attributes from the fallback payload.'
                    : 'Unsafe preservation was not explicitly approved, so form markup is sanitized or removed instead of persisted as raw HTML.');
        }

        return $unsafeApproved
            ? 'Safe Mode would sanitize or remove unsafe markup; unsafe mode preserves the fallback.'
            : ($this->safeMode
                ? 'Safe Mode sanitizes the HtmlCode payload and may remove unsafe markup.'
                : 'Unsafe preservation was not explicitly approved, so the fallback payload is sanitized or removed.');
    }

    public function unsupportedHtmlRemediation(DOMElement $node): string
    {
        if ($this->isFormControlNode($node)) {
            return 'Use an approved WordPress/form integration, rebuild the fields natively, or explicitly approve unsafe HtmlCode fallback after reviewing action, method, target, validation attributes, and field data.';
        }

        return 'Map this structure to native Oxygen elements, replace it with an approved integration, or explicitly approve unsafe fallback.';
    }

    private function fallbackCategoryForNode(DOMElement $node): string
    {
        $tag = strtolower($node->tagName);

        return match ($tag) {
            'script' => $this->canEmitExecutableCodeFallback ? 'unsafe_executable_fallback' : 'removed_executable',
            'link' => $this->canEmitExecutableCodeFallback ? 'unsafe_head_asset_fallback' : 'removed_head_asset',
            'form', 'input', 'textarea', 'select' => 'unsupported_form',
            'iframe', 'object', 'embed', 'svg' => 'unsupported_embed',
            default => 'unsupported_html_code',
        };
    }

    private function isFormControlNode(DOMElement $node): bool
    {
        return in_array(strtolower($node->tagName), ['form', 'input', 'textarea', 'select'], true);
    }

    private function isExecutableAttributeName(string $name): bool
    {
        return $name !== '' && (
            strpos($name, 'on') === 0
            || strpos($name, 'data-oxy-at-') === 0
            || strpos($name, 'x-') === 0
            || strpos($name, 'v-') === 0
            || strpos($name, 'ng-') === 0
            || strpos($name, 'hx-on') === 0
            || strpos($name, 'bind:') === 0
            || strpos($name, ':') === 0
            || strpos($name, '@') === 0
        );
    }

    private function isFormAssociatedAttributeName(string $name): bool
    {
        return in_array($name, [
            'action',
            'method',
            'target',
            'formaction',
            'formtarget',
            'formmethod',
            'formenctype',
            'formnovalidate',
        ], true);
    }

    private function domNodeLocation(DOMElement $node): string
    {
        $parts = [];
        $current = $node;

        while ($current instanceof DOMElement) {
            $label = strtolower($current->tagName);
            if ($current->hasAttribute('id') && trim($current->getAttribute('id')) !== '') {
                $label .= '#' . trim($current->getAttribute('id'));
            }

            array_unshift($parts, $label);

            if (!($current->parentNode instanceof DOMElement)) {
                break;
            }

            $current = $current->parentNode;
        }

        return implode(' > ', $parts);
    }

    private function nodeSelectorLabel(DOMElement $node): string
    {
        $selector = strtolower($node->tagName);
        if ($node->hasAttribute('id') && trim($node->getAttribute('id')) !== '') {
            $selector .= '#' . trim($node->getAttribute('id'));
        }

        if ($node->hasAttribute('class')) {
            $classes = array_values(array_filter(preg_split('/\s+/', trim($node->getAttribute('class'))) ?: []));
            foreach ($classes as $className) {
                $selector .= '.' . preg_replace('/[^A-Za-z0-9_-]/', '-', $className);
            }
        }

        return $selector;
    }

    private function safeNodeSnippet(DOMElement $node): string
    {
        $html = $node->ownerDocument instanceof DOMDocument
            ? (string) $node->ownerDocument->saveHTML($node)
            : '<' . strtolower($node->tagName) . '>';

        $html = preg_replace('/<script\b([^>]*)>.*?<\/script>/is', '<script$1>[removed script]</script>', $html) ?? $html;
        $html = preg_replace('/\son[a-z0-9_-]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', ' data-removed-event="[removed]"', $html) ?? $html;
        $html = preg_replace('/javascript\s*:/i', '[unsafe-url]:', $html) ?? $html;
        $html = preg_replace('/\s+/', ' ', $html) ?? $html;
        $html = trim($html);

        if (strlen($html) > 180) {
            $html = substr($html, 0, 177) . '...';
        }

        return $html !== '' ? $html : '<' . strtolower($node->tagName) . '>';
    }

    /**
     * @param array<string, mixed> $asset
     */
    private function assetStringField(array $asset, string $field, string $default): string
    {
        $value = $asset[$field] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }
}
