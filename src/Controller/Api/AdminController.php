<?php

namespace App\Controller\Api;

use App\Entity\Chauffeur;
use App\Entity\Ride;
use App\Entity\RideReport;
use App\Repository\ChauffeurRepository;
use App\Repository\RideRepository;
use App\Repository\RideReportRepository;
use App\Repository\AvisRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller d'administration complet
 */
#[Route('/api/admin')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    // Commission WayZo en pourcentage
    private const PLATFORM_COMMISSION = 15;

    public function __construct(
        private ChauffeurRepository $chauffeurRepository,
        private RideRepository $rideRepository,
        private RideReportRepository $rideReportRepository,
        private AvisRepository $avisRepository,
        private EntityManagerInterface $entityManager
    ) {}

    // ==================== DASHBOARD ====================

    /**
     * Dashboard admin avec statistiques globales
     */
    #[Route('/dashboard', name: 'api_admin_dashboard', methods: ['GET'])]
    public function dashboard(): JsonResponse
    {
        // Statistiques chauffeurs
        $totalChauffeurs = $this->chauffeurRepository->count([]);
        $activeChauffeurs = $this->chauffeurRepository->count(['status' => Chauffeur::STATUS_ACTIVE]);
        $pendingChauffeurs = $this->chauffeurRepository->count(['status' => Chauffeur::STATUS_PENDING]);
        $blockedChauffeurs = $this->chauffeurRepository->count(['status' => Chauffeur::STATUS_BLOCKED]);

        // Statistiques courses
        $rideStats = $this->getRideStats();
        
        // Revenus plateforme
        $revenueStats = $this->getRevenueStats();
        
        // Signalements en attente
        $pendingReports = count($this->rideReportRepository->findPending());

        return $this->json([
            'chauffeurs' => [
                'total' => $totalChauffeurs,
                'active' => $activeChauffeurs,
                'pending' => $pendingChauffeurs,
                'blocked' => $blockedChauffeurs,
            ],
            'rides' => $rideStats,
            'revenue' => $revenueStats,
            'pendingReports' => $pendingReports,
            'commissionRate' => self::PLATFORM_COMMISSION,
        ]);
    }

    // ==================== CHAUFFEURS ====================

    /**
     * Liste de tous les chauffeurs
     */
    #[Route('/chauffeurs', name: 'api_admin_chauffeurs_list', methods: ['GET'])]
    public function listChauffeurs(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $search = $request->query->get('search');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(10, (int) $request->query->get('limit', 20)));
        
        $qb = $this->chauffeurRepository->createQueryBuilder('c')
            ->orderBy('c.id', 'DESC');
        
        if ($status) {
            $qb->andWhere('c.status = :status')->setParameter('status', $status);
        }
        
        if ($search) {
            $qb->andWhere('c.nom LIKE :search OR c.prenom LIKE :search OR c.email LIKE :search OR c.nomSociete LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }
        
        // Count total
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        
        // Pagination
        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $chauffeurs = $qb->getQuery()->getResult();
        
        $data = array_map(function(Chauffeur $c) {
            return $this->serializeChauffeurForAdmin($c);
        }, $chauffeurs);

        return $this->json([
            'chauffeurs' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int) $total,
                'totalPages' => ceil($total / $limit),
            ]
        ]);
    }

    /**
     * Détail complet d'un chauffeur
     */
    #[Route('/chauffeurs/{id}', name: 'api_admin_chauffeur_detail', methods: ['GET'])]
    public function getChauffeur(Chauffeur $chauffeur): JsonResponse
    {
        // Statistiques du chauffeur
        $ridesCreated = $this->rideRepository->count(['chauffeur' => $chauffeur]);
        $ridesAccepted = $this->rideRepository->count(['chauffeurAccepteur' => $chauffeur]);
        $ridesCompleted = $this->rideRepository->count(['chauffeurAccepteur' => $chauffeur, 'status' => 'terminée']);
        
        // Revenus générés
        $revenueData = $this->getChauffeurRevenue($chauffeur);
        
        // Note moyenne
        $avgRating = $this->avisRepository->getAverageRating($chauffeur);
        $reviewCount = $this->avisRepository->countAvisForChauffeur($chauffeur);
        
        // Signalements
        $reports = $this->rideReportRepository->findByReporter($chauffeur);
        
        return $this->json([
            'chauffeur' => $this->serializeChauffeurForAdmin($chauffeur, true),
            'stats' => [
                'ridesCreated' => $ridesCreated,
                'ridesAccepted' => $ridesAccepted,
                'ridesCompleted' => $ridesCompleted,
                'avgRating' => $avgRating,
                'reviewCount' => $reviewCount,
            ],
            'revenue' => $revenueData,
            'reportsCount' => count($reports),
        ]);
    }

    /**
     * Modifier le statut d'un chauffeur
     */
    #[Route('/chauffeurs/{id}/status', name: 'api_admin_chauffeur_status', methods: ['PUT'])]
    public function updateChauffeurStatus(Chauffeur $chauffeur, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $newStatus = $data['status'] ?? null;
        
        $validStatuses = [Chauffeur::STATUS_ACTIVE, Chauffeur::STATUS_BLOCKED, Chauffeur::STATUS_PENDING];
        
        if (!in_array($newStatus, $validStatuses)) {
            return $this->json(['error' => 'Statut invalide'], 400);
        }
        
        $chauffeur->setStatus($newStatus);
        $this->entityManager->flush();
        
        return $this->json([
            'success' => true,
            'message' => 'Statut mis à jour',
            'chauffeur' => $this->serializeChauffeurForAdmin($chauffeur)
        ]);
    }

    // ==================== COURSES ====================

    /**
     * Liste de toutes les courses avec filtres
     */
    #[Route('/rides', name: 'api_admin_rides_list', methods: ['GET'])]
    public function listRides(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
        
        $qb = $this->rideRepository->createQueryBuilder('r')
            ->leftJoin('r.chauffeur', 'c')
            ->leftJoin('r.chauffeurAccepteur', 'ca')
            ->orderBy('r.date', 'DESC')
            ->addOrderBy('r.time', 'DESC');
        
        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }
        
        if ($dateFrom) {
            $qb->andWhere('r.date >= :dateFrom')->setParameter('dateFrom', new \DateTime($dateFrom));
        }
        
        if ($dateTo) {
            $qb->andWhere('r.date <= :dateTo')->setParameter('dateTo', new \DateTime($dateTo));
        }
        
        // Count total
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        
        // Pagination
        $qb->setFirstResult(($page - 1) * $limit)->setMaxResults($limit);
        $rides = $qb->getQuery()->getResult();
        
        $data = array_map(function(Ride $r) {
            return $this->serializeRideForAdmin($r);
        }, $rides);

        return $this->json([
            'rides' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int) $total,
                'totalPages' => ceil($total / $limit),
            ],
            'statusCounts' => $this->getRideStatusCounts(),
        ]);
    }

    /**
     * Détail d'une course
     */
    #[Route('/rides/{id}', name: 'api_admin_ride_detail', methods: ['GET'])]
    public function getRide(Ride $ride): JsonResponse
    {
        // Signalements liés à cette course
        $reports = $this->rideReportRepository->findByRide($ride->getId());
        
        return $this->json([
            'ride' => $this->serializeRideForAdmin($ride, true),
            'reports' => array_map(fn(RideReport $r) => $r->toArray(), $reports),
            'commission' => $this->calculateCommission($ride),
        ]);
    }

    // ==================== REVENUS ====================

    /**
     * Statistiques de revenus de la plateforme
     */
    #[Route('/revenue', name: 'api_admin_revenue', methods: ['GET'])]
    public function getRevenue(Request $request): JsonResponse
    {
        $period = $request->query->get('period', 'month'); // day, week, month, year
        $dateFrom = $request->query->get('date_from');
        $dateTo = $request->query->get('date_to');
        
        // Courses terminées
        $qb = $this->rideRepository->createQueryBuilder('r')
            ->where('r.status = :status')
            ->setParameter('status', 'terminée');
        
        if ($dateFrom) {
            $qb->andWhere('r.date >= :dateFrom')->setParameter('dateFrom', new \DateTime($dateFrom));
        }
        
        if ($dateTo) {
            $qb->andWhere('r.date <= :dateTo')->setParameter('dateTo', new \DateTime($dateTo));
        }
        
        $rides = $qb->getQuery()->getResult();
        
        $totalRides = count($rides);
        $totalRevenue = 0;
        $totalCommission = 0;
        
        foreach ($rides as $ride) {
            $price = $ride->getPrice() ?? 0;
            $totalRevenue += $price;
            $totalCommission += $price * (self::PLATFORM_COMMISSION / 100);
        }
        
        // Revenus par période
        $revenueByPeriod = $this->getRevenueByPeriod($period, $dateFrom, $dateTo);
        
        return $this->json([
            'summary' => [
                'totalRides' => $totalRides,
                'totalRevenue' => round($totalRevenue, 2),
                'totalCommission' => round($totalCommission, 2),
                'commissionRate' => self::PLATFORM_COMMISSION,
            ],
            'byPeriod' => $revenueByPeriod,
        ]);
    }

    /**
     * Top chauffeurs par revenus
     */
    #[Route('/revenue/top-chauffeurs', name: 'api_admin_top_chauffeurs', methods: ['GET'])]
    public function getTopChauffeurs(Request $request): JsonResponse
    {
        $limit = min(50, max(5, (int) $request->query->get('limit', 10)));
        
        // Chauffeurs qui ont accepté le plus de courses terminées
        $results = $this->rideRepository->createQueryBuilder('r')
            ->select('IDENTITY(r.chauffeurAccepteur) as chauffeurId, SUM(r.price) as totalRevenue, COUNT(r.id) as ridesCount')
            ->where('r.status = :status')
            ->andWhere('r.chauffeurAccepteur IS NOT NULL')
            ->setParameter('status', 'terminée')
            ->groupBy('r.chauffeurAccepteur')
            ->orderBy('totalRevenue', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        
        $topChauffeurs = [];
        foreach ($results as $row) {
            $chauffeur = $this->chauffeurRepository->find($row['chauffeurId']);
            if ($chauffeur) {
                $topChauffeurs[] = [
                    'chauffeur' => [
                        'id' => $chauffeur->getId(),
                        'name' => $chauffeur->getPrenom() . ' ' . $chauffeur->getNom(),
                        'company' => $chauffeur->getNomSociete(),
                    ],
                    'totalRevenue' => round((float) $row['totalRevenue'], 2),
                    'ridesCount' => (int) $row['ridesCount'],
                    'commission' => round((float) $row['totalRevenue'] * (self::PLATFORM_COMMISSION / 100), 2),
                ];
            }
        }
        
        return $this->json($topChauffeurs);
    }

    // ==================== HELPERS ====================

    private function serializeChauffeurForAdmin(Chauffeur $c, bool $full = false): array
    {
        $data = [
            'id' => $c->getId(),
            'nom' => $c->getNom(),
            'prenom' => $c->getPrenom(),
            'email' => $c->getEmail(),
            'tel' => $c->getTel(),
            'nomSociete' => $c->getNomSociete(),
            'siret' => $c->getSiret(),
            'status' => $c->getStatus(),
            'vehicle' => $c->getVehicle(),
            'ville' => $c->getVille(),
            'codePostal' => $c->getCodePostal(),
            'roles' => $c->getRoles(),
        ];
        
        if ($full) {
            $data['adresse'] = $c->getAdresse();
            $data['dateNaissance'] = $c->getDateNaissance()?->format('Y-m-d');
            $data['permis'] = $c->getPermis();
            $data['kbis'] = $c->getKbis();
            $data['carteVtc'] = $c->getCarteVtc();
            $data['macaron'] = $c->getMacaron();
            $data['pieceIdentite'] = $c->getPieceIdentite();
        }
        
        return $data;
    }

    private function serializeRideForAdmin(Ride $r, bool $full = false): array
    {
        $price = $r->getPrice() ?? 0;
        $commission = $price * (self::PLATFORM_COMMISSION / 100);
        
        $data = [
            'id' => $r->getId(),
            'clientName' => $r->getClientName(),
            'clientContact' => $r->getClientContact(),
            'depart' => $r->getDepart(),
            'destination' => $r->getDestination(),
            'date' => $r->getDate()?->format('Y-m-d'),
            'time' => $r->getTime()?->format('H:i'),
            'status' => $r->getStatus(),
            'visibility' => $r->getVisibility(),
            'price' => $price,
            'commission' => round($commission, 2),
            'driverReceives' => $price, // Le chauffeur accepteur reçoit le montant affiché
            'sellerPays' => round($price + $commission, 2), // Le vendeur paie prix + commission
            'chauffeur' => $r->getChauffeur() ? [
                'id' => $r->getChauffeur()->getId(),
                'name' => $r->getChauffeur()->getPrenom() . ' ' . $r->getChauffeur()->getNom(),
            ] : null,
            'chauffeurAccepteur' => $r->getChauffeurAccepteur() ? [
                'id' => $r->getChauffeurAccepteur()->getId(),
                'name' => $r->getChauffeurAccepteur()->getPrenom() . ' ' . $r->getChauffeurAccepteur()->getNom(),
            ] : null,
        ];
        
        if ($full) {
            $data['passengers'] = $r->getPassengers();
            $data['luggage'] = $r->getLuggage();
            $data['vehicle'] = $r->getVehicle();
            $data['boosterSeat'] = $r->getBoosterSeat();
            $data['babySeat'] = $r->getBabySeat();
            $data['comment'] = $r->getComment();
            $data['groupe'] = $r->getGroupe() ? [
                'id' => $r->getGroupe()->getId(),
                'nom' => $r->getGroupe()->getNom(),
            ] : null;
        }
        
        return $data;
    }

    private function getRideStats(): array
    {
        $counts = $this->getRideStatusCounts();
        
        return [
            'total' => array_sum($counts),
            'byStatus' => $counts,
        ];
    }

    private function getRideStatusCounts(): array
    {
        $results = $this->rideRepository->createQueryBuilder('r')
            ->select('r.status, COUNT(r.id) as count')
            ->groupBy('r.status')
            ->getQuery()
            ->getResult();
        
        $counts = [
            'disponible' => 0,
            'acceptée' => 0,
            'en_cours' => 0,
            'prise_en_charge' => 0,
            'terminée' => 0,
            'annulée' => 0,
        ];
        
        foreach ($results as $row) {
            if (isset($counts[$row['status']])) {
                $counts[$row['status']] = (int) $row['count'];
            }
        }
        
        return $counts;
    }

    private function getRevenueStats(): array
    {
        // Courses terminées
        $completedRides = $this->rideRepository->findBy(['status' => 'terminée']);
        
        $totalRevenue = 0;
        $totalCommission = 0;
        
        foreach ($completedRides as $ride) {
            $price = $ride->getPrice() ?? 0;
            $totalRevenue += $price;
            $totalCommission += $price * (self::PLATFORM_COMMISSION / 100);
        }
        
        // Ce mois
        $startOfMonth = new \DateTime('first day of this month');
        $monthRides = $this->rideRepository->createQueryBuilder('r')
            ->where('r.status = :status')
            ->andWhere('r.date >= :start')
            ->setParameter('status', 'terminée')
            ->setParameter('start', $startOfMonth)
            ->getQuery()
            ->getResult();
        
        $monthRevenue = 0;
        $monthCommission = 0;
        foreach ($monthRides as $ride) {
            $price = $ride->getPrice() ?? 0;
            $monthRevenue += $price;
            $monthCommission += $price * (self::PLATFORM_COMMISSION / 100);
        }
        
        return [
            'total' => [
                'revenue' => round($totalRevenue, 2),
                'commission' => round($totalCommission, 2),
                'rides' => count($completedRides),
            ],
            'thisMonth' => [
                'revenue' => round($monthRevenue, 2),
                'commission' => round($monthCommission, 2),
                'rides' => count($monthRides),
            ],
            'commissionRate' => self::PLATFORM_COMMISSION,
        ];
    }

    private function getChauffeurRevenue(Chauffeur $chauffeur): array
    {
        // Courses créées (vendues) - le vendeur paie la commission
        $createdRides = $this->rideRepository->findBy([
            'chauffeur' => $chauffeur,
            'status' => 'terminée'
        ]);
        
        $totalSold = 0;
        $totalCommissionPaid = 0; // Commission payée en tant que vendeur
        foreach ($createdRides as $ride) {
            $price = $ride->getPrice() ?? 0;
            $commission = $price * (self::PLATFORM_COMMISSION / 100);
            $totalSold += $price;
            $totalCommissionPaid += $commission;
        }
        
        // Courses effectuées (acceptées) - le chauffeur accepteur reçoit le montant affiché
        $acceptedRides = $this->rideRepository->findBy([
            'chauffeurAccepteur' => $chauffeur,
            'status' => 'terminée'
        ]);
        
        $totalEarned = 0;
        foreach ($acceptedRides as $ride) {
            $totalEarned += $ride->getPrice() ?? 0; // Reçoit 100% du prix affiché
        }
        
        return [
            'ridesCreated' => count($createdRides),
            'totalSold' => round($totalSold, 2), // Montant des courses vendues
            'commissionPaidAsSeller' => round($totalCommissionPaid, 2), // Commission payée en tant que vendeur
            'totalPaidToWayZo' => round($totalSold + $totalCommissionPaid, 2), // Total payé à WayZo
            'ridesCompleted' => count($acceptedRides),
            'totalEarned' => round($totalEarned, 2), // Montant gagné en effectuant des courses
        ];
    }

    private function calculateCommission(Ride $ride): array
    {
        $price = $ride->getPrice() ?? 0;
        $commission = $price * (self::PLATFORM_COMMISSION / 100);
        
        return [
            'ridePrice' => $price,
            'commissionRate' => self::PLATFORM_COMMISSION,
            'commissionAmount' => round($commission, 2),
            'driverReceives' => $price, // Le chauffeur accepteur reçoit le montant affiché
            'sellerPays' => round($price + $commission, 2), // Le vendeur paie prix + 15%
        ];
    }

    private function getRevenueByPeriod(string $period, ?string $dateFrom, ?string $dateTo): array
    {
        $qb = $this->rideRepository->createQueryBuilder('r')
            ->select('r.date, SUM(r.price) as revenue, COUNT(r.id) as rides')
            ->where('r.status = :status')
            ->setParameter('status', 'terminée')
            ->groupBy('r.date')
            ->orderBy('r.date', 'ASC');
        
        if ($dateFrom) {
            $qb->andWhere('r.date >= :dateFrom')->setParameter('dateFrom', new \DateTime($dateFrom));
        }
        
        if ($dateTo) {
            $qb->andWhere('r.date <= :dateTo')->setParameter('dateTo', new \DateTime($dateTo));
        }
        
        $results = $qb->getQuery()->getResult();
        
        $data = [];
        foreach ($results as $row) {
            $revenue = (float) $row['revenue'];
            $data[] = [
                'date' => $row['date']->format('Y-m-d'),
                'revenue' => round($revenue, 2),
                'commission' => round($revenue * (self::PLATFORM_COMMISSION / 100), 2),
                'rides' => (int) $row['rides'],
            ];
        }
        
        return $data;
    }
}
