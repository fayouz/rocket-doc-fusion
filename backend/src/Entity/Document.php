<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Enum\DocumentStatus;
use App\Repository\DocumentRepository;
use App\State\DocumentDeleteProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rocket\Core\Entity\Application;
use Rocket\Core\Entity\TrackedTrait;
use Rocket\Core\Entity\User;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A merged document: a DOCX template filled with values by ONLYOFFICE (POST /api/documents, merged by the worker),
 * then edited in the ONLYOFFICE editor. Every save is a new version; the template is kept.
 * Documents are private: only their owner sees them.
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Index(name: 'idx_document_owner_updated', columns: ['owner_id', 'updated_at'])]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/documents'),
        new Get(uriTemplate: '/documents/{id}', security: 'object.getOwner() == user'),
        new Delete(uriTemplate: '/documents/{id}', security: 'object.getOwner() == user', processor: DocumentDeleteProcessor::class),
    ],
    normalizationContext: ['groups' => ['document:read', 'tracking']],
    security: "is_granted('ROLE_USER')",
    order: ['updatedAt' => 'DESC'],
    paginationClientItemsPerPage: true,
)]
#[ApiFilter(SearchFilter::class, properties: ['status' => 'exact', 'title' => 'ipartial'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'updatedAt', 'title'])]
class Document
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['document:read'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** The application that created it, on behalf of the owner. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Application $application = null;

    /** Name of the document, without extension. */
    #[ORM\Column(length: 255)]
    #[Groups(['document:read'])]
    private string $title;

    /** File name of the template, as uploaded. */
    #[ORM\Column(length: 255)]
    #[Groups(['document:read'])]
    private string $templateName;

    /** @var array<string, mixed> values given to the merge */
    #[ORM\Column(name: 'merge_values', type: Types::JSON)]
    #[Groups(['document:read'])]
    private array $values;

    #[ORM\Column(length: 16, enumType: DocumentStatus::class)]
    #[Groups(['document:read'])]
    private DocumentStatus $status = DocumentStatus::Merging;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['document:read'])]
    private ?string $error = null;

    /** 0 while merging, 1 once merged, then one more per save in the editor (or replacement of the content). */
    #[ORM\Column]
    #[Groups(['document:read'])]
    private int $version = 0;

    /** Size of the current version (bytes). */
    #[ORM\Column(nullable: true)]
    #[Groups(['document:read'])]
    private ?int $size = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256 = null;

    /**
     * Key of the document for ONLYOFFICE: the editing sessions of a key share their changes. It changes when a
     * session ends with a save (callback status 2) or when the content is replaced, never during a session.
     */
    #[ORM\Column(length: 128)]
    private string $editorKey;

    /** Last save from the editor. */
    #[ORM\Column(nullable: true)]
    #[Groups(['document:read'])]
    private ?\DateTimeImmutable $editedAt = null;

    #[ORM\Column(length: 80, unique: true)]
    private string $storageKey;

    use TrackedTrait;

    /** @param array<string, mixed> $values */
    public function __construct(User $owner, string $title, string $templateName, array $values)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->title = $title;
        $this->templateName = $templateName;
        $this->values = $values;
        $this->storageKey = substr((string) $this->id, 0, 2).'/'.$this->id;
        $this->renewEditorKey();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    #[Groups(['document:read'])]
    public function getOwnerEmail(): string
    {
        return $this->owner->getEmail();
    }

    public function getApplication(): ?Application
    {
        return $this->application;
    }

    public function setApplication(?Application $application): static
    {
        $this->application = $application;

        return $this;
    }

    #[Groups(['document:read'])]
    public function getApplicationName(): ?string
    {
        return $this->application?->getName();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getTemplateName(): string
    {
        return $this->templateName;
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getStatus(): DocumentStatus
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function getEditorKey(): string
    {
        return $this->editorKey;
    }

    public function getEditedAt(): ?\DateTimeImmutable
    {
        return $this->editedAt;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    #[Groups(['document:read'])]
    public function isReady(): bool
    {
        return DocumentStatus::Ready === $this->status;
    }

    /** A new version of the content: the merge result (version 1), a save from the editor, a replacement. */
    public function addVersion(int $size, string $sha256): int
    {
        $this->status = DocumentStatus::Ready;
        $this->error = null;
        $this->size = $size;
        $this->sha256 = $sha256;

        return ++$this->version;
    }

    public function markEdited(\DateTimeImmutable $at): void
    {
        $this->editedAt = $at;
    }

    public function markFailed(string $error): void
    {
        $this->status = DocumentStatus::Failed;
        $this->error = mb_substr($error, 0, 2000);
    }

    /** Next editing sessions start from the current version. */
    public function renewEditorKey(): void
    {
        $this->editorKey = str_replace('-', '', (string) $this->id).'-'.bin2hex(random_bytes(6));
    }
}
