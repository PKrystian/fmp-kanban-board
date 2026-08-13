<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260813090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_column ADD INDEX IDX_BOARD_COLUMN_BOARD_POSITION (board_id, position), DROP INDEX IDX_D14DC3D9E7EC5785');
        $this->addSql('ALTER TABLE card ADD INDEX IDX_CARD_COLUMN_ARCHIVED_POSITION (column_id, archived_at, position), DROP INDEX IDX_161498D3BE8E8ED5');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board_column ADD INDEX IDX_D14DC3D9E7EC5785 (board_id), DROP INDEX IDX_BOARD_COLUMN_BOARD_POSITION');
        $this->addSql('ALTER TABLE card ADD INDEX IDX_161498D3BE8E8ED5 (column_id), DROP INDEX IDX_CARD_COLUMN_ARCHIVED_POSITION');
    }
}
