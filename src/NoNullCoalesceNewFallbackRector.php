<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\New_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoNullCoalesceNewFallbackRector extends AbstractRector
{
    private const string BAN_MARKER = '// RECTOR-BAN: implicit "?? new ..." fallback hides a required dependency — remove the fallback, make the parameter required, and let the caller decide';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid the null-coalesce-into-new-instance fallback pattern (`$dep ?? new ClassName(...)`). '
            . 'Such fallbacks hide a required dependency behind a default-construction trapdoor: the '
            . 'caller silently runs the wrong wiring instead of failing at the boundary. Make the '
            . 'parameter required and let the DI container or the test inject the real collaborator.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        public function __construct(?Clock $clock = null)
                        {
                            $this->clock = $clock ?? new SystemClock();
                        }
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        public function __construct(private readonly Clock $clock)
                        {
                        }
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
        return [Coalesce::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Coalesce) {
            return null;
        }

        if (!$node->right instanceof New_) {
            return null;
        }

        if ($this->isAlreadyMarked($node)) {
            return null;
        }

        /** @var list<Comment> $existing */
        $existing = $node->getAttribute('comments', []);
        $node->setAttribute('comments', \array_merge(
            $existing,
            [new Comment(self::BAN_MARKER)],
        ));

        return $node;
    }

    private function isAlreadyMarked(Node $node): bool
    {
        /** @var list<Comment> $comments */
        $comments = $node->getAttribute('comments', []);

        foreach ($comments as $comment) {
            if (\str_contains($comment->getText(), 'RECTOR-BAN')) {
                return true;
            }
        }

        return false;
    }
}
