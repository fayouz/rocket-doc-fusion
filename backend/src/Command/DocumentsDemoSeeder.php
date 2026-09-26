<?php

namespace App\Command;

use App\Fusion\DocumentMerger;
use App\Fusion\SampleTemplate;
use App\Repository\DocumentRepository;
use Rocket\Core\Command\DemoSeederInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\File;

/** Demo documents: Alice's quote, merged from the sample template by the worker once ONLYOFFICE is up. */
final class DocumentsDemoSeeder implements DemoSeederInterface
{
    public function __construct(
        private readonly DocumentMerger $merger,
        private readonly DocumentRepository $documents,
        private readonly SampleTemplate $sample,
    ) {
    }

    public function seed(array $users, SymfonyStyle $io): void
    {
        $alice = $users['alice@example.org'] ?? null;
        if (null === $alice || null !== $this->documents->findOneBy(['owner' => $alice])) {
            return;
        }
        $path = $this->sample->write();
        try {
            $this->merger->create(new File($path), SampleTemplate::NAME, SampleTemplate::VALUES, $alice, null, 'Devis Société Martin');
        } finally {
            @unlink($path);
        }
        $io->writeln('Demo document: « Devis Société Martin » (alice@example.org), merged by the worker.');
    }
}
