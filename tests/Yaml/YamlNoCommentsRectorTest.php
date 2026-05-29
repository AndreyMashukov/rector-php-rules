<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests\Yaml;

use Amashukov\RectorRules\Yaml\YamlComment;
use Amashukov\RectorRules\Yaml\YamlCommentStripper;
use Amashukov\RectorRules\Yaml\YamlCommentStripperInterface;
use Amashukov\RectorRules\Yaml\YamlNoCommentsChecker;
use Amashukov\RectorRules\Yaml\YamlNoCommentsCheckerInterface;
use Amashukov\RectorRules\Yaml\YamlNoCommentsRector;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(YamlNoCommentsRector::class)]
final class YamlNoCommentsRectorTest extends TestCase
{
    public function testRuleDefinitionMentionsYaml(): void
    {
        $rule = $this->makeRule();

        self::assertStringContainsString('YAML', $rule->getRuleDefinition()->getDescription());
    }

    public function testNodeTypesIsGenericNode(): void
    {
        $rule = $this->makeRule();

        self::assertSame([Node::class], $rule->getNodeTypes());
    }

    public function testConfigureRejectsNonArrayPaths(): void
    {
        $rule = $this->makeRule();
        $this->expectException(InvalidArgumentException::class);
        $rule->configure([YamlNoCommentsRector::PATHS => 'config/']);
    }

    public function testConfigureRejectsNonListPaths(): void
    {
        $rule = $this->makeRule();
        $this->expectException(InvalidArgumentException::class);
        $rule->configure([YamlNoCommentsRector::PATHS => ['a' => 'config/']]);
    }

    public function testConfigureRejectsNonStringPathEntry(): void
    {
        $rule = $this->makeRule();
        $this->expectException(InvalidArgumentException::class);
        $rule->configure([YamlNoCommentsRector::PATHS => ['config/', 42]]);
    }

    public function testConfigureRejectsNonArrayExtensions(): void
    {
        $rule = $this->makeRule();
        $this->expectException(InvalidArgumentException::class);
        $rule->configure([
            YamlNoCommentsRector::PATHS      => [],
            YamlNoCommentsRector::EXTENSIONS => 'yaml',
        ]);
    }

    public function testConfigureRejectsNonStringExtensionEntry(): void
    {
        $rule = $this->makeRule();
        $this->expectException(InvalidArgumentException::class);
        $rule->configure([
            YamlNoCommentsRector::PATHS      => [],
            YamlNoCommentsRector::EXTENSIONS => ['yaml', 42],
        ]);
    }

    public function testRefactorReturnsNullWhenNoPathsConfigured(): void
    {
        $rule = $this->makeRule();
        $rule->configure([]);

        self::assertNull($rule->refactor(new Identifier('x')));
    }

    public function testRefactorReturnsNullWhenYamlIsClean(): void
    {
        $checker = $this->createMock(YamlNoCommentsCheckerInterface::class);
        $checker->method('check')->willReturn([]);

        $stripper = $this->createMock(YamlCommentStripperInterface::class);
        $stripper->expects($this->never())->method('stripFile');

        $rule = new YamlNoCommentsRector($checker, $stripper);
        $rule->configure([YamlNoCommentsRector::PATHS => ['/some/path']]);

        self::assertNull($rule->refactor(new Identifier('x')));
    }

    public function testRefactorThrowsAndStripsOnFindings(): void
    {
        $checker = $this->createMock(YamlNoCommentsCheckerInterface::class);
        $checker->method('check')->willReturn([
            new YamlComment('/tmp/a.yaml', 1, 1, YamlComment::KIND_WHOLE_LINE, '# header'),
            new YamlComment('/tmp/a.yaml', 4, 8, YamlComment::KIND_INLINE, '# tail'),
            new YamlComment('/tmp/b.yml', 2, 1, YamlComment::KIND_WHOLE_LINE, '# noise'),
        ]);

        $stripper = $this->createMock(YamlCommentStripperInterface::class);
        $stripper->expects($this->exactly(2))
            ->method('stripFile')
            ->willReturnCallback(static function (string $file): int {
                self::assertContains($file, ['/tmp/a.yaml', '/tmp/b.yml']);

                return 1;
            });

        $rule = new YamlNoCommentsRector($checker, $stripper);
        $rule->configure([YamlNoCommentsRector::PATHS => ['/tmp']]);

        try {
            $rule->refactor(new Identifier('x'));
            self::fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            self::assertStringContainsString('/tmp/a.yaml:1:1', $msg);
            self::assertStringContainsString('/tmp/a.yaml:4:8', $msg);
            self::assertStringContainsString('/tmp/b.yml:2:1', $msg);
            self::assertStringContainsString('3 comment(s) across 2 file(s)', $msg);
        }
    }

