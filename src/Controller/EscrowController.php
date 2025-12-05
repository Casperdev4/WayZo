<?php

namespace App\Controller;

use App\Entity\Chauffeur;
use App\Entity\EscrowPayment;
use App\Entity\Ride;
use App\Repository\EscrowPaymentRepository;
use App\Repository\RideRepository;
use App\Service\EscrowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/escrow')]
class EscrowController extends AbstractController
{
    public function __construct(
        private EscrowService $escrowService,
        private EscrowPaymentRepository $escrowRepository,
        private RideRepository $rideRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Récupère les informations d'escrow pour une course
     */
    #[Route('/ride/{id}', name: 'api_escrow_ride', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function getEscrowForRide(Ride $ride): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $escrow = $ride->getEscrowPayment();
        
        if (!$escrow) {
            return $this->json([
                'success' => true,
                'hasEscrow' => false,
                'escrow' => null
            ]);
        }

        // Vérifier que l'utilisateur est concerné par cette course
        $isSeller = $escrow->getSeller()->getId() === $user->getId();
        $isBuyer = $escrow->getBuyer()?->getId() === $user->getId();

        if (!$isSeller && !$isBuyer) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        return $this->json([
            'success' => true,
            'hasEscrow' => true,
            'escrow' => $escrow->toArray(),
            'role' => $isSeller ? 'seller' : 'buyer'
        ]);
    }

