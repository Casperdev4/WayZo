<?php

namespace App\Controller\Api;

use App\Entity\RideTracking;
use App\Entity\Ride;
use App\Repository\RideTrackingRepository;
use App\Repository\RideRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/tracking')]
class TrackingController extends AbstractController
{
    public function __construct(
        private RideTrackingRepository $trackingRepository,
        private RideRepository $rideRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Envoyer la position GPS du chauffeur (appelé par le chauffeur en cours de route)
     */
    #[Route('/position/{rideId}', name: 'api_tracking_send_position', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendPosition(int $rideId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $ride = $this->rideRepository->find($rideId);
        
        if (!$ride) {
            return $this->json(['error' => 'Course non trouvée'], 404);
        }
        
        // Seul le chauffeur accepteur peut envoyer sa position
        if ($ride->getChauffeurAccepteur() !== $user) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }
        
        // La course doit être en cours
        if ($ride->getStatus() !== 'en_cours') {
            return $this->json(['error' => 'La course n\'est pas en cours'], 400);
        }
        
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            return $this->json(['error' => 'Coordonnées manquantes'], 400);
        }
        
        $tracking = new RideTracking();
        $tracking->setRide($ride);
        $tracking->setLatitude((float) $data['latitude']);
        $tracking->setLongitude((float) $data['longitude']);
        $tracking->setSpeed($data['speed'] ?? null);
        $tracking->setHeading($data['heading'] ?? null);
        $tracking->setAccuracy($data['accuracy'] ?? null);
        
        $this->entityManager->persist($tracking);
        $this->entityManager->flush();
        
        return $this->json([
            'success' => true,
            'id' => $tracking->getId(),
            'timestamp' => $tracking->getTimestamp()->format('c'),
        ]);
    }

    /**
     * Récupérer les positions d'une course (pour le propriétaire)
     */
    #[Route('/ride/{rideId}', name: 'api_tracking_get_positions', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getPositions(int $rideId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $ride = $this->rideRepository->find($rideId);
        
        if (!$ride) {
            return $this->json(['error' => 'Course non trouvée'], 404);
        }
        
        // Le propriétaire ou le chauffeur accepteur peuvent voir les positions
        $isOwner = $ride->getChauffeur() === $user;
        $isAcceptor = $ride->getChauffeurAccepteur() === $user;
        
        if (!$isOwner && !$isAcceptor) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }
        
        // Récupérer les positions depuis un timestamp (pour mise à jour incrémentale)
        $since = $request->query->get('since');
        
        if ($since) {
            $sinceDate = new \DateTime($since);
            $positions = $this->trackingRepository->findSince($ride, $sinceDate);
        } else {
            $positions = $this->trackingRepository->findByRide($ride);
        }
        
        // Dernière position pour l'affichage du marqueur
        $lastPosition = $this->trackingRepository->findLastPosition($ride);
        
        $positionsData = array_map(function(RideTracking $pos) {
            return [
                'id' => $pos->getId(),
                'lat' => $pos->getLatitude(),
                'lng' => $pos->getLongitude(),
                'speed' => $pos->getSpeed(),
                'heading' => $pos->getHeading(),
                'accuracy' => $pos->getAccuracy(),
                'timestamp' => $pos->getTimestamp()->format('c'),
            ];
        }, $positions);
        
        return $this->json([
            'rideId' => $ride->getId(),
            'status' => $ride->getStatus(),
            'positions' => $positionsData,
            'lastPosition' => $lastPosition ? [
                'lat' => $lastPosition->getLatitude(),
                'lng' => $lastPosition->getLongitude(),
                'speed' => $lastPosition->getSpeed(),
                'heading' => $lastPosition->getHeading(),
                'accuracy' => $lastPosition->getAccuracy(),
                'timestamp' => $lastPosition->getTimestamp()->format('c'),
            ] : null,
            'ride' => [
                'depart' => $ride->getDepart(),
                'destination' => $ride->getDestination(),
                'chauffeur' => $ride->getChauffeurAccepteur() ? [
                    'id' => $ride->getChauffeurAccepteur()->getId(),
                    'name' => $ride->getChauffeurAccepteur()->getPrenom() . ' ' . $ride->getChauffeurAccepteur()->getNom(),
                ] : null,
            ],
        ]);
    }

    /**
     * Récupérer uniquement la dernière position (polling rapide)
     */
    #[Route('/ride/{rideId}/last', name: 'api_tracking_last_position', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getLastPosition(int $rideId): JsonResponse
    {
        $user = $this->getUser();
        $ride = $this->rideRepository->find($rideId);
        
        if (!$ride) {
            return $this->json(['error' => 'Course non trouvée'], 404);
        }
        
        // Le propriétaire ou le chauffeur accepteur peuvent voir
        $isOwner = $ride->getChauffeur() === $user;
        $isAcceptor = $ride->getChauffeurAccepteur() === $user;
        
        if (!$isOwner && !$isAcceptor) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }
        
        $lastPosition = $this->trackingRepository->findLastPosition($ride);
        
        return $this->json([
            'status' => $ride->getStatus(),
            'position' => $lastPosition ? [
                'lat' => $lastPosition->getLatitude(),
                'lng' => $lastPosition->getLongitude(),
                'speed' => $lastPosition->getSpeed(),
                'heading' => $lastPosition->getHeading(),
                'accuracy' => $lastPosition->getAccuracy(),
                'timestamp' => $lastPosition->getTimestamp()->format('c'),
            ] : null,
        ]);
    }
}
