<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260926120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merged documents';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document (id UUID NOT NULL, title VARCHAR(255) NOT NULL, template_name VARCHAR(255) NOT NULL, merge_values JSON NOT NULL, status VARCHAR(16) NOT NULL, error TEXT DEFAULT NULL, version INT NOT NULL, size INT DEFAULT NULL, sha256 VARCHAR(64) DEFAULT NULL, editor_key VARCHAR(128) NOT NULL, edited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, storage_key VARCHAR(80) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by VARCHAR(180) DEFAULT NULL, updated_by VARCHAR(180) DEFAULT NULL, owner_id UUID NOT NULL, application_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D8698A76111795A5 ON document (storage_key)');
        $this->addSql('CREATE INDEX idx_document_owner_updated ON document (owner_id, updated_at)');
        $this->addSql('CREATE INDEX IDX_D8698A767E3C61F9 ON document (owner_id)');
        $this->addSql('CREATE INDEX IDX_D8698A763E030ACD ON document (application_id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A767E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT FK_D8698A763E030ACD FOREIGN KEY (application_id) REFERENCES application (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP CONSTRAINT FK_D8698A767E3C61F9');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT FK_D8698A763E030ACD');
        $this->addSql('DROP TABLE document');
    }
}
