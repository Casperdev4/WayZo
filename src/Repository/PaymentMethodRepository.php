<?php

namespace App\Repository;

use App\Entity\PaymentMethod;
use App\Entity\Chauffeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentMethod>
 */
class PaymentMethodRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentMethod::class);
    }

    /**
     * Trouve tous les moyens de paiement actifs d'un chauffeur
     */
    public function findActiveByChauffeur(Chauffeur $chauffeur): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.chauffeur = :chauffeur')
            ->andWhere('p.isActive = true')
            ->setParameter('chauffeur', $chauffeur)
            ->orderBy('p.isDefault', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve le moyen de paiement par défaut d'un chauffeur
     */
    public function findDefaultByChauffeur(Chauffeur $chauffeur): ?PaymentMethod
    {
        return $this->createQueryBuilder('p')
            ->where('p.chauffeur = :chauffeur')
            ->andWhere('p.isActive = true')
            ->andWhere('p.isDefault = true')
            ->setParameter('chauffeur', $chauffeur)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve tous les comptes bancaires d'un chauffeur
     */
    public function findBankAccountsByChauffeur(Chauffeur $chauffeur): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.chauffeur = :chauffeur')
            ->andWhere('p.type = :type')
            ->andWhere('p.isActive = true')
            ->setParameter('chauffeur', $chauffeur)
            ->setParameter('type', PaymentMethod::TYPE_BANK_ACCOUNT)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve toutes les cartes bancaires d'un chauffeur
     */
    public function findCardsByChauffeur(Chauffeur $chauffeur): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.chauffeur = :chauffeur')
            ->andWhere('p.type = :type')
            ->andWhere('p.isActive = true')
            ->setParameter('chauffeur', $chauffeur)
            ->setParameter('type', PaymentMethod::TYPE_CARD)
            ->getQuery()
            ->getResult();
    }
}
