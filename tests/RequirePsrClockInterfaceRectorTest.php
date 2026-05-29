<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\RequirePsrClockInterfaceRector;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequirePsrClockInterfaceRector::class)]
final class RequirePsrClockInterfaceRectorTest extends TestCase
{
    public function testRuleDefinitionDescribesIntent(): void
    {
        $rule = new RequirePsrClockInterfaceRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('PSR-20 Clock', $def->getDescription());
        self::assertStringContainsString('ClockInterface', $def->getDescription());
    }

    public function testNodeTypesCoverFuncCallAndNew(): void
    {
        $rule  = new RequirePsrClockInterfaceRector();
        $types = $rule->getNodeTypes();

        self::assertContains(FuncCall::class, $types);
        self::assertContains(New_::class, $types);
    }
}
