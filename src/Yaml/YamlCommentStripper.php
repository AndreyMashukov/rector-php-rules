<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Yaml;

use RuntimeException;

final class YamlCommentStripper implements YamlCommentStripperInterface
{
    /**
     * Rewrite the file in place with every YAML comment stripped out. Returns
     * the number of lines that were modified.
     */
    public function stripFile(string $file): int
    {
        $contents = @\file_get_contents($file);

        if ($contents === false) {
            throw new RuntimeException(\sprintf('Cannot read YAML file: %s', $file));
        }

        [$rewritten, $changed] = $this->stripString($contents);

        if ($changed > 0) {
            $written = @\file_put_contents($file, $rewritten);

            if ($written === false) {
                throw new RuntimeException(\sprintf('Cannot write YAML file: %s', $file));
            }
        }

        return $changed;
    }

    /**
     * Strip comments from a YAML string. Returns [rewritten, changedLineCount].
     *
     * Preserves the original line-ending style (CRLF vs LF) and the trailing
     * newline if it was present.
     *
     * @return array{0: string, 1: int}
     */
    public function stripString(string $contents): array
    {
        // Tokenise into [line, separator] tuples so we can rebuild the file
        // with its original line endings preserved.
        $tuples = $this->splitPreservingSeparators($contents);

        $changed = 0;
        foreach ($tuples as $i => [$line, $sep]) {
            $stripped = $this->stripLine($line);

            if ($stripped !== $line) {
                $tuples[$i] = [$stripped, $sep];
                $changed++;
            }
        }

        $out = '';
        foreach ($tuples as [$line, $sep]) {
            $out .= $line . $sep;
        }

        return [$out, $changed];
    }

    /**
     * Strip a single line. If the line collapses to whitespace-only after
     * removing a whole-line comment, the line becomes empty (caller decides
     * whether to keep the blank line).
     */
    private function stripLine(string $line): string
    {
        $length            = \strlen($line);
        $inSingle          = false;
        $inDouble          = false;
        $sawNonWhitespace  = false;
        $prevWasWhitespace = true;
        $commentStart      = null;

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
                if (!$sawNonWhitespace) {
                    $commentStart = 0;

                    break;
                }

                if ($prevWasWhitespace) {
                    // Inline comment — back up to the run of leading whitespace
                    // so we strip the spaces before `#` too.
                    $commentStart = $i;
                    while ($commentStart > 0 && ($line[$commentStart - 1] === ' ' || $line[$commentStart - 1] === "\t")) {
                        $commentStart--;
                    }

                    break;
                }

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

        if ($commentStart === null) {
            return $line;
        }

        return \substr($line, 0, $commentStart);
    }

    /**
     * Split contents into [line, line-separator] tuples, keeping the original
     * separator for each line so we can rebuild the file byte-for-byte modulo
     * the comment removals.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function splitPreservingSeparators(string $contents): array
    {
        if (\preg_match_all('/([^\r\n]*)(\r\n|\r|\n|$)/', $contents, $matches, \PREG_SET_ORDER) === false) {
            return [[$contents, '']];
        }

        $tuples = [];
        foreach ($matches as $m) {
            $line = $m[1];
            $sep  = $m[2];

            if ($line === '' && $sep === '') {
                // End-of-string sentinel produced by the regex; drop it.
                continue;
            }
            $tuples[] = [$line, $sep];
        }

        return $tuples;
    }
}
