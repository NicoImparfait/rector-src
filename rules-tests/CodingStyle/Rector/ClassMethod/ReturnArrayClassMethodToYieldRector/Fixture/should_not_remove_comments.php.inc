<?php

namespace Rector\Tests\CodingStyle\Rector\ClassMethod\ReturnArrayClassMethodToYieldRector\Fixture;

use Rector\Tests\CodingStyle\Rector\ClassMethod\ReturnArrayClassMethodToYieldRector\Source\ParentTestCase;

final class ShouldNotRemoveComments extends ParentTestCase
{
    public function provideData(): array
    {
        // Let's pretend that this comment is extremely important and meaningful.
        // It should not be removed by Rector.
        return [
            // This is yet another comment.
            ['item1'],

            // And a final one.
            ['item2'],
        ];
    }
}

?>
-----
<?php

namespace Rector\Tests\CodingStyle\Rector\ClassMethod\ReturnArrayClassMethodToYieldRector\Fixture;

use Rector\Tests\CodingStyle\Rector\ClassMethod\ReturnArrayClassMethodToYieldRector\Source\ParentTestCase;

final class ShouldNotRemoveComments extends ParentTestCase
{
    public function provideData(): \Iterator
    {
        // Let's pretend that this comment is extremely important and meaningful.
        // It should not be removed by Rector.
        // This is yet another comment.
        yield ['item1'];
        // And a final one.
        yield ['item2'];
    }
}

?>
