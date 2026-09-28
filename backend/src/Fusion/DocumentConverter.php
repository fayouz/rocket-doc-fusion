<?php

namespace App\Fusion;

use App\Entity\Document;
use App\OnlyOffice\OnlyOfficeClient;
use App\OnlyOffice\OnlyOfficeUrls;

/** PDF of the current version of a document, converted by ONLYOFFICE once per version. */
class DocumentConverter
{
    public function __construct(
        private readonly OnlyOfficeClient $onlyOffice,
        private readonly OnlyOfficeUrls $urls,
        private readonly DocumentStorage $storage,
    ) {
    }

    /** @return string path of the PDF */
    public function pdf(Document $document): string
    {
        $path = $this->storage->pdfPath($document);
        if (!is_file($path)) {
            $pdf = $this->onlyOffice->convert(
                $this->urls->version($document, $document->getVersion(), 600),
                'pdf-'.$document->getEditorKey().'-v'.$document->getVersion(),
                'docx',
                'pdf',
                $document->getTitle().'.docx',
            );
            $this->storage->write($path, $pdf);
        }

        return $path;
    }
}
