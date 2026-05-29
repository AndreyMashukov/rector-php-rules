<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Yaml;

interface YamlNoCommentsCheckerInterface
{
    /**
     * Walk the given paths (files or directories) and collect every YAML
     * comment found. Whole-line comments and inline comments are reported
     * separately via the YamlComment::KIND_* constants. The optional
     * $extensionsOverride argument overrides the default extension list
     * for this call only.
     *
     * @param list<string>      $paths
     * @param list<string>|null $extensionsOverride
     * @return list<YamlComment>
     */
    public function check(array $paths, ?array $extensionsOverride = null): array;

    /**
     * @return list<YamlComment>
     */
    public function checkFile(string $file): array;

    /**
     * @return list<YamlComment>
     */
    public function checkString(string $contents, string $sourceLabel = '<string>'): array;
}
