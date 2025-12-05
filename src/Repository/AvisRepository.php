<?php

namespace App\Repository;

use App\Entity\Avis;
use App\Entity\Chauffeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Avis>
 *
 * @method Avis|null find($id, $lockMode = null, $lockVersion = null)
 * @method Avis|null findOneBy(array $criteria, array $orderBy = null)
 * @method Avis[]    findAll()
 * @method Avis[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AvisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Avis::class);
    }

    /**
     * Calcule la note moyenne d'un chauffeur
     */
    public function getAverageRating(Chauffeur $chauffeur): ?float
    {
        $result = $this->createQueryBuilder('a')
            ->select('AVG(a.note) as avgNote')
            ->where('a.chauffeurNote = :chauffeur')
            ->setParameter('chauffeur', $chauffeur)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? round((float) $result, 1) : null;
    }

    /**
     * Compte le nombre d'avis reçus par un chauffeur
     */
    public function countAvisForChauffeur(Chauffeur $chauffeur): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.chauffeurNote = :chauffeur')
            ->setParameter('chauffeur', $chauffeur)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère les avis d'un chauffeur avec pagination
     */
    public function findByChaufffeurPaginated(Chauffeur $chauffeur, int $page = 1, int $limit = 10): array
    {
        $offset = ($page - 1) * $limit;

        $avis = $this->createQueryBuilder('a')
            ->where('a.chauffeurNote = :chauffeur')
            ->setParameter('chauffeur', $chauffeur)
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $total = $this->countAvisForChauffeur($chauffeur);

        return [
            'avis' => $avis,
            'total' => $total,
            'page' => $page,
            'totalPages' => ceil($total / $limit),
        ];
    }

    /**
     * Récupère la distribution des notes pour un chauffeur
     */
    public function getRatingDistribution(Chauffeur $chauffeur): array
    {
        $results = $this->createQueryBuilder('a')
            ->select('a.note, COUNT(a.id) as count')
            ->where('a.chauffeurNote = :chauffeur')
            ->setParameter('chauffeur', $chauffeur)
            ->groupBy('a.note')
            ->orderBy('a.note', 'DESC')
            ->getQuery()
            ->getResult();

        // Initialiser avec toutes les notes de 1 à 5
        $distribution = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        
        foreach ($results as $row) {
            $distribution[(int)$row['note']] = (int)$row['count'];
        }

        return $distribution;
    }

    /**
     * Vérifie si un avis existe déjà pour une course
     */
    public function existsForCourse(int $courseId): bool
    {
        return (bool) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->join('a.course', 'c')
            ->where('c.id = :courseId')
            ->setParameter('courseId', $courseId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifie si un avis existe déjà pour une ride
     */
    public function existsForRide(int $rideId): bool
    {
        return (bool) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.ride = :rideId')
            ->setParameter('rideId', $rideId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Vérifie si un chauffeur a déjà noté un autre pour une ride
     */
    public function existsForRideAndAuteur(int $rideId, int $auteurId): bool
    {
        return (bool) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.ride = :rideId')
            ->andWhere('a.auteur = :auteurId')
            ->setParameter('rideId', $rideId)
            ->setParameter('auteurId', $auteurId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère les derniers avis de la plateforme
     */
    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcule les statistiques globales des avis
     */
    public function getGlobalStats(): array
    {
        $result = $this->createQueryBuilder('a')
            ->select('AVG(a.note) as avgNote, COUNT(a.id) as totalAvis')
            ->getQuery()
            ->getSingleResult();

        return [
            'averageRating' => $result['avgNote'] ? round((float)$result['avgNote'], 1) : 0,
            'totalAvis' => (int)$result['totalAvis'],
        ];
    }
}
