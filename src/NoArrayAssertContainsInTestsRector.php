<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use Amashukov\RectorRules\Internal\CommentMarkerTrait;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoArrayAssertContainsInTestsRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const string BAN_MARKER = ' /* RECTOR-BAN: assertContains(actual, [A, B, ...]) is a smudged "either A or B" assertion — the production code path must be deterministic. If the actual value can be more than one thing, the test is racing the production code OR papering over a non-deterministic branch. Fix the test architecture (drive the production path to ONE known state), then assert that state exactly with assertSame. */';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid `assertContains($actual, [A, B, ...])` / `self::assertContains(...)` calls where the second argument '
            . 'is an inline array literal. The pattern is a smudged "either A or B" assertion that hides non-determinism. '
            . 'If the production code under test is correctly deterministic, the test should assert exactly one expected '
            . 'state via `assertSame`. If you cannot pin one state, the test is racing the production code path — fix the '
            . 'architecture (mock the right boundary, sequence the drive helpers, install a frozen clock), then assert '
            . 'the one true post-condition. Applies under tests/**; .rector/ rules and migrations skipped.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        self::assertContains($entity->getStatus(), [Status::READY, Status::DONE]);
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        // pin the production path so it reaches ONE deterministic state, then:
                        self::assertSame(Status::DONE, $entity->getStatus());
                        CODE_SAMPLE,
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof StaticCall) {
            return null;
        }
        if (!$node->name instanceof Identifier) {
            return null;
        }
        if ('assertContains' !== $node->name->toString()) {
            return null;
        }
        if (!$node->class instanceof Name) {
            return null;
        }
        $className = $node->class->toLowerString();
        if (!in_array($className, ['self', 'static', 'parent'], true) && !str_contains($className, 'testcase')) {
            return null;
        }
        if (count($node->args) < 2) {
            return null;
        }
        $secondArg = $node->args[1];
        if (!$secondArg instanceof Arg) {
            return null;
        }
        if (!$secondArg->value instanceof Array_) {
            return null;
        }

        $file = $this->file->getFilePath();
        if (!self::isTestPath($file)) {
            return null;
        }

        $existing = self::existingComments($node);
        foreach ($existing as $comment) {
            if (str_contains($comment->getText(), 'RECTOR-BAN: assertContains(actual, [A, B')) {
                return null;
            }
        }
        $node->setAttribute('comments', [...$existing, new Comment(self::BAN_MARKER)]);

        return $node;
    }

    public static function isTestPath(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        if (!str_contains($normalized, '/tests/')) {
            return false;
        }
        if (str_contains($normalized, '/.rector/')) {
            return false;
        }
        return !str_contains($normalized, '/migrations/');
    }
}
