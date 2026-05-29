<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoDirectDispatchInFunctionalTestsRector;
use PhpParser\Node\Expr\MethodCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoDirectDispatchInFunctionalTestsRector::class)]
final class NoDirectDispatchInFunctionalTestsRectorTest extends TestCase
{
    public function testRuleDefinitionDocumentsTheApiAlternative(): void
    {
        $rule = new NoDirectDispatchInFunctionalTestsRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('EventDispatcher', $def->getDescription());
        self::assertStringContainsString('MessageBus', $def->getDescription());
    }

    public function testNodeTypesTargetMethodCallOnly(): void
    {
        $rule  = new NoDirectDispatchInFunctionalTestsRector();
        $types = $rule->getNodeTypes();

        self::assertSame([MethodCall::class], $types);
    }

    public function testIsFunctionalTestPathAcceptsAllFunctionalSubdirs(): void
    {
        self::assertTrue(NoDirectDispatchInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Functional/Controller/Y.php'));
        self::assertTrue(NoDirectDispatchInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Functional/Scenario/Y.php'));
        self::assertTrue(NoDirectDispatchInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Functional/Repository/Y.php'));
        self::assertFalse(NoDirectDispatchInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Unit/Y.php'));
        self::assertFalse(NoDirectDispatchInFunctionalTestsRector::isFunctionalTestPath('/x/src/Y.php'));
    }
}
