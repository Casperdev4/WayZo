<?php

namespace App\Controller\Api;

use App\Entity\RideReport;
use App\Repository\RideReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller pour la gestion admin des signalements
 */
#[Route('/api/admin/reports')]
#[IsGranted('ROLE_ADMIN')]
class AdminReportController extends AbstractController
{
    public function __construct(
        private RideReportRepository $rideReportRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Liste tous les signalements (avec filtres)
     */
    #[Route('', name: 'api_admin_reports_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $status = $request->query->get('status');
        
        if ($status && in_array($status, [RideReport::STATUS_PENDING, RideReport::STATUS_REVIEWED, RideReport::STATUS_RESOLVED, RideReport::STATUS_REJECTED])) {
            $reports = $this->rideReportRepository->findBy(
                ['status' => $status],
                ['createdAt' => 'DESC']
            );
        } else {
            $reports = $this->rideReportRepository->findBy(
                [],
                ['createdAt' => 'DESC']
            );
        }
        
        return $this->json([
            'reports' => array_map(fn(RideReport $r) => $r->toArray(), $reports),
            'counts' => $this->rideReportRepository->countByStatus()
        ]);
    }

    /**
     * Liste les signalements en attente (prioritaires)
     */
    #[Route('/pending', name: 'api_admin_reports_pending', methods: ['GET'])]
    public function pending(): JsonResponse
    {
        $reports = $this->rideReportRepository->findPending();
        
        return $this->json([
            'reports' => array_map(fn(RideReport $r) => $r->toArray(), $reports),
            'count' => count($reports)
        ]);
    }

    /**
     * Voir le détail d'un signalement
     */
    #[Route('/{id}', name: 'api_admin_reports_show', methods: ['GET'])]
    public function show(RideReport $report): JsonResponse
    {
        return $this->json($report->toArray());
    }

    /**
     * Mettre à jour le statut d'un signalement
     */
    #[Route('/{id}/status', name: 'api_admin_reports_update_status', methods: ['PUT'])]
    public function updateStatus(RideReport $report, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $newStatus = $data['status'] ?? null;
        $response = $data['response'] ?? null;
        
        $validStatuses = [
            RideReport::STATUS_PENDING,
            RideReport::STATUS_REVIEWED,
            RideReport::STATUS_RESOLVED,
            RideReport::STATUS_REJECTED,
        ];
        
        if (!in_array($newStatus, $validStatuses)) {
            return $this->json(['error' => 'Statut invalide'], 400);
        }
        
        $report->setStatus($newStatus);
        
        if ($response) {
            $report->setAdminResponse($response);
        }
        
        if ($newStatus === RideReport::STATUS_RESOLVED || $newStatus === RideReport::STATUS_REJECTED) {
            $report->setResolvedAt(new \DateTime());
        }
        
        $this->entityManager->flush();
        
        return $this->json([
            'success' => true,
            'message' => 'Statut mis à jour',
            'report' => $report->toArray()
        ]);
    }

    /**
     * Statistiques des signalements
     */
    #[Route('/stats/overview', name: 'api_admin_reports_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        return $this->json([
            'counts' => $this->rideReportRepository->countByStatus(),
            'types' => [
                ['value' => RideReport::TYPE_CLIENT_ABSENT, 'label' => 'Client absent'],
                ['value' => RideReport::TYPE_CLIENT_RETARD, 'label' => 'Client en retard'],
                ['value' => RideReport::TYPE_MAUVAISE_ADRESSE, 'label' => 'Mauvaise adresse'],
                ['value' => RideReport::TYPE_CLIENT_ANNULE, 'label' => 'Client a annulé'],
                ['value' => RideReport::TYPE_COMPORTEMENT, 'label' => 'Problème comportement'],
                ['value' => RideReport::TYPE_PAIEMENT, 'label' => 'Problème paiement'],
                ['value' => RideReport::TYPE_AUTRE, 'label' => 'Autre'],
            ]
        ]);
    }
}
