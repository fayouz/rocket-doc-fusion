<?php

namespace App\Fusion;

use App\Entity\Document;
use App\Enum\DocumentStatus;
use App\Message\MergeDocument;
use App\OnlyOffice\OnlyOfficeClient;
use App\OnlyOffice\OnlyOfficeException;
use App\OnlyOffice\OnlyOfficeUrls;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Merges: a document is created from a template and values, then merged by the worker through ONLYOFFICE
 * (Document Builder). The template is kept with the document.
 */
class DocumentMerger
{
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    /** Waiting for the result of ONLYOFFICE (worker), in seconds. */
    private const TIMEOUT = 120;

    public function __construct(
        private readonly TemplateInspector $inspector,
        private readonly MergeScript $script,
        private readonly DocumentStorage $storage,
        private readonly OnlyOfficeClient $onlyOffice,
        private readonly OnlyOfficeUrls $urls,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        #[Autowire(env: 'int:DOC_FUSION_MAX_FILE_SIZE')] private readonly int $maxFileSize,
    ) {
    }

    public function maxFileSize(): int
    {
        return $this->maxFileSize;
    }

    /**
     * Variables of an uploaded template.
     *
     * @return array{variables: list<string>, sections: list<array{name: string, fields: list<string>}>, errors: list<string>}
     */
    public function inspect(File $template): array
    {
        $this->checkTemplate($template);
        try {
            return $this->inspector->inspect($template->getPathname());
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }

    /**
     * A new document, merged in the background.
     *
     * @param array<string, mixed> $values
     */
    public function create(File $template, string $templateName, array $values, User $owner, ?Application $application, ?string $title = null): Document
    {
        $variables = $this->inspect($template);
        if ([] !== $variables['errors']) {
            throw new UnprocessableEntityHttpException('The template is invalid: '.implode(' ', $variables['errors']));
        }
        $name = str_replace(['/', '\\'], '_', trim($templateName)) ?: 'modele.docx';
        $title = trim((string) $title) ?: (string) preg_replace('/\.docx$/i', '', $name);
        $document = (new Document($owner, mb_substr($title, 0, 255), mb_substr($name, 0, 255), $values))->setApplication($application);
        $this->storage->copy($template->getPathname(), $this->storage->templatePath($document));
        $this->em->persist($document);
        $this->em->flush();
        $this->bus->dispatch(new MergeDocument($document->getId()->toRfc4122()));

        return $document;
    }

    /** Runs the merge of a document waiting for it (worker). */
    public function merge(Document $document): void
    {
        if (DocumentStatus::Merging !== $document->getStatus()) {
            return;
        }
        try {
            $template = $this->inspector->inspect($this->storage->templatePath($document));
            $key = 'merge-'.$document->getId()->toRfc4122().'-'.bin2hex(random_bytes(4));
            $result = $this->onlyOffice->build($key, $this->urls->mergeScript($document), $this->script->argument($template, $document->getValues()));
            $deadline = time() + self::TIMEOUT;
            while (!$result['end']) {
                if (time() > $deadline) {
                    throw new OnlyOfficeException('ONLYOFFICE did not finish the merge in time.');
                }
                usleep(500_000);
                $result = $this->onlyOffice->build($key);
            }
            $url = $result['urls'][MergeScript::OUTPUT] ?? throw new OnlyOfficeException('ONLYOFFICE returned no document.');
            $this->addVersion($document, $this->onlyOffice->download($url));
        } catch (OnlyOfficeException|\InvalidArgumentException $e) {
            $document->markFailed($e->getMessage());
            $this->em->flush();
        }
    }

    /** Stores a new version of the content (merge result, save from the editor, replacement). */
    public function addVersion(Document $document, string $content): void
    {
        if (\strlen($content) > $this->maxFileSize) {
            throw new OnlyOfficeException(\sprintf('The document is larger than the limit (%d bytes).', $this->maxFileSize));
        }
        $version = $document->getVersion() + 1;
        $this->storage->write($this->storage->versionPath($document, $version), $content);
        $document->addVersion(\strlen($content), hash('sha256', $content));
        $this->em->flush();
    }

    public function checkTemplate(File $file): void
    {
        if ($file->getSize() > $this->maxFileSize) {
            throw new HttpException(413, \sprintf('The file is larger than the limit (%d bytes).', $this->maxFileSize));
        }
        $mime = $file->getMimeType();
        if (!\in_array($mime, [self::DOCX, 'application/zip', 'application/octet-stream'], true)) {
            throw new UnprocessableEntityHttpException('The template must be a Word document (.docx).');
        }
    }
}
