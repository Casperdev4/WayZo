<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251205175747 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_method (id INT AUTO_INCREMENT NOT NULL, chauffeur_id INT NOT NULL, type VARCHAR(20) NOT NULL, label VARCHAR(100) DEFAULT NULL, card_type VARCHAR(20) DEFAULT NULL, card_holder_name VARCHAR(100) DEFAULT NULL, card_last4 VARCHAR(4) DEFAULT NULL, card_exp_month VARCHAR(2) DEFAULT NULL, card_exp_year VARCHAR(2) DEFAULT NULL, bank_name VARCHAR(100) DEFAULT NULL, iban VARCHAR(34) DEFAULT NULL, bic VARCHAR(11) DEFAULT NULL, account_holder_name VARCHAR(100) DEFAULT NULL, is_default TINYINT(1) NOT NULL, is_active TINYINT(1) NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_7B61A1F685C0B3BE (chauffeur_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_method ADD CONSTRAINT FK_7B61A1F685C0B3BE FOREIGN KEY (chauffeur_id) REFERENCES chauffeur (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE payment_method DROP FOREIGN KEY FK_7B61A1F685C0B3BE
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE payment_method
        SQL);
    }
}
