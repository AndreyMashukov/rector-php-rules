<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Yaml;

use RuntimeException;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class YamlNoCommentsChecker implements YamlNoCommentsCheckerInterface
{
    /** @var list<string> */
    private const array DEFAULT_EXTENSIONS = ['yaml', 'yml'];

    /** @var list<string> */
    private array $extensions;

    /**
     * @param list<string>|null $extensions File extensions to scan (without the dot). Defaults to ['yaml', 'yml'].
     */
    public function __construct(?array $extensions = null)
    {
        if ($extensions === null) {
            $this->extensions = self::DEFAULT_EXTENSIONS;

            return;
        }

        $this->extensions = $extensions;
    }

    /**
     * Walk the given paths (files or directories) and collect every YAML comment
     * found. Whole-line comments and inline comments are reported separately
     * via the YamlComment::KIND_* constants.
     *
     * Paths may be absolute or relative to the current working directory.
     * The optional $extensionsOverride argument overrides the default
     * extension list for this call only.
     *
     * @param list<string>      $paths
     * @param list<string>|null $extensionsOverride
     * @return list<YamlComment>
     */
    public function check(array $paths, ?array $extensionsOverride = null): array
    {
        $effectiveExtensions = $extensionsOverride === null ? $this->extensions : $extensionsOverride;
        $files               = $this->collectFiles($paths, $effectiveExtensions);
        $findings = [];

        foreach ($files as $file) {
            foreach ($this->checkFile($file) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Scan a single YAML file and return any comments found.
     *
     * @return list<YamlComment>
     */
    public function checkFile(string $file): array
    {
        $contents = @\file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Cannot read YAML file: %s', $file));
        }

        return $this->checkString($contents, $file);
    }

    /**
     * Scan a YAML string (already loaded). $sourceLabel is reported back in findings.
     *
     * @return list<YamlComment>
     */
    public function checkString(string $contents, string $sourceLabel = '<string>'): array
    {
        $findings = [];
        $rawLines = \preg_split('/\r\n|\r|\n/', $contents);
        if ($rawLines === false) {
            throw new RuntimeException('preg_split failed splitting YAML lines');
        }
        $lines = $rawLines;

        foreach ($lines as $lineIndex => $line) {
            $finding = $this->scanLine($line, $lineIndex + 1, $sourceLabel);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /**
     * Detect a comment on one line. The first comment per line is reported.
     * Returns null if the line is clean.
     *
     * The scanner understands single- and double-quoted scalars so a hash
     * sign inside "value with # not a comment" is not flagged.
     */
    private function scanLine(string $line, int $lineNumber, string $sourceLabel): ?YamlComment
    {
        $length            = \strlen($line);
        $inSingle          = false;
        $inDouble          = false;
        $sawNonWhitespace  = false;
        $prevWasWhitespace = true;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($inSingle) {
                if ($char === "'") {
                    $inSingle = false;
                }
                $prevWasWhitespace = false;

                continue;
            }

            if ($inDouble) {
                if ($char === '\\' && $i + 1 < $length) {
                    $i++;
                    continue;
                }

                if ($char === '"') {
                    $inDouble = false;
                }
                $prevWasWhitespace = false;

                continue;
            }

            if ($char === "'") {
                $inSingle          = true;
                $sawNonWhitespace  = true;
                $prevWasWhitespace = false;

                continue;
            }

            if ($char === '"') {
                $inDouble          = true;
                $sawNonWhitespace  = true;
                $prevWasWhitespace = false;

                continue;
            }

            if ($char === '#') {
                // Inline only if a non-whitespace character preceded us AND
                // the # is preceded by whitespace (the YAML inline-comment
                // requirement). At-start-of-line means whole-line comment.
                if (!$sawNonWhitespace) {
                    return new YamlComment(
                        $sourceLabel,
                        $lineNumber,
                        $i + 1,
                        YamlComment::KIND_WHOLE_LINE,
                        \rtrim($line),
                    );
                }

                if ($prevWasWhitespace) {
                    return new YamlComment(
                        $sourceLabel,
                        $lineNumber,
                        $i + 1,
                        YamlComment::KIND_INLINE,
                        \rtrim(\substr($line, $i)),
                    );
                }

                // `#` is glued onto a non-quoted token (e.g. URL fragments
                // like https://example.com/page#section). Per YAML 1.2 a
                // comment marker must be preceded by whitespace, so this is
                // not a comment.
                $prevWasWhitespace = false;

                continue;
            }

            if ($char === ' ' || $char === "\t") {
                $prevWasWhitespace = true;

                continue;
            }

            $sawNonWhitespace  = true;
            $prevWasWhitespace = false;
        }

        return null;
    }

    /**
     * Expand the given path list into a flat list of files matching the
     * given extensions. Directories are walked recursively.
     *
     * @param list<string> $paths
     * @param list<string> $extensions
     * @return list<string>
     */
    private function collectFiles(array $paths, array $extensions): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (\is_file($path)) {
                if ($this->matchesExtension($path, $extensions)) {
                    $files[] = $path;
                }

                continue;
            }

            if (\is_dir($path)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                );

                foreach ($iterator as $file) {
                    /** @var SplFileInfo $file */
                    if ($file->isFile() && $this->matchesExtension($file->getPathname(), $extensions)) {
                        $files[] = $file->getPathname();
                    }
                }

                continue;
            }

            throw new RuntimeException(\sprintf('Path does not exist: %s', $path));
        }

        \sort($files);

        return $files;
    }

    /**
     * @param list<string> $extensions
     */
    private function matchesExtension(string $path, array $extensions): bool
    {
        $ext = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));

        return \in_array($ext, $extensions, true);
    }
}
