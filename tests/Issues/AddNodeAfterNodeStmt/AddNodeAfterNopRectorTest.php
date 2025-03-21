<?php

declare(strict_types=1);

namespace Rector\Core\Tests\Issues\AddNodeAfterNodeStmt;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class AddNodeAfterNopRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideData')]
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    public static function provideData(): Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/FixtureNextNop');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/add_next_nop_configured_rule.php';
    }
}
