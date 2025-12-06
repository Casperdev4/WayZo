<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Chauffeur;
use App\Entity\Ride;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    /**
     * Récupère les conversations d'un utilisateur
     */
    public function findByUser(Chauffeur $user): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.chauffeur1 = :user OR c.chauffeur2 = :user')
            ->setParameter('user', $user)
            ->orderBy('c.lastMessageAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve une conversation entre deux chauffeurs pour une course
     */
    public function findByRideAndParticipants(Ride $ride, Chauffeur $chauffeur1, Chauffeur $chauffeur2): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->where('c.ride = :ride')
            ->andWhere('(c.chauffeur1 = :c1 AND c.chauffeur2 = :c2) OR (c.chauffeur1 = :c2 AND c.chauffeur2 = :c1)')
            ->setParameter('ride', $ride)
            ->setParameter('c1', $chauffeur1)
            ->setParameter('c2', $chauffeur2)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve une conversation par course
     */
    public function findByRide(Ride $ride): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->where('c.ride = :ride')
            ->setParameter('ride', $ride)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve une conversation directe (sans ride) entre deux chauffeurs
     */
    public function findDirectConversationBetween(Chauffeur $chauffeur1, Chauffeur $chauffeur2): ?Conversation
    {
        return $this->createQueryBuilder('c')
            ->where('c.ride IS NULL')
            ->andWhere('(c.chauffeur1 = :c1 AND c.chauffeur2 = :c2) OR (c.chauffeur1 = :c2 AND c.chauffeur2 = :c1)')
            ->setParameter('c1', $chauffeur1)
            ->setParameter('c2', $chauffeur2)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
