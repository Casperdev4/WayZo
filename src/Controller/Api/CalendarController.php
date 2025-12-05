<?php

namespace App\Controller\Api;

use App\Repository\RideRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/calendar')]
class CalendarController extends BaseApiController
{
    public function __construct(
        private RideRepository $rideRepository
    ) {}

    /**
     * Récupérer les événements du calendrier (courses acceptées à effectuer)
     */
    #[Route('/events', name: 'api_calendar_events', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getEvents(Request $request): JsonResponse
    {
        $user = $this->getChauffeur();
        
        // Filtres optionnels
        $startDate = $request->query->get('start');
        $endDate = $request->query->get('end');
        
        // Récupérer uniquement les courses ACCEPTÉES par ce chauffeur (pas les courses disponibles)
        $acceptedRides = $this->rideRepository->findBy(['chauffeurAccepteur' => $user]);
        
        // Filtrer pour garder seulement les courses acceptées, en cours ou terminées
        // Statuts utilisés : 'disponible', 'acceptée', 'en_cours', 'terminée', 'annulée'
        $allRides = array_filter($acceptedRides, function($ride) {
            return in_array($ride->getStatus(), ['acceptée', 'en_cours', 'terminée']);
        });
        
        // Filtrer par date si spécifié
        if ($startDate && $endDate) {
            $start = new \DateTime($startDate);
            $end = new \DateTime($endDate);
            
            $allRides = array_filter($allRides, function($ride) use ($start, $end) {
                $rideDate = $ride->getDate();
                return $rideDate && $rideDate >= $start && $rideDate <= $end;
            });
        }
        
        // Formater en événements calendrier
        $events = array_map(function($ride) use ($user) {
            $isOwner = $ride->getChauffeur() && $ride->getChauffeur()->getId() === $user->getId();
            
            // Couleur selon le statut
            $color = match($ride->getStatus()) {
                'disponible' => 'orange',
                'acceptée' => 'blue',
                'en_cours' => 'indigo',
                'terminée' => 'green',
                'annulée' => 'red',
                default => 'gray'
            };
            
            // Construire la date/heure de début
            $startDateTime = $ride->getDate();
            if ($startDateTime && $ride->getTime()) {
                $startDateTime = \DateTime::createFromFormat(
                    'Y-m-d H:i',
                    $ride->getDate()->format('Y-m-d') . ' ' . $ride->getTime()->format('H:i')
                );
            }
            
            // Durée estimée de 1h par défaut
            $endDateTime = clone $startDateTime;
            $endDateTime->modify('+1 hour');
            
            return [
                'id' => (string) $ride->getId(),
                'title' => $ride->getDepart() . ' → ' . $ride->getDestination(),
                'start' => $startDateTime?->format('c'),
                'end' => $endDateTime?->format('c'),
                'color' => $color,
                'extendedProps' => [
                    'eventColor' => $color,
                    'rideId' => $ride->getId(),
                    'clientName' => $ride->getClientName(),
                    'clientContact' => $ride->getClientContact(),
                    'depart' => $ride->getDepart(),
                    'destination' => $ride->getDestination(),
                    'price' => $ride->getPrice(),
                    'passengers' => $ride->getPassengers(),
                    'status' => $ride->getStatus(),
                    'isOwner' => $isOwner,
                    'comment' => $ride->getComment(),
                ],
            ];
        }, $allRides);
        
        return new JsonResponse(array_values($events));
    }

    /**
     * Créer un événement (réservation rapide)
     */
    #[Route('/events', name: 'api_calendar_create_event', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createEvent(Request $request): JsonResponse
    {
        // Rediriger vers la création de course
        // Cette route est juste un alias pour la cohérence avec le composant Calendar
        return new JsonResponse([
            'message' => 'Utilisez POST /api/rides pour créer une course',
            'redirect' => '/api/rides',
        ], 200);
    }

    /**
     * Statistiques du planning (courses acceptées uniquement)
     */
    #[Route('/stats', name: 'api_calendar_stats', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getStats(): JsonResponse
    {
        $user = $this->getUser();
        
        $today = new \DateTime('today');
        $weekStart = (new \DateTime())->modify('monday this week');
        $weekEnd = (new \DateTime())->modify('sunday this week');
        $monthStart = new \DateTime('first day of this month');
        $monthEnd = new \DateTime('last day of this month');
        
        // Uniquement les courses acceptées par ce chauffeur
        $acceptedRides = $this->rideRepository->findBy(['chauffeurAccepteur' => $user]);
        
        // Filtrer pour garder les courses actives (acceptée, en_cours, terminée)
        $allRides = array_filter($acceptedRides, function($ride) {
            return in_array($ride->getStatus(), ['acceptée', 'en_cours', 'terminée']);
        });
        
        // Courses aujourd'hui
        $todayRides = array_filter($allRides, function($ride) use ($today) {
            return $ride->getDate() && $ride->getDate()->format('Y-m-d') === $today->format('Y-m-d');
        });
        
        // Courses cette semaine
        $weekRides = array_filter($allRides, function($ride) use ($weekStart, $weekEnd) {
            $date = $ride->getDate();
            return $date && $date >= $weekStart && $date <= $weekEnd;
        });
        
        // Courses ce mois
        $monthRides = array_filter($allRides, function($ride) use ($monthStart, $monthEnd) {
            $date = $ride->getDate();
            return $date && $date >= $monthStart && $date <= $monthEnd;
        });
        
        // Courses à faire (acceptées mais pas encore terminées)
        $upcomingRides = array_filter($allRides, fn($r) => in_array($r->getStatus(), ['acceptée', 'en_cours']));
        
        return new JsonResponse([
            'today' => count($todayRides),
            'thisWeek' => count($weekRides),
            'thisMonth' => count($monthRides),
            'pending' => count($upcomingRides),
            'upcomingRides' => array_map(function($ride) {
                return [
                    'id' => $ride->getId(),
                    'title' => $ride->getDepart() . ' → ' . $ride->getDestination(),
                    'date' => $ride->getDate()?->format('Y-m-d'),
                    'time' => $ride->getTime()?->format('H:i'),
                    'status' => $ride->getStatus(),
                ];
            }, array_slice(array_values($upcomingRides), 0, 5)),
        ]);
    }
}
