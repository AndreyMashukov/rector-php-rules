<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use PhpParser\Node\NullableType;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use RuntimeException;

final class NoCommentsOutsideInterfaceMethodDocBlockRector extends AbstractRector
{
    private const array PHPSTAN_TAGS_ALLOWLIST = [
        '@param',
        '@return',
        '@throws',
        '@template',
        '@template-covariant',
        '@template-contravariant',
        '@template-extends',
        '@template-implements',
        '@extends',
        '@implements',
        '@phpstan-param',
        '@phpstan-return',
        '@phpstan-template',
        '@phpstan-template-covariant',
        '@phpstan-template-contravariant',
        '@phpstan-assert',
        '@phpstan-assert-if-true',
        '@phpstan-assert-if-false',
        '@phpstan-pure',
        '@phpstan-impure',
        '@phpstan-readonly',
        '@phpstan-readonly-allow-private-mutation',
        '@phpstan-allow-private-mutation',
        '@phpstan-self-out',
        '@phpstan-this-out',
        '@phpstan-param-out',
        '@phpstan-import-type',
        '@phpstan-type',
        '@phpstan-method',
        '@phpstan-property',
        '@phpstan-property-read',
        '@phpstan-property-write',
        '@phpstan-ignore',
        '@phpstan-ignore-next-line',
        '@psalm-param',
        '@psalm-return',
        '@psalm-template',
        '@psalm-suppress',
    ];

