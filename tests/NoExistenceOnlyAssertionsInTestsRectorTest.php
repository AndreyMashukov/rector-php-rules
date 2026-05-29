<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoExistenceOnlyAssertionsInTestsRector;
use PhpParser\Node\Expr\StaticCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoExistenceOnlyAssertionsInTestsRector::class)]
final class NoExistenceOnlyAssertionsInTestsRectorTest extends TestCase
{
    public function testRuleDefinitionMentionsBannedAssertions(): void
    {
        $rule = new NoExistenceOnlyAssertionsInTestsRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('assertNotEmpty', $def->getDescription());
        self::assertStringContainsString('assertNotNull', $def->getDescription());
        self::assertStringContainsString('assertArrayHasKey', $def->getDescription());
        self::assertStringContainsString('assertSame', $def->getDescription());
    }

    public function testNodeTypesTargetStaticCallOnly(): void
    {
        $rule  = new NoExistenceOnlyAssertionsInTestsRector();
        $types = $rule->getNodeTypes();

        self::assertSame([StaticCall::class], $types);
    }

    public function testIsTestPathAcceptsTestsRejectsMigrationsAndRules(): void
    {
        self::assertTrue(NoExistenceOnlyAssertionsInTestsRector::isTestPath('/x/tests/Unit/Foo.php'));
        self::assertTrue(NoExistenceOnlyAssertionsInTestsRector::isTestPath('/x/tests/Functional/Bar.php'));
        self::assertFalse(NoExistenceOnlyAssertionsInTestsRector::isTestPath('/x/migrations/Version1.php'));
        self::assertFalse(NoExistenceOnlyAssertionsInTestsRector::isTestPath('/x/.rector/Rules/X.php'));
        self::assertFalse(NoExistenceOnlyAssertionsInTestsRector::isTestPath('/x/src/Foo.php'));
    }
}
