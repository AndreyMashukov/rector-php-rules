<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoEnvironmentCheckInSrcRector;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoEnvironmentCheckInSrcRector::class)]
final class NoEnvironmentCheckInSrcRectorTest extends TestCase
{
    public function testRuleDefinitionMentionsTheBannedPatterns(): void
    {
        $rule = new NoEnvironmentCheckInSrcRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('prod / dev / test', $def->getDescription());
        self::assertStringContainsString('APP_ENV', $def->getDescription());
        self::assertStringContainsString('getEnvironment', $def->getDescription());
    }

    public function testNodeTypesCoverComparisonsAndFetchesAndCalls(): void
    {
        $rule  = new NoEnvironmentCheckInSrcRector();
        $types = $rule->getNodeTypes();

        self::assertContains(Equal::class, $types);
        self::assertContains(Identical::class, $types);
        self::assertContains(NotEqual::class, $types);
        self::assertContains(NotIdentical::class, $types);
        self::assertContains(ArrayDimFetch::class, $types);
        self::assertContains(FuncCall::class, $types);
        self::assertContains(MethodCall::class, $types);
    }

    public function testIsSrcPathAcceptsSrcAndRejectsTestsMigrationsRules(): void
    {
        self::assertTrue(NoEnvironmentCheckInSrcRector::isSrcPath('/x/src/Controller/X.php'));
        self::assertFalse(NoEnvironmentCheckInSrcRector::isSrcPath('/x/tests/Unit/X.php'));
        self::assertFalse(NoEnvironmentCheckInSrcRector::isSrcPath('/x/migrations/Version1.php'));
        self::assertFalse(NoEnvironmentCheckInSrcRector::isSrcPath('/x/.rector/Rules/X.php'));
    }
}
