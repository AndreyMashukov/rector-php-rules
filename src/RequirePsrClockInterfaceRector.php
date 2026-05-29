<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class RequirePsrClockInterfaceRector extends AbstractRector
{
    private const array BANNED_FUNCTIONS = ['time', 'microtime'];

    private const array BANNED_NEW_CLASSES = ['DateTime', 'DateTimeImmutable'];

    private const array SKIP_PATH_FRAGMENTS = [
        '/src/Entity/',
        '/Entity/',
        '/migrations/',
        '/src/DataFixtures/',
        '/tests/',
        '/.rector/',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Require PSR-20 Clock — bans time() / microtime() / new DateTime / new DateTimeImmutable in production code. '
            . 'Inject Psr\Clock\ClockInterface and use $this->clock->now() so time is mockable and the test suite is '
            . 'deterministic. Skips entity / migrations / DataFixtures / tests / .rector.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        $age = time() - (int) $stored;
                        $expires = new DateTimeImmutable('+1 day');
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        $age = $this->clock->now()->getTimestamp() - (int) $stored;
                        $expires = $this->clock->now()->modify('+1 day');
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
        return [FuncCall::class, New_::class];
    }

    public function refactor(Node $node): ?Node
    {
        $filePath = $this->file->getFilePath();
        foreach (self::SKIP_PATH_FRAGMENTS as $skip) {
            if (str_contains($filePath, $skip)) {
                return null;
            }
        }

        if ($node instanceof FuncCall) {
            $name = $this->getName($node);
            if (null === $name || !in_array($name, self::BANNED_FUNCTIONS, true)) {
                return null;
            }
            $this->attachMarker($node);

            return $node;
        }

        if ($node instanceof New_) {
            $className = $this->getName($node->class);
            if (null === $className || !in_array($className, self::BANNED_NEW_CLASSES, true)) {
                return null;
            }
            $this->attachMarker($node);

            return $node;
        }

        return null;
    }

    private function attachMarker(Node $node): void
    {
        $node->setAttribute(
            'comments',
            [new Comment(
                '// RECTOR-BAN: non-DI clock access — inject Psr\\Clock\\ClockInterface and use '
                . '$this->clock->now(). Parse stored ISO strings via '
                . 'DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $iso).',
            )],
        );
    }
}
