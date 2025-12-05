<?php

namespace App\Entity;

use App\Repository\EscrowPaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Gestion des paiements en séquestre (escrow)
 * 
 * Workflow:
 * 1. Chauffeur A publie course à 100€ → Stripe bloque 115€ (100€ + 15% commission)
 * 2. Chauffeur B accepte et effectue la course
 * 3. B marque "Terminée" → A a 24h pour confirmer/contester
 * 4. Validation → B reçoit 100€, WayZo reçoit 15€
 */
#[ORM\Entity(repositoryClass: EscrowPaymentRepository::class)]
#[ORM\HasLifecycleCallbacks]
class EscrowPayment
{
    // Statuts du paiement escrow
    public const STATUS_PENDING = 'pending';           // En attente de création PaymentIntent
    public const STATUS_HELD = 'held';                 // Fonds bloqués sur Stripe
    public const STATUS_AWAITING_VALIDATION = 'awaiting_validation'; // Course terminée, en attente de validation
    public const STATUS_COMPLETED = 'completed';       // Paiement effectué à B
    public const STATUS_REFUNDED = 'refunded';         // Remboursé à A
    public const STATUS_PARTIAL_REFUND = 'partial_refund'; // Remboursement partiel (annulation tardive)
    public const STATUS_DISPUTED = 'disputed';         // Litige en cours
    public const STATUS_FAILED = 'failed';             // Échec du paiement

    // Raisons d'annulation
    public const CANCEL_REASON_SELLER_BEFORE_ACCEPT = 'seller_before_accept';  // A annule avant acceptation
    public const CANCEL_REASON_SELLER_AFTER_ACCEPT = 'seller_after_accept';    // A annule après acceptation
    public const CANCEL_REASON_BUYER_CANCEL = 'buyer_cancel';                  // B annule
    public const CANCEL_REASON_DISPUTE = 'dispute';                            // Litige

    // Commission WayZo (15%)
    public const COMMISSION_RATE = 0.15;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Course associée
     */
    #[ORM\OneToOne(inversedBy: 'escrowPayment', cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false)]
    private ?Ride $ride = null;

    /**
     * Chauffeur vendeur (A) - celui qui publie et paie
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Chauffeur $seller = null;

    /**
     * Chauffeur acheteur (B) - celui qui effectue et reçoit
     */
    #[ORM\ManyToOne]
    private ?Chauffeur $buyer = null;

    /**
     * Prix de la course (ce que B recevra)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $rideAmount = null;

    /**
     * Commission WayZo (15% du prix)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $commissionAmount = null;

    /**
     * Montant total bloqué (rideAmount + commissionAmount)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $totalAmount = null;

    /**
     * Statut du paiement escrow
     */
    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PENDING;

    /**
     * ID du PaymentIntent Stripe (pour bloquer les fonds)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    /**
     * ID du Transfer Stripe (paiement à B)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeTransferId = null;

    /**
     * ID du Refund Stripe (si remboursement)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeRefundId = null;

    /**
     * Date de blocage des fonds
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $heldAt = null;

    /**
     * Date de marquage "terminée" par B
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $markedCompletedAt = null;

    /**
     * Date limite de validation par A (markedCompletedAt + 24h)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validationDeadline = null;

    /**
     * Date de confirmation par A
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    /**
     * Date du paiement effectif à B
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    /**
     * Date de remboursement (si applicable)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    /**
     * Montant remboursé à A (si annulation)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $refundedAmount = null;

    /**
     * Montant de compensation à B (si annulation tardive par A)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $compensationAmount = null;

    /**
     * Raison de l'annulation
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $cancelReason = null;

    /**
     * Notes/commentaires sur le paiement
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ==================== GETTERS & SETTERS ====================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRide(): ?Ride
    {
        return $this->ride;
    }

    public function setRide(Ride $ride): static
    {
        $this->ride = $ride;
        return $this;
    }

    public function getSeller(): ?Chauffeur
    {
        return $this->seller;
    }

    public function setSeller(Chauffeur $seller): static
    {
        $this->seller = $seller;
        return $this;
    }

    public function getBuyer(): ?Chauffeur
    {
        return $this->buyer;
    }

    public function setBuyer(?Chauffeur $buyer): static
    {
        $this->buyer = $buyer;
        return $this;
    }

    public function getRideAmount(): ?string
    {
        return $this->rideAmount;
    }

    public function setRideAmount(string $rideAmount): static
    {
        $this->rideAmount = $rideAmount;
        return $this;
    }

    public function getCommissionAmount(): ?string
    {
        return $this->commissionAmount;
    }

    public function setCommissionAmount(string $commissionAmount): static
    {
        $this->commissionAmount = $commissionAmount;
        return $this;
    }

    public function getTotalAmount(): ?string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): static
    {
        $this->totalAmount = $totalAmount;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;
        return $this;
    }

    public function getStripeTransferId(): ?string
    {
        return $this->stripeTransferId;
    }

    public function setStripeTransferId(?string $stripeTransferId): static
    {
        $this->stripeTransferId = $stripeTransferId;
        return $this;
    }

    public function getStripeRefundId(): ?string
    {
        return $this->stripeRefundId;
    }

    public function setStripeRefundId(?string $stripeRefundId): static
    {
        $this->stripeRefundId = $stripeRefundId;
        return $this;
    }

    public function getHeldAt(): ?\DateTimeImmutable
    {
        return $this->heldAt;
    }

    public function setHeldAt(?\DateTimeImmutable $heldAt): static
    {
        $this->heldAt = $heldAt;
        return $this;
    }

    public function getMarkedCompletedAt(): ?\DateTimeImmutable
    {
        return $this->markedCompletedAt;
    }

    public function setMarkedCompletedAt(?\DateTimeImmutable $markedCompletedAt): static
    {
        $this->markedCompletedAt = $markedCompletedAt;
        
        // Calculer la deadline de validation (24h après)
        if ($markedCompletedAt) {
            $this->validationDeadline = $markedCompletedAt->modify('+24 hours');
        }
        
        return $this;
    }

    public function getValidationDeadline(): ?\DateTimeImmutable
    {
        return $this->validationDeadline;
    }

    public function setValidationDeadline(?\DateTimeImmutable $validationDeadline): static
    {
        $this->validationDeadline = $validationDeadline;
        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;
        return $this;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;
        return $this;
    }

    public function getRefundedAt(): ?\DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function setRefundedAt(?\DateTimeImmutable $refundedAt): static
    {
        $this->refundedAt = $refundedAt;
        return $this;
    }

    public function getRefundedAmount(): ?string
    {
        return $this->refundedAmount;
    }

    public function setRefundedAmount(?string $refundedAmount): static
    {
        $this->refundedAmount = $refundedAmount;
        return $this;
    }

    public function getCompensationAmount(): ?string
    {
        return $this->compensationAmount;
    }

    public function setCompensationAmount(?string $compensationAmount): static
    {
        $this->compensationAmount = $compensationAmount;
        return $this;
    }

    public function getCancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function setCancelReason(?string $cancelReason): static
    {
        $this->cancelReason = $cancelReason;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    // ==================== MÉTHODES UTILITAIRES ====================

    /**
     * Calcule les montants à partir du prix de la course
     */
    public static function calculateAmounts(float $ridePrice): array
    {
        $commission = round($ridePrice * self::COMMISSION_RATE, 2);
        $total = $ridePrice + $commission;
        
        return [
            'rideAmount' => $ridePrice,
            'commissionAmount' => $commission,
            'totalAmount' => $total
        ];
    }

