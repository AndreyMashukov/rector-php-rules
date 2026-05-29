<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoTypeOnlyAssertionsInTestsRector;
use PhpParser\Node\Expr\StaticCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoTypeOnlyAssertionsInTestsRector::class)]
final class NoTypeOnlyAssertionsInTestsRectorTest extends TestCase
{
    public function testRuleDefinitionMentionsBannedAssertions(): void
    {
        $rule = new NoTypeOnlyAssertionsInTestsRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('assertIsArray', $def->getDescription());
        self::assertStringContainsString('assertIsString', $def->getDescription());
        self::assertStringContainsString('assertIsNot', $def->getDescription());
        self::assertStringContainsString('assertSame', $def->getDescription());
    }

    public function testNodeTypesTargetStaticCallOnly(): void
    {
        $rule  = new NoTypeOnlyAssertionsInTestsRector();
        $types = $rule->getNodeTypes();

        self::assertSame([StaticCall::class], $types);
    }

    public function testIsTestPathAcceptsTestsRejectsMigrationsAndRules(): void
    {
        self::assertTrue(NoTypeOnlyAssertionsInTestsRector::isTestPath('/x/tests/Unit/Foo.php'));
        self::assertTrue(NoTypeOnlyAssertionsInTestsRector::isTestPath('/x/tests/Functional/Bar.php'));
        self::assertFalse(NoTypeOnlyAssertionsInTestsRector::isTestPath('/x/migrations/Version1.php'));
        self::assertFalse(NoTypeOnlyAssertionsInTestsRector::isTestPath('/x/.rector/Rules/X.php'));
        self::assertFalse(NoTypeOnlyAssertionsInTestsRector::isTestPath('/x/src/Foo.php'));
    }
}
