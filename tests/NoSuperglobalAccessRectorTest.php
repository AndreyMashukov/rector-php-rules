<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use Amashukov\RectorRules\NoSuperglobalAccessRector;
use PhpParser\Node\Stmt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoSuperglobalAccessRector::class)]
final class NoSuperglobalAccessRectorTest extends TestCase
{
    public function testRuleDefinitionMentionsTheBannedPatterns(): void
    {
        $rule = new NoSuperglobalAccessRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('$_ENV', $def->getDescription());
        self::assertStringContainsString('$_SERVER', $def->getDescription());
        self::assertStringContainsString('$_GET', $def->getDescription());
        self::assertStringContainsString('$_POST', $def->getDescription());
        self::assertStringContainsString('$_REQUEST', $def->getDescription());
        self::assertStringContainsString('$_COOKIE', $def->getDescription());
        self::assertStringContainsString('$_FILES', $def->getDescription());
        self::assertStringContainsString('$_SESSION', $def->getDescription());
        self::assertStringContainsString('getenv()', $def->getDescription());
        self::assertStringContainsString('putenv()', $def->getDescription());
    }

    public function testNodeTypesWalksAtStatementLevelSoCommentMarkerRendersInPrettyPrinterOutput(): void
    {
        $rule  = new NoSuperglobalAccessRector();
        $types = $rule->getNodeTypes();

        self::assertSame([Stmt::class], $types);
    }

    public function testIsCoveredPathAcceptsSrcBundlesAndTests(): void
    {
        self::assertTrue(NoSuperglobalAccessRector::isCoveredPath('/x/src/Controller/X.php'));
        self::assertTrue(NoSuperglobalAccessRector::isCoveredPath('/x/bundles/Foo/Service/X.php'));
        self::assertTrue(NoSuperglobalAccessRector::isCoveredPath('/x/tests/Functional/X.php'));
    }

    public function testIsCoveredPathRejectsMigrationsRulesVarVendorBinPublic(): void
    {
        self::assertFalse(NoSuperglobalAccessRector::isCoveredPath('/x/migrations/Version1.php'));
        self::assertFalse(NoSuperglobalAccessRector::isCoveredPath('/x/.rector/Rules/X.php'));
        self::assertFalse(NoSuperglobalAccessRector::isCoveredPath('/x/var/cache/x.php'));
        self::assertFalse(NoSuperglobalAccessRector::isCoveredPath('/x/vendor/symfony/x.php'));
        self::assertFalse(NoSuperglobalAccessRector::isCoveredPath('/x/bin/console'));
        self::assertFalse(NoSuperglobalAccessRector::isCoveredPath('/x/public/index.php'));
    }

    public function testIsCoveredPathRejectsUnknownDirectoryOutsideKnownRoots(): void
    {
        self::assertFalse(NoSuperglobalAccessRector::isCoveredPath('/x/config/X.php'));
        self::assertFalse(NoSuperglobalAccessRector::isCoveredPath('/x/translations/X.php'));
    }
}
