<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use Amashukov\RectorRules\Internal\CommentMarkerTrait;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoDirectDispatchInFunctionalTestsRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const string BAN_MARKER = '// RECTOR-BAN: direct event/bus dispatch in Functional test — drive via the production trigger (controller / cron / mocked RPC + real dispatcher)';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid direct EventDispatcher::dispatch / MessageBusInterface::dispatch calls inside tests/Functional/**. '
            . 'Direct dispatch bypasses the production trigger (controller / external worker / dispatcher) that fires the '
            . 'event in production. Drive via the API endpoint that wraps the publish, or mock the outbound adapter and '
            . 'let the real dispatcher emit the event.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        $container->get('event_dispatcher')->dispatch(new MyEvent(...));
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        // Mock the outbound adapter; let the real dispatcher emit the event end-to-end.
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
        return [MethodCall::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof MethodCall) {
            return null;
        }
        if (!self::isFunctionalTestPath($this->file->getFilePath())) {
            return null;
        }
        $methodName = $this->getName($node->name);
        if ('dispatch' !== $methodName) {
            return null;
        }
        if (!$this->callerLooksLikeDispatcher($node)) {
            return null;
        }

        $existing = self::existingComments($node);
        foreach ($existing as $comment) {
            if (str_contains((string) $comment->getText(), 'RECTOR-BAN: direct event/bus dispatch')) {
                return null;
            }
        }
        $node->setAttribute('comments', [...$existing, new Comment(self::BAN_MARKER)]);

        return $node;
    }

    private function callerLooksLikeDispatcher(MethodCall $node): bool
    {
        $needles = ['dispatcher', 'eventdispatcher', 'events', 'bus', 'messagebus', 'messenger', 'event_dispatcher', 'message_bus'];

        $hint = $this->resolveCallerHint($node->var);
        if (null !== $hint) {
            $lower = strtolower($hint);
            foreach ($needles as $n) {
                if (str_contains($lower, $n)) {
                    return true;
                }
            }
        }

        if ($node->var instanceof MethodCall && 'get' === strtolower((string) $this->getName($node->var->name))) {
            foreach ($node->var->args as $arg) {
                if (!$arg instanceof Arg) {
                    continue;
                }
                if ($arg->value instanceof String_) {
                    $svc = strtolower($arg->value->value);
                    foreach ($needles as $n) {
                        if (str_contains($svc, $n)) {
                            return true;
                        }
                    }
                }
                if ($arg->value instanceof ClassConstFetch && $arg->value->class instanceof Name) {
                    $cls = strtolower($arg->value->class->toLowerString());
                    foreach ($needles as $n) {
                        if (str_contains($cls, $n)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function resolveCallerHint(Node $expr): ?string
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            return $expr->name;
        }
        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            return $expr->name->toString();
        }
        if ($expr instanceof MethodCall && $expr->name instanceof Identifier) {
            $inner = $expr->name->toString();
            if (str_starts_with(strtolower($inner), 'get') && strlen($inner) > 3) {
                return substr($inner, 3);
            }

            return $inner;
        }
        if ($expr instanceof FuncCall && $expr->name instanceof Name && 'get' === strtolower($expr->name->toLowerString())) {
            return null;
        }

        return null;
    }

    public static function isFunctionalTestPath(string $file): bool
    {
        return str_contains(str_replace('\\', '/', $file), 'tests/Functional/');
    }
}
