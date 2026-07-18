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
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'fixtures'
            . DIRECTORY_SEPARATOR . 'oxygen6-contracts'
            . DIRECTORY_SEPARATOR . 'element-types.json';
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException('Could not read stable Oxygen element contract at ' . $path);
        }

        $contract = json_decode($json, true);
        if (!is_array($contract) || !is_array($contract['elementTypes'] ?? null)) {
            throw new \RuntimeException('Stable Oxygen element contract is invalid at ' . $path);
        }

        return array_values(array_filter($contract['elementTypes'], 'is_string'));
    }
}
