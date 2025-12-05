<?php

namespace App\Repository;

use App\Entity\Chauffeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Chauffeur>
 */
class ChauffeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Chauffeur::class);
    }

    /**
     * Récupérer tous les tokens FCM valides
     * @return string[]
     */
    public function getAllFcmTokens(): array
    {
        $result = $this->createQueryBuilder('c')
            ->select('c.fcmToken')
            ->where('c.fcmToken IS NOT NULL')
            ->andWhere('c.status = :status')
            ->setParameter('status', Chauffeur::STATUS_ACTIVE)
            ->getQuery()
            ->getResult();

        return array_column($result, 'fcmToken');
    }

    /**
     * Récupérer les chauffeurs avec tokens FCM
     * @return Chauffeur[]
     */
    public function findWithFcmTokens(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.fcmToken IS NOT NULL')
            ->andWhere('c.status = :status')
            ->setParameter('status', Chauffeur::STATUS_ACTIVE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupérer les tokens FCM par plateforme
     * @return string[]
     */
    public function getFcmTokensByPlatform(string $platform): array
    {
        $result = $this->createQueryBuilder('c')
            ->select('c.fcmToken')
            ->where('c.fcmToken IS NOT NULL')
            ->andWhere('c.fcmPlatform = :platform')
            ->andWhere('c.status = :status')
            ->setParameter('platform', $platform)
            ->setParameter('status', Chauffeur::STATUS_ACTIVE)
            ->getQuery()
            ->getResult();

        return array_column($result, 'fcmToken');
    }
}
