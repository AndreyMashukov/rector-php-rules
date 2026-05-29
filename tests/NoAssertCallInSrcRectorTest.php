<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoAssertCallInSrcRector;
use FilesystemIterator;
use PhpParser\Node\Expr\FuncCall;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(NoAssertCallInSrcRector::class)]
final class NoAssertCallInSrcRectorTest extends TestCase
{
    public function testRuleDefinitionPointsAtTheReplacementPattern(): void
    {
        $rule = new NoAssertCallInSrcRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('src/**', $def->getDescription());
        self::assertStringContainsString('LogicException', $def->getDescription());
        self::assertStringContainsString('@phpstan-assert', $def->getDescription());
    }

    public function testNodeTypesTargetFuncCallOnly(): void
    {
        $rule  = new NoAssertCallInSrcRector();
        $types = $rule->getNodeTypes();

        self::assertSame([FuncCall::class], $types);
    }

    public function testIsSrcPathAcceptsSrcAndRejectsTestsMigrationsRules(): void
    {
        self::assertTrue(NoAssertCallInSrcRector::isSrcPath('/x/src/Service/Foo.php'));
        self::assertTrue(NoAssertCallInSrcRector::isSrcPath('/x/src/Entity/Foo.php'));
        self::assertFalse(NoAssertCallInSrcRector::isSrcPath('/x/tests/Unit/X.php'));
        self::assertFalse(NoAssertCallInSrcRector::isSrcPath('/x/migrations/Version1.php'));
        self::assertFalse(NoAssertCallInSrcRector::isSrcPath('/x/.rector/Rules/X.php'));
        self::assertFalse(NoAssertCallInSrcRector::isSrcPath('/x/app/foo.php'));
    }

    public function testThisPackageSrcCarriesZeroAssertCalls(): void
    {
        $srcDir = __DIR__ . '/../src';
        $files  = $this->phpFilesUnder($srcDir);

        $offenders = [];
        foreach ($files as $file) {
            if ('NoAssertCallInSrcRector.php' === basename($file)) {
                continue;
            }
            $contents = (string) file_get_contents($file);
            if (preg_match('/(?<!::)\\\?\bassert\(/', $contents)) {
                $offenders[] = str_replace($srcDir, 'src', $file);
            }
        }

        self::assertSame([], $offenders, sprintf('assert() call(s) found in src — must be replaced with `if (!cond) throw new \LogicException(...)`. Offenders: %s', implode(', ', $offenders)));
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        $out      = [];
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && 'php' === $entry->getExtension()) {
                $out[] = $entry->getPathname();
            }
        }

        return $out;
    }
}
