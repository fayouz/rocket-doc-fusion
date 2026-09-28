<?php

namespace App\Fusion;

use App\Entity\Document;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Files of the documents, under DATA_DIR/documents/<opaque key>/ (never named after the document): the template,
 * every version (v1.docx is the merge result, then one per save) and their PDF conversions.
 */
class DocumentStorage
{
    private readonly Filesystem $fs;

    public function __construct(
        #[Autowire(env: 'resolve:DATA_DIR')] private readonly string $dataDir,
    ) {
        $this->fs = new Filesystem();
    }

    public function templatePath(Document $document): string
    {
        return $this->dir($document).'/template.docx';
    }

    public function versionPath(Document $document, ?int $version = null): string
    {
        return $this->dir($document).'/v'.($version ?? $document->getVersion()).'.docx';
    }

    public function pdfPath(Document $document, ?int $version = null): string
    {
        return $this->dir($document).'/v'.($version ?? $document->getVersion()).'.pdf';
    }

    public function write(string $path, string $content): void
    {
        $this->fs->dumpFile($path, $content);
    }

    public function copy(string $from, string $to): void
    {
        $this->fs->copy($from, $to, true);
    }

    public function delete(Document $document): void
    {
        $this->fs->remove($this->dir($document));
    }

    private function dir(Document $document): string
    {
        return rtrim($this->dataDir, '/').'/documents/'.$document->getStorageKey();
    }
}
