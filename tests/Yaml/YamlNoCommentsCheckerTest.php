<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests\Yaml;

use Amashukov\RectorRules\Yaml\YamlComment;
use Amashukov\RectorRules\Yaml\YamlNoCommentsChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(YamlNoCommentsChecker::class)]
#[CoversClass(YamlComment::class)]
final class YamlNoCommentsCheckerTest extends TestCase
{
    public function testCleanYamlIsAccepted(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString(<<<'YAML'
            framework:
              secret: '%env(APP_SECRET)%'
              http_client:
                default_options:
                  timeout: 4
            YAML);

        self::assertSame([], $findings);
    }

    public function testWholeLineCommentIsFlagged(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString(<<<'YAML'
            framework:
              # narrative line comment
              secret: '%env(APP_SECRET)%'
            YAML);

        self::assertCount(1, $findings);
        self::assertSame(YamlComment::KIND_WHOLE_LINE, $findings[0]->kind);
        self::assertSame(2, $findings[0]->line);
    }

    public function testInlineCommentIsFlagged(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString(<<<'YAML'
            framework:
              secret: '%env(APP_SECRET)%' # explain the obvious
            YAML);

        self::assertCount(1, $findings);
        self::assertSame(YamlComment::KIND_INLINE, $findings[0]->kind);
        self::assertSame(2, $findings[0]->line);
        self::assertStringContainsString('# explain the obvious', $findings[0]->excerpt);
    }

    public function testHashInsideDoubleQuotesIsNotComment(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString(<<<'YAML'
            framework:
              note: "value with # hash inside double quotes"
            YAML);

        self::assertSame([], $findings);
    }

    public function testHashInsideSingleQuotesIsNotComment(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString(<<<'YAML'
            framework:
              note: 'value with # hash inside single quotes'
            YAML);

        self::assertSame([], $findings);
    }

    public function testHashGluedToUrlIsNotComment(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString(<<<'YAML'
            framework:
              docs_url: https://example.com/page#section
            YAML);

        self::assertSame([], $findings);
    }

    public function testEscapedQuoteInsideDoubleQuotedStringIsHandled(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString(<<<'YAML'
            framework:
              note: "with \"escaped\" quotes # not a comment"
            YAML);

        self::assertSame([], $findings);
    }

    public function testMultipleCommentsAreAllReported(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString(<<<'YAML'
            # header comment
            framework:
              # leading
              secret: '%env(APP_SECRET)%' # inline
            YAML);

        self::assertCount(3, $findings);
        self::assertSame([1, 3, 4], \array_map(static fn(YamlComment $c): int => $c->line, $findings));
        self::assertSame(
            [
                YamlComment::KIND_WHOLE_LINE,
                YamlComment::KIND_WHOLE_LINE,
                YamlComment::KIND_INLINE,
            ],
            \array_map(static fn(YamlComment $c): string => $c->kind, $findings),
        );
    }

    public function testEmptyFileIsClean(): void
    {
        $checker = new YamlNoCommentsChecker();
        self::assertSame([], $checker->checkString(''));
    }

    public function testOnlyWhitespaceLinesAreClean(): void
    {
        $checker = new YamlNoCommentsChecker();
        self::assertSame([], $checker->checkString("\n   \n\t\n"));
    }

    public function testWindowsLineEndingsAreSupported(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString("framework:\r\n  # crlf comment\r\n  secret: '%env(X)%'\r\n");

        self::assertCount(1, $findings);
        self::assertSame(2, $findings[0]->line);
    }

    public function testReportsCorrectColumnForWholeLineComment(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString("    # indented comment\n");

        self::assertCount(1, $findings);
        self::assertSame(5, $findings[0]->column);
    }

    public function testReportsCorrectColumnForInlineComment(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString("key: value # inline\n");

        self::assertCount(1, $findings);
        self::assertSame(12, $findings[0]->column);
    }

    public function testSourceLabelIsPropagated(): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString("# hi\n", 'config/services.yaml');

