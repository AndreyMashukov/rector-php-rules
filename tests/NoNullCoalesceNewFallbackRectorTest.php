<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoNullCoalesceNewFallbackRector;
use PhpParser\Comment;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoNullCoalesceNewFallbackRector::class)]
final class NoNullCoalesceNewFallbackRectorTest extends TestCase
{
    public function testRuleDefinitionMentionsFallback(): void
    {
        $rule = new NoNullCoalesceNewFallbackRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('null-coalesce', $def->getDescription());
        self::assertStringContainsString('new', $def->getDescription());
        self::assertStringContainsString('dependency', $def->getDescription());
    }

    public function testNodeTypesTargetsCoalesceOnly(): void
    {
        $rule  = new NoNullCoalesceNewFallbackRector();
        $types = $rule->getNodeTypes();

        self::assertSame([Coalesce::class], $types);
    }

    public function testRefactorFlagsCoalesceWithNewOnRight(): void
    {
        $rule = new NoNullCoalesceNewFallbackRector();

        $node = new Coalesce(
            new Variable('clock'),
            new New_(new Name('SystemClock')),
        );

        $result = $rule->refactor($node);

        self::assertSame($node, $result);
        $comments = $node->getAttribute('comments');
        self::assertIsArray($comments);
        self::assertCount(1, $comments);
        self::assertInstanceOf(Comment::class, $comments[0]);
        self::assertStringContainsString('RECTOR-BAN', $comments[0]->getText());
        self::assertStringContainsString('fallback', $comments[0]->getText());
    }

    public function testRefactorIgnoresCoalesceWithoutNew(): void
    {
        $rule = new NoNullCoalesceNewFallbackRector();

        $node = new Coalesce(
            new Variable('value'),
            new ConstFetch(new Name('null')),
        );

        self::assertNull($rule->refactor($node));
        self::assertNull($node->getAttribute('comments'));
    }

    public function testRefactorReturnsNullForNonCoalesceNode(): void
    {
        $rule = new NoNullCoalesceNewFallbackRector();
        $node = new Variable('x');

        self::assertNull($rule->refactor($node));
    }

    public function testRefactorIsIdempotent(): void
    {
        $rule = new NoNullCoalesceNewFallbackRector();
        $node = new Coalesce(
            new Variable('clock'),
            new New_(new Name('SystemClock')),
        );

        $rule->refactor($node);
        $rule->refactor($node);

        $comments = $node->getAttribute('comments');
        self::assertIsArray($comments);
        self::assertCount(1, $comments);
    }
}
