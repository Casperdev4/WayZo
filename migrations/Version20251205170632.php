<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251205170632 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE ride_report (id INT AUTO_INCREMENT NOT NULL, ride_id INT NOT NULL, reporter_id INT NOT NULL, type VARCHAR(50) NOT NULL, description LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL, admin_response LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, INDEX IDX_D9CF446A302A8A70 (ride_id), INDEX IDX_D9CF446AE1CFE6F5 (reporter_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ride_report ADD CONSTRAINT FK_D9CF446A302A8A70 FOREIGN KEY (ride_id) REFERENCES ride (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ride_report ADD CONSTRAINT FK_D9CF446AE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES chauffeur (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE ride_report DROP FOREIGN KEY FK_D9CF446A302A8A70
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ride_report DROP FOREIGN KEY FK_D9CF446AE1CFE6F5
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE ride_report
        SQL);
    }
}
