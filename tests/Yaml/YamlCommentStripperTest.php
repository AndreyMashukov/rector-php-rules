<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests\Yaml;

use Amashukov\RectorRules\Yaml\YamlCommentStripper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(YamlCommentStripper::class)]
final class YamlCommentStripperTest extends TestCase
{
    public function testCleanYamlIsUnchanged(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "framework:\n  secret: '%env(APP_SECRET)%'\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame($input, $out);
        self::assertSame(0, $count);
    }

    public function testWholeLineCommentIsRemoved(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "framework:\n  # narrative\n  secret: x\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame("framework:\n\n  secret: x\n", $out);
        self::assertSame(1, $count);
    }

    public function testInlineCommentIsRemovedWithLeadingWhitespace(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "key: value # tail\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame("key: value\n", $out);
        self::assertSame(1, $count);
    }

    public function testHashInsideDoubleQuotedScalarIsPreserved(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "note: \"value with # hash\"\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame($input, $out);
        self::assertSame(0, $count);
    }

    public function testHashInsideSingleQuotedScalarIsPreserved(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "note: 'value with # hash'\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame($input, $out);
        self::assertSame(0, $count);
    }

    public function testEscapedQuoteInDoubleQuotedScalarIsHonored(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "note: \"with \\\"escaped\\\" quotes # not a comment\"\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame($input, $out);
        self::assertSame(0, $count);
    }

    public function testHashGluedToTokenIsNotComment(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "docs: https://example.com/page#section\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame($input, $out);
        self::assertSame(0, $count);
    }

    public function testCrlfLineEndingsArePreserved(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "framework:\r\n  # narrative\r\n  secret: x\r\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame("framework:\r\n\r\n  secret: x\r\n", $out);
        self::assertSame(1, $count);
    }

    public function testMixedLineEndingsArePreservedPerLine(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "a: 1\n# noise\r\nb: 2\r";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame("a: 1\n\r\nb: 2\r", $out);
        self::assertSame(1, $count);
    }

    public function testTrailingNewlineIsPreserved(): void
    {
        $stripper                = new YamlCommentStripper();
        [$outWith, $changedWith] = $stripper->stripString("key: value\n");

        self::assertSame("key: value\n", $outWith);
        self::assertSame(0, $changedWith);

        [$outNo, $changedNo] = $stripper->stripString("key: value");

        self::assertSame("key: value", $outNo);
        self::assertSame(0, $changedNo);
    }

    public function testMultipleCommentsAreAllRemoved(): void
    {
        $stripper      = new YamlCommentStripper();
        $input         = "# header\nframework:\n  # leading\n  secret: x # inline\n  ttl: 3 # explain\n";
        [$out, $count] = $stripper->stripString($input);

        self::assertSame("\nframework:\n\n  secret: x\n  ttl: 3\n", $out);
        self::assertSame(4, $count);
    }

    public function testEmptyStringRoundtripsClean(): void
    {
        $stripper      = new YamlCommentStripper();
        [$out, $count] = $stripper->stripString('');

        self::assertSame('', $out);
        self::assertSame(0, $count);
    }

    public function testOnlyWhitespaceLinesAreClean(): void
    {
        $stripper      = new YamlCommentStripper();
        [$out, $count] = $stripper->stripString("\n   \n\t\n");

        self::assertSame("\n   \n\t\n", $out);
        self::assertSame(0, $count);
    }

    public function testCommentOnFileWithoutFinalNewline(): void
    {
        $stripper      = new YamlCommentStripper();
        [$out, $count] = $stripper->stripString("# only a comment");

        self::assertSame('', $out);
        self::assertSame(1, $count);
    }

    public function testStripFileRewritesDirtyFile(): void
    {
        $path = self::tempYamlFile("# header\nfoo: 1 # tail\n");

        try {
            $stripper = new YamlCommentStripper();
            $changed  = $stripper->stripFile($path);

            self::assertSame(2, $changed);
            self::assertSame("\nfoo: 1\n", \file_get_contents($path));
        } finally {
            @\unlink($path);
        }
    }

    public function testStripFileDoesNotTouchCleanFile(): void
    {
        $path     = self::tempYamlFile("foo: 1\nbar: 2\n");
        $original = \file_get_contents($path);
        \clearstatcache(true, $path);
        $mtimeBefore = \filemtime($path);
        \touch($path, $mtimeBefore - 60); // backdate so we can detect a write
        \clearstatcache(true, $path);
        $mtimeBefore = \filemtime($path);

        try {
            $stripper = new YamlCommentStripper();
            $changed  = $stripper->stripFile($path);

            self::assertSame(0, $changed);
            self::assertSame($original, \file_get_contents($path));

            \clearstatcache(true, $path);
            $mtimeAfter = \filemtime($path);
            self::assertSame($mtimeBefore, $mtimeAfter, 'clean file should not be touched');
        } finally {
            @\unlink($path);
        }
    }

    public function testStripFileThrowsOnUnreadablePath(): void
    {
        $stripper = new YamlCommentStripper();
        $this->expectException(RuntimeException::class);
        $stripper->stripFile('/tmp/definitely-missing-' . \bin2hex(\random_bytes(8)) . '.yaml');
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function provideStripCases(): array
    {
        return [
            'comment after numeric'   => ['ttl: 600 # seconds', 'ttl: 600', 1],
            'comment after bool'      => ['debug: true # toggle', 'debug: true', 1],
            'comment after list item' => ['- foo # one', '- foo', 1],
            'tab before hash'         => ["key: value\t# tab", 'key: value', 1],
            'leading tab comment'     => ["\t# tabbed", '', 1],
            'mid-line url'            => ['link: https://x.com/page#section', 'link: https://x.com/page#section', 0],
            'mid-line url with tail'  => ['link: https://x.com/p#s # tail', 'link: https://x.com/p#s', 1],
        ];
    }

    #[DataProvider('provideStripCases')]
    public function testInlineStripCases(string $input, string $expected, int $changes): void
    {
        $stripper            = new YamlCommentStripper();
        [$out, $count]       = $stripper->stripString($input);

        // stripString preserves the input's line-separator style (or lack of one).
        self::assertSame($expected, $out);
        self::assertSame($changes, $count);
    }

    private static function tempYamlFile(string $contents): string
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'yaml-strip-');
        self::assertIsString($tmp);
        $path = $tmp . '.yaml';
        \rename($tmp, $path);
        \file_put_contents($path, $contents);

        return $path;
    }
}
