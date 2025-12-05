<?php

namespace App\Service;

use App\Entity\EscrowPayment;
use App\Entity\Ride;
use App\Entity\Chauffeur;
use App\Repository\EscrowPaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service de gestion des paiements en séquestre (escrow)
 * 
 * Workflow complet :
 * 1. createEscrow() - Chauffeur A publie, Stripe bloque 115€
 * 2. assignBuyer() - Chauffeur B accepte la course
 * 3. markCompleted() - B marque la course terminée
 * 4. confirmByOwner() - A confirme (ou auto après 24h)
 * 5. releasePayment() - B reçoit 100€, WayZo 15€
 */
class EscrowService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StripeService $stripeService,
        private EscrowPaymentRepository $escrowRepository,
        private ?LoggerInterface $logger = null
    ) {}

    /**
     * Crée un paiement escrow lors de la publication d'une course
     * Bloque les fonds sur Stripe (prix + 15% commission)
     * 
     * @param Ride $ride La course à publier
     * @param Chauffeur $seller Le chauffeur qui publie (vendeur)
     * @param string|null $paymentMethodId ID du moyen de paiement Stripe (optionnel)
     */
    public function createEscrow(Ride $ride, Chauffeur $seller, ?string $paymentMethodId = null): EscrowPayment
    {
        // Vérifier que le chauffeur a un moyen de paiement
        if (!$seller->getStripeCustomerId()) {
            throw new \Exception('Vous devez configurer un moyen de paiement avant de publier une course.');
        }

        // Calculer les montants
        $ridePrice = $ride->getPrice();
        $amounts = EscrowPayment::calculateAmounts($ridePrice);

        // Créer le PaymentIntent Stripe (bloque les fonds)
        $totalAmountCents = (int) ($amounts['totalAmount'] * 100);
        
        try {
            $paymentIntent = $this->stripeService->createPaymentIntent(
                $totalAmountCents,
                'eur',
                $seller->getStripeCustomerId(),
                $paymentMethodId,
                [
                    'ride_id' => $ride->getId(),
                    'seller_id' => $seller->getId(),
                    'type' => 'escrow',
                    'ride_amount' => $amounts['rideAmount'],
                    'commission' => $amounts['commissionAmount']
                ]
            );
        } catch (\Exception $e) {
            $this->logger?->error('Erreur création PaymentIntent escrow', [
                'error' => $e->getMessage(),
                'ride_id' => $ride->getId()
            ]);
            throw new \Exception('Impossible de bloquer les fonds. Vérifiez votre moyen de paiement.');
        }

        // Créer l'entité EscrowPayment
        $escrow = new EscrowPayment();
        $escrow->setRide($ride)
            ->setSeller($seller)
            ->setRideAmount((string) $amounts['rideAmount'])
            ->setCommissionAmount((string) $amounts['commissionAmount'])
            ->setTotalAmount((string) $amounts['totalAmount'])
            ->setStripePaymentIntentId($paymentIntent['id'])
            ->setStatus(EscrowPayment::STATUS_HELD)
            ->setHeldAt(new \DateTimeImmutable());

        // Mettre à jour le statut de paiement de la course
        $ride->setPaymentStatus('secured');
        $ride->setEscrowPayment($escrow);

        $this->entityManager->persist($escrow);
        $this->entityManager->flush();

        $this->logger?->info('Escrow créé avec succès', [
            'escrow_id' => $escrow->getId(),
            'ride_id' => $ride->getId(),
            'total_blocked' => $amounts['totalAmount']
        ]);

        return $escrow;
    }

    /**
     * Assigne un chauffeur B (buyer) quand il accepte la course
     */
    public function assignBuyer(EscrowPayment $escrow, Chauffeur $buyer): void
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_HELD) {
            throw new \Exception('Cette course n\'est plus disponible.');
        }

        if ($escrow->getSeller()->getId() === $buyer->getId()) {
            throw new \Exception('Vous ne pouvez pas accepter votre propre course.');
        }

        // Vérifier que B a un compte Connect configuré
        if (!$buyer->getStripeAccountId() || !$buyer->isStripeAccountComplete()) {
            throw new \Exception('Vous devez configurer votre compte bancaire pour recevoir des paiements.');
        }

        $escrow->setBuyer($buyer);
        $this->entityManager->flush();

        $this->logger?->info('Buyer assigné à l\'escrow', [
            'escrow_id' => $escrow->getId(),
            'buyer_id' => $buyer->getId()
        ]);
    }

    /**
     * Marque la course comme terminée (appelé par le chauffeur B)
     * Démarre le délai de 24h pour validation par A
     */
    public function markCompleted(EscrowPayment $escrow, Chauffeur $buyer): void
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_HELD) {
            throw new \Exception('Cette course ne peut pas être marquée comme terminée.');
        }

        if ($escrow->getBuyer()?->getId() !== $buyer->getId()) {
            throw new \Exception('Vous n\'êtes pas autorisé à effectuer cette action.');
        }

        $now = new \DateTimeImmutable();
        $escrow->setMarkedCompletedAt($now);
        $escrow->setStatus(EscrowPayment::STATUS_AWAITING_VALIDATION);

        // Mettre à jour le statut de la course
        $ride = $escrow->getRide();
        $ride->setStatus('completed');
        $ride->setPaymentStatus('awaiting_validation');

        $this->entityManager->flush();

        $this->logger?->info('Course marquée terminée, en attente de validation', [
            'escrow_id' => $escrow->getId(),
            'validation_deadline' => $escrow->getValidationDeadline()->format('c')
        ]);

        // TODO: Envoyer notification au vendeur (A)
    }

    /**
     * Confirmation par le propriétaire (A) que la course a bien été effectuée
     */
    public function confirmByOwner(EscrowPayment $escrow, Chauffeur $seller): void
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_AWAITING_VALIDATION) {
            throw new \Exception('Cette course n\'est pas en attente de validation.');
        }

        if ($escrow->getSeller()->getId() !== $seller->getId()) {
            throw new \Exception('Vous n\'êtes pas autorisé à effectuer cette action.');
        }

        $escrow->setConfirmedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Libérer le paiement
        $this->releasePayment($escrow);
    }

    /**
     * Libère le paiement : envoie l'argent à B et la commission à WayZo
     */
    public function releasePayment(EscrowPayment $escrow): void
    {
        if (!$escrow->canReleaseToBuyer()) {
            throw new \Exception('Le paiement ne peut pas être libéré.');
        }

        $buyer = $escrow->getBuyer();
        $rideAmountCents = (int) ((float) $escrow->getRideAmount() * 100);

        try {
            // Transférer au chauffeur B via Stripe Connect
            $transfer = $this->stripeService->createTransfer(
                $rideAmountCents,
                $buyer->getStripeAccountId(),
                'eur',
                [
                    'escrow_id' => $escrow->getId(),
                    'ride_id' => $escrow->getRide()->getId(),
                    'type' => 'ride_payment'
                ]
            );

            $escrow->setStripeTransferId($transfer['id']);
            $escrow->setPaidAt(new \DateTimeImmutable());
            $escrow->setStatus(EscrowPayment::STATUS_COMPLETED);

            // Mettre à jour le statut de paiement de la course
            $escrow->getRide()->setPaymentStatus('paid');

            $this->entityManager->flush();

            $this->logger?->info('Paiement libéré avec succès', [
                'escrow_id' => $escrow->getId(),
                'buyer_id' => $buyer->getId(),
                'amount' => $escrow->getRideAmount(),
                'commission' => $escrow->getCommissionAmount()
            ]);

            // TODO: Envoyer notification de paiement reçu à B
            // TODO: Envoyer récapitulatif à A

        } catch (\Exception $e) {
            $this->logger?->error('Erreur lors du transfert', [
                'escrow_id' => $escrow->getId(),
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Erreur lors du paiement. Veuillez contacter le support.');
        }
    }

    /**
     * Annulation par le vendeur (A) AVANT qu'un chauffeur accepte
     * Remboursement total (115€)
     */
    public function cancelBeforeAcceptance(EscrowPayment $escrow, Chauffeur $seller): void
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_HELD) {
            throw new \Exception('Cette course ne peut pas être annulée.');
        }

        if ($escrow->getSeller()->getId() !== $seller->getId()) {
            throw new \Exception('Vous n\'êtes pas autorisé à effectuer cette action.');
        }

        if ($escrow->getBuyer() !== null) {
            throw new \Exception('Un chauffeur a déjà accepté cette course. Utilisez l\'annulation avec pénalité.');
        }

        try {
            // Remboursement total via Stripe
            $refund = $this->stripeService->createRefund($escrow->getStripePaymentIntentId());

            $escrow->setStripeRefundId($refund['id']);
            $escrow->setRefundedAt(new \DateTimeImmutable());
            $escrow->setRefundedAmount($escrow->getTotalAmount());
            $escrow->setStatus(EscrowPayment::STATUS_REFUNDED);
            $escrow->setCancelReason(EscrowPayment::CANCEL_REASON_SELLER_BEFORE_ACCEPT);

            // Annuler la course
            $ride = $escrow->getRide();
            $ride->setStatus('cancelled');
            $ride->setPaymentStatus('refunded');

            $this->entityManager->flush();

            $this->logger?->info('Course annulée et remboursée (avant acceptation)', [
                'escrow_id' => $escrow->getId(),
                'refunded_amount' => $escrow->getTotalAmount()
            ]);

        } catch (\Exception $e) {
            $this->logger?->error('Erreur remboursement', [
                'escrow_id' => $escrow->getId(),
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Erreur lors du remboursement. Veuillez contacter le support.');
        }
    }

    /**
     * Annulation par le vendeur (A) APRÈS qu'un chauffeur a accepté
     * Applique les pénalités selon le délai avant la course
     */
    public function cancelAfterAcceptance(EscrowPayment $escrow, Chauffeur $seller): void
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_HELD) {
            throw new \Exception('Cette course ne peut pas être annulée.');
        }

        if ($escrow->getSeller()->getId() !== $seller->getId()) {
            throw new \Exception('Vous n\'êtes pas autorisé à effectuer cette action.');
        }

        if ($escrow->getBuyer() === null) {
            // Pas de buyer, utiliser l'annulation simple
            $this->cancelBeforeAcceptance($escrow, $seller);
            return;
        }

        $ride = $escrow->getRide();
        $rideDateTime = $ride->getRideDateTime();

        if (!$rideDateTime) {
            throw new \Exception('Impossible de déterminer la date de la course.');
        }

        // Calculer les montants selon le délai
        $amounts = $escrow->calculateCancellationAmounts($rideDateTime);
        $buyer = $escrow->getBuyer();

        try {
            // 1. Rembourser partiellement le vendeur
            if ($amounts['refundAmount'] > 0) {
                $refundAmountCents = (int) ($amounts['refundAmount'] * 100);
                $refund = $this->stripeService->createRefund(
                    $escrow->getStripePaymentIntentId(),
                    $refundAmountCents
                );
                $escrow->setStripeRefundId($refund['id']);
            }

            // 2. Transférer la compensation au buyer (B)
            if ($amounts['compensationAmount'] > 0 && $buyer->getStripeAccountId()) {
                $compensationCents = (int) ($amounts['compensationAmount'] * 100);
                $this->stripeService->createTransfer(
                    $compensationCents,
                    $buyer->getStripeAccountId(),
                    'eur',
                    [
                        'escrow_id' => $escrow->getId(),
                        'type' => 'cancellation_compensation'
                    ]
                );
            }

            // Mettre à jour l'escrow
            $escrow->setRefundedAt(new \DateTimeImmutable());
            $escrow->setRefundedAmount((string) $amounts['refundAmount']);
            $escrow->setCompensationAmount((string) $amounts['compensationAmount']);
            $escrow->setStatus(EscrowPayment::STATUS_PARTIAL_REFUND);
            $escrow->setCancelReason(EscrowPayment::CANCEL_REASON_SELLER_AFTER_ACCEPT);

            // Annuler la course
            $ride->setStatus('cancelled');
            $ride->setPaymentStatus('partial_refund');
            $ride->setChauffeurAccepteur(null); // Retirer l'acceptation

            $this->entityManager->flush();

            $this->logger?->info('Course annulée avec pénalités', [
                'escrow_id' => $escrow->getId(),
                'refunded_to_seller' => $amounts['refundAmount'],
                'compensation_to_buyer' => $amounts['compensationAmount'],
                'wayzo_keeps' => $amounts['wayzoKeeps']
            ]);

            // TODO: Notifier A et B

        } catch (\Exception $e) {
            $this->logger?->error('Erreur annulation avec pénalités', [
                'escrow_id' => $escrow->getId(),
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Erreur lors de l\'annulation. Veuillez contacter le support.');
        }
    }

    /**
     * Annulation par le buyer (B)
     * Remet la course en ligne pour un autre chauffeur
     */
    public function cancelByBuyer(EscrowPayment $escrow, Chauffeur $buyer): void
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_HELD) {
            throw new \Exception('Cette course ne peut pas être annulée.');
        }

        if ($escrow->getBuyer()?->getId() !== $buyer->getId()) {
            throw new \Exception('Vous n\'êtes pas autorisé à effectuer cette action.');
        }

        // Retirer le buyer, la course redevient disponible
        $escrow->setBuyer(null);
        
        $ride = $escrow->getRide();
        $ride->setStatus('available');
        $ride->setChauffeurAccepteur(null);

        $escrow->setNotes(($escrow->getNotes() ?? '') . "\nAnnulé par le chauffeur B le " . date('d/m/Y H:i'));

        $this->entityManager->flush();

        $this->logger?->info('Course annulée par le buyer, remise en ligne', [
            'escrow_id' => $escrow->getId(),
            'buyer_id' => $buyer->getId()
        ]);

        // TODO: Incrémenter le compteur d'annulations de B
        // TODO: Notifier A que la course est de nouveau disponible
    }

    /**
     * Ouvre un litige sur le paiement
     */
    public function openDispute(EscrowPayment $escrow, Chauffeur $initiator, string $reason): void
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_AWAITING_VALIDATION) {
            throw new \Exception('Un litige ne peut être ouvert qu\'après la fin de la course.');
        }

        // Vérifier que l'initiateur est le seller ou le buyer
        if ($escrow->getSeller()->getId() !== $initiator->getId() 
            && $escrow->getBuyer()?->getId() !== $initiator->getId()) {
            throw new \Exception('Vous n\'êtes pas autorisé à ouvrir un litige.');
        }

        $escrow->setStatus(EscrowPayment::STATUS_DISPUTED);
        $escrow->setNotes("LITIGE ouvert par chauffeur #{$initiator->getId()} le " . date('d/m/Y H:i') . "\nRaison: " . $reason);

        $escrow->getRide()->setPaymentStatus('disputed');

        $this->entityManager->flush();

        $this->logger?->warning('Litige ouvert', [
            'escrow_id' => $escrow->getId(),
            'initiator_id' => $initiator->getId(),
            'reason' => $reason
        ]);

        // TODO: Notifier l'équipe WayZo
        // TODO: Notifier l'autre partie
    }

    /**
     * Traite les validations automatiques (à appeler via CRON)
     * Libère les paiements dont la deadline de 24h est dépassée
     */
    public function processAutoValidations(): int
    {
        $expired = $this->escrowRepository->findExpiredValidations();
        $count = 0;

        foreach ($expired as $escrow) {
            try {
                $this->releasePayment($escrow);
                $count++;
                
                $this->logger?->info('Validation automatique effectuée', [
                    'escrow_id' => $escrow->getId()
                ]);
            } catch (\Exception $e) {
                $this->logger?->error('Erreur validation automatique', [
                    'escrow_id' => $escrow->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $count;
    }

    // ==================== ADMIN METHODS ====================

    /**
     * Résoudre un litige en faveur d'une partie (admin uniquement)
     */
    public function adminResolveDispute(EscrowPayment $escrow, string $favorOf, string $adminNote): void
    {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_DISPUTED) {
            throw new \Exception('Seuls les escrows en litige peuvent être résolus');
        }

        $escrow->setNotes(
            ($escrow->getNotes() ? $escrow->getNotes() . "\n" : '') .
            "[ADMIN] " . (new \DateTime())->format('Y-m-d H:i:s') . " - " . $adminNote
        );

        if ($favorOf === 'seller') {
            // Rembourser le vendeur intégralement
            $totalAmount = (int) ($escrow->getTotalAmount() * 100);
            
            try {
                $refund = $this->stripeService->createRefund(
                    $escrow->getStripePaymentIntentId(),
                    $totalAmount
                );
                $escrow->setStripeRefundId($refund['id']);

                $escrow->setStatus(EscrowPayment::STATUS_REFUNDED);
                $escrow->setRefundedAmount($escrow->getTotalAmount());
                $escrow->setRefundedAt(new \DateTimeImmutable());
                $escrow->getRide()->setPaymentStatus('refunded');

            } catch (\Exception $e) {
                throw new \Exception('Erreur Stripe lors du remboursement: ' . $e->getMessage());
            }
        } elseif ($favorOf === 'buyer') {
            // Payer l'acheteur (la course a été effectuée)
            try {
                $this->releasePayment($escrow);
            } catch (\Exception $e) {
                throw new \Exception('Erreur lors du paiement: ' . $e->getMessage());
            }
        } else {
            throw new \InvalidArgumentException('favorOf doit être "seller" ou "buyer"');
        }

        $this->entityManager->flush();

        $this->logger?->info('Litige résolu par admin', [
            'escrow_id' => $escrow->getId(),
            'favor_of' => $favorOf,
            'note' => $adminNote
        ]);
    }

    /**
     * Résoudre un litige avec montants personnalisés (admin uniquement)
     */
    public function adminResolveDisputeCustom(
        EscrowPayment $escrow,
        float $refundToSeller,
        float $payToBuyer,
        string $adminNote
    ): void {
        if ($escrow->getStatus() !== EscrowPayment::STATUS_DISPUTED) {
            throw new \Exception('Seuls les escrows en litige peuvent être résolus');
        }

        $escrow->setNotes(
            ($escrow->getNotes() ? $escrow->getNotes() . "\n" : '') .
            "[ADMIN CUSTOM] " . (new \DateTime())->format('Y-m-d H:i:s') . 
            " - Remboursement vendeur: {$refundToSeller}€, Paiement acheteur: {$payToBuyer}€ - " . $adminNote
        );

        // Rembourser partiellement le vendeur si nécessaire
        if ($refundToSeller > 0) {
            $refundAmountCents = (int) ($refundToSeller * 100);
            
            try {
                $refund = $this->stripeService->createRefund(
                    $escrow->getStripePaymentIntentId(),
                    $refundAmountCents
                );
                $escrow->setStripeRefundId($refund['id']);
                $escrow->setRefundedAmount($refundToSeller);
                $escrow->setRefundedAt(new \DateTimeImmutable());
            } catch (\Exception $e) {
                throw new \Exception('Erreur Stripe lors du remboursement: ' . $e->getMessage());
            }
        }

        // Payer l'acheteur si nécessaire
        if ($payToBuyer > 0 && $escrow->getBuyer()?->getStripeAccountId()) {
            $payAmountCents = (int) ($payToBuyer * 100);
            
            try {
                $transfer = $this->stripeService->createTransfer(
                    $payAmountCents,
                    $escrow->getBuyer()->getStripeAccountId(),
                    'Résolution litige escrow #' . $escrow->getId()
                );
                $escrow->setStripeTransferId($transfer['id']);
                $escrow->setCompensationAmount($payToBuyer);
            } catch (\Exception $e) {
                throw new \Exception('Erreur Stripe lors du transfert: ' . $e->getMessage());
            }
        }

        $escrow->setStatus(EscrowPayment::STATUS_PARTIAL_REFUND);
        $escrow->getRide()->setPaymentStatus('partial_refund');

        $this->entityManager->flush();

        $this->logger?->info('Litige résolu avec montants personnalisés', [
            'escrow_id' => $escrow->getId(),
            'refund_to_seller' => $refundToSeller,
            'pay_to_buyer' => $payToBuyer
        ]);
    }

    /**
     * Forcer un remboursement complet (admin uniquement)
     */
    public function adminForceRefund(EscrowPayment $escrow, string $reason): void
    {
        if (in_array($escrow->getStatus(), [
            EscrowPayment::STATUS_COMPLETED,
            EscrowPayment::STATUS_REFUNDED
        ])) {
            throw new \Exception('Cet escrow ne peut plus être remboursé');
        }

        $escrow->setNotes(
            ($escrow->getNotes() ? $escrow->getNotes() . "\n" : '') .
            "[ADMIN FORCE REFUND] " . (new \DateTime())->format('Y-m-d H:i:s') . " - " . $reason
        );

        // Si un PaymentIntent existe, le rembourser
        if ($escrow->getStripePaymentIntentId()) {
            $totalAmount = (int) ($escrow->getTotalAmount() * 100);
            
            try {
                $refund = $this->stripeService->createRefund(
                    $escrow->getStripePaymentIntentId(),
                    $totalAmount
                );
                $escrow->setStripeRefundId($refund['id']);
            } catch (\Exception $e) {
                $this->logger?->error('Erreur lors du remboursement forcé', [
                    'escrow_id' => $escrow->getId(),
                    'error' => $e->getMessage()
                ]);
                throw new \Exception('Erreur Stripe lors du remboursement: ' . $e->getMessage());
            }
        }

        $escrow->setStatus(EscrowPayment::STATUS_REFUNDED);
        $escrow->setRefundedAmount($escrow->getTotalAmount());
        $escrow->setRefundedAt(new \DateTimeImmutable());
        $escrow->setCancelReason($reason);
        $escrow->getRide()->setPaymentStatus('refunded');

        $this->entityManager->flush();

        $this->logger?->warning('Remboursement forcé par admin', [
            'escrow_id' => $escrow->getId(),
            'reason' => $reason
        ]);
    }

    /**
     * Récupère les statistiques des paiements
     */
    public function getStats(): array
    {
        return $this->escrowRepository->getStats();
    }
}
