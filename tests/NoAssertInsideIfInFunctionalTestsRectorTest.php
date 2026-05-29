<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoAssertInsideIfInFunctionalTestsRector;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Stmt\If_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoAssertInsideIfInFunctionalTestsRector::class)]
final class NoAssertInsideIfInFunctionalTestsRectorTest extends TestCase
{
    public function testRuleDefinitionMentionsConditionalAssertionBan(): void
    {
        $rule = new NoAssertInsideIfInFunctionalTestsRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('assertX()', $def->getDescription());
        self::assertStringContainsString('deterministic', $def->getDescription());
        self::assertStringContainsString('tests/Functional/', $def->getDescription());
    }

    public function testNodeTypesCoverIfAndMatch(): void
    {
        $rule  = new NoAssertInsideIfInFunctionalTestsRector();
        $types = $rule->getNodeTypes();

        self::assertContains(If_::class, $types);
        self::assertContains(Match_::class, $types);
    }
}