    /**
     * Bloque les fonds pour une course (création de l'escrow + PaymentIntent)
     */
    #[Route('/hold-funds', name: 'api_escrow_hold_funds', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function holdFunds(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['rideId'])) {
            return $this->json(['error' => 'ID de course requis'], 400);
        }

        $ride = $this->rideRepository->find($data['rideId']);
        
        if (!$ride) {
            return $this->json(['error' => 'Course non trouvée'], 404);
        }

        // Vérifier que l'utilisateur est le propriétaire de la course
        if ($ride->getChauffeur()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Vous n\'êtes pas le propriétaire de cette course'], 403);
        }

        // Vérifier qu'il n'y a pas déjà un escrow
        if ($ride->getEscrowPayment()) {
            return $this->json(['error' => 'Un paiement est déjà associé à cette course'], 400);
        }

        try {
            // Créer l'escrow et bloquer les fonds
            $paymentMethodId = $data['paymentMethodId'] ?? null;
            $escrow = $this->escrowService->createEscrow($ride, $user, $paymentMethodId);

            return $this->json([
                'success' => true,
                'message' => 'Fonds bloqués avec succès',
                'escrow' => $escrow->toArray()
            ], 201);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Calcule le montant total à bloquer pour une course
     */
    #[Route('/calculate', name: 'api_escrow_calculate', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function calculate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['price']) || $data['price'] <= 0) {
            return $this->json(['error' => 'Prix invalide'], 400);
        }

        $price = (float) $data['price'];
        $amounts = EscrowPayment::calculateAmounts($price);

        return $this->json([
            'success' => true,
            'rideAmount' => $amounts['rideAmount'],
            'commissionAmount' => $amounts['commissionAmount'],
            'commissionRate' => EscrowPayment::COMMISSION_RATE * 100 . '%',
            'totalAmount' => $amounts['totalAmount'],
            'message' => sprintf(
                'Le montant de %.2f€ sera bloqué sur votre compte (%.2f€ pour la course + %.2f€ de frais de service). Ce montant sera transféré au chauffeur qui effectuera la course.',
                $amounts['totalAmount'],
                $amounts['rideAmount'],
                $amounts['commissionAmount']
            )
        ]);
    }

    /**
     * Marque une course comme terminée (appelé par le buyer B)
     */
    #[Route('/{id}/complete', name: 'api_escrow_complete', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function markCompleted(EscrowPayment $escrow): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        try {
            $this->escrowService->markCompleted($escrow, $user);

            return $this->json([
                'success' => true,
                'message' => 'Course marquée comme terminée. Le propriétaire a 24h pour confirmer.',
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Confirme que la course a été effectuée (appelé par le seller A)
     */
    #[Route('/{id}/confirm', name: 'api_escrow_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function confirm(EscrowPayment $escrow): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        try {
            $this->escrowService->confirmByOwner($escrow, $user);

            return $this->json([
                'success' => true,
                'message' => 'Course confirmée. Le paiement a été transféré au chauffeur.',
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Annule une course (par le seller A)
     */
    #[Route('/{id}/cancel', name: 'api_escrow_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function cancel(EscrowPayment $escrow): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        try {
            if ($escrow->getBuyer() === null) {
                $this->escrowService->cancelBeforeAcceptance($escrow, $user);
                $message = 'Course annulée. Vous serez remboursé intégralement.';
            } else {
                $this->escrowService->cancelAfterAcceptance($escrow, $user);
                
                $amounts = $escrow->calculateCancellationAmounts($escrow->getRide()->getRideDateTime());
                $message = sprintf(
                    'Course annulée. Remboursement de %.2f€. Le chauffeur reçoit %.2f€ de compensation.',
                    $amounts['refundAmount'],
                    $amounts['compensationAmount']
                );
            }

            return $this->json([
                'success' => true,
                'message' => $message,
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Le buyer (B) abandonne la course (la remet en ligne)
     */
    #[Route('/{id}/abandon', name: 'api_escrow_abandon', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function abandon(EscrowPayment $escrow): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        try {
            $this->escrowService->cancelByBuyer($escrow, $user);

            return $this->json([
                'success' => true,
                'message' => 'Vous avez abandonné cette course. Elle est de nouveau disponible.',
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Ouvre un litige sur une course
     */
    #[Route('/{id}/dispute', name: 'api_escrow_dispute', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function dispute(EscrowPayment $escrow, Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? '';

        if (strlen($reason) < 10) {
            return $this->json(['error' => 'Veuillez décrire le problème (minimum 10 caractères)'], 400);
        }

        try {
            $this->escrowService->openDispute($escrow, $user, $reason);

            return $this->json([
                'success' => true,
                'message' => 'Litige ouvert. Notre équipe va examiner votre demande.',
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Liste les paiements en attente de validation (pour le seller)
     */
    #[Route('/awaiting-validation', name: 'api_escrow_awaiting', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function awaitingValidation(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $escrows = $this->escrowRepository->findAwaitingValidationBySeller($user);

        return $this->json([
            'success' => true,
            'count' => count($escrows),
            'data' => array_map(fn($e) => $e->toArray(), $escrows)
        ]);
    }

    /**
     * Historique des paiements du chauffeur
     */
    #[Route('/history', name: 'api_escrow_history', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function history(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $type = $request->query->get('type', 'all'); // seller, buyer, all

        $asSeller = [];
        $asBuyer = [];

        if ($type === 'all' || $type === 'seller') {
            $asSeller = $this->escrowRepository->findBySeller($user);
        }
        
        if ($type === 'all' || $type === 'buyer') {
            $asBuyer = $this->escrowRepository->findByBuyer($user);
        }

        return $this->json([
            'success' => true,
            'asSeller' => array_map(fn($e) => $e->toArray(), $asSeller),
            'asBuyer' => array_map(fn($e) => $e->toArray(), $asBuyer)
        ]);
    }

    /**
     * Prévisualise les pénalités d'annulation
     */
    #[Route('/{id}/cancellation-preview', name: 'api_escrow_cancellation_preview', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function cancellationPreview(EscrowPayment $escrow): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        if ($escrow->getSeller()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $ride = $escrow->getRide();
        $rideDateTime = $ride->getRideDateTime();

        if (!$rideDateTime) {
            return $this->json(['error' => 'Impossible de calculer les pénalités'], 400);
        }

        if ($escrow->getBuyer() === null) {
            return $this->json([
                'success' => true,
                'hasBuyer' => false,
                'refundAmount' => (float) $escrow->getTotalAmount(),
                'compensationAmount' => 0,
                'message' => 'Aucun chauffeur n\'a accepté. Remboursement intégral possible.'
            ]);
        }

        $amounts = $escrow->calculateCancellationAmounts($rideDateTime);

        $hoursUntilRide = ($rideDateTime->getTimestamp() - time()) / 3600;
        
        if ($hoursUntilRide > 48) {
            $tier = 'Plus de 48h avant la course';
        } elseif ($hoursUntilRide > 24) {
            $tier = 'Entre 24h et 48h avant la course';
        } elseif ($hoursUntilRide > 6) {
            $tier = 'Entre 6h et 24h avant la course';
        } else {
            $tier = 'Moins de 6h avant la course';
        }

        return $this->json([
            'success' => true,
            'hasBuyer' => true,
            'hoursUntilRide' => round($hoursUntilRide, 1),
            'tier' => $tier,
            'refundAmount' => $amounts['refundAmount'],
            'compensationAmount' => $amounts['compensationAmount'],
            'wayzoKeeps' => $amounts['wayzoKeeps'],
            'message' => sprintf(
                '%s - Vous serez remboursé de %.2f€. Le chauffeur recevra %.2f€ de compensation.',
                $tier,
                $amounts['refundAmount'],
                $amounts['compensationAmount']
            )
        ]);
    }

    // ==================== ADMIN ENDPOINTS ====================

    /**
     * Liste tous les escrows (admin uniquement)
     */
    #[Route('/admin/list', name: 'api_escrow_admin_list', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminListAll(Request $request): JsonResponse
    {
        $status = $request->query->get('status'); // filtrer par statut
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
        
        $qb = $this->escrowRepository->createQueryBuilder('e')
            ->leftJoin('e.ride', 'r')
            ->leftJoin('e.seller', 's')
            ->leftJoin('e.buyer', 'b')
            ->orderBy('e.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('e.status = :status')->setParameter('status', $status);
        }

        // Compte total
        $countQb = clone $qb;
        $total = $countQb->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        // Pagination
        $escrows = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->json([
            'success' => true,
            'data' => array_map(fn($e) => $this->serializeEscrowForAdmin($e), $escrows),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => (int) $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
    }

    /**
     * Liste uniquement les litiges (disputed)
     */
    #[Route('/admin/disputes', name: 'api_escrow_admin_disputes', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminListDisputes(): JsonResponse
    {
        $disputes = $this->escrowRepository->findBy(
            ['status' => EscrowPayment::STATUS_DISPUTED],
            ['updatedAt' => 'DESC']
        );

        return $this->json([
            'success' => true,
            'count' => count($disputes),
            'data' => array_map(fn($e) => $this->serializeEscrowForAdmin($e), $disputes)
        ]);
    }

    /**
     * Détail complet d'un escrow (admin)
     */
    #[Route('/admin/{id}', name: 'api_escrow_admin_detail', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminDetail(EscrowPayment $escrow): JsonResponse
    {
        return $this->json([
            'success' => true,
            'escrow' => $this->serializeEscrowForAdmin($escrow),
            'ride' => $this->serializeRideForAdmin($escrow->getRide()),
            'timeline' => $this->getEscrowTimeline($escrow)
        ]);
    }

    /**
     * Résoudre un litige en faveur du vendeur (remboursement au seller)
     */
    #[Route('/admin/{id}/resolve-for-seller', name: 'api_escrow_admin_resolve_seller', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminResolveForSeller(EscrowPayment $escrow, Request $request): JsonResponse
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_DISPUTED) {
            return $this->json(['error' => 'Seuls les escrows en litige peuvent être résolus'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $adminNote = $data['note'] ?? 'Résolu par admin en faveur du vendeur';

        try {
            // Rembourser le seller intégralement (moins les frais Stripe éventuels)
            $this->escrowService->adminResolveDispute($escrow, 'seller', $adminNote);

            return $this->json([
                'success' => true,
                'message' => 'Litige résolu en faveur du vendeur. Remboursement effectué.',
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Résoudre un litige en faveur de l'acheteur (paiement au buyer)
     */
    #[Route('/admin/{id}/resolve-for-buyer', name: 'api_escrow_admin_resolve_buyer', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminResolveForBuyer(EscrowPayment $escrow, Request $request): JsonResponse
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_DISPUTED) {
            return $this->json(['error' => 'Seuls les escrows en litige peuvent être résolus'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $adminNote = $data['note'] ?? 'Résolu par admin en faveur de l\'acheteur';

        try {
            // Payer le buyer (la course a été effectuée)
            $this->escrowService->adminResolveDispute($escrow, 'buyer', $adminNote);

            return $this->json([
                'success' => true,
                'message' => 'Litige résolu en faveur de l\'acheteur. Paiement effectué.',
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Résoudre un litige avec partage personnalisé
     */
    #[Route('/admin/{id}/resolve-custom', name: 'api_escrow_admin_resolve_custom', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminResolveCustom(EscrowPayment $escrow, Request $request): JsonResponse
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_DISPUTED) {
            return $this->json(['error' => 'Seuls les escrows en litige peuvent être résolus'], 400);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['refundToSeller']) || !isset($data['payToBuyer'])) {
            return $this->json(['error' => 'Montants de remboursement requis'], 400);
        }

        $refundToSeller = (float) $data['refundToSeller'];
        $payToBuyer = (float) $data['payToBuyer'];
        $adminNote = $data['note'] ?? 'Résolution personnalisée par admin';

        $totalAmount = (float) $escrow->getTotalAmount();
        
        if ($refundToSeller + $payToBuyer > $totalAmount) {
            return $this->json([
                'error' => sprintf(
                    'Le total des montants (%.2f€) dépasse le montant disponible (%.2f€)',
                    $refundToSeller + $payToBuyer,
                    $totalAmount
                )
            ], 400);
        }

        try {
            $this->escrowService->adminResolveDisputeCustom(
                $escrow,
                $refundToSeller,
                $payToBuyer,
                $adminNote
            );

            return $this->json([
                'success' => true,
                'message' => sprintf(
                    'Litige résolu. Vendeur remboursé: %.2f€, Acheteur payé: %.2f€',
                    $refundToSeller,
                    $payToBuyer
                ),
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Forcer le remboursement complet (cas exceptionnel)
     */
    #[Route('/admin/{id}/force-refund', name: 'api_escrow_admin_force_refund', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminForceRefund(EscrowPayment $escrow, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $reason = $data['reason'] ?? 'Remboursement forcé par admin';

        if (in_array($escrow->getStatus(), [
            EscrowPayment::STATUS_COMPLETED,
            EscrowPayment::STATUS_REFUNDED
        ])) {
            return $this->json(['error' => 'Cet escrow ne peut plus être remboursé'], 400);
        }

        try {
            $this->escrowService->adminForceRefund($escrow, $reason);

            return $this->json([
                'success' => true,
                'message' => 'Remboursement forcé effectué avec succès',
                'escrow' => $escrow->toArray()
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Ajouter une note admin à un escrow
     */
    #[Route('/admin/{id}/note', name: 'api_escrow_admin_add_note', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminAddNote(EscrowPayment $escrow, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['note']) || empty($data['note'])) {
            return $this->json(['error' => 'Note requise'], 400);
        }

        /** @var Chauffeur $admin */
        $admin = $this->getUser();
        $timestamp = (new \DateTime())->format('Y-m-d H:i:s');
        $adminName = $admin->getFullName();
        
        $existingNotes = $escrow->getNotes() ?? '';
        $newNote = sprintf("[%s - %s] %s", $timestamp, $adminName, $data['note']);
        
        $escrow->setNotes($existingNotes . ($existingNotes ? "\n" : '') . $newNote);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Note ajoutée',
            'notes' => $escrow->getNotes()
        ]);
    }

    /**
     * Statistiques des escrows (admin)
     */
    #[Route('/admin/stats', name: 'api_escrow_admin_stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminStats(): JsonResponse
    {
        $stats = [
            'pending' => $this->escrowRepository->count(['status' => EscrowPayment::STATUS_PENDING]),
            'fundsHeld' => $this->escrowRepository->count(['status' => EscrowPayment::STATUS_HELD]),
            'awaitingValidation' => $this->escrowRepository->count(['status' => EscrowPayment::STATUS_AWAITING_VALIDATION]),
            'completed' => $this->escrowRepository->count(['status' => EscrowPayment::STATUS_COMPLETED]),
            'disputed' => $this->escrowRepository->count(['status' => EscrowPayment::STATUS_DISPUTED]),
            'refunded' => $this->escrowRepository->count(['status' => EscrowPayment::STATUS_REFUNDED]),
            'failed' => $this->escrowRepository->count(['status' => EscrowPayment::STATUS_FAILED]),
        ];

        // Total des montants
        $totals = $this->escrowRepository->createQueryBuilder('e')
            ->select('SUM(e.rideAmount) as totalRide, SUM(e.commissionAmount) as totalCommission, SUM(e.totalAmount) as totalAmount')
            ->where('e.status = :completed')
            ->setParameter('completed', EscrowPayment::STATUS_COMPLETED)
            ->getQuery()
            ->getSingleResult();

        return $this->json([
            'success' => true,
            'statusCounts' => $stats,
            'totalCompleted' => [
                'rideAmount' => (float) ($totals['totalRide'] ?? 0),
                'commissionAmount' => (float) ($totals['totalCommission'] ?? 0),
                'totalAmount' => (float) ($totals['totalAmount'] ?? 0)
            ]
        ]);
    }

    // ==================== HELPERS ====================

    /**
     * Sérialise un escrow avec toutes les infos pour l'admin
     */
    private function serializeEscrowForAdmin(EscrowPayment $escrow): array
    {
        $data = $escrow->toArray();
        
        // Ajouter les infos complètes du seller
        $seller = $escrow->getSeller();
        $data['seller'] = [
            'id' => $seller->getId(),
            'fullName' => $seller->getPrenom() . ' ' . $seller->getNom(),
            'email' => $seller->getEmail(),
            'phone' => $seller->getTel(), // Utilise getTel() de Chauffeur
            'stripeAccountId' => $seller->getStripeAccountId()
        ];

        // Ajouter les infos complètes du buyer
        $buyer = $escrow->getBuyer();
        if ($buyer) {
            $data['buyer'] = [
                'id' => $buyer->getId(),
                'fullName' => $buyer->getPrenom() . ' ' . $buyer->getNom(),
                'email' => $buyer->getEmail(),
                'phone' => $buyer->getTel(), // Utilise getTel() de Chauffeur
                'stripeAccountId' => $buyer->getStripeAccountId()
            ];
        }

        // Ajouter les IDs Stripe pour investigation
        $data['stripe'] = [
            'paymentIntentId' => $escrow->getStripePaymentIntentId(),
            'transferId' => $escrow->getStripeTransferId(),
            'refundId' => $escrow->getStripeRefundId()
        ];

        return $data;
    }

    /**
     * Sérialise une course avec toutes les infos pour l'admin
     */
    private function serializeRideForAdmin(Ride $ride): array
    {
        return [
            'id' => $ride->getId(),
            'clientName' => $ride->getClientName(),
            'clientContact' => $ride->getClientContact(),
            'depart' => $ride->getDepart(),
            'destination' => $ride->getDestination(),
            'date' => $ride->getDate()?->format('Y-m-d'),
            'time' => $ride->getTime()?->format('H:i'),
            'price' => $ride->getPrice(),
            'status' => $ride->getStatus(),
            'paymentStatus' => $ride->getPaymentStatus()
        ];
    }

    /**
     * Génère une timeline des événements de l'escrow
     */
    private function getEscrowTimeline(EscrowPayment $escrow): array
    {
        $timeline = [];

        $timeline[] = [
            'event' => 'Création',
            'date' => $escrow->getCreatedAt()->format('Y-m-d H:i:s'),
            'status' => 'created'
        ];

        if ($escrow->getHeldAt()) {
            $timeline[] = [
                'event' => 'Fonds bloqués',
                'date' => $escrow->getHeldAt()->format('Y-m-d H:i:s'),
                'status' => 'funds_held',
                'amount' => (float) $escrow->getTotalAmount()
            ];
        }

        if ($escrow->getMarkedCompletedAt()) {
            $timeline[] = [
                'event' => 'Marqué comme terminé par l\'acheteur',
                'date' => $escrow->getMarkedCompletedAt()->format('Y-m-d H:i:s'),
                'status' => 'marked_completed'
            ];
        }

        if ($escrow->getConfirmedAt()) {
            $timeline[] = [
                'event' => 'Confirmé par le vendeur',
                'date' => $escrow->getConfirmedAt()->format('Y-m-d H:i:s'),
                'status' => 'confirmed'
            ];
        }

        if ($escrow->getPaidAt()) {
            $timeline[] = [
                'event' => 'Paiement effectué',
                'date' => $escrow->getPaidAt()->format('Y-m-d H:i:s'),
                'status' => 'paid',
                'amount' => (float) $escrow->getRideAmount()
            ];
        }

        if ($escrow->getRefundedAt()) {
            $timeline[] = [
                'event' => 'Remboursement effectué',
                'date' => $escrow->getRefundedAt()->format('Y-m-d H:i:s'),
                'status' => 'refunded',
                'amount' => (float) $escrow->getRefundedAmount()
            ];
        }

        return $timeline;
    }
}

