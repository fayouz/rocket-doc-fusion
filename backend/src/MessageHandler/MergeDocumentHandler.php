<?php

namespace App\MessageHandler;

use App\Fusion\DocumentMerger;
use App\Message\MergeDocument;
use App\Repository\DocumentRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MergeDocumentHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentMerger $merger,
    ) {
    }

    public function __invoke(MergeDocument $message): void
    {
        $document = $this->documents->find($message->documentId);
        if (null !== $document) {
            $this->merger->merge($document);
        }
    }
}
