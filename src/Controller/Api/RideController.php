<?php

namespace App\Controller\Api;

use App\Entity\Ride;
use App\Entity\Groupe;
use App\Entity\Notification;
use App\Entity\Conversation;
use App\Entity\RideReport;
use App\Repository\RideRepository;
use App\Repository\GroupeRepository;
use App\Repository\GroupeMembreRepository;
use App\Repository\ConversationRepository;
use App\Repository\AvisRepository;
use App\Repository\RideReportRepository;
use App\Service\ActivityLogService;
use App\Service\GeocodingService;
use App\Service\EscrowService;
use App\Service\MercureService;
use App\Service\PushNotificationService;
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
        private ConversationRepository $conversationRepository,
        private ActivityLogService $activityLogService,
        private GeocodingService $geocodingService,
        private AvisRepository $avisRepository,
        private RideReportRepository $rideReportRepository,
        private EscrowService $escrowService,
        private MercureService $mercureService,
        private PushNotificationService $pushNotificationService
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

            // Géocoder les adresses pour obtenir les coordonnées GPS
            $coords = $this->geocodingService->geocodeRide($ride->getDepart(), $ride->getDestination());
            
            if ($coords['departure']) {
                $ride->setDepartLat($coords['departure']['lat']);
                $ride->setDepartLng($coords['departure']['lng']);
            }
            
            if ($coords['arrival']) {
                $ride->setDestinationLat($coords['arrival']['lat']);
                $ride->setDestinationLng($coords['arrival']['lng']);
            }

            $this->entityManager->persist($ride);
            $this->entityManager->flush();

            // Créer l'escrow pour la course (mode optionnel - le blocage sera fait au paiement)
            // Le paiement sera bloqué via l'endpoint /api/escrow/hold-funds
            $ride->setPaymentStatus('pending');
            $this->entityManager->flush();

            // 🔥 Logger l'activité
            $this->activityLogService->logRideCreated(
                $user, 
                $ride->getId(), 
                $ride->getDepart(), 
                $ride->getDestination()
            );

            // 🔥 Publier en temps réel via Mercure (nouvelle course disponible)
            $this->mercureService->publishNewRide($this->serializeRide($ride));

            // 📱 Notifier tous les chauffeurs d'une nouvelle course disponible
            $this->pushNotificationService->notifyNewRide($this->serializeRide($ride));

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
            /** @var \App\Entity\Chauffeur $user */
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
            
            // Créer une notification pour le propriétaire de la course
            $owner = $ride->getChauffeur();
            $notification = new Notification();
            $notification->setRecipient($owner);
            $notification->setSender($user);
            $notification->setType(Notification::TYPE_RIDE_ACCEPTED);
            $notification->setTitle('Course acceptée');
            $notification->setMessage($user->getPrenom() . ' ' . $user->getNom() . ' a accepté votre course ' . $ride->getDepart() . ' → ' . $ride->getDestination());
            $notification->setRide($ride);
            $this->entityManager->persist($notification);
            
            // Créer une conversation pour cette course
            $existingConversation = $this->conversationRepository->findByRide($ride);
            if (!$existingConversation) {
                $conversation = new Conversation();
                $conversation->setChauffeur1($owner);
                $conversation->setChauffeur2($user);
                $conversation->setRide($ride);
                $this->entityManager->persist($conversation);
            }
            
            $this->entityManager->flush();

            // 🔥 Publier en temps réel via Mercure
            $this->mercureService->publishRideStatusChange(
                $ride->getId(),
                'disponible',
                'acceptée',
                $this->serializeRide($ride)
            );

            // 📱 Notifier le propriétaire de la course
            $this->pushNotificationService->notifyRideAccepted($owner, $this->serializeRide($ride), $user);

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
            $validStatuses = ['acceptée', 'en_cours', 'prise_en_charge', 'terminée', 'annulée'];
            if (!in_array($newStatus, $validStatuses)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Statut invalide'
                ], 400);
            }
            
            $ride->setStatus($newStatus);
            
            // Créer une notification pour le propriétaire de la course
            $owner = $ride->getChauffeur();
            $chauffeur = $ride->getChauffeurAccepteur();
            if ($owner && $owner !== $user && $chauffeur) {
                $notification = new Notification();
                $notification->setRecipient($owner);
                $notification->setSender($chauffeur);
                $notification->setRide($ride);
                
                switch ($newStatus) {
                    case 'en_cours':
                        $notification->setType(Notification::TYPE_RIDE_STARTED);
                        $notification->setTitle('Course démarrée');
                        $notification->setMessage($chauffeur->getPrenom() . ' est en route vers le point de départ');
                        $this->entityManager->persist($notification);
                        break;
                    case 'prise_en_charge':
                        $notification->setType(Notification::TYPE_RIDE_STARTED);
                        $notification->setTitle('Client pris en charge');
                        $notification->setMessage('Le client est à bord, course en direction de ' . $ride->getDestination());
                        $this->entityManager->persist($notification);
                        break;
                    case 'terminée':
                        $notification->setType(Notification::TYPE_RIDE_COMPLETED);
                        $notification->setTitle('Course terminée');
                        $notification->setMessage('La course ' . $ride->getDepart() . ' → ' . $ride->getDestination() . ' est terminée');
                        $this->entityManager->persist($notification);
                        break;
                }
            }
            
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
     * Annuler une course
     */
    #[Route('/{id}/cancel', name: 'api_rides_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(Ride $ride, Request $request): JsonResponse
    {
        try {
            /** @var \App\Entity\Chauffeur $user */
            $user = $this->getUser();
            $data = json_decode($request->getContent(), true);
            $reason = $data['reason'] ?? 'Aucune raison spécifiée';
            
            // Vérifier que l'utilisateur est autorisé (propriétaire ou accepteur)
            $isOwner = $ride->getChauffeur() === $user;
            $isAcceptor = $ride->getChauffeurAccepteur() === $user;
            
            if (!$isOwner && !$isAcceptor) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Vous n\'êtes pas autorisé à annuler cette course'
                ], 403);
            }
            
            // Vérifier que la course n'est pas déjà terminée
            if ($ride->getStatus() === 'terminée') {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Impossible d\'annuler une course terminée'
                ], 400);
            }
            
            $oldStatus = $ride->getStatus();
            $ride->setStatus('annulée');
            $ride->setComment(($ride->getComment() ?? '') . "\n\n[ANNULATION] " . $reason);
            
            // Notifier l'autre partie
            $recipient = $isOwner ? $ride->getChauffeurAccepteur() : $ride->getChauffeur();
            if ($recipient) {
                $notification = new Notification();
                $notification->setRecipient($recipient);
                $notification->setSender($user);
                $notification->setType(Notification::TYPE_RIDE_CANCELLED);
                $notification->setTitle('Course annulée');
                $notification->setMessage(
                    $user->getPrenom() . ' a annulé la course ' . 
                    $ride->getDepart() . ' → ' . $ride->getDestination() . 
                    '. Raison : ' . $reason
                );
                $notification->setRide($ride);
                $this->entityManager->persist($notification);
            }
            
            $this->entityManager->flush();
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Course annulée avec succès',
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
     * Signaler un problème sur une course
     */
    #[Route('/{id}/report', name: 'api_rides_report', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function report(Ride $ride, Request $request): JsonResponse
    {
        try {
            /** @var \App\Entity\Chauffeur $user */
            $user = $this->getUser();
            $data = json_decode($request->getContent(), true);
            
            // Vérifier que l'utilisateur est impliqué dans la course
            $isOwner = $ride->getChauffeur() === $user;
            $isAcceptor = $ride->getChauffeurAccepteur() === $user;
            
            if (!$isOwner && !$isAcceptor) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Vous n\'êtes pas autorisé à signaler cette course'
                ], 403);
            }
            
            // Valider le type de signalement
            $validTypes = [
                RideReport::TYPE_CLIENT_ABSENT,
                RideReport::TYPE_CLIENT_RETARD,
                RideReport::TYPE_MAUVAISE_ADRESSE,
                RideReport::TYPE_CLIENT_ANNULE,
                RideReport::TYPE_COMPORTEMENT,
                RideReport::TYPE_PAIEMENT,
                RideReport::TYPE_AUTRE,
            ];
            
            $type = $data['type'] ?? RideReport::TYPE_AUTRE;
            if (!in_array($type, $validTypes)) {
                $type = RideReport::TYPE_AUTRE;
            }
            
            $description = $data['description'] ?? '';
            if (empty($description)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'La description est obligatoire'
                ], 400);
            }
            
            // Créer le signalement
            $report = new RideReport();
            $report->setRide($ride);
            $report->setReporter($user);
            $report->setType($type);
            $report->setDescription($description);
            $report->setStatus(RideReport::STATUS_PENDING);
            
            $this->entityManager->persist($report);
            
            // Notifier les admins (on pourrait envoyer un email ici aussi)
            // Pour l'instant, le signalement sera visible dans le panel admin
            
            $this->entityManager->flush();
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Signalement envoyé à l\'administration',
                'report' => $report->toArray()
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Récupérer les types de signalement disponibles
     */
    #[Route('/report-types', name: 'api_rides_report_types', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getReportTypes(): JsonResponse
    {
        return new JsonResponse([
            ['value' => RideReport::TYPE_CLIENT_ABSENT, 'label' => 'Client absent'],
            ['value' => RideReport::TYPE_CLIENT_RETARD, 'label' => 'Client en retard'],
            ['value' => RideReport::TYPE_MAUVAISE_ADRESSE, 'label' => 'Mauvaise adresse'],
            ['value' => RideReport::TYPE_CLIENT_ANNULE, 'label' => 'Client a annulé sur place'],
            ['value' => RideReport::TYPE_COMPORTEMENT, 'label' => 'Problème de comportement'],
            ['value' => RideReport::TYPE_PAIEMENT, 'label' => 'Problème de paiement'],
            ['value' => RideReport::TYPE_AUTRE, 'label' => 'Autre problème'],
        ]);
    }

    /**
     * Sérialiser une course en tableau
     */
    private function serializeRide(Ride $ride, $currentUser = null): array
    {
        $isOwner = $currentUser && $ride->getChauffeur() && $ride->getChauffeur()->getId() === $currentUser->getId();
        $isAcceptor = $currentUser && $ride->getChauffeurAccepteur() && $ride->getChauffeurAccepteur()->getId() === $currentUser->getId();
        
        // Récupérer les notes moyennes des chauffeurs
        $chauffeurRating = null;
        $chauffeurAccepteurRating = null;
        
        if ($ride->getChauffeur()) {
            $chauffeurRating = $this->avisRepository->getAverageRating($ride->getChauffeur());
        }
        if ($ride->getChauffeurAccepteur()) {
            $chauffeurAccepteurRating = $this->avisRepository->getAverageRating($ride->getChauffeurAccepteur());
        }
        
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
            'isAcceptor' => $isAcceptor,
            // Coordonnées GPS pour la carte
            'departureCoords' => $ride->getDepartureCoords(),
            'arrivalCoords' => $ride->getArrivalCoords(),
            'groupe' => $ride->getGroupe() ? [
                'id' => $ride->getGroupe()->getId(),
                'nom' => $ride->getGroupe()->getNom(),
            ] : null,
            'chauffeur' => $ride->getChauffeur() ? [
                'id' => $ride->getChauffeur()->getId(),
                'name' => $ride->getChauffeur()->getPrenom() . ' ' . $ride->getChauffeur()->getNom(),
                'averageRating' => $chauffeurRating,
            ] : null,
            'chauffeurAccepteur' => $ride->getChauffeurAccepteur() ? [
                'id' => $ride->getChauffeurAccepteur()->getId(),
                'name' => $ride->getChauffeurAccepteur()->getPrenom() . ' ' . $ride->getChauffeurAccepteur()->getNom(),
                'averageRating' => $chauffeurAccepteurRating,
            ] : null,
        ];
    }
}
