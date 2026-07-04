<?php

namespace OxyHtmlConverter\Report;

/**
 * Tracks and reports conversion statistics, warnings, and errors.
 */
class ConversionReport
{
    private int $elementCount = 0;
    private int $tailwindClassCount = 0;
    private int $customClassCount = 0;
    private array $warnings = [];
    private array $errors = [];
    private array $info = [];
    private array $unsupportedItems = [];

    /**
     * Increment the count of converted elements.
     */
    public function incrementElementCount(int $amount = 1): void
    {
        $this->elementCount += $amount;
    }

    /**
     * Increment the count of detected Tailwind classes.
     */
    public function incrementTailwindClassCount(int $amount = 1): void
    {
        $this->tailwindClassCount += $amount;
    }

    /**
     * Increment the count of detected custom classes.
     */
    public function incrementCustomClassCount(int $amount = 1): void
    {
        $this->customClassCount += $amount;
    }

    /**
     * Add a warning to the report.
     */
    public function addWarning(string $message): void
    {
        if (!in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Add an error to the report.
     */
    public function addError(string $message): void
    {
        if (!in_array($message, $this->errors, true)) {
            $this->errors[] = $message;
        }
    }

    /**
     * Add an info message to the report.
     */
    public function addInfo(string $message): void
    {
        if (!in_array($message, $this->info, true)) {
            $this->info[] = $message;
        }
    }

    /**
     * Add a source-located unsupported or fallback decision to the report.
     */
    public function addUnsupportedItem(
        string $location,
        string $reason,
        string $severity = 'review',
        string $owner = 'core',
        string $remediation = ''
    ): void {
        $item = [
            'location' => trim($location) !== '' ? trim($location) : 'unknown',
            'reason' => trim($reason) !== '' ? trim($reason) : 'Unsupported structure requires review.',
            'severity' => in_array($severity, ['info', 'review', 'blocking'], true) ? $severity : 'review',
            'owner' => trim($owner) !== '' ? trim($owner) : 'core',
            'remediation' => trim($remediation) !== '' ? trim($remediation) : 'Map natively, remove it, or choose an explicit fallback.',
        ];

        if (!in_array($item, $this->unsupportedItems, true)) {
            $this->unsupportedItems[] = $item;
        }
    }

    /**
     * Reset the report data.
     */
    public function reset(): void
    {
        $this->elementCount = 0;
        $this->tailwindClassCount = 0;
        $this->customClassCount = 0;
        $this->warnings = [];
        $this->errors = [];
        $this->info = [];
        $this->unsupportedItems = [];
    }

    /**
     * Export the report as an array.
     */
    public function toArray(): array
    {
        return [
            'elements' => $this->elementCount,
            'tailwindClasses' => $this->tailwindClassCount,
            'customClasses' => $this->customClassCount,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'info' => $this->info,
            'unsupportedItems' => $this->unsupportedItems,
        ];
    }
}