    private const array ENTITY_PROPERTY_TAGS_ALLOWLIST = [
        ...self::PHPSTAN_TAGS_ALLOWLIST,
        '@var',
        '@phpstan-var',
        '@psalm-var',
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Strip every comment (//, /* */, /** */) outside interface method doc-blocks. '
            . 'Code is self-documenting via naming + tests; prose belongs in repository documentation. '
            . 'PHPStan-tag-only docblocks (no prose) anywhere in the codebase are preserved.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        final class Foo
                        {
                            /** why this constant exists */
                            private const int X = 1;

                            /**
                             * Multi-line prose about what doX does.
                             */
                            public function doX(): void
                            {
                                // step 1 — fetch
                                $this->y();
                            }
                        }
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        final class Foo
                        {
                            private const int X = 1;

                            public function doX(): void
                            {
                                $this->y();
                            }
                        }
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
        return [
            Namespace_::class,
            Interface_::class,
            Class_::class,
            Trait_::class,
            Enum_::class,
            Function_::class,
        ];
    }

    public function refactor(Node $node): ?Node
    {
        $changed = $this->stripCommentsWithCarveOut($node);

        if ($node instanceof ClassLike) {
            foreach ($node->attrGroups as $attrGroup) {
                if ($this->stripCommentsWithCarveOut($attrGroup)) {
                    $changed = true;
                }
            }
        }

        if ($node instanceof Interface_) {
            foreach ($node->stmts as $member) {
                if ($member instanceof ClassMethod) {
                    if ($this->keepDocOnly($member)) {
                        $changed = true;
                    }
                    if ($this->stripCommentsOnMethodParams($member)) {
                        $changed = true;
                    }
                    if ($this->stripCommentsOnAttributeGroups($member)) {
                        $changed = true;
                    }
                    continue;
                }
                if ($this->stripCommentsWithCarveOut($member)) {
                    $changed = true;
                }
                if ($this->stripCommentsOnAttributeGroups($member)) {
                    $changed = true;
                }
            }
        } elseif ($node instanceof Class_ || $node instanceof Trait_ || $node instanceof Enum_) {
            $isEntityFile = $this->isEntityFolderFile();
            foreach ($node->stmts as $member) {
                $allowVar = $member instanceof Property
                    && (
                        ($isEntityFile && self::isCollectionProperty($member))
                        || self::isPrivateTypedArrayProperty($member)
                    );
                $allowlist = $allowVar
                    ? self::ENTITY_PROPERTY_TAGS_ALLOWLIST
                    : self::PHPSTAN_TAGS_ALLOWLIST;
                if ($this->stripCommentsWithCarveOut($member, $allowlist)) {
                    $changed = true;
                }
                if ($this->stripCommentsOnAttributeGroups($member)) {
                    $changed = true;
                }
                if ($member instanceof ClassMethod) {
                    if ($this->stripCommentsOnMethodParams($member)) {
                        $changed = true;
                    }
                    if (null !== $member->stmts && $this->stripCommentsInside($member->stmts)) {
                        $changed = true;
                    }
                }
            }
        } elseif ($node instanceof Function_) {
            if ($this->stripCommentsOnAttributeGroups($node)) {
                $changed = true;
            }
            foreach ($node->params as $param) {
                if ($this->stripCommentsWithCarveOut($param)) {
                    $changed = true;
                }
                if ($this->stripCommentsOnAttributeGroups($param)) {
                    $changed = true;
                }
            }
            if ($this->stripCommentsInside($node->stmts)) {
                $changed = true;
            }
        } elseif ($node instanceof Namespace_) {
            foreach ($node->stmts as $member) {
                if ($member instanceof ClassLike || $member instanceof Function_) {
                    continue;
                }
                if ($this->stripCommentsWithCarveOut($member)) {
                    $changed = true;
                }
            }
        }

        return $changed ? $node : null;
    }

    private function stripCommentsOnMethodParams(ClassMethod $method): bool
    {
        $changed = false;
        foreach ($method->params as $param) {
            if ($this->stripAllComments($param)) {
                $changed = true;
            }
            if ($this->stripCommentsOnAttributeGroups($param)) {
                $changed = true;
            }
        }

        return $changed;
    }

    private function stripCommentsOnAttributeGroups(Node $node): bool
    {
        if (!property_exists($node, 'attrGroups')) {
            return false;
        }
        $changed = false;
        /**
         * @var list<AttributeGroup> $attrGroups
         */
        $attrGroups = $node->attrGroups;
        foreach ($attrGroups as $attrGroup) {
            if ($this->stripAllComments($attrGroup)) {
                $changed = true;
            }
        }

        return $changed;
    }

    private function stripAllComments(Node $node): bool
    {
        $comments = $node->getComments();
        if ([] === $comments) {
            return false;
        }
        $node->setAttribute('comments', []);

        return true;
    }

    /**
     * @param null|list<string> $allowlist
     */
    private function stripCommentsWithCarveOut(Node $node, ?array $allowlist = null): bool
    {
        $effective = $allowlist === null ? self::PHPSTAN_TAGS_ALLOWLIST : $allowlist;
        $comments  = $node->getComments();
        if ([] === $comments) {
            return false;
        }

        $survivors = [];
        foreach ($comments as $comment) {
            if ($comment instanceof Doc) {
                $extracted = $this->extractAllowedTags($comment, $effective);
                if ($extracted instanceof Doc) {
                    $survivors[] = $extracted;
                }

                continue;
            }
            if ($this->isAllowedTagLineComment($comment, $effective)) {
                $survivors[] = $comment;
            }
        }

        if ($survivors === $comments) {
            return false;
        }

        $node->setAttribute('comments', $survivors);

        return true;
    }

    /**
     * @param list<string> $allowlist
     */
    private function isAllowedTagLineComment(Comment $comment, array $allowlist): bool
    {
        $text = trim($comment->getText());
        foreach (['#^//\s*#', '#^/\*+\s*#', '#\s*\*+/$#'] as $pattern) {
            $stripped = preg_replace($pattern, '', $text);
            if ($stripped === null) {
                throw new RuntimeException('preg_replace failed stripping comment prefix/suffix');
            }
            $text = $stripped;
        }
        $text = trim($text);
        if ('' === $text || '@' !== $text[0]) {
            return false;
        }
        $tag = explode(' ', $text, 2)[0];

        return in_array($tag, $allowlist, true);
    }

    private function isEntityFolderFile(): bool
    {
        if (!isset($this->file)) {
            return false;
        }

        return self::isEntityFolderPath($this->file->getFilePath());
    }

    public static function isEntityFolderPath(string $path): bool
    {
        return str_contains($path, '/src/Entity/') || str_contains($path, '\src\Entity\\');
    }

    public static function isPrivateTypedArrayProperty(Property $property): bool
    {
        if (0 === ($property->flags & Class_::MODIFIER_PRIVATE)) {
            return false;
        }
        $type = $property->type;
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        return $type instanceof Identifier && 'array' === $type->name;
    }

    public static function isCollectionProperty(Property $property): bool
    {
        $type = $property->type;
        if ($type instanceof NullableType) {
            $type = $type->type;
        }
        if (!$type instanceof Name) {
            return false;
        }
        $last = $type->getLast();

        return 'Collection' === $last || 'ArrayCollection' === $last;
    }

    private function keepDocOnly(Node $node): bool
    {
        $comments = $node->getComments();
        if ([] === $comments) {
            return false;
        }
        $doc  = $node->getDocComment();
        $next = $doc instanceof Doc ? [$doc] : [];
        if ($next === $comments) {
            return false;
        }
        $node->setAttribute('comments', $next);

        return true;
    }

    /**
     * @param array<int, Node> $stmts
     */
    private function stripCommentsInside(array $stmts): bool
    {
        $rule    = $this;
        $visitor = new class ($rule) extends NodeVisitorAbstract {
            public bool $changed = false;

            public function __construct(private readonly NoCommentsOutsideInterfaceMethodDocBlockRector $rule) {}

            public function enterNode(Node $node)
            {
                if ([] === $node->getComments()) {
                    return null;
                }
                if ($this->rule->applyCarveOut($node)) {
                    $this->changed = true;
                }

                return null;
            }
        };
        $traverser = new NodeTraverser($visitor);
        $traverser->traverse($stmts);

        return $visitor->changed;
    }

    public function applyCarveOut(Node $node): bool
    {
        return $this->stripCommentsWithCarveOut($node);
    }

    /**
     * @param list<string> $allowlist
     */
    private function extractAllowedTags(Doc $doc, array $allowlist): ?Doc
    {
        $blocks        = [];
        $currentLines  = [];
        $currentTag    = '';
        $insideAllowed = false;

        $rawLines = preg_split('/\R/', $doc->getText());
        if ($rawLines === false) {
            throw new RuntimeException('preg_split failed splitting docblock lines');
        }
        foreach ($rawLines as $rawLine) {
            $line = preg_replace('#^\s*/?\*+/?\s*#', '', $rawLine);
            if ($line === null) {
                throw new RuntimeException('preg_replace failed stripping docblock prefix');
            }
            $stripped = preg_replace('#\s*\*+/\s*$#', '', $line);
            if ($stripped === null) {
                throw new RuntimeException('preg_replace failed stripping docblock suffix');
            }
            $line = rtrim($stripped);

            if (1 === preg_match('/^\s*(@\S+)/', $line, $matches)) {
                if ($insideAllowed && [] !== $currentLines) {
                    $blocks[]      = ['tag' => $currentTag, 'lines' => $currentLines];
                    $currentLines  = [];
                }
                $tag = $matches[1];
                if (in_array($tag, $allowlist, true)) {
                    $insideAllowed  = true;
                    $currentTag     = $tag;
                    $currentLines[] = ltrim($line);
                } else {
                    $insideAllowed = false;
                }

                continue;
            }

            if ($insideAllowed && '' !== trim($line)) {
                $currentLines[] = ltrim($line);
            }
        }
        if ($insideAllowed && [] !== $currentLines) {
            $blocks[] = ['tag' => $currentTag, 'lines' => $currentLines];
        }
        if ([] === $blocks) {
            return null;
        }

        $body         = '';
        $previousType = '';
        foreach ($blocks as $blockIndex => $block) {
            $type = $this->tagGroupKey($block['tag']);
            if ($blockIndex > 0 && $type !== $previousType) {
                $body .= "\n     *";
            }
            foreach ($block['lines'] as $blockLine) {
                $body .= "\n     * " . $blockLine;
            }
            $previousType = $type;
        }

        return new Doc('/**' . $body . "\n     */");
    }

    private function tagGroupKey(string $tag): string
    {
        $bare = ltrim($tag, '@');
        $bare = preg_replace('/^(phpstan|psalm)-/', '', $bare) ?? $bare;

        return strtolower($bare);
    }
}
