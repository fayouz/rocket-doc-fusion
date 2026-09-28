<?php

namespace App\Controller;

use App\Entity\Document;
use App\Fusion\DocumentMerger;
use App\Fusion\DocumentStorage;
use App\Fusion\MergeScript;
use App\OnlyOffice\OnlyOfficeClient;
use App\OnlyOffice\OnlyOfficeException;
use App\OnlyOffice\OnlyOfficeJwt;
use App\OnlyOffice\OnlyOfficeUrls;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Called by ONLYOFFICE Docs, without a user token: every address is signed (OnlyOfficeUrls), and the callback also
 * carries the JWT of ONLYOFFICE.
 */
final class OnlyOfficeController extends AbstractController
{
    public function __construct(private readonly UriSigner $signer)
    {
    }

    #[Route('/api/onlyoffice/documents/{id}/template', name: 'onlyoffice_template', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function template(Document $document, Request $request, DocumentStorage $storage): BinaryFileResponse
    {
        $this->denyUnlessSigned($request);

        return $this->file($storage->templatePath($document), 'template.docx')->setPrivate();
    }

    #[Route('/api/onlyoffice/documents/{id}/merge.js', name: 'onlyoffice_merge_script', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function mergeScript(Document $document, Request $request, MergeScript $script, OnlyOfficeUrls $urls): Response
    {
        $this->denyUnlessSigned($request);

        return new Response($script->generate($urls->template($document)), headers: ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    #[Route('/api/onlyoffice/documents/{id}/versions/{version}', name: 'onlyoffice_version', requirements: ['id' => Requirement::UUID, 'version' => '\d+'], methods: ['GET'])]
    public function version(Document $document, int $version, Request $request, DocumentStorage $storage): BinaryFileResponse
    {
        $this->denyUnlessSigned($request);
        $path = $storage->versionPath($document, $version);
        if (!is_file($path)) {
            throw $this->createNotFoundException();
        }
        $response = $this->file($path, 'document.docx');
        $response->headers->set('Content-Type', DocumentMerger::DOCX);

        return $response->setPrivate();
    }

    /**
     * Editor callback: status 2 (every editor closed, changes to save) and 6 (force save) give the address of the
     * saved file, stored as a new version. After status 2, the next sessions use a new key.
     */
    #[Route('/api/onlyoffice/documents/{id}/callback', name: 'onlyoffice_callback', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function callback(
        Document $document,
        Request $request,
        OnlyOfficeJwt $jwt,
        OnlyOfficeClient $onlyOffice,
        DocumentMerger $merger,
        EntityManagerInterface $em,
        ClockInterface $clock,
        LoggerInterface $logger,
    ): JsonResponse {
        $this->denyUnlessSigned($request);
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            return $this->json(['error' => 1, 'message' => 'Invalid body.'], Response::HTTP_BAD_REQUEST);
        }
        if ($jwt->isEnabled()) {
            $token = $body['token'] ?? (string) preg_replace('/^Bearer\s+/i', '', $request->headers->get('Authorization', ''));
            try {
                $body = $jwt->verify((string) $token);
            } catch (\UnexpectedValueException $e) {
                return $this->json(['error' => 1, 'message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
            }
            // Older versions wrap the body in "payload".
            $body = \is_array($body['payload'] ?? null) ? $body['payload'] : $body;
        }
        $status = (int) ($body['status'] ?? 0);
        if (!\in_array($status, [2, 6], true)) {
            if (\in_array($status, [3, 7], true)) {
                $logger->warning('ONLYOFFICE could not save document {document} (status {status}).', ['document' => $document->getId(), 'status' => $status]);
            }

            return $this->json(['error' => 0]);
        }
        if (($body['key'] ?? null) !== $document->getEditorKey() || !\is_string($body['url'] ?? null)) {
            return $this->json(['error' => 1, 'message' => 'Unknown editing session.'], Response::HTTP_CONFLICT);
        }
        try {
            $merger->addVersion($document, $onlyOffice->download($body['url']));
        } catch (OnlyOfficeException $e) {
            $logger->error('Saving document {document} failed: {error}', ['document' => $document->getId(), 'error' => $e->getMessage()]);

            return $this->json(['error' => 1, 'message' => $e->getMessage()]);
        }
        $document->markEdited($clock->now());
        if (2 === $status) {
            $document->renewEditorKey();
        }
        $em->flush();

        return $this->json(['error' => 0]);
    }

    private function denyUnlessSigned(Request $request): void
    {
        if (!$this->signer->checkRequest($request)) {
            // Not an access denied of the security layer: that would answer 401 and ask for a user token.
            throw new AccessDeniedHttpException('Invalid or expired signature.');
        }
    }
}
