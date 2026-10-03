<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Yaml;

final readonly class YamlComment
{
    public const string KIND_WHOLE_LINE = 'whole_line';
    public const string KIND_INLINE     = 'inline';

    public function __construct(
        public string $file,
        public int $line,
        public int $column,
        public string $kind,
        public string $excerpt,
    ) {}
}
