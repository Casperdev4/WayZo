<?php

namespace App\Controller\Api;

use App\Entity\Ride;
use App\Entity\Groupe;
use App\Repository\RideRepository;
use App\Repository\GroupeRepository;
use App\Repository\GroupeMembreRepository;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/rides')]
class RideController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RideRepository $rideRepository,
        private GroupeRepository $groupeRepository,
        private GroupeMembreRepository $groupeMembreRepository,
        private ActivityLogService $activityLogService
    ) {}

    /**
     * Liste des courses (filtrées par visibilité)
     */
    #[Route('', name: 'api_rides_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $visibility = $request->query->get('visibility'); // 'public', 'groupe', 'all'
        $groupeId = $request->query->get('groupe_id');
        $status = $request->query->get('status');
        
        // Récupérer toutes les courses
        $qb = $this->rideRepository->createQueryBuilder('r')
            ->leftJoin('r.groupe', 'g')
            ->leftJoin('r.chauffeur', 'c')
            ->orderBy('r.date', 'DESC');
        
        // Filtre par statut
        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        
        // Filtre par groupe spécifique
        if ($groupeId) {
            $qb->andWhere('r.groupe = :groupeId')->setParameter('groupeId', $groupeId);
        }
        
        // Filtre par visibilité
        if ($visibility === 'public') {
            $qb->andWhere('r.visibility = :vis')->setParameter('vis', 'public');
        } elseif ($visibility === 'groupe') {
            $qb->andWhere('r.visibility = :vis')->setParameter('vis', 'groupe');
        }
        
        $rides = $qb->getQuery()->getResult();
        
        // Filtrer selon la visibilité pour l'utilisateur
        $visibleRides = array_filter($rides, function (Ride $ride) use ($user) {
            return $ride->isVisibleBy($user);
        });
        
        $data = array_map(function (Ride $ride) {
            return $this->serializeRide($ride);
        }, array_values($visibleRides));

        return new JsonResponse($data);
    }

    /**
     * Liste des courses disponibles pour le chauffeur connecté
     * Inclut les courses publiques + celles de ses groupes
     */
    #[Route('/available', name: 'api_rides_available', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function available(): JsonResponse
    {
        $user = $this->getUser();
        
        // Récupérer les IDs des groupes dont le chauffeur est membre
        $groupeIds = [];
        $memberships = $this->groupeMembreRepository->findBy(['chauffeur' => $user]);
        foreach ($memberships as $membership) {
            $groupeIds[] = $membership->getGroupe()->getId();
        }
        
        // Ajouter les groupes dont il est propriétaire
        $ownedGroups = $this->groupeRepository->findBy(['proprietaire' => $user]);
        foreach ($ownedGroups as $groupe) {
            if (!in_array($groupe->getId(), $groupeIds)) {
                $groupeIds[] = $groupe->getId();
            }
        }
        
        // Construire la requête
        $qb = $this->rideRepository->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', 'disponible');
        
        // Courses publiques OU courses de ses groupes
        if (!empty($groupeIds)) {
            $qb->andWhere('r.visibility = :public OR (r.visibility = :groupe AND r.groupe IN (:groupeIds))')
                ->setParameter('public', 'public')
                ->setParameter('groupe', 'groupe')
                ->setParameter('groupeIds', $groupeIds);
        } else {
            // Uniquement courses publiques si pas de groupe
            $qb->andWhere('r.visibility = :public')
                ->setParameter('public', 'public');
        }
        
        $qb->orderBy('r.date', 'ASC');
        
        $rides = $qb->getQuery()->getResult();
        
        $data = array_map(function (Ride $ride) use ($user) {
            return $this->serializeRide($ride, $user);
        }, $rides);

        return new JsonResponse($data);
    }

    /**
     * Liste des courses d'un groupe spécifique
     */
    #[Route('/groupe/{groupeId}', name: 'api_rides_by_groupe', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function byGroupe(int $groupeId): JsonResponse
    {
        $user = $this->getUser();
        $groupe = $this->groupeRepository->find($groupeId);
        
        if (!$groupe) {
            return new JsonResponse(['error' => 'Groupe non trouvé'], 404);
        }
        
        // Vérifier que l'utilisateur est membre du groupe
        $isMember = false;
        if ($groupe->getProprietaire() === $user) {
            $isMember = true;
        } else {
            foreach ($groupe->getMembres() as $membre) {
                if ($membre->getChauffeur() === $user) {
                    $isMember = true;
                    break;
                }
            }
        }
        
        if (!$isMember) {
            return new JsonResponse(['error' => 'Accès non autorisé'], 403);
        }
        
        $rides = $this->rideRepository->findBy(
            ['groupe' => $groupe, 'visibility' => 'groupe'],
            ['date' => 'DESC']
        );
        
        $data = array_map(function (Ride $ride) {
            return $this->serializeRide($ride);
        }, $rides);

        return new JsonResponse($data);
    }

    /**
     * Détail d'une course
     */
    #[Route('/{id}', name: 'api_rides_show', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(Ride $ride): JsonResponse
    {
        $user = $this->getUser();
        return new JsonResponse($this->serializeRide($ride, $user));
    }

    /**
     * Créer une course
     */
    #[Route('', name: 'api_rides_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $user = $this->getUser();

            $ride = new Ride();
            $ride->setClientName($data['clientName']);
            $ride->setClientContact($data['clientContact']);
            $ride->setDepart($data['depart']);
            $ride->setDestination($data['destination']);
            $ride->setDate(new \DateTime($data['date']));
            $ride->setTime(new \DateTime($data['time']));
            $ride->setPassengers((int) $data['passengers']);
            $ride->setLuggage((int) $data['luggage']);
            $ride->setVehicle($data['vehicle']);
            $ride->setBoosterSeat((int) $data['boosterSeat']);
            $ride->setBabySeat((int) $data['babySeat']);
            $ride->setPrice((float) $data['price']);
            $ride->setComment($data['comment'] ?? null);
            $ride->setStatus('disponible');
            
            // Associer le chauffeur connecté
            $ride->setChauffeur($user);

            // Gestion de la visibilité et du groupe
            $visibility = $data['visibility'] ?? 'public';
            $ride->setVisibility($visibility);
            
            // Si visibilité groupe, associer le groupe
            if ($visibility === 'groupe' && isset($data['groupeId'])) {
                $groupe = $this->groupeRepository->find($data['groupeId']);
                
                if (!$groupe) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Groupe non trouvé'
                    ], 404);
                }
                
                // Vérifier que l'utilisateur est membre du groupe
                $isMember = ($groupe->getProprietaire() === $user);
                if (!$isMember) {
                    foreach ($groupe->getMembres() as $membre) {
                        if ($membre->getChauffeur() === $user) {
                            $isMember = true;
                            break;
                        }
                    }
                }
                
                if (!$isMember) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Vous n\'êtes pas membre de ce groupe'
                    ], 403);
                }
                
                $ride->setGroupe($groupe);
            }

            $this->entityManager->persist($ride);
            $this->entityManager->flush();

            // 🔥 Logger l'activité
            $this->activityLogService->logRideCreated(
                $user, 
                $ride->getId(), 
                $ride->getDepart(), 
                $ride->getDestination()
            );

            return new JsonResponse([
                'success' => true,
                'message' => 'Course créée avec succès',
                'ride' => $this->serializeRide($ride)
            ], 201);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la création: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Modifier une course
     */
    #[Route('/{id}', name: 'api_rides_update', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function update(Ride $ride, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $user = $this->getUser();

            if (isset($data['clientName'])) $ride->setClientName($data['clientName']);
            if (isset($data['clientContact'])) $ride->setClientContact($data['clientContact']);
            if (isset($data['depart'])) $ride->setDepart($data['depart']);
            if (isset($data['destination'])) $ride->setDestination($data['destination']);
            if (isset($data['date'])) $ride->setDate(new \DateTime($data['date']));
            if (isset($data['time'])) $ride->setTime(new \DateTime($data['time']));
            if (isset($data['passengers'])) $ride->setPassengers((int) $data['passengers']);
            if (isset($data['luggage'])) $ride->setLuggage((int) $data['luggage']);
            if (isset($data['vehicle'])) $ride->setVehicle($data['vehicle']);
            if (isset($data['boosterSeat'])) $ride->setBoosterSeat((int) $data['boosterSeat']);
            if (isset($data['babySeat'])) $ride->setBabySeat((int) $data['babySeat']);
            if (isset($data['price'])) $ride->setPrice((float) $data['price']);
            if (isset($data['comment'])) $ride->setComment($data['comment']);
            if (isset($data['status'])) $ride->setStatus($data['status']);
            
            // Gestion de la visibilité et du groupe
            if (isset($data['visibility'])) {
                $ride->setVisibility($data['visibility']);
                
                if ($data['visibility'] === 'groupe' && isset($data['groupeId'])) {
                    $groupe = $this->groupeRepository->find($data['groupeId']);
                    if ($groupe) {
                        // Vérifier l'appartenance au groupe
                        $isMember = ($groupe->getProprietaire() === $user);
                        if (!$isMember) {
                            foreach ($groupe->getMembres() as $membre) {
                                if ($membre->getChauffeur() === $user) {
                                    $isMember = true;
                                    break;
                                }
                            }
                        }
                        if ($isMember) {
                            $ride->setGroupe($groupe);
                        }
                    }
                } elseif ($data['visibility'] === 'public') {
                    $ride->setGroupe(null);
                }
            }

            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Course mise à jour',
                'ride' => $this->serializeRide($ride)
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Supprimer une course
     */
    #[Route('/{id}', name: 'api_rides_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Ride $ride): JsonResponse
    {
        try {
            $this->entityManager->remove($ride);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Course supprimée'
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Accepter une course (par un autre chauffeur)
     */
    #[Route('/{id}/accept', name: 'api_rides_accept', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function accept(Ride $ride): JsonResponse
    {
        try {
            $user = $this->getUser();
            
            // Vérifier que ce n'est pas le même chauffeur
            if ($ride->getChauffeur() === $user) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas accepter votre propre course'
                ], 400);
            }

            $ride->setChauffeurAccepteur($user);
            $ride->setStatus('acceptée');
            
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Course acceptée',
                'ride' => $this->serializeRide($ride)
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Mettre à jour le statut d'une course
     */
    #[Route('/{id}/status', name: 'api_rides_update_status', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function updateStatus(Ride $ride, Request $request): JsonResponse
    {
        try {
            $user = $this->getUser();
            $data = json_decode($request->getContent(), true);
            $newStatus = $data['status'] ?? null;
            
            // Vérifier que l'utilisateur est le chauffeur accepteur
            if ($ride->getChauffeurAccepteur() !== $user) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Vous n\'êtes pas autorisé à modifier cette course'
                ], 403);
            }
            
            // Valider le statut
            $validStatuses = ['acceptée', 'en_cours', 'terminée', 'annulée'];
            if (!in_array($newStatus, $validStatuses)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Statut invalide'
                ], 400);
            }
            
            $ride->setStatus($newStatus);
            $this->entityManager->flush();
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Statut mis à jour',
                'ride' => $this->serializeRide($ride, $user)
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Sérialiser une course en tableau
     */
    private function serializeRide(Ride $ride, $currentUser = null): array
    {
        $isOwner = $currentUser && $ride->getChauffeur() && $ride->getChauffeur()->getId() === $currentUser->getId();
        
        return [
            'id' => $ride->getId(),
            'clientName' => $ride->getClientName(),
            'clientContact' => $ride->getClientContact(),
            'depart' => $ride->getDepart(),
            'destination' => $ride->getDestination(),
            'date' => $ride->getDate()?->format('Y-m-d'),
            'time' => $ride->getTime()?->format('H:i'),
            'passengers' => $ride->getPassengers(),
            'luggage' => $ride->getLuggage(),
            'vehicle' => $ride->getVehicle(),
            'boosterSeat' => $ride->getBoosterSeat(),
            'babySeat' => $ride->getBabySeat(),
            'price' => $ride->getPrice(),
            'comment' => $ride->getComment(),
            'status' => $ride->getStatus(),
            'statusVendeur' => $ride->getStatusVendeur(),
            'visibility' => $ride->getVisibility(),
            'isOwner' => $isOwner,
            'groupe' => $ride->getGroupe() ? [
                'id' => $ride->getGroupe()->getId(),
                'nom' => $ride->getGroupe()->getNom(),
            ] : null,
            'chauffeur' => $ride->getChauffeur() ? [
                'id' => $ride->getChauffeur()->getId(),
                'name' => $ride->getChauffeur()->getPrenom() . ' ' . $ride->getChauffeur()->getNom(),
            ] : null,
            'chauffeurAccepteur' => $ride->getChauffeurAccepteur() ? [
                'id' => $ride->getChauffeurAccepteur()->getId(),
                'name' => $ride->getChauffeurAccepteur()->getPrenom() . ' ' . $ride->getChauffeurAccepteur()->getNom(),
            ] : null,
        ];
    }
}
