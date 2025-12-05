<?php

namespace App\Repository;

use App\Entity\EscrowPayment;
use App\Entity\Chauffeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EscrowPayment>
 */
class EscrowPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EscrowPayment::class);
    }

    /**
     * Trouve les paiements en attente de validation dont la deadline est dépassée
     * (pour la validation automatique)
     */
    public function findExpiredValidations(): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.status = :status')
            ->andWhere('e.validationDeadline < :now')
            ->setParameter('status', EscrowPayment::STATUS_AWAITING_VALIDATION)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les paiements escrow d'un chauffeur (en tant que vendeur)
     */
    public function findBySeller(Chauffeur $chauffeur): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.seller = :chauffeur')
            ->orderBy('e.createdAt', 'DESC')
            ->setParameter('chauffeur', $chauffeur)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les paiements escrow d'un chauffeur (en tant qu'acheteur/exécutant)
     */
    public function findByBuyer(Chauffeur $chauffeur): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.buyer = :chauffeur')
            ->orderBy('e.createdAt', 'DESC')
            ->setParameter('chauffeur', $chauffeur)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les paiements en attente de validation pour un chauffeur (vendeur)
     */
    public function findAwaitingValidationBySeller(Chauffeur $chauffeur): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.seller = :chauffeur')
            ->andWhere('e.status = :status')
            ->setParameter('chauffeur', $chauffeur)
            ->setParameter('status', EscrowPayment::STATUS_AWAITING_VALIDATION)
            ->orderBy('e.validationDeadline', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcule le total des commissions WayZo sur une période
     */
    public function getTotalCommissions(\DateTimeInterface $from, \DateTimeInterface $to): float
    {
        $result = $this->createQueryBuilder('e')
            ->select('SUM(e.commissionAmount) as total')
            ->where('e.status = :status')
            ->andWhere('e.paidAt BETWEEN :from AND :to')
            ->setParameter('status', EscrowPayment::STATUS_COMPLETED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Statistiques des paiements
     */
    public function getStats(): array
    {
        $qb = $this->createQueryBuilder('e');
        
        $stats = $qb
            ->select([
                'COUNT(e.id) as total',
                'SUM(CASE WHEN e.status = :held THEN 1 ELSE 0 END) as held',
                'SUM(CASE WHEN e.status = :awaiting THEN 1 ELSE 0 END) as awaiting',
                'SUM(CASE WHEN e.status = :completed THEN 1 ELSE 0 END) as completed',
                'SUM(CASE WHEN e.status = :refunded THEN 1 ELSE 0 END) as refunded',
                'SUM(CASE WHEN e.status = :disputed THEN 1 ELSE 0 END) as disputed',
                'SUM(e.totalAmount) as totalVolume',
                'SUM(CASE WHEN e.status = :completed THEN e.commissionAmount ELSE 0 END) as totalCommissions'
            ])
            ->setParameter('held', EscrowPayment::STATUS_HELD)
            ->setParameter('awaiting', EscrowPayment::STATUS_AWAITING_VALIDATION)
            ->setParameter('completed', EscrowPayment::STATUS_COMPLETED)
            ->setParameter('refunded', EscrowPayment::STATUS_REFUNDED)
            ->setParameter('disputed', EscrowPayment::STATUS_DISPUTED)
            ->getQuery()
            ->getSingleResult();

        return $stats;
    }
}
