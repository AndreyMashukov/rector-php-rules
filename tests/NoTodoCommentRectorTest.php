<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoTodoCommentRector;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoTodoCommentRector::class)]
final class NoTodoCommentRectorTest extends TestCase
{
    public function testRuleDefinitionDescribesIntent(): void
    {
        $def = (new NoTodoCommentRector())->getRuleDefinition();

        self::assertStringContainsString('TODO', $def->getDescription());
        self::assertStringContainsString('does not redeem', $def->getDescription());
    }

    public function testNodeTypesTargetStatementsOnly(): void
    {
        self::assertSame([Stmt::class], (new NoTodoCommentRector())->getNodeTypes());
    }

    public function testFlagsOwnedTodoLineComment(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            // TODO(@alice): switch client once PROJ-123 lands
            $client = new Client();
            PHP);

        self::assertStringContainsString('RECTOR-BAN', $printed);
    }

    public function testFlagsBareFixmeAndHack(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            // FIXME this is wrong
            $a = 1;
            // HACK works for now
            $b = 2;
            PHP);

        self::assertSame(2, substr_count($printed, 'RECTOR-BAN'));
    }

    public function testIgnoresProseThatMerelyMentionsMarker(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            // see the TODO backlog in the wiki for context
            $a = 1;
            PHP);

        self::assertStringNotContainsString('RECTOR-BAN', $printed);
    }

    public function testIsIdempotent(): void
    {
        $source = <<<'PHP'
            <?php
            // TODO fix later
            $a = 1;
            PHP;

        $once  = $this->refactorSnippet($source);
        $twice = $this->refactorSnippet($once);

        self::assertSame(1, substr_count($twice, 'RECTOR-BAN'));
    }

    private function refactorSnippet(string $source): string
    {
        $rule   = new NoTodoCommentRector();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts  = $parser->parse($source);
        self::assertNotNull($stmts, 'snippet must parse');

        foreach ($stmts as $node) {
            $rule->refactor($node);
        }

        return (new Standard())->prettyPrintFile($stmts);
    }
}
