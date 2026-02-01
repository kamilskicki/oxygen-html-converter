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
        if (!in_array($message, $this->warnings)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Add an error to the report.
     */
    public function addError(string $message): void
    {
        if (!in_array($message, $this->errors)) {
            $this->errors[] = $message;
        }
    }

    /**
     * Add an info message to the report.
     */
    public function addInfo(string $message): void
    {
        if (!in_array($message, $this->info)) {
            $this->info[] = $message;
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
        ];
    }
}