<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251205165944 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE avis ADD ride_id INT DEFAULT NULL, CHANGE course_id course_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE avis ADD CONSTRAINT FK_8F91ABF0302A8A70 FOREIGN KEY (ride_id) REFERENCES ride (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_8F91ABF0302A8A70 ON avis (ride_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE avis DROP FOREIGN KEY FK_8F91ABF0302A8A70
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_8F91ABF0302A8A70 ON avis
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE avis DROP ride_id, CHANGE course_id course_id INT NOT NULL
        SQL);
    }
}
