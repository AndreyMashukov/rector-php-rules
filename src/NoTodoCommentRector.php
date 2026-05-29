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
use RuntimeException;

final class NoTodoCommentRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const string BAN_MARKER = ' // RECTOR-BAN: deferred-work marker is forbidden — implement it now or track it in an issue, do not leave a stub';

    /** @var list<string> */
    private const array MARKERS = ['TODO', 'FIXME', 'XXX', 'HACK'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid TODO / FIXME / XXX / HACK markers in PHP comments and docblocks outright. '
            . 'An owner or ticket does not redeem a marker — a deferred note is work decided against but left '
            . 'in the tree. Implement it now or track it in an issue. Rector flags every occurrence; commit is blocked.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        // TODO(@alice): switch to the pooled client once PROJ-123 lands
                        $client = new Client();
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        $client = new PooledClient();
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
        if ($docComment instanceof Doc && $this->opensWithMarker($docComment->getText())) {
            $node->setDocComment(new Doc($docComment->getText() . self::BAN_MARKER));
            $changed = true;
        }

        $comments = self::existingComments($node);
        if ([] !== $comments) {
            $newComments = [];
            $mutated     = false;
            foreach ($comments as $comment) {
                if (!$comment instanceof Doc && $this->opensWithMarker($comment->getText())) {
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

    private function opensWithMarker(string $text): bool
    {
        if (str_contains($text, self::BAN_MARKER)) {
            return false;
        }
        $lines = preg_split('/\R/', $text);
        if ($lines === false) {
            throw new RuntimeException('preg_split failed splitting comment lines');
        }
        foreach ($lines as $line) {
            $trimmed = ltrim($line, "/#* \t");
            foreach (self::MARKERS as $marker) {
                if (!str_starts_with($trimmed, $marker)) {
                    continue;
                }
                $rest = substr($trimmed, strlen($marker));
                if ('' === $rest || !ctype_alnum($rest[0])) {
                    return true;
                }
            }
        }

        return false;
    }
}