    public function testRefactorIsCalledOncePerRun(): void
    {
        $checker = $this->createMock(YamlNoCommentsCheckerInterface::class);
        $checker->expects($this->once())->method('check')->willReturn([]);

        $stripper = $this->createMock(YamlCommentStripperInterface::class);

        $rule = new YamlNoCommentsRector($checker, $stripper);
        $rule->configure([YamlNoCommentsRector::PATHS => ['/some/path']]);

        $rule->refactor(new Identifier('x'));
        $rule->refactor(new Identifier('y'));
        $rule->refactor(new Identifier('z'));
    }

    public function testResetForTestsAllowsReinvocation(): void
    {
        $checker = $this->createMock(YamlNoCommentsCheckerInterface::class);
        $checker->expects($this->exactly(2))->method('check')->willReturn([]);

        $stripper = $this->createMock(YamlCommentStripperInterface::class);

        $rule = new YamlNoCommentsRector($checker, $stripper);
        $rule->configure([YamlNoCommentsRector::PATHS => ['/some/path']]);

        $rule->refactor(new Identifier('x'));
        $rule->resetForTests();
        $rule->refactor(new Identifier('y'));
    }

    public function testExtensionsConfigurationIsPassedThrough(): void
    {
        $checker = $this->createMock(YamlNoCommentsCheckerInterface::class);
        $checker->expects($this->once())
            ->method('check')
            ->with(['/some/path'], ['neon'])
            ->willReturn([]);

        $stripper = $this->createMock(YamlCommentStripperInterface::class);

        $rule = new YamlNoCommentsRector($checker, $stripper);
        $rule->configure([
            YamlNoCommentsRector::PATHS      => ['/some/path'],
            YamlNoCommentsRector::EXTENSIONS => ['neon'],
        ]);

        $rule->refactor(new Identifier('x'));
    }

    public function testDefaultExtensionsAreNullThroughChecker(): void
    {
        $checker = $this->createMock(YamlNoCommentsCheckerInterface::class);
        $checker->expects($this->once())
            ->method('check')
            ->with(['/some/path'], null)
            ->willReturn([]);

        $stripper = $this->createMock(YamlCommentStripperInterface::class);

        $rule = new YamlNoCommentsRector($checker, $stripper);
        $rule->configure([YamlNoCommentsRector::PATHS => ['/some/path']]);

        $rule->refactor(new Identifier('x'));
    }

    public function testIntegrationWithRealCheckerAndStripper(): void
    {
        $dir = \sys_get_temp_dir() . '/yaml-rule-int-' . \bin2hex(\random_bytes(6));
        \mkdir($dir);

        try {
            \file_put_contents($dir . '/a.yaml', "# header\nfoo: bar # tail\n");

            $rule = $this->makeRule();
            $rule->configure([YamlNoCommentsRector::PATHS => [$dir]]);

            try {
                $rule->refactor(new Identifier('x'));
                self::fail('Expected RuntimeException');
            } catch (RuntimeException) {
                // expected
            }

            // After the run, the YAML should be stripped clean.
            self::assertSame("\nfoo: bar\n", \file_get_contents($dir . '/a.yaml'));

            // A fresh rule run sees a clean tree and returns null.
            $second = $this->makeRule();
            $second->configure([YamlNoCommentsRector::PATHS => [$dir]]);
            self::assertNull($second->refactor(new Identifier('y')));
        } finally {
            @\unlink($dir . '/a.yaml');
            @\rmdir($dir);
        }
    }

    private function makeRule(): YamlNoCommentsRector
    {
        return new YamlNoCommentsRector(new YamlNoCommentsChecker(), new YamlCommentStripper());
    }
}
