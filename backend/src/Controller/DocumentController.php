<?php

namespace App\Controller;

use App\Entity\Document;
use App\Fusion\DocumentConverter;
use App\Fusion\DocumentMerger;
use App\Fusion\DocumentStorage;
use App\Fusion\SampleTemplate;
use App\OnlyOffice\EditorConfig;
use App\OnlyOffice\OnlyOfficeClient;
use App\OnlyOffice\OnlyOfficeException;
use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Security\ActorContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Attribute\MapUploadedFile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

final class DocumentController extends AbstractController
{
    private const CONTEXT = ['groups' => ['document:read', 'tracking']];

    /** Variables of a template (multipart "file"): {variables, sections: [{name, fields}], errors}. */
    #[Route('/api/templates/inspect', name: 'api_template_inspect', methods: ['POST'])]
    public function inspect(#[MapUploadedFile([new Assert\NotNull()])] UploadedFile $file, DocumentMerger $merger, ActorContext $actor): JsonResponse
    {
        $actor->requireUser();

        return $this->json($merger->inspect($file));
    }

    /** A sample template to start from (a quote with variables and a repeated table row). */
    #[Route('/api/templates/sample', name: 'api_template_sample', methods: ['GET'])]
    public function sample(SampleTemplate $sample): BinaryFileResponse
    {
        $response = $this->file($sample->write(), SampleTemplate::NAME);
        $response->headers->set('Content-Type', DocumentMerger::DOCX);

        return $response->deleteFileAfterSend();
    }

    /** Limits of the merge page. */
    #[Route('/api/documents/settings', name: 'api_document_settings', methods: ['GET'], priority: 10)]
    public function settings(DocumentMerger $merger): JsonResponse
    {
        return $this->json(['maxFileSize' => $merger->maxFileSize()]);
    }

    /**
     * Merges a template: multipart "template" (DOCX), "values" (JSON object: {name: text, section: [{field: text}]}),
     * optional "title". Answers 202 with the document, merged in the background ("merging", then "ready" or "failed").
     * Applications merge on behalf of a user (X-Impersonate-User).
     */
    #[Route('/api/documents', name: 'api_document_create', methods: ['POST'])]
    public function create(
        #[MapUploadedFile([new Assert\NotNull()])] UploadedFile $template,
        Request $request,
        DocumentMerger $merger,
        ActorContext $actor,
    ): JsonResponse {
        $values = json_decode($request->request->getString('values', '{}') ?: '{}', true);
        if (!\is_array($values) || array_is_list($values) && [] !== $values) {
            throw new UnprocessableEntityHttpException('"values" must be a JSON object.');
        }
        $document = $merger->create($template, $template->getClientOriginalName(), $values, $actor->requireUser(), $actor->getApplication(), $request->request->getString('title') ?: null);

        return $this->json($document, Response::HTTP_ACCEPTED, context: self::CONTEXT);
    }

    /** Current version of the document: DOCX (default) or PDF (?format=pdf); ?download=1 as an attachment. */
    #[Route('/api/documents/{id}/content', name: 'api_document_content', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function content(Document $document, Request $request, ActorContext $actor, DocumentStorage $storage, DocumentConverter $converter): Response
    {
        $this->denyUnlessOwner($document, $actor);
        if (!$document->isReady()) {
            return $this->json(['detail' => 'The document is not merged yet.'], Response::HTTP_CONFLICT);
        }
        $pdf = 'pdf' === $request->query->getString('format');
        try {
            $path = $pdf ? $converter->pdf($document) : $storage->versionPath($document);
        } catch (OnlyOfficeException $e) {
            return $this->json(['detail' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $pdf ? 'application/pdf' : DocumentMerger::DOCX);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");
        $name = str_replace(['/', '\\'], '_', $document->getTitle()).($pdf ? '.pdf' : '.docx');
        $fallback = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: ''), '_') ?: 'document';
        $inline = $pdf && !$request->query->getBoolean('download');
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, $fallback);

        return $response;
    }

    /** Replaces the content with a DOCX (raw body or multipart "file"): a new version; open editors start over. */
    #[Route('/api/documents/{id}/content', name: 'api_document_replace', requirements: ['id' => Requirement::UUID], methods: ['PUT', 'POST'])]
    public function replace(Document $document, Request $request, ActorContext $actor, DocumentMerger $merger, EntityManagerInterface $em): JsonResponse
    {
        $this->denyUnlessOwner($document, $actor);
        $file = $request->files->get('file');
        if ($file instanceof UploadedFile) {
            $merger->checkTemplate($file);
            $content = (string) file_get_contents($file->getPathname());
        } else {
            $content = $request->getContent();
            $tmp = tempnam(sys_get_temp_dir(), 'docx');
            file_put_contents($tmp, $content);
            try {
                $merger->checkTemplate(new File($tmp));
            } finally {
                @unlink($tmp);
            }
        }
        if ('' === $content || !str_starts_with($content, "PK\x03\x04")) {
            throw new UnprocessableEntityHttpException('The content must be a Word document (.docx).');
        }
        $merger->addVersion($document, $content);
        $document->renewEditorKey();
        $em->flush();

        return $this->json($document, context: self::CONTEXT);
    }

    /** Configuration of the ONLYOFFICE editor: ?mode=edit (the owner) or view. */
    #[Route('/api/documents/{id}/editor', name: 'api_document_editor', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function editor(Document $document, Request $request, ActorContext $actor, EditorConfig $editor): JsonResponse
    {
        $this->denyUnlessOwner($document, $actor);
        if (!$document->isReady()) {
            return $this->json(['detail' => 'The document is not merged yet.'], Response::HTTP_CONFLICT);
        }

        return $this->json($editor->for($document, $actor->requireUser(), 'view' !== $request->query->getString('mode', 'edit')));
    }

    /**
     * Asks the open editors to save now (before sending the document, for instance): the new version arrives through
     * the callback. 202, or 200 with "saved": false when nothing is being edited.
     */
    #[Route('/api/documents/{id}/force-save', name: 'api_document_force_save', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function forceSave(Document $document, ActorContext $actor, OnlyOfficeClient $onlyOffice): JsonResponse
    {
        $this->denyUnlessOwner($document, $actor);
        try {
            $onlyOffice->command('forcesave', ['key' => $document->getEditorKey()]);
        } catch (OnlyOfficeException) {
            // Error 4: no changes, or no editing session.
            return $this->json(['saved' => false, 'version' => $document->getVersion()]);
        }

        return $this->json(['saved' => true, 'version' => $document->getVersion()], Response::HTTP_ACCEPTED);
    }

    /** Documents are private: others do not see they exist. */
    private function denyUnlessOwner(Document $document, ActorContext $actor): void
    {
        if ($document->getOwner() !== $actor->requireUser()) {
            throw $this->createNotFoundException();
        }
    }
}
