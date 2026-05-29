<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Yaml;

interface YamlCommentStripperInterface
{
    /**
     * Rewrite the file in place with every YAML comment stripped out.
     * Returns the number of lines that were modified.
     */
    public function stripFile(string $file): int;

    /**
     * Strip comments from a YAML string. Returns [rewritten, changedLineCount].
     *
     * @return array{0: string, 1: int}
     */
    public function stripString(string $contents): array;
}
