<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Document;
use App\Fusion\DocumentStorage;
use Doctrine\ORM\EntityManagerInterface;

/** @implements ProcessorInterface<Document, null> */
final class DocumentDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentStorage $storage,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Document) {
            $this->em->remove($data);
            $this->em->flush();
            $this->storage->delete($data);
        }

        return null;
    }
}
