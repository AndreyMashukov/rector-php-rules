<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Tests;

use PhpParser\Node\Stmt;
use Amashukov\RectorRules\NoCommentsOutsideInterfaceMethodDocBlockRector;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoCommentsOutsideInterfaceMethodDocBlockRector::class)]
final class NoCommentsOutsideInterfaceMethodDocBlockRectorTest extends TestCase
{
    public function testRuleDefinitionDescribesIntent(): void
    {
        $rule = new NoCommentsOutsideInterfaceMethodDocBlockRector();
        $def  = $rule->getRuleDefinition();

        self::assertStringContainsString('comment', $def->getDescription());
        self::assertStringContainsString('interface', $def->getDescription());
        self::assertStringContainsString('repository documentation', $def->getDescription());
    }

    public function testNodeTypesCoverEveryScopeRoot(): void
    {
        $rule  = new NoCommentsOutsideInterfaceMethodDocBlockRector();
        $types = $rule->getNodeTypes();

        self::assertContains(Namespace_::class, $types);
        self::assertContains(Interface_::class, $types);
        self::assertContains(Class_::class, $types);
        self::assertContains(Trait_::class, $types);
        self::assertContains(Enum_::class, $types);
        self::assertContains(Function_::class, $types);
    }

    public function testInterfaceMethodDocBlockIsPreserved(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            interface FooInterface {
                /**
                 * @param list<int> $xs
                 * @return string
                 */
                public function bar(array $xs): string;
            }
            PHP);

