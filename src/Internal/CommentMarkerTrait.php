<?php

declare(strict_types=1);

namespace Amashukov\RectorRules\Internal;

use PhpParser\Comment;
use PhpParser\Node;

/**
 * @internal
 */
trait CommentMarkerTrait
{
    /**
     * @return list<Comment>
     */
    private static function existingComments(Node $node): array
    {
        $raw = $node->getAttribute('comments');
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $comment) {
            if ($comment instanceof Comment) {
                $out[] = $comment;
            }
        }

        return $out;
    }
}
