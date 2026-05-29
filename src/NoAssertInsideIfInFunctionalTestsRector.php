<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use PhpParser\Node\Stmt\Else_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\If_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoAssertInsideIfInFunctionalTestsRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid PHPUnit assertX() calls inside `if`/`elseif`/`else`/`match` branches in tests/Functional/** — '
            . 'assertions must be deterministic, not conditional. Skips tests/Functional/Traits/ (shared test '
            . 'infrastructure, not real tests). Detection-only (no auto-fix) — re-emits the node unchanged so '
            . 'a reviewer refactors the conditional assertion into per-state helpers.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        if (Status::READY === $entity->getStatus()) {
                            $entity = $this->advance($entity);
                            self::assertNotNull($entity);
                        }
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        $entity = $this->driveReadyToActive($entity);
                        self::assertSame(Status::ACTIVE, $entity->getStatus());
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
        return [If_::class, Match_::class];
    }

    public function refactor(Node $node): ?Node
    {
        $filePath = $this->file->getFilePath();
        if (!str_contains($filePath, '/tests/Functional/')) {
            return null;
        }

        if (str_contains($filePath, '/tests/Functional/Traits/')) {
            return null;
        }
        if (!str_ends_with($filePath, 'Test.php')) {
            return null;
        }

        $banned = false;
        if ($node instanceof If_) {
            $banned = $this->subtreeContainsAssertion($node->stmts);
            foreach ($node->elseifs as $elseif) {
                $banned = $banned || $this->subtreeContainsAssertion($elseif->stmts);
            }
            if ($node->else instanceof Else_) {
                $banned = $banned || $this->subtreeContainsAssertion($node->else->stmts);
            }
        }
        if ($node instanceof Match_) {
            foreach ($node->arms as $arm) {
                $banned = $banned || $this->subtreeContainsAssertion([$arm->body]);
            }
        }

        return null;
    }

    /**
     * @param array<int, Node|null> $nodes
     */
    private function subtreeContainsAssertion(array $nodes): bool
    {
        foreach ($nodes as $sub) {
            if (null === $sub) {
                continue;
            }
            $found = false;
            $this->traverseNodesWithCallable($sub, function (Node $node) use (&$found): ?Node {
                if (!$node instanceof MethodCall && !$node instanceof StaticCall) {
                    return null;
                }
                $name = $this->getName($node->name);
                if (null === $name) {
                    return null;
                }
                $receiver = $node instanceof StaticCall
                    ? $this->getName($node->class)
                    : ($node->var instanceof Variable ? $this->getName($node->var) : null);
                $isTestReceiver = in_array($receiver, ['self', 'static', 'parent', 'this'], true);
                if (!$isTestReceiver) {
                    return null;
                }
                if (1 !== preg_match('/^assert[A-Z]/', $name)) {
                    return null;
                }
                $found = true;

                return null;
            });

            if ($found) {
                return true;
            }
        }

        return false;
    }
}