        self::assertStringContainsString('@param list<int>', $printed);
        self::assertStringContainsString('@return string', $printed);
    }

    public function testInterfaceMethodSiblingInlineCommentIsStrippedButDocSurvives(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            interface FooInterface {
                // ad-hoc note about bar
                /**
                 * @param list<int> $xs
                 */
                public function bar(array $xs): void;
            }
            PHP);

        self::assertStringNotContainsString('ad-hoc note', $printed);
        self::assertStringContainsString('@param list<int>', $printed);
    }

    public function testInterfaceClassLevelDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            /**
             * FooInterface handles X.
             */
            interface FooInterface {
                public function bar(): void;
            }
            PHP);

        self::assertStringNotContainsString('FooInterface handles X', $printed);
    }

    public function testClassMethodProseDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /**
                 * Does the thing, eventually.
                 *
                 * Side note: load-bearing for cron.
                 */
                public function bar(): void {}
            }
            PHP);

        self::assertStringNotContainsString('Does the thing', $printed);
        self::assertStringNotContainsString('Side note', $printed);
        self::assertStringNotContainsString('cron', $printed);
    }

    public function testClassMethodTagOnlyDocBlockIsPreservedViaCarveOut(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /**
                 * @param list<int> $xs
                 * @return string
                 */
                public function bar(array $xs): string { return ''; }
            }
            PHP);

        self::assertStringContainsString('@param list<int>', $printed);
        self::assertStringContainsString('@return string', $printed);
    }

    public function testClassMethodMixedTagAndProseDocBlockKeepsTagsDropsProse(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /**
                 * Does the thing.
                 *
                 * @param list<int> $xs
                 */
                public function bar(array $xs): void {}
            }
            PHP);

        self::assertStringNotContainsString('Does the thing', $printed);
        self::assertStringContainsString('@param list<int>', $printed);
    }

    public function testMultiLineArrayShapeTagSurvivesIntact(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /**
                 * Builds the response shape.
                 *
                 * @return array{
                 *     id: string,
                 *     amount: numeric-string,
                 *     status: string,
                 * }
                 */
                public function bar(): array { return []; }
            }
            PHP);

        self::assertStringNotContainsString('Builds the response shape', $printed);
        self::assertStringContainsString('@return array{', $printed);
        self::assertStringContainsString('id: string,', $printed);
        self::assertStringContainsString('amount: numeric-string,', $printed);
        self::assertStringContainsString('status: string,', $printed);
    }

    public function testInlineVarNarrowingAnnotationInsideMethodBodyIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                public function bar(string $raw): string
                {
                    /** @var numeric-string $raw */
                    return bcmul($raw, '1000', 0);
                }
            }
            PHP);

        self::assertStringNotContainsString('@var', $printed);
    }

    public function testInlinePhpstanIgnoreLineCommentIsPreserved(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                public function bar(): string
                {
                    // @phpstan-ignore-next-line fromSeed asserts secretKey length
                    return sodium_crypto_sign_detached('m', $this->secret);
                }
            }
            PHP);

        self::assertStringContainsString('@phpstan-ignore-next-line', $printed);
    }

    public function testInlineProsePrecedingStatementIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                public function bar(): void
                {
                    // narrate the assignment
                    $x = 1;
                }
            }
            PHP);

        self::assertStringNotContainsString('narrate the assignment', $printed);
    }

    public function testCtorPromotedPropertyProseDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                public function __construct(
                    /**
                     * Internal note explaining when this value is null and the
                     * upstream payload pattern that produces it.
                     */
                    private ?string $value,
                ) {}
            }
            PHP);

        self::assertStringNotContainsString('Internal note', $printed);
        self::assertStringNotContainsString('upstream payload', $printed);
    }

    public function testCtorPromotedPropertyDocBlockIsAlwaysStrippedRegardlessOfTagContent(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                public function __construct(
                    /**
                     * @var numeric-string
                     */
                    private string $amount,
                ) {}
            }
            PHP);

        self::assertStringNotContainsString('@var numeric-string', $printed, 'Comments INSIDE __construct() params are unconditionally stripped — no PHPStan-tag carve-out for promoted-property docblocks.');
    }

    public function testLineCommentAbovePropertyAttributeGroupIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                public function __construct(
                    // TICKET-XXX: widened from 20 to 32 for longer literals
                    #[Attr]
                    private string $chain,
                ) {}
            }
            PHP);

        self::assertStringNotContainsString('TICKET-XXX', $printed);
        self::assertStringNotContainsString('widened from 20', $printed);
    }

    public function testLineCommentBetweenClassLevelAttributeGroupsIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            #[A]
            // `field_name` is the dedup boundary
            #[B]
            final class Foo {}
            PHP);

        self::assertStringNotContainsString('field_name` is the dedup boundary', $printed);
    }

    public function testClassMethodInlineCommentInBodyIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                public function bar(): void
                {
                    // step 1 — fetch
                    $this->y();
                    /* TODO: review */
                    $this->z();
                }
            }
            PHP);

        self::assertStringNotContainsString('step 1', $printed);
        self::assertStringNotContainsString('fetch', $printed);
        self::assertStringNotContainsString('TODO', $printed);
        self::assertStringNotContainsString('review', $printed);
    }

    public function testPropertyVarDocBlockOnPrivateArrayPropertyIsPreserved(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /** @var array<string, int> */
                private array $map = [];
            }
            PHP);

        self::assertStringContainsString('@var array<string, int>', $printed);
    }

    public function testPropertyVarDocBlockOnPublicPropertyIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /** @var array<string, int> */
                public array $map = [];
            }
            PHP);

        self::assertStringNotContainsString('@var', $printed);
    }

    public function testPropertyVarDocBlockOnPrivateStringPropertyIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /** @var non-empty-string */
                private string $token = 'x';
            }
            PHP);

        self::assertStringNotContainsString('@var', $printed);
    }

    public function testIsEntityFolderPathRecognizesSrcEntity(): void
    {
        self::assertTrue(NoCommentsOutsideInterfaceMethodDocBlockRector::isEntityFolderPath('/x/src/Entity/User.php'));
        self::assertTrue(NoCommentsOutsideInterfaceMethodDocBlockRector::isEntityFolderPath('/x/src/Entity/Sub/Foo.php'));
    }

    public function testIsEntityFolderPathRejectsOtherDirectories(): void
    {
        self::assertFalse(NoCommentsOutsideInterfaceMethodDocBlockRector::isEntityFolderPath('/x/src/Service/Foo.php'));
        self::assertFalse(NoCommentsOutsideInterfaceMethodDocBlockRector::isEntityFolderPath('/x/src/Controller/EntityController.php'));
        self::assertFalse(NoCommentsOutsideInterfaceMethodDocBlockRector::isEntityFolderPath('/x/bundles/Foo/Entity/Bar.php'));
        self::assertFalse(NoCommentsOutsideInterfaceMethodDocBlockRector::isEntityFolderPath(''));
    }

    public function testIsPrivateTypedArrayPropertyMatchesPrivateArrayPropertyOnly(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts  = $parser->parse('<?php namespace Foo; final class X { private array $a = []; private ?array $b = null; public array $c = []; private string $d = ""; private array $e = []; }');
        self::assertNotNull($stmts);
        $class = $stmts[0]->stmts[0] ?? null;
        self::assertInstanceOf(Class_::class, $class);

        $properties = array_values(array_filter($class->stmts, static fn(Stmt $s): bool => $s instanceof Property));
        self::assertCount(5, $properties);

        self::assertTrue(NoCommentsOutsideInterfaceMethodDocBlockRector::isPrivateTypedArrayProperty($properties[0]));
        self::assertTrue(NoCommentsOutsideInterfaceMethodDocBlockRector::isPrivateTypedArrayProperty($properties[1]));
        self::assertFalse(NoCommentsOutsideInterfaceMethodDocBlockRector::isPrivateTypedArrayProperty($properties[2]));
        self::assertFalse(NoCommentsOutsideInterfaceMethodDocBlockRector::isPrivateTypedArrayProperty($properties[3]));
        self::assertTrue(NoCommentsOutsideInterfaceMethodDocBlockRector::isPrivateTypedArrayProperty($properties[4]));
    }

    public function testIsCollectionPropertyMatchesCollectionAndArrayCollection(): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts  = $parser->parse('<?php namespace Foo; use Doctrine\Common\Collections\Collection; use Doctrine\Common\Collections\ArrayCollection; final class X { private Collection $a; private ArrayCollection $b; private ?Collection $c; private array $d; private string $e; }');
        self::assertNotNull($stmts);
        $class = $stmts[0]->stmts[2] ?? null;
        self::assertInstanceOf(Class_::class, $class);

        $properties = array_values(array_filter($class->stmts, static fn(Stmt $s): bool => $s instanceof Property));
        self::assertCount(5, $properties);

        self::assertTrue(NoCommentsOutsideInterfaceMethodDocBlockRector::isCollectionProperty($properties[0]));
        self::assertTrue(NoCommentsOutsideInterfaceMethodDocBlockRector::isCollectionProperty($properties[1]));
        self::assertTrue(NoCommentsOutsideInterfaceMethodDocBlockRector::isCollectionProperty($properties[2]));
        self::assertFalse(NoCommentsOutsideInterfaceMethodDocBlockRector::isCollectionProperty($properties[3]));
        self::assertFalse(NoCommentsOutsideInterfaceMethodDocBlockRector::isCollectionProperty($properties[4]));
    }

    public function testPropertyProseDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /**
                 * The map keyed by user id, populated by populateMap().
                 * Last touched in PR #321.
                 */
                private array $map = [];
            }
            PHP);

        self::assertStringNotContainsString('user id', $printed);
        self::assertStringNotContainsString('PR #321', $printed);
    }

    public function testClassConstDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            final class Foo {
                /** The maximum number of attempts before bailout. */
                public const int MAX_ATTEMPTS = 5;
            }
            PHP);

        self::assertStringNotContainsString('maximum number', $printed);
        self::assertStringNotContainsString('bailout', $printed);
    }

    public function testAbstractClassMethodDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            abstract class Foo {
                /**
                 * Does the thing.
                 */
                abstract public function bar(): void;
            }
            PHP);

        self::assertStringNotContainsString('Does the thing', $printed);
    }

    public function testTraitMethodDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            trait FooTrait {
                /**
                 * Trait helper method.
                 */
                public function bar(): void {}
            }
            PHP);

        self::assertStringNotContainsString('Trait helper', $printed);
    }

    public function testEnumCaseDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            enum Status: string {
                /** active state */
                case Active = 'active';
            }
            PHP);

        self::assertStringNotContainsString('active state', $printed);
    }

    public function testFileLevelDocBlockIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php

            /**
             * This file is part of the Foo package.
             */
            namespace Foo;
            final class Foo {}
            PHP);

        self::assertStringNotContainsString('part of the Foo package', $printed);
    }

    public function testFunctionBodyCommentIsStripped(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            function bar(): void
            {
                // explain the next line
                doSomething();
            }
            PHP);

        self::assertStringNotContainsString('explain the next line', $printed);
    }

    public function testDeclaresAndUseImportsSurvive(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            declare(strict_types=1);

            namespace Foo;

            use Foo\Bar;
            use Foo\Baz;

            final class X {}
            PHP);

        self::assertStringContainsString('strict_types=1', $printed);
        self::assertStringContainsString('use Foo\Bar;', $printed);
        self::assertStringContainsString('use Foo\Baz;', $printed);
    }

    public function testPhpAttributesAreUntouched(): void
    {
        $printed = $this->refactorSnippet(<<<'PHP'
            <?php
            namespace Foo;
            #[Route('/foo')]
            final class Foo {
                #[Inject]
                public function bar(): void {}
            }
            PHP);

        self::assertStringContainsString("#[Route('/foo')]", $printed);
        self::assertStringContainsString('#[Inject]', $printed);
    }

    public function testFinalRuleProducesNoChangeForCommentFreeSource(): void
    {
        $rule    = new NoCommentsOutsideInterfaceMethodDocBlockRector();
        $parser  = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts   = $parser->parse('<?php namespace Foo; final class X { public function bar(): void {} }');
        self::assertNotNull($stmts);

        $changedAny = false;
        foreach ($stmts as $node) {
            if (in_array($node::class, $rule->getNodeTypes(), true) && $rule->refactor($node) instanceof Node) {
                $changedAny = true;
            }
        }
        self::assertFalse($changedAny, 'rule must return null on comment-free source');
    }

    private function refactorSnippet(string $source): string
    {
        $rule    = new NoCommentsOutsideInterfaceMethodDocBlockRector();
        $parser  = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts   = $parser->parse($source);
        self::assertNotNull($stmts, 'snippet must parse');

        $this->refactorRecursively($rule, $stmts);

        return (new Standard())->prettyPrintFile($stmts);
    }

    /**
     * @param array<int, Node> $nodes
     */
    private function refactorRecursively(
        NoCommentsOutsideInterfaceMethodDocBlockRector $rule,
        array $nodes,
    ): void {
        $matched = $rule->getNodeTypes();
        foreach ($nodes as $node) {
            foreach ($matched as $type) {
                if ($node instanceof $type) {
                    $rule->refactor($node);
                    break;
                }
            }
            if ($node instanceof Namespace_) {
                $this->refactorRecursively($rule, $node->stmts);
            }
            if ($node instanceof Class_ || $node instanceof Interface_ || $node instanceof Trait_ || $node instanceof Enum_) {
                foreach ($node->stmts as $member) {
                    if ($member instanceof ClassMethod && null !== $member->stmts) {
                        $this->refactorRecursively($rule, $member->stmts);
                    }
                }
            }
        }
    }
}
