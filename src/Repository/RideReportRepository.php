<?php

namespace App\Repository;

use App\Entity\RideReport;
use App\Entity\Chauffeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RideReport>
 */
class RideReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RideReport::class);
    }

    /**
     * Récupère tous les signalements en attente (pour admin)
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', RideReport::STATUS_PENDING)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les signalements d'un chauffeur
     */
    public function findByReporter(Chauffeur $chauffeur): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.reporter = :chauffeur')
            ->setParameter('chauffeur', $chauffeur)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère les signalements pour une course
     */
    public function findByRide(int $rideId): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.ride = :rideId')
            ->setParameter('rideId', $rideId)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les signalements par statut
     */
    public function countByStatus(): array
    {
        $results = $this->createQueryBuilder('r')
            ->select('r.status, COUNT(r.id) as count')
            ->groupBy('r.status')
            ->getQuery()
            ->getResult();

        $counts = [
            'pending' => 0,
            'reviewed' => 0,
            'resolved' => 0,
            'rejected' => 0,
        ];

        foreach ($results as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }
}
