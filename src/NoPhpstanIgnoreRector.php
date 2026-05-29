<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use Amashukov\RectorRules\Internal\CommentMarkerTrait;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoPhpstanIgnoreRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const string BAN_MARKER = ' // RECTOR-BAN: @phpstan-ignore / @psalm-suppress are forbidden — fix the type, not the report';

    /** @var list<string> */
    private const array BANNED_PATTERNS = [
        '@phpstan-ignore',
        '@phpstan-ignore-next-line',
        '@phpstan-ignore-line',
        '@psalm-suppress',
        'phpstan-baseline',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid PHPStan / Psalm ignore + suppress annotations anywhere in PHP comments + docblocks. '
            . 'Never silence a typecheck error — fix the type, not the report. Rector flags every occurrence; '
            . 'commit is blocked.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        // @phpstan-ignore-next-line
                        $foo->bar();
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        if ($foo instanceof Bar) {
                            $foo->bar();
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
        return [Stmt::class];
    }

    public function refactor(Node $node): ?Node
    {
        $changed = false;

        $docComment = $node->getDocComment();
        if ($docComment instanceof Doc && $this->isBanned($docComment->getText())) {
            $node->setDocComment(new Doc($docComment->getText() . self::BAN_MARKER));
            $changed = true;
        }

        $comments = self::existingComments($node);
        if ([] !== $comments) {
            $newComments = [];
            $mutated     = false;
            foreach ($comments as $comment) {
                if ($comment instanceof Doc) {
                    $newComments[] = $comment;

                    continue;
                }
                if ($this->isBanned($comment->getText())) {
                    $newComments[] = new Comment($comment->getText() . self::BAN_MARKER);
                    $mutated       = true;

                    continue;
                }
                $newComments[] = $comment;
            }
            if ($mutated) {
                $node->setAttribute('comments', $newComments);
                $changed = true;
            }
        }

        return $changed ? $node : null;
    }

    private function isBanned(string $text): bool
    {
        foreach (self::BANNED_PATTERNS as $pattern) {
            if (str_contains($text, $pattern) && !str_contains($text, self::BAN_MARKER)) {
                return true;
            }
        }

        return false;
    }
}
