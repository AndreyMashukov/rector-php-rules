<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use Amashukov\RectorRules\Internal\CommentMarkerTrait;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoExistenceOnlyAssertionsInTestsRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const array BANNED = [
        'assertNotEmpty',
        'assertNotNull',
        'assertArrayHasKey',
        'assertObjectHasProperty',
        'assertObjectHasAttribute',
    ];

    private const string BAN_MARKER = ' /* RECTOR-BAN smudged-assert (existence-only): assertNotNull / assertNotEmpty / assertArrayHasKey pin presence, not value. Production returns SPECIFIC values — pin the actual via assertSame; the existence-vs-not is implicit in equality comparison. "Value is present" without "value is X" passes on every wrong-but-present value. */';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid PHPUnit existence-only assertions (assertNotEmpty / assertNotNull / assertArrayHasKey / '
            . 'assertObjectHasProperty / assertObjectHasAttribute) anywhere under tests/**. Tests that say '
            . '"something is present" without saying "it is X" pass on every wrong-but-present value. Pin the actual '
            . 'value via assertSame; the existence check is implicit in the equality comparison.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        self::assertNotNull($entity->getCompletedAt());
                        self::assertNotEmpty($body['items']);
                        self::assertArrayHasKey('id', $body);
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        self::assertSame('2026-05-21T12:00:00+00:00', $entity->getCompletedAt()?->format(DateTimeInterface::ATOM));
                        self::assertSame(3, count($body['items']));
                        self::assertSame('a1b2c3d4-...', $body['id']);
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
        if (!in_array($node->name->toString(), self::BANNED, true)) {
            return null;
        }
        if (!$node->class instanceof Name) {
            return null;
        }
        $cls = $node->class->toLowerString();
        if (!in_array($cls, ['self', 'static', 'parent'], true) && !str_contains($cls, 'testcase') && !str_contains($cls, 'assert')) {
            return null;
        }
        if (!self::isTestPath($this->file->getFilePath())) {
            return null;
        }

        $existing = self::existingComments($node);
        foreach ($existing as $c) {
            if (str_contains($c->getText(), 'RECTOR-BAN smudged-assert (existence-only)')) {
                return null;
            }
        }
        $node->setAttribute('comments', [...$existing, new Comment(self::BAN_MARKER)]);

        return $node;
    }

    public static function isTestPath(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);

        return str_contains($normalized, '/tests/')
            && !str_contains($normalized, '/.rector/')
            && !str_contains($normalized, '/migrations/');
    }
}
