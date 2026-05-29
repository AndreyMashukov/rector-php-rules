<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\AssignOp\Coalesce as CoalesceAssign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoSilentFallbackRector extends AbstractRector
{
    private const string MARKER_PREFIX = '// RECTOR-BAN:';

    private const string BAN_COALESCE
        = '// RECTOR-BAN: `??` fallback hides a missing value — validate at the boundary or let it crash';

    private const string BAN_COALESCE_ASSIGN
        = '// RECTOR-BAN: `??=` fallback hides a missing value — assign explicitly after an existence check or let it crash';

    private const string BAN_TERNARY
        = '// RECTOR-BAN: `isset(...) ? ... : ...` / `array_key_exists(...) ? ... : ...` fallback ternary hides a missing value — validate at the boundary or let it crash';

    private const string BAN_SHORT_TERNARY
        = '// RECTOR-BAN: short ternary `?:` is a falsy-coalesce fallback — branch explicitly or let it crash';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid silent fallbacks for missing values: `$x ?? $default`, `$x ??= $default`, '
            . '`isset($a[$k]) ? $a[$k] : $default`, `!isset($x) ? $default : $x`, '
            . '`array_key_exists(...) ? : $default`, and the short ternary `$x ?: $default`. '
            . 'A missing value must crash at the call site or be validated at the boundary, '
            . 'never papered over with a hidden default. Sibling rules: `no-silent-fallback` '
            . '(eslint-plugin-mess-detector), `nosilentfallback` (go-lint), `no_silent_fallback` '
            . '(rust-lint). The narrower `NoNullCoalesceNewFallbackRector` still handles '
            . '`?? new ClassName(...)` — this rule deliberately skips that shape to avoid '
            . 'double-marking.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        $env  = $_ENV['APP_ENV'] ?? 'dev';
                        $port = $config['port'] ?? 8080;
                        $items ??= [];
                        $name = isset($user['name']) ? $user['name'] : 'anonymous';
                        $title = $row['title'] ?: 'Untitled';
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        $env  = self::requireEnv('APP_ENV');
                        $port = self::requireConfigInt($config, 'port');
                        if ($items === null) {
                            throw new \LogicException('items must be set before this point');
                        }
                        if (!isset($user['name'])) {
                            throw new \DomainException('user has no name');
                        }
                        $name = $user['name'];
                        if ($row['title'] === '') {
                            throw new \DomainException('row title cannot be empty');
                        }
                        $title = $row['title'];
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
        return [Coalesce::class, CoalesceAssign::class, Ternary::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Coalesce) {
            if ($node->right instanceof New_) {
                return null;
            }

            return $this->mark($node, self::BAN_COALESCE);
        }

        if ($node instanceof CoalesceAssign) {
            return $this->mark($node, self::BAN_COALESCE_ASSIGN);
        }

        if ($node instanceof Ternary) {
            if ($node->if === null) {
                return $this->mark($node, self::BAN_SHORT_TERNARY);
            }

            if ($this->isFallbackCondition($node->cond)) {
                return $this->mark($node, self::BAN_TERNARY);
            }
        }

        return null;
    }

    private function isFallbackCondition(Node $cond): bool
    {
        $inner = $cond instanceof BooleanNot ? $cond->expr : $cond;

        if ($inner instanceof Isset_) {
            return true;
        }

        if (
            $inner instanceof FuncCall
            && $inner->name instanceof Name
            && $inner->name->toString() === 'array_key_exists'
        ) {
            return true;
        }

        return false;
    }

    private function mark(Node $node, string $bannerText): Node
    {
        if ($this->isAlreadyMarked($node)) {
            return $node;
        }

        /** @var list<Comment> $existing */
        $existing = $node->getAttribute('comments', []);
        $node->setAttribute('comments', \array_merge(
            $existing,
            [new Comment($bannerText)],
        ));

        return $node;
    }

    private function isAlreadyMarked(Node $node): bool
    {
        /** @var list<Comment> $comments */
        $comments = $node->getAttribute('comments', []);

        foreach ($comments as $comment) {
            if (\str_contains($comment->getText(), self::MARKER_PREFIX)) {
                return true;
            }
        }

        return false;
    }
}
