<?php

declare(strict_types=1);

/**
 * Validate the WordPress.org readme without network access.
 */

$projectRoot = dirname(__DIR__);
$readmePath = $argv[1] ?? $projectRoot . DIRECTORY_SEPARATOR . 'readme.txt';
$displayPath = $argv[1] ?? 'readme.txt';
$errors = [];

if (!is_file($readmePath) || !is_readable($readmePath)) {
    fwrite(STDERR, sprintf("Readme validation failed: %s is not readable.\n", $displayPath));
    exit(1);
}

$contents = file_get_contents($readmePath);
if ($contents === false) {
    fwrite(STDERR, sprintf("Readme validation failed: could not read %s.\n", $displayPath));
    exit(1);
}

$contents = preg_replace("/\r\n?|\x{2028}|\x{2029}/u", "\n", $contents) ?? $contents;
$contents = preg_replace('/^\x{FEFF}/u', '', $contents) ?? $contents;

if (preg_match('/\A===[ \t]+(.+?)[ \t]+===[ \t]*$/m', $contents) !== 1) {
    $errors[] = 'The first line must be a plugin title formatted as "=== Plugin Name ===".';
}

$firstSectionOffset = strlen($contents);
if (preg_match('/^==[ \t]+(.+?)[ \t]+==[ \t]*$/m', $contents, $firstSectionMatch, PREG_OFFSET_CAPTURE) === 1) {
    $firstSectionOffset = (int) $firstSectionMatch[0][1];
}
$preamble = substr($contents, 0, $firstSectionOffset);
$headers = [];

if (preg_match_all('/^([A-Za-z][A-Za-z ]*):[ \t]*(.*?)[ \t]*$/m', $preamble, $headerMatches, PREG_SET_ORDER)) {
    foreach ($headerMatches as $match) {
        $key = strtolower(trim($match[1]));
        if (array_key_exists($key, $headers)) {
            $errors[] = sprintf('Header "%s" must appear only once.', trim($match[1]));
            continue;
        }

        $headers[$key] = trim($match[2]);
    }
}

$requiredHeaders = [
    'stable tag' => 'Stable tag',
    'tested up to' => 'Tested up to',
    'requires at least' => 'Requires at least',
    'requires php' => 'Requires PHP',
    'license' => 'License',
];

foreach ($requiredHeaders as $key => $label) {
    if (!isset($headers[$key]) || $headers[$key] === '') {
        $errors[] = sprintf('Required header "%s" is missing or empty.', $label);
    }
}

foreach (['tested up to' => 'Tested up to', 'requires at least' => 'Requires at least', 'requires php' => 'Requires PHP'] as $key => $label) {
    if (isset($headers[$key]) && $headers[$key] !== '' && preg_match('/^\d+(?:\.\d+){1,2}$/', $headers[$key]) !== 1) {
        $errors[] = sprintf('Header "%s" must contain a numeric version such as 6.5 or 8.2.', $label);
    }
}

if (isset($headers['stable tag']) && $headers['stable tag'] !== ''
    && preg_match('/^(?:trunk|\d+(?:\.\d+)*)$/', $headers['stable tag']) !== 1
) {
    $errors[] = 'Header "Stable tag" must be "trunk" or a numeric WordPress.org tag such as 0.9.0.';
}

$shortDescription = '';
$preambleLines = explode("\n", $preamble);
foreach ($preambleLines as $index => $line) {
    if ($index === 0 || trim($line) === '' || preg_match('/^[A-Za-z][A-Za-z ]*:/', $line) === 1) {
        continue;
    }

    $shortDescription = trim($line);
    break;
}

if ($shortDescription === '') {
    $errors[] = 'A short description is required between the headers and the Description section.';
}

$shortDescriptionLength = characterLength($shortDescription);
if ($shortDescriptionLength > 150) {
    $errors[] = sprintf('The short description is %d characters; WordPress.org allows at most 150.', $shortDescriptionLength);
}

