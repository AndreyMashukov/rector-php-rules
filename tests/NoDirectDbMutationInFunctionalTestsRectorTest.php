<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoDirectDbMutationInFunctionalTestsRector;
use PhpParser\Node\Expr\MethodCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoDirectDbMutationInFunctionalTestsRector::class)]
final class NoDirectDbMutationInFunctionalTestsRectorTest extends TestCase
{
    public function testRuleDefinitionDocumentsTheApiAlternative(): void
    {
        $rule = new NoDirectDbMutationInFunctionalTestsRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('Functional', $def->getDescription());
        self::assertStringContainsString('HTTP API', $def->getDescription());
        self::assertStringContainsString('Repository/', $def->getDescription());
    }

    public function testNodeTypesTargetMethodCallOnly(): void
    {
        $rule  = new NoDirectDbMutationInFunctionalTestsRector();
        $types = $rule->getNodeTypes();

        self::assertSame([MethodCall::class], $types);
    }

    public function testIsFunctionalTestPathAcceptsControllerRejectsRepositoryAndOthers(): void
    {
        self::assertTrue(NoDirectDbMutationInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Functional/Controller/Y.php'));
        self::assertTrue(NoDirectDbMutationInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Functional/Command/Y.php'));
        self::assertTrue(NoDirectDbMutationInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Functional/Scenario/Y.php'));
        self::assertFalse(NoDirectDbMutationInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Functional/Repository/Y.php'));
        self::assertFalse(NoDirectDbMutationInFunctionalTestsRector::isFunctionalTestPath('/x/tests/Unit/Y.php'));
        self::assertFalse(NoDirectDbMutationInFunctionalTestsRector::isFunctionalTestPath('/x/src/Y.php'));
    }
}
