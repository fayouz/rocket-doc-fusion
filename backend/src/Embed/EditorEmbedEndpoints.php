<?php

namespace App\Embed;

use Rocket\Core\Embed\EmbedEndpointsInterface;

/** The embedded editor (/embed/editor) opens, saves and downloads the documents of its user, and nothing else. */
final class EditorEmbedEndpoints implements EmbedEndpointsInterface
{
    public function embedEndpoints(): iterable
    {
        yield ['GET', '#^/api/documents/[^/]+$#'];
        yield ['GET', '#^/api/documents/[^/]+/(editor|content)$#'];
        yield ['POST', '#^/api/documents/[^/]+/force-save$#'];
    }
}
