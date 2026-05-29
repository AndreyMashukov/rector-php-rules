<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoSilentFallbackRector;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;

#[CoversClass(NoSilentFallbackRector::class)]
final class NoSilentFallbackRectorTest extends TestCase
{
    public function testRuleDefinitionDescribesIntent(): void
    {
        $def = (new NoSilentFallbackRector())->getRuleDefinition();

        self::assertStringContainsString('Forbid silent fallbacks', $def->getDescription());
        self::assertStringContainsString('isset', $def->getDescription());
        self::assertStringContainsString('NoNullCoalesceNewFallbackRector', $def->getDescription());
    }

    public function testNodeTypesCoverThreeOperators(): void
    {
        self::assertSame(
            [Coalesce::class, CoalesceAssign::class, Ternary::class],
            (new NoSilentFallbackRector())->getNodeTypes(),
        );
    }

    public function testFlagsScalarCoalesce(): void
    {
        $node = new Coalesce(new Variable('env'), new String_('dev'));

        $result = (new NoSilentFallbackRector())->refactor($node);

        self::assertSame($node, $result);
        $this->assertHasMarker($node, '`??` fallback');
    }

    public function testFlagsArrayLiteralCoalesce(): void
    {
        $node = new Coalesce(new Variable('items'), new Array_([]));

        (new NoSilentFallbackRector())->refactor($node);

        $this->assertHasMarker($node, '`??` fallback');
    }

    public function testFlagsCoalesceAssign(): void
    {
        $node = new CoalesceAssign(new Variable('items'), new Array_([]));

        (new NoSilentFallbackRector())->refactor($node);

        $this->assertHasMarker($node, '`??=` fallback');
    }

    public function testFlagsIssetTernary(): void
    {
        $name = new Variable('name');
        $node = new Ternary(new Isset_([$name]), $name, new String_('anonymous'));

        (new NoSilentFallbackRector())->refactor($node);

        $this->assertHasMarker($node, 'fallback ternary');
    }

    public function testFlagsNotIssetTernary(): void
    {
        $name = new Variable('name');
        $node = new Ternary(
            new BooleanNot(new Isset_([$name])),
            new String_('anonymous'),
            $name,
        );

        (new NoSilentFallbackRector())->refactor($node);

        $this->assertHasMarker($node, 'fallback ternary');
    }

    public function testFlagsArrayKeyExistsTernary(): void
    {
        $cond = new FuncCall(new Name('array_key_exists'), [
            new Arg(new String_('name')),
            new Arg(new Variable('user')),
        ]);
        $node = new Ternary($cond, new Variable('name'), new String_('anonymous'));

        (new NoSilentFallbackRector())->refactor($node);

        $this->assertHasMarker($node, 'fallback ternary');
    }

    public function testFlagsShortTernary(): void
    {
        $node = new Ternary(new Variable('title'), null, new String_('Untitled'));

        (new NoSilentFallbackRector())->refactor($node);

        $this->assertHasMarker($node, 'short ternary');
    }

    public function testSkipsCoalesceIntoNew(): void
    {
        $node = new Coalesce(new Variable('clock'), new New_(new Name('SystemClock')));

        self::assertNull((new NoSilentFallbackRector())->refactor($node));
        self::assertNull($node->getAttribute('comments'));
    }

    public function testLeavesUnrelatedTernaryAlone(): void
    {
        $cond = new Greater(new Variable('value'), new Int_(0));
        $node = new Ternary($cond, new Int_(1), new Int_(-1));

        self::assertNull((new NoSilentFallbackRector())->refactor($node));
        self::assertNull($node->getAttribute('comments'));
    }

    public function testLeavesNonNullVariableCheckTernaryAlone(): void
    {
        $cond = new NotIdentical(
            new Variable('value'),
            new ConstFetch(new Name('null')),
        );
        $node = new Ternary($cond, new Variable('value'), new String_('default'));

        self::assertNull((new NoSilentFallbackRector())->refactor($node));
        self::assertNull($node->getAttribute('comments'));
    }

    public function testRefactorReturnsNullForUnrelatedNode(): void
    {
        self::assertNull((new NoSilentFallbackRector())->refactor(new Variable('x')));
    }

    public function testRefactorIsIdempotent(): void
    {
        $rule = new NoSilentFallbackRector();
        $node = new Coalesce(new Variable('env'), new String_('dev'));

        $rule->refactor($node);
        $rule->refactor($node);

        $comments = $node->getAttribute('comments');
        self::assertCount(1, $this->ensureCommentList($comments));
    }

    private function assertHasMarker(Node $node, string $needle): void
    {
        $comments = $this->ensureCommentList($node->getAttribute('comments'));
        self::assertNotEmpty($comments);
        $last = $comments[array_key_last($comments)];
        self::assertStringContainsString('RECTOR-BAN', $last->getText());
        self::assertStringContainsString($needle, $last->getText());
    }

    /**
     * @param  mixed                $value
     * @return array<int, Comment>
     */
    private function ensureCommentList(mixed $value): array
    {
        self::assertIsArray($value);
        /** @var array<int, Comment> $value */
        return $value;
    }
}
