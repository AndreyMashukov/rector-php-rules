<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Yaml;

use InvalidArgumentException;
use PhpParser\Node;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use RuntimeException;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

final class YamlNoCommentsRector extends AbstractRector implements ConfigurableRectorInterface
{
    public const string PATHS      = 'paths';
    public const string EXTENSIONS = 'extensions';

    /** @var list<string> */
    private array $paths = [];

    /** @var list<string>|null */
    private ?array $extensions = null;

    private bool $alreadyChecked = false;

    public function __construct(
        private readonly YamlNoCommentsCheckerInterface $checker,
        private readonly YamlCommentStripperInterface $stripper,
    ) {}

    /**
     * @param array<string, mixed> $configuration
     */
    public function configure(array $configuration): void
    {
        $paths = [];
        if (array_key_exists(self::PATHS, $configuration)) {
            $paths = $configuration[self::PATHS];
        }
        if (!\is_array($paths) || !\array_is_list($paths)) {
            throw new InvalidArgumentException(self::PATHS . ' must be a list of path strings');
        }
        foreach ($paths as $p) {
            if (!\is_string($p)) {
                throw new InvalidArgumentException(self::PATHS . ' entries must all be strings');
            }
        }
        $this->paths = $paths;

        if (!array_key_exists(self::EXTENSIONS, $configuration) || $configuration[self::EXTENSIONS] === null) {
            return;
        }
        $extensions = $configuration[self::EXTENSIONS];
        if (!\is_array($extensions) || !\array_is_list($extensions)) {
            throw new InvalidArgumentException(self::EXTENSIONS . ' must be a list of extension strings');
        }
        foreach ($extensions as $ext) {
            if (!\is_string($ext)) {
                throw new InvalidArgumentException(self::EXTENSIONS . ' entries must all be strings');
            }
        }
        $this->extensions = $extensions;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Forbid comments (whole-line and inline) in YAML configuration files at the configured paths. '
            . 'Rector cannot rewrite YAML through its PHP AST, so this rule walks the configured paths '
            . 'recursively on the first PHP node Rector visits, strips comments out of every offending '
            . 'YAML file, and throws a RuntimeException listing the original findings. The next run is '
            . 'clean.',
            [
                new ConfiguredCodeSample(
                    <<<'YAML'
                        # configure messenger transport
                        framework:
                          messenger:
                            transports:
                              async: '%env(MESSENGER_TRANSPORT_DSN)%' # async queue
                        YAML,
                    <<<'YAML'
                        framework:
                          messenger:
                            transports:
                              async: '%env(MESSENGER_TRANSPORT_DSN)%'
                        YAML,
                    [
                        self::PATHS => ['config/'],
                    ],
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Node::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($this->alreadyChecked) {
            return null;
        }
        $this->alreadyChecked = true;

        if ($this->paths === []) {
            return null;
        }

        $findings = $this->checker->check($this->paths, $this->extensions);

        if ($findings === []) {
            return null;
        }

        $touchedFiles = [];
        foreach ($findings as $f) {
            $touchedFiles[$f->file] = true;
        }
        foreach (\array_keys($touchedFiles) as $path) {
            $this->stripper->stripFile($path);
        }

        $report = [];
        foreach ($findings as $f) {
            $report[] = \sprintf(
                '%s:%d:%d: %s comment: %s',
                $f->file,
                $f->line,
                $f->column,
                $f->kind === YamlComment::KIND_WHOLE_LINE ? 'whole-line' : 'inline',
                \ltrim($f->excerpt),
            );
        }

        throw new RuntimeException(\sprintf(
            "YAML files contained forbidden comments (stripped in place — re-run to verify clean state):\n%s\n%d comment(s) across %d file(s).",
            \implode("\n", $report),
            \count($findings),
            \count($touchedFiles),
        ));
    }

    /**
     * @internal Test hook: reset the once-per-run guard between assertions.
     */
    public function resetForTests(): void
    {
        $this->alreadyChecked = false;
    }
}
