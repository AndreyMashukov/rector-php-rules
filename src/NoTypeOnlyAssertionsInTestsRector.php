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

final class NoTypeOnlyAssertionsInTestsRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const array BANNED = [
        'assertIsArray',
        'assertIsString',
        'assertIsInt',
        'assertIsBool',
        'assertIsFloat',
        'assertIsNumeric',
        'assertIsObject',
        'assertIsCallable',
        'assertIsScalar',
        'assertIsIterable',
        'assertIsResource',
        'assertIsClosedResource',
        'assertIsNotArray',
        'assertIsNotString',
        'assertIsNotInt',
        'assertIsNotBool',
        'assertIsNotFloat',
        'assertIsNotNumeric',
        'assertIsNotObject',
        'assertIsNotCallable',
        'assertIsNotScalar',
        'assertIsNotIterable',
        'assertIsNotResource',
    ];

    private const string BAN_MARKER = ' /* RECTOR-BAN smudged-assert (type-only): assertIs*() pins shape, not value. Production returns SPECIFIC values — assert them via assertSame / assertEquals. Type-only checks pass on every wrong-but-typed value (assertIsArray passes for [] when caller expects [{id, status, ...}]). */';

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid PHPUnit type-only assertions (assertIsArray / assertIsString / assertIsInt / assertIsBool / '
            . 'assertIsFloat / assertIsNumeric / assertIsObject / assertIsCallable / assertIsScalar / assertIsIterable / '
            . 'assertIsResource / assertIsClosedResource and their assertIsNot* counterparts) anywhere under tests/**. '
            . 'Type-only asserts pass on every wrong-but-typed value — a test that "shape is right" is not a test that '
            . 'the production code does the right thing. Fix: pin the actual value via assertSame.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        self::assertIsArray($body['data']);
                        self::assertIsString($json['id']);
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        self::assertSame(['enabled' => true, 'count' => 3], $body['data']);
                        self::assertSame('a1b2c3d4-...', $json['id']);
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
            if (str_contains($c->getText(), 'RECTOR-BAN smudged-assert (type-only)')) {
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
