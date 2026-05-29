<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use Amashukov\RectorRules\Internal\CommentMarkerTrait;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoDirectDbMutationInFunctionalTestsRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const string BAN_MARKER = '// RECTOR-BAN: direct DB mutation in Functional test — drive state via the public API surface';

    /**
     * @var array<string, list<string>>
     */
    private const array BANNED = [
        'Doctrine\ORM\EntityManagerInterface' => ['flush', 'persist', 'remove', 'merge', 'refresh', 'detach', 'lock'],
        'Doctrine\DBAL\Connection'            => ['executeStatement', 'executeUpdate', 'insert', 'update', 'delete', 'prepare', 'executeQuery'],
        'Doctrine\DBAL\Statement'             => ['execute', 'executeStatement'],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid direct DB mutations (EntityManagerInterface / DBAL\\Connection / DBAL\\Statement write methods) inside '
            . 'tests/Functional/**. Every Functional test should reach state EXCLUSIVELY through the public HTTP API + cron '
            . '+ messenger transports — direct DB writes bypass the controller / event-subscriber / antifraud-guard '
            . 'invariants the test is supposed to defend. Carve-out: tests/Functional/Repository/** (repo tests inherently '
            . 'exercise persistence).',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        $em->remove($entity);
                        $em->flush();
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        $this->client->request('POST', '/api/entity/' . $entity->getId() . '/cancel');
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
        if (null === $methodName) {
            return null;
        }
        $allBannedMethods = array_merge(...array_values(self::BANNED));
        if (!in_array($methodName, $allBannedMethods, true)) {
            return null;
        }
        if (!$this->callerLooksLikeDbAccess($node)) {
            return null;
        }

        $existing = self::existingComments($node);
        foreach ($existing as $comment) {
            if (str_contains((string) $comment->getText(), 'RECTOR-BAN: direct DB mutation')) {
                return null;
            }
        }
        $node->setAttribute('comments', [...$existing, new Comment(self::BAN_MARKER)]);

        return $node;
    }

    private function callerLooksLikeDbAccess(MethodCall $node): bool
    {
        $name = $this->resolveCallerHint($node->var);
        if (null === $name) {
            return false;
        }
        $lower = strtolower($name);
        foreach (['em', 'entitymanager', 'entitymgr', 'doctrine', 'manager', 'db', 'dbal', 'conn', 'connection'] as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
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

        return null;
    }

    public static function isFunctionalTestPath(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        if (!str_contains($normalized, 'tests/Functional/')) {
            return false;
        }
        return !str_contains($normalized, 'tests/Functional/Repository/');
    }
}
