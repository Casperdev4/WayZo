<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251205183114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE escrow_payment (id INT AUTO_INCREMENT NOT NULL, ride_id INT NOT NULL, seller_id INT NOT NULL, buyer_id INT DEFAULT NULL, ride_amount NUMERIC(10, 2) NOT NULL, commission_amount NUMERIC(10, 2) NOT NULL, total_amount NUMERIC(10, 2) NOT NULL, status VARCHAR(30) NOT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, stripe_transfer_id VARCHAR(255) DEFAULT NULL, stripe_refund_id VARCHAR(255) DEFAULT NULL, held_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', marked_completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', validation_deadline DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', confirmed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', paid_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', refunded_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', refunded_amount NUMERIC(10, 2) DEFAULT NULL, compensation_amount NUMERIC(10, 2) DEFAULT NULL, cancel_reason VARCHAR(50) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_EF1596FB302A8A70 (ride_id), INDEX IDX_EF1596FB8DE820D9 (seller_id), INDEX IDX_EF1596FB6C755722 (buyer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE escrow_payment ADD CONSTRAINT FK_EF1596FB302A8A70 FOREIGN KEY (ride_id) REFERENCES ride (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE escrow_payment ADD CONSTRAINT FK_EF1596FB8DE820D9 FOREIGN KEY (seller_id) REFERENCES chauffeur (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE escrow_payment ADD CONSTRAINT FK_EF1596FB6C755722 FOREIGN KEY (buyer_id) REFERENCES chauffeur (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ride ADD payment_status VARCHAR(30) DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            ALTER TABLE escrow_payment DROP FOREIGN KEY FK_EF1596FB302A8A70
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE escrow_payment DROP FOREIGN KEY FK_EF1596FB8DE820D9
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE escrow_payment DROP FOREIGN KEY FK_EF1596FB6C755722
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE escrow_payment
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ride DROP payment_status
        SQL);
    }
}
