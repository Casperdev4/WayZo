<?php

namespace App\Entity;

use App\Repository\RideReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signalement d'une course (problème client, retard, etc.)
 */
#[ORM\Entity(repositoryClass: RideReportRepository::class)]
#[ORM\HasLifecycleCallbacks]
class RideReport
{
    // Types de signalement
    public const TYPE_CLIENT_ABSENT = 'client_absent';
    public const TYPE_CLIENT_RETARD = 'client_retard';
    public const TYPE_MAUVAISE_ADRESSE = 'mauvaise_adresse';
    public const TYPE_CLIENT_ANNULE = 'client_annule';
    public const TYPE_COMPORTEMENT = 'comportement';
    public const TYPE_PAIEMENT = 'paiement';
    public const TYPE_AUTRE = 'autre';

    // Statuts du signalement
    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ride::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Ride $ride = null;

    #[ORM\ManyToOne(targetEntity: Chauffeur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Chauffeur $reporter = null;

    #[ORM\Column(length: 50)]
    private string $type = self::TYPE_AUTRE;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $adminResponse = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $resolvedAt = null;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRide(): ?Ride
    {
        return $this->ride;
    }

    public function setRide(?Ride $ride): static
    {
        $this->ride = $ride;
        return $this;
    }

    public function getReporter(): ?Chauffeur
    {
        return $this->reporter;
    }

    public function setReporter(?Chauffeur $reporter): static
    {
        $this->reporter = $reporter;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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

    public function getAdminResponse(): ?string
    {
        return $this->adminResponse;
    }

    public function setAdminResponse(?string $adminResponse): static
    {
        $this->adminResponse = $adminResponse;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeInterface
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeInterface $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public static function getTypeLabel(string $type): string
    {
        return match($type) {
            self::TYPE_CLIENT_ABSENT => 'Client absent',
            self::TYPE_CLIENT_RETARD => 'Client en retard',
            self::TYPE_MAUVAISE_ADRESSE => 'Mauvaise adresse',
            self::TYPE_CLIENT_ANNULE => 'Client a annulé',
            self::TYPE_COMPORTEMENT => 'Problème comportement',
            self::TYPE_PAIEMENT => 'Problème paiement',
            self::TYPE_AUTRE => 'Autre',
            default => 'Inconnu'
        };
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'rideId' => $this->ride?->getId(),
            'ride' => $this->ride ? [
                'id' => $this->ride->getId(),
                'depart' => $this->ride->getDepart(),
                'destination' => $this->ride->getDestination(),
                'date' => $this->ride->getDate()?->format('Y-m-d'),
                'time' => $this->ride->getTime()?->format('H:i'),
                'clientName' => $this->ride->getClientName(),
            ] : null,
            'reporter' => $this->reporter ? [
                'id' => $this->reporter->getId(),
                'name' => $this->reporter->getPrenom() . ' ' . $this->reporter->getNom(),
            ] : null,
            'type' => $this->type,
            'typeLabel' => self::getTypeLabel($this->type),
            'description' => $this->description,
            'status' => $this->status,
            'adminResponse' => $this->adminResponse,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
            'resolvedAt' => $this->resolvedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
