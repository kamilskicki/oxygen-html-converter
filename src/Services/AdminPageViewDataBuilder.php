<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Builds the view model used by the converter admin page template.
 */
class AdminPageViewDataBuilder
{
    public function __construct(private readonly EnvironmentService $environment)
    {
    }

    /**
     * @param array<string, mixed> $ui
     * @return array<string, mixed>
     */
    public function build(string $classMode, string $elementMappingMode, array $ui): array
    {
        $isEssentialPluginActive = $this->environment->isBreakdanceElementsForOxygenActive();
        $isEssentialContractCompatible = $this->environment->isEssentialButtonContractCompatible();
        $effectiveButtonMapping = $this->environment->shouldPreferEssentialElements() ? 'essential' : 'oxygen';
        $contractIssues = $isEssentialPluginActive ? $this->environment->getEssentialButtonContractIssues() : [];

        $contractStatusText = __('Not checked', 'oxygen-html-converter');
        $contractStatusClass = 'is-neutral';

        if ($isEssentialPluginActive) {
            if ($isEssentialContractCompatible) {
                $contractStatusText = __('Compatible', 'oxygen-html-converter');
                $contractStatusClass = 'is-success';
            } else {
                $contractStatusText = __('Incompatible', 'oxygen-html-converter');
                $contractStatusClass = 'is-danger';
            }
        }

        return [
            'classMode' => $classMode,
            'elementMappingMode' => $elementMappingMode,
            'ui' => $ui,
            'isEssentialPluginActive' => $isEssentialPluginActive,
            'isEssentialContractCompatible' => $isEssentialContractCompatible,
            'effectiveButtonMapping' => $effectiveButtonMapping,
            'contractIssues' => $contractIssues,
            'contractStatusText' => $contractStatusText,
            'contractStatusClass' => $contractStatusClass,
        ];
    }
}