    /**
     * Calcule le montant de remboursement selon le délai avant la course
     * 
     * > 48h : 100% (moins commission)
     * 24h-48h : 85€ remboursé, 15€ à B
     * < 24h : 70€ remboursé, 30€ à B
     * < 6h : 50€ remboursé, 50€ à B
     */
    public function calculateCancellationAmounts(\DateTimeInterface $rideDateTime): array
    {
        $now = new \DateTimeImmutable();
        $hoursUntilRide = ($rideDateTime->getTimestamp() - $now->getTimestamp()) / 3600;
        
        $rideAmount = (float) $this->rideAmount;
        $totalAmount = (float) $this->totalAmount;
        $commission = (float) $this->commissionAmount;
        
        if ($hoursUntilRide > 48) {
            // Plus de 48h : remboursement total moins commission WayZo
            return [
                'refundAmount' => $rideAmount, // A récupère le prix de la course
                'compensationAmount' => 0,      // B ne reçoit rien
                'wayzoKeeps' => $commission     // WayZo garde la commission
            ];
        } elseif ($hoursUntilRide > 24) {
            // 24h - 48h : 15€ à B
            $compensation = min(15, $rideAmount * 0.15);
            return [
                'refundAmount' => $rideAmount - $compensation,
                'compensationAmount' => $compensation,
                'wayzoKeeps' => $commission
            ];
        } elseif ($hoursUntilRide > 6) {
            // 6h - 24h : 30€ à B
            $compensation = min(30, $rideAmount * 0.30);
            return [
                'refundAmount' => $rideAmount - $compensation,
                'compensationAmount' => $compensation,
                'wayzoKeeps' => $commission
            ];
        } else {
            // Moins de 6h : 50% à B
            $compensation = $rideAmount * 0.50;
            return [
                'refundAmount' => $rideAmount - $compensation,
                'compensationAmount' => $compensation,
                'wayzoKeeps' => $commission
            ];
        }
    }

    /**
     * Vérifie si la deadline de validation est dépassée
     */
    public function isValidationExpired(): bool
    {
        if (!$this->validationDeadline) {
            return false;
        }
        
        return new \DateTimeImmutable() > $this->validationDeadline;
    }

    /**
     * Vérifie si les fonds sont bloqués
     */
    public function isFundsHeld(): bool
    {
        return $this->status === self::STATUS_HELD;
    }

    /**
     * Vérifie si le paiement peut être libéré à B
     */
    public function canReleaseToBuyer(): bool
    {
        return $this->status === self::STATUS_AWAITING_VALIDATION 
            && $this->buyer !== null
            && ($this->confirmedAt !== null || $this->isValidationExpired());
    }

    /**
     * Convertit en tableau pour l'API
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rideId' => $this->ride?->getId(),
            'sellerId' => $this->seller?->getId(),
            'buyerId' => $this->buyer?->getId(),
            'rideAmount' => (float) $this->rideAmount,
            'commissionAmount' => (float) $this->commissionAmount,
            'totalAmount' => (float) $this->totalAmount,
            'status' => $this->status,
            'heldAt' => $this->heldAt?->format('c'),
            'markedCompletedAt' => $this->markedCompletedAt?->format('c'),
            'validationDeadline' => $this->validationDeadline?->format('c'),
            'confirmedAt' => $this->confirmedAt?->format('c'),
            'paidAt' => $this->paidAt?->format('c'),
            'refundedAt' => $this->refundedAt?->format('c'),
            'refundedAmount' => $this->refundedAmount ? (float) $this->refundedAmount : null,
            'compensationAmount' => $this->compensationAmount ? (float) $this->compensationAmount : null,
            'cancelReason' => $this->cancelReason,
            'isValidationExpired' => $this->isValidationExpired(),
            'createdAt' => $this->createdAt?->format('c'),
        ];
    }
}
