<?php

namespace OxyHtmlConverter\Tests\Unit;

use OxyHtmlConverter\Contracts\ElementContractRegistry;
use PHPUnit\Framework\TestCase;

class ElementContractRegistryTest extends TestCase
{
    public function testRegistryClassifiesEveryStableFirstPartyOxygenElement(): void
    {
        $registered = self::stableOxygenElementSlugs();
        $classified = array_keys(ElementContractRegistry::getFirstPartyElementStatuses());

        sort($registered);
        sort($classified);

        $this->assertSame($registered, $classified);
    }

    public function testRegistryRejectsFabricatedHeaderElement(): void
    {
        $this->assertTrue(ElementContractRegistry::isForbidden('OxygenElements\\Header'));
        $this->assertSame(
            ElementContractRegistry::STATUS_FORBIDDEN,
            ElementContractRegistry::getStatus('OxygenElements\\Header')
        );
    }

    public function testEveryClassifiedElementUsesKnownStatus(): void
    {
        $validStatuses = [
            ElementContractRegistry::STATUS_SUPPORTED,
            ElementContractRegistry::STATUS_FALLBACK_ONLY,
            ElementContractRegistry::STATUS_UNSAFE_DEFERRED,
            ElementContractRegistry::STATUS_NEVER_GENERATED,
        ];

        foreach (ElementContractRegistry::getFirstPartyElementStatuses() as $elementType => $status) {
            $this->assertContains($status, $validStatuses, $elementType);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function stableOxygenElementSlugs(): array
    {
        $configuredDir = getenv('OXY_HTML_CONVERTER_OXYGEN_DIR');
        $oxygenDir = is_string($configuredDir) && trim($configuredDir) !== ''
            ? rtrim($configuredDir, "\\/")
            : dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . 'oxygen';
        $elementsDir = $oxygenDir
            . DIRECTORY_SEPARATOR . 'subplugins'
            . DIRECTORY_SEPARATOR . 'oxygen-elements'
            . DIRECTORY_SEPARATOR . 'elements';

        $directories = glob($elementsDir . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'element.php');
        if (!is_array($directories)) {
            throw new \RuntimeException('Could not read stable Oxygen element source at ' . $elementsDir);
        }

        $slugs = [];
        foreach ($directories as $elementFile) {
            $source = file_get_contents($elementFile);
            if (!is_string($source)) {
                continue;
            }

            if (preg_match('/registerElementForEditing\(\s*"([^"]+)"/', $source, $matches) === 1) {
                $slugs[] = stripcslashes($matches[1]);
            }
        }

        return $slugs;
    }
}