$sectionNames = [];
$sectionOffsets = [];
if (preg_match_all('/^==[ \t]+(.+?)[ \t]+==[ \t]*$/m', $contents, $sectionMatches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    foreach ($sectionMatches as $match) {
        $sectionNames[] = trim($match[1][0]);
        $sectionOffsets[] = (int) $match[0][1];
    }
}

$requiredSections = [
    'description' => ['Description'],
    'installation' => ['Installation'],
    'faq' => ['Frequently Asked Questions', 'FAQ'],
    'screenshots' => ['Screenshots'],
    'changelog' => ['Changelog'],
];
$requiredSectionPositions = [];

foreach ($requiredSections as $key => $aliases) {
    $positions = [];
    foreach ($sectionNames as $position => $sectionName) {
        if (in_array(strtolower($sectionName), array_map('strtolower', $aliases), true)) {
            $positions[] = $position;
        }
    }

    if ($positions === []) {
        $errors[] = sprintf('Required section "%s" is missing.', implode('" or "', $aliases));
        continue;
    }

    if (count($positions) > 1) {
        $errors[] = sprintf('Section "%s" must appear only once.', implode('" or "', $aliases));
    }

    $requiredSectionPositions[$key] = $positions[0];
    $sectionBody = sectionBody($contents, $sectionOffsets, $positions[0]);
    if (trim($sectionBody) === '') {
        $errors[] = sprintf('Section "%s" must not be empty.', $sectionNames[$positions[0]]);
    }
}

$lastPosition = -1;
foreach (array_keys($requiredSections) as $key) {
    if (!isset($requiredSectionPositions[$key])) {
        continue;
    }

    if ($requiredSectionPositions[$key] <= $lastPosition) {
        $errors[] = 'Required sections must be ordered: Description, Installation, FAQ, Screenshots, Changelog.';
        break;
    }

    $lastPosition = $requiredSectionPositions[$key];
}

$screenshotNumbers = [];
if (isset($requiredSectionPositions['screenshots'])) {
    $screenshotsBody = sectionBody($contents, $sectionOffsets, $requiredSectionPositions['screenshots']);
    if (preg_match_all('/^\s*(\d+)\.\s+\S.*$/m', $screenshotsBody, $screenshotMatches)) {
        $screenshotNumbers = array_map('intval', $screenshotMatches[1]);
    }
}

$assetScreenshotNumbers = screenshotAssetNumbers($projectRoot);
if ($screenshotNumbers !== rangeOrEmpty(1, count($screenshotNumbers))) {
    $errors[] = 'Screenshot entries must be numbered consecutively starting at 1.';
}
if ($assetScreenshotNumbers !== rangeOrEmpty(1, count($assetScreenshotNumbers))) {
    $errors[] = 'Screenshot asset files must be numbered consecutively starting at screenshot-1.';
}
if ($screenshotNumbers !== $assetScreenshotNumbers) {
    $errors[] = sprintf(
        'Screenshot entries (%s) do not match screenshot asset files (%s).',
        numberList($screenshotNumbers),
        numberList($assetScreenshotNumbers)
    );
}

$pluginVersion = pluginVersion($projectRoot . DIRECTORY_SEPARATOR . 'oxygen-html-converter.php');
if ($pluginVersion === null) {
    $errors[] = 'Could not determine the current plugin version from oxygen-html-converter.php.';
} else {
    $expectedStableTag = wordpressStableTag($pluginVersion);
    if (($headers['stable tag'] ?? '') !== $expectedStableTag) {
        $errors[] = sprintf(
            'Stable tag "%s" does not match the expected WordPress.org tag "%s" for plugin version "%s".',
            $headers['stable tag'] ?? '',
            $expectedStableTag,
            $pluginVersion
        );
    }

    if (isset($requiredSectionPositions['changelog'])) {
        $changelogBody = sectionBody($contents, $sectionOffsets, $requiredSectionPositions['changelog']);
        $versionPattern = '/^=[ \t]*' . preg_quote($pluginVersion, '/') . '[ \t]*=[ \t]*$/mi';
        if (preg_match($versionPattern, $changelogBody) !== 1) {
            $errors[] = sprintf('The Changelog section has no entry for current version %s.', $pluginVersion);
        }
    }
}

$tags = [];
if (isset($headers['tags'])) {
    $tags = array_values(array_filter(array_map('trim', explode(',', $headers['tags'])), static fn(string $tag): bool => $tag !== ''));
    if (count($tags) > 5) {
        $errors[] = sprintf('The Tags header contains %d tags; WordPress.org allows at most 5.', count($tags));
    }
}

if (isset($headers['contributors'])) {
    $contributors = array_map('trim', explode(',', $headers['contributors']));
    foreach ($contributors as $contributor) {
        if ($contributor === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $contributor) !== 1) {
            $errors[] = sprintf(
                'Contributor "%s" is invalid; use comma-separated, lowercase WordPress.org usernames.',
                $contributor
            );
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, sprintf("Readme validation failed: %d error(s).\n", count($errors)));
    foreach ($errors as $error) {
        fwrite(STDERR, sprintf("- %s\n", $error));
    }
    exit(1);
}

printf("Readme validation passed: %s\n", $displayPath);
printf("- Current version/changelog: %s\n", $pluginVersion);
printf("- WordPress.org stable tag: %s\n", $headers['stable tag'] ?? '');
printf("- Short description: %d/150 characters\n", $shortDescriptionLength);
printf("- Tags: %d/5\n", count($tags));
printf("- Screenshots: %d entries, %d files\n", count($screenshotNumbers), count($assetScreenshotNumbers));

/**
 * @param list<int> $sectionOffsets
 */
function sectionBody(string $contents, array $sectionOffsets, int $position): string
{
    $headingEnd = strpos($contents, "\n", $sectionOffsets[$position]);
    if ($headingEnd === false) {
        return '';
    }

    $bodyStart = $headingEnd + 1;
    $bodyEnd = $sectionOffsets[$position + 1] ?? strlen($contents);

    return substr($contents, $bodyStart, $bodyEnd - $bodyStart);
}

function characterLength(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}

/**
 * @return list<int>
 */
function screenshotAssetNumbers(string $projectRoot): array
{
    $directories = [
        $projectRoot . DIRECTORY_SEPARATOR . 'assets-wporg',
        $projectRoot . DIRECTORY_SEPARATOR . '.wordpress-org',
        $projectRoot,
    ];
    $numbers = [];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        $files = scandir($directory);
        if ($files === false) {
            continue;
        }

        foreach ($files as $file) {
            if (preg_match('/^screenshot-(\d+)\.(?:png|jpe?g|gif)$/i', $file, $matches) === 1) {
                $numbers[] = (int) $matches[1];
            }
        }
    }

    $numbers = array_values(array_unique($numbers));
    sort($numbers, SORT_NUMERIC);

    return $numbers;
}

function pluginVersion(string $pluginFile): ?string
{
    if (!is_file($pluginFile) || !is_readable($pluginFile)) {
        return null;
    }

    $contents = file_get_contents($pluginFile);
    if ($contents === false || preg_match('/^[ \t*]*Version:[ \t]*(\S.*)$/mi', $contents, $matches) !== 1) {
        return null;
    }

    return trim($matches[1]);
}

function wordpressStableTag(string $pluginVersion): string
{
    if (preg_match('/^(\d+(?:\.\d+)*)(?:[-+].+)?$/', $pluginVersion, $matches) !== 1) {
        return $pluginVersion;
    }

    return $matches[1];
}

/**
 * @return list<int>
 */
function rangeOrEmpty(int $start, int $count): array
{
    return $count === 0 ? [] : range($start, $count);
}

/**
 * @param list<int> $numbers
 */
function numberList(array $numbers): string
{
    return $numbers === [] ? 'none' : implode(', ', $numbers);
}
