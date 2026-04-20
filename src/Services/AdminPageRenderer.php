<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

/**
 * Renders the converter admin UI from a dedicated view template.
 */
class AdminPageRenderer
{
    private string $templatePath;

    public function __construct(?string $templatePath = null)
    {
        $this->templatePath = $templatePath ?: dirname(__DIR__) . '/Views/admin-page.php';
    }

    /**
     * @param array<string, mixed> $viewData
     */
    public function render(array $viewData): void
    {
        if (!is_file($this->templatePath)) {
            throw new \RuntimeException('Admin page template not found.');
        }

        extract($viewData, EXTR_SKIP);
        require $this->templatePath;
    }
}
