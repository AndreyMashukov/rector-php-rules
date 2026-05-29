<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Yaml;

final class YamlComment
{
    public const string KIND_WHOLE_LINE = 'whole_line';
    public const string KIND_INLINE     = 'inline';

    public function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly int $column,
        public readonly string $kind,
        public readonly string $excerpt,
    ) {}
}
