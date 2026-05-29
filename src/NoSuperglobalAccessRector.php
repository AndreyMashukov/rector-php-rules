<?php

declare(strict_types=1);

namespace Amashukov\RectorRules;

use Amashukov\RectorRules\Internal\CommentMarkerTrait;
use PhpParser\Comment;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class NoSuperglobalAccessRector extends AbstractRector
{
    use CommentMarkerTrait;

    private const string BAN_MARKER = '// RECTOR-BAN: superglobal access ($_ENV/$_SERVER/$_GET/$_POST/$_REQUEST/$_COOKIE/$_FILES/$_SESSION) and getenv/putenv are forbidden — read env via DI constructor args wired from container configuration; read request via your framework Request object';

    /**
     * @var list<string>
     */
    private const array BANNED_SUPERGLOBALS = [
        '_ENV',
        '_SERVER',
        '_GET',
        '_POST',
        '_REQUEST',
        '_COOKIE',
        '_FILES',
        '_SESSION',
    ];

    /**
     * @var list<string>
     */
    private const array BANNED_ENV_FUNCTIONS = ['getenv', 'putenv'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid every PHP superglobal access ($_ENV / $_SERVER / $_GET / $_POST / $_REQUEST / $_COOKIE / $_FILES / $_SESSION) '
            . 'and getenv() / putenv() calls anywhere under src/, bundles/, and tests/. Env values flow through DI '
            . '(constructor arg). Request data flows through the framework Request object. Tests pull from the container. '
            . 'Skips migrations/, .rector/, var/, vendor/, bin/, public/.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
                        $apiKey = $_ENV['API_KEY'] ?? getenv('API_KEY');
                        CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                        $apiKey = $this->apiKey; // injected by DI from container configuration
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
        return [Stmt::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!self::isCoveredPath($this->file->getFilePath())) {
            return null;
        }
        if (!$this->statementContainsBannedSuperglobal($node)) {
            return null;
        }

        $existing = self::existingComments($node);
        foreach ($existing as $comment) {
            if (str_contains($comment->getText(), 'RECTOR-BAN: superglobal')) {
                return null;
            }
        }
        $node->setAttribute('comments', [...$existing, new Comment(self::BAN_MARKER)]);

        return $node;
    }

    private function statementContainsBannedSuperglobal(Node $stmt): bool
    {
        $finder = new NodeFinder();
        $hits   = $finder->find($stmt, function (Node $inner): bool {
            if ($inner instanceof ArrayDimFetch && $inner->var instanceof Variable) {
                $varName = $inner->var->name;

                return is_string($varName) && in_array($varName, self::BANNED_SUPERGLOBALS, true);
            }
            if ($inner instanceof Variable) {
                $varName = $inner->name;

                return is_string($varName) && in_array($varName, self::BANNED_SUPERGLOBALS, true);
            }
            if ($inner instanceof FuncCall && $inner->name instanceof Name) {
                $callee = $inner->name->toLowerString();
                if (!in_array($callee, self::BANNED_ENV_FUNCTIONS, true)) {
                    return false;
                }
                if (!isset($inner->args[0])) {
                    return false;
                }

                return $inner->args[0] instanceof Arg;
            }

            return false;
        });

        return [] !== $hits;
    }

    public static function isCoveredPath(string $file): bool
    {
        $normalized = str_replace('\\', '/', $file);
        foreach (['/migrations/', '/.rector/', '/var/', '/vendor/', '/bin/', '/public/'] as $skip) {
            if (str_contains($normalized, $skip)) {
                return false;
            }
        }

        return str_contains($normalized, '/src/')
            || str_contains($normalized, '/bundles/')
            || str_contains($normalized, '/tests/');
    }
}