        self::assertCount(1, $findings);
        self::assertSame('config/services.yaml', $findings[0]->file);
    }

    public function testCheckFileReadsFromDisk(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'yaml-test-');
        self::assertIsString($tmp);
        $path = $tmp . '.yaml';
        \rename($tmp, $path);

        try {
            \file_put_contents($path, "# top-level comment\nkey: value\n");
            $checker  = new YamlNoCommentsChecker();
            $findings = $checker->checkFile($path);

            self::assertCount(1, $findings);
            self::assertSame($path, $findings[0]->file);
        } finally {
            @\unlink($path);
        }
    }

    public function testCheckFileThrowsOnUnreadablePath(): void
    {
        $checker = new YamlNoCommentsChecker();
        $this->expectException(RuntimeException::class);
        $checker->checkFile('/tmp/definitely-missing-' . \bin2hex(\random_bytes(8)) . '.yaml');
    }

    public function testCheckWalksDirectoryAndIgnoresNonYamlFiles(): void
    {
        $dir = \sys_get_temp_dir() . '/yaml-walk-' . \bin2hex(\random_bytes(6));
        \mkdir($dir);
        \mkdir($dir . '/nested');

        try {
            \file_put_contents($dir . '/clean.yaml', "key: value\n");
            \file_put_contents($dir . '/dirty.yml', "# bad\nkey: value\n");
            \file_put_contents($dir . '/ignored.txt', "# this file is not YAML\n");
            \file_put_contents($dir . '/nested/inner.yaml', "key: 1 # nope\n");

            $checker  = new YamlNoCommentsChecker();
            $findings = $checker->check([$dir]);

            self::assertCount(2, $findings);

            $files = \array_map(static fn(YamlComment $c): string => $c->file, $findings);
            \sort($files);
            self::assertSame(
                [
                    $dir . '/dirty.yml',
                    $dir . '/nested/inner.yaml',
                ],
                $files,
            );
        } finally {
            @\unlink($dir . '/clean.yaml');
            @\unlink($dir . '/dirty.yml');
            @\unlink($dir . '/ignored.txt');
            @\unlink($dir . '/nested/inner.yaml');
            @\rmdir($dir . '/nested');
            @\rmdir($dir);
        }
    }

    public function testCustomExtensionsListIsHonored(): void
    {
        $dir = \sys_get_temp_dir() . '/yaml-ext-' . \bin2hex(\random_bytes(6));
        \mkdir($dir);

        try {
            \file_put_contents($dir . '/file.yaml', "# yaml\n");
            \file_put_contents($dir . '/file.neon', "# neon\n");

            $defaultFindings = (new YamlNoCommentsChecker())->check([$dir]);
            self::assertCount(1, $defaultFindings);
            self::assertStringEndsWith('.yaml', $defaultFindings[0]->file);

            $customFindings = (new YamlNoCommentsChecker(['neon']))->check([$dir]);
            self::assertCount(1, $customFindings);
            self::assertStringEndsWith('.neon', $customFindings[0]->file);
        } finally {
            @\unlink($dir . '/file.yaml');
            @\unlink($dir . '/file.neon');
            @\rmdir($dir);
        }
    }

    public function testCheckThrowsOnNonexistentPath(): void
    {
        $checker = new YamlNoCommentsChecker();
        $this->expectException(RuntimeException::class);
        $checker->check(['/tmp/definitely-missing-' . \bin2hex(\random_bytes(8))]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideCommentPositions(): array
    {
        return [
            'comment after numeric'   => ['ttl: 600 # seconds', YamlComment::KIND_INLINE],
            'comment after bool'      => ['debug: true # toggle', YamlComment::KIND_INLINE],
            'comment after list item' => ['- foo # one', YamlComment::KIND_INLINE],
            'document separator'      => ["---\n# header\n", YamlComment::KIND_WHOLE_LINE],
        ];
    }

    #[DataProvider('provideCommentPositions')]
    public function testKindClassification(string $yaml, string $expectedKind): void
    {
        $checker  = new YamlNoCommentsChecker();
        $findings = $checker->checkString($yaml);

        self::assertCount(1, $findings);
        self::assertSame($expectedKind, $findings[0]->kind);
    }
}
