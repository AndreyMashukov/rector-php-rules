<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoArrayAssertContainsInTestsRector;
use PhpParser\Node\Expr\StaticCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoArrayAssertContainsInTestsRector::class)]
final class NoArrayAssertContainsInTestsRectorTest extends TestCase
{
    public function testRuleDefinitionDescribesTheSmudgedAssertionPattern(): void
    {
        $rule = new NoArrayAssertContainsInTestsRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('assertContains', $def->getDescription());
        self::assertStringContainsString('inline array literal', $def->getDescription());
        self::assertStringContainsString('assertSame', $def->getDescription());
    }

    public function testNodeTypesTargetStaticCallOnly(): void
    {
        $rule  = new NoArrayAssertContainsInTestsRector();
        $types = $rule->getNodeTypes();

        self::assertSame([StaticCall::class], $types);
    }

    public function testIsTestPathAcceptsTestsRejectsMigrationsAndRules(): void
    {
        self::assertTrue(NoArrayAssertContainsInTestsRector::isTestPath('/x/tests/Unit/Foo.php'));
        self::assertTrue(NoArrayAssertContainsInTestsRector::isTestPath('/x/tests/Functional/Bar.php'));
        self::assertFalse(NoArrayAssertContainsInTestsRector::isTestPath('/x/migrations/Version1.php'));
        self::assertFalse(NoArrayAssertContainsInTestsRector::isTestPath('/x/.rector/Rules/X.php'));
        self::assertFalse(NoArrayAssertContainsInTestsRector::isTestPath('/x/src/Foo.php'));
    }
}
