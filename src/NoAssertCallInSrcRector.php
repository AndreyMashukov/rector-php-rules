<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use Amashukov\RectorRules\Internal\CommentMarkerTrait;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoAssertCallInSrcRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const string BAN_MARKER = ' /* RECTOR-BAN: assert() banned in src — use a runtime instanceof + throw new LogicException(...) so the failure mode is explicit and PHPStan still narrows */';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid bare assert() / \assert() function calls anywhere under src/**. '
            . 'assert() is silent under production zend.assertions=-1 and offers nothing over an explicit '
            . '`if (!cond) throw new \LogicException(...)`. PHPStan narrows after instanceof + throw just as well as after '
            . '\assert, and a `@phpstan-assert` docblock on a private helper keeps the narrowing centralised. '
            . 'Skips tests/, migrations/, and .rector/.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        \assert($value instanceof Foo || $value instanceof Bar);
                        $value->doThing();
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        if (!$value instanceof Foo && !$value instanceof Bar) {
                            throw new \LogicException(sprintf('unsupported %s', $value::class));
                        }
                        $value->doThing();
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
        return [FuncCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof FuncCall) {
            return null;
        }
        if (!$node->name instanceof Name) {
            return null;
        }
        if ('assert' !== strtolower($node->name->toLowerString())) {
            return null;
        }

        $file = $this->file->getFilePath();
        if (!self::isSrcPath($file)) {
            return null;
        }

        $existing = self::existingComments($node);
        foreach ($existing as $comment) {
            if (str_contains($comment->getText(), 'RECTOR-BAN: assert() banned')) {
                return null;
            }
        }
        $node->setAttribute('comments', [...$existing, new Comment(self::BAN_MARKER)]);

        return $node;
    }

    public static function isSrcPath(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        if (!str_contains($normalized, '/src/')) {
            return false;
        }
        if (str_contains($normalized, '/tests/')) {
            return false;
        }
        if (str_contains($normalized, '/migrations/')) {
            return false;
        }
        return !str_contains($normalized, '/.rector/');
    }
}
