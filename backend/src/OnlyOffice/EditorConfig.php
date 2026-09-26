<?php

namespace App\OnlyOffice;

use App\Entity\Document;
use Rocket\Core\Entity\User;

/**
 * Configuration of the ONLYOFFICE editor for a document (DocsAPI.DocEditor), signed: "view" to read it, "edit" for its
 * owner. The editor downloads the current version and saves through the callback (new version).
 */
final class EditorConfig
{
    public function __construct(
        private readonly OnlyOfficeUrls $urls,
        private readonly OnlyOfficeJwt $jwt,
    ) {
    }

    /** @return array{scriptUrl: string, config: array<string, mixed>} */
    public function for(Document $document, User $user, bool $edit): array
    {
        $edit = $edit && $document->getOwner() === $user;
        $config = [
            'document' => [
                'fileType' => 'docx',
                'key' => $document->getEditorKey(),
                'title' => $document->getTitle().'.docx',
                'url' => $this->urls->version($document, $document->getVersion()),
                'permissions' => ['edit' => $edit, 'download' => true, 'print' => true, 'review' => $edit, 'comment' => $edit],
            ],
            'documentType' => 'word',
            'editorConfig' => [
                'mode' => $edit ? 'edit' : 'view',
                'lang' => 'fr',
                'region' => 'fr-FR',
                'callbackUrl' => $this->urls->callback($document),
                'user' => ['id' => $user->getId()->toRfc4122(), 'name' => trim($user->getFirstName().' '.$user->getLastName()) ?: $user->getEmail()],
                'customization' => ['forcesave' => true, 'autosave' => true, 'compactHeader' => true, 'hideRightMenu' => true],
            ],
        ];
        if ($this->jwt->isEnabled()) {
            $config['token'] = $this->jwt->sign($config);
        }

        return ['scriptUrl' => $this->urls->editorScript(), 'config' => $config];
    }
}
