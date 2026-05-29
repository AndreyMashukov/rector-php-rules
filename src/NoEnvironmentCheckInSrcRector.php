<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use Amashukov\RectorRules\Internal\CommentMarkerTrait;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoEnvironmentCheckInSrcRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const string BAN_MARKER = ' /* RECTOR-BAN: env-check (prod/dev/test) in src is forbidden — gate behaviour via DI / route-loader / config, never via runtime env branching */';

    /**
     * @var list<string>
     */
    private const array ENV_VALUES = ['prod', 'dev', 'test', 'staging'];

    /**
     * @var list<string>
     */
    private const array ENV_KEYS = ['APP_ENV', 'SYMFONY_ENV', 'KERNEL_ENVIRONMENT'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid runtime env checks (prod / dev / test / staging) anywhere in src/**. '
            . 'Behaviour gating belongs at the DI / config / route-loader layer — never inline `if ($env === "test")` '
            . 'branches inside production code. The patterns flagged: `$_ENV["APP_ENV"]` access, `getenv("APP_ENV")`, '
            . '`$kernel->getEnvironment()` calls, and string literal comparisons against {prod, dev, test, staging}. '
            . 'Skips tests/, migrations/, and .rector/.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        if ('test' !== ($_ENV['APP_ENV'] ?? '')) {
                            return new JsonResponse(['error' => 'not_found'], 404);
                        }
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        // gate the route at the config layer (env-scoped route loader / DI compiler pass) instead.
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
        return [Equal::class, Identical::class, NotEqual::class, NotIdentical::class, ArrayDimFetch::class, FuncCall::class, MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!self::isSrcPath($this->file->getFilePath())) {
            return null;
        }

        if (!$this->matchesEnvCheck($node)) {
            return null;
        }

        $existing = self::existingComments($node);
        foreach ($existing as $comment) {
            if (str_contains($comment->getText(), 'RECTOR-BAN: env-check')) {
                return null;
            }
        }
        $node->setAttribute('comments', [...$existing, new Comment(self::BAN_MARKER)]);

        return $node;
    }

    private function matchesEnvCheck(Node $node): bool
    {
        if ($node instanceof Equal || $node instanceof Identical || $node instanceof NotEqual || $node instanceof NotIdentical) {
            return $this->hasEnvLiteral($node->left) || $this->hasEnvLiteral($node->right);
        }
        if ($node instanceof ArrayDimFetch) {
            return $this->isEnvSuperglobalAccess($node);
        }
        if ($node instanceof FuncCall) {
            return $this->isGetenvOnEnvKey($node);
        }
        return $node instanceof MethodCall && $node->name instanceof Identifier && 'getEnvironment' === $node->name->toString();
    }

    private function hasEnvLiteral(Node $expr): bool
    {
        return $expr instanceof String_ && in_array($expr->value, self::ENV_VALUES, true);
    }

    private function isEnvSuperglobalAccess(ArrayDimFetch $node): bool
    {
        if (!$node->var instanceof Variable) {
            return false;
        }
        $varName = $node->var->name;
        if (!is_string($varName) || !in_array($varName, ['_ENV', '_SERVER'], true)) {
            return false;
        }
        if (!$node->dim instanceof String_) {
            return false;
        }

        return in_array($node->dim->value, self::ENV_KEYS, true);
    }

    private function isGetenvOnEnvKey(FuncCall $node): bool
    {
        if (!$node->name instanceof Name || 'getenv' !== $node->name->toLowerString()) {
            return false;
        }
        if (!isset($node->args[0])) {
            return false;
        }
        $first = $node->args[0];
        if (!$first instanceof Arg || !$first->value instanceof String_) {
            return false;
        }

        return in_array($first->value->value, self::ENV_KEYS, true);
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
