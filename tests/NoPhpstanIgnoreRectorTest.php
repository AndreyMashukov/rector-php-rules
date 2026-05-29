<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoPhpstanIgnoreRector;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoPhpstanIgnoreRector::class)]
final class NoPhpstanIgnoreRectorTest extends TestCase
{
    public function testRuleDefinitionMentionsBannedAnnotations(): void
    {
        $rule = new NoPhpstanIgnoreRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('PHPStan', $def->getDescription());
        self::assertStringContainsString('silence', $def->getDescription());
    }

    public function testNodeTypesTargetStatementsOnly(): void
    {
        $rule  = new NoPhpstanIgnoreRector();
        $types = $rule->getNodeTypes();

        self::assertSame([Stmt::class], $types);
    }
}
