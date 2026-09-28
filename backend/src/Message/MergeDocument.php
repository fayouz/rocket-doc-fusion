<?php

namespace App\Message;

use Rocket\Core\Message\AsyncMessageInterface;

/** Merge the template of a document with its values (worker). */
final class MergeDocument implements AsyncMessageInterface
{
    public function __construct(public readonly string $documentId)
    {
    }
}
