<?php

namespace App\Entity;

use App\Repository\RideRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RideRepository::class)]
class Ride
{
    // Constantes de visibilité
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_GROUPE = 'groupe';
    public const VISIBILITY_PRIVATE = 'prive';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private ?string $clientName = null;

    #[ORM\Column(length: 30)]
    private ?string $clientContact = null;

    #[ORM\Column(length: 255)]
    private ?string $depart = null;

    #[ORM\Column(length: 255)]
    private ?string $destination = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    private ?\DateTimeInterface $time = null;

    #[ORM\Column]
    private ?int $passengers = null;

    #[ORM\Column]
    private ?int $luggage = null;

    #[ORM\Column(length: 255)]
    private ?string $vehicle = null;

    #[ORM\Column]
    private ?int $boosterSeat = null;

    #[ORM\Column]
    private ?int $babySeat = null;

    #[ORM\Column]
    private ?float $price = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $statusVendeur = null;

    #[ORM\ManyToOne(inversedBy: 'rides')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Chauffeur $chauffeur = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Chauffeur $chauffeurAccepteur = null;

    /**
     * Groupe de confiance pour les courses privées
     * Si null = course publique visible par tous
     */
    #[ORM\ManyToOne(targetEntity: Groupe::class)]
    private ?Groupe $groupe = null;

    /**
     * Visibilité de la course
     * public = tous les chauffeurs
     * groupe = uniquement les membres du groupe
     * prive = uniquement le chauffeur sélectionné
     */
    #[ORM\Column(length: 20)]
    private string $visibility = 'public';

    // Coordonnées GPS du départ
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $departLat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $departLng = null;

    // Coordonnées GPS de la destination
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $destinationLat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $destinationLng = null;

    /**
     * Paiement escrow associé à cette course
     */
    #[ORM\OneToOne(mappedBy: 'ride', cascade: ['persist', 'remove'])]
    private ?EscrowPayment $escrowPayment = null;

    /**
     * Statut de paiement de la course
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $paymentStatus = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClientName(): ?string
    {
        return $this->clientName;
    }

    public function setClientName(string $clientName): static
    {
        $this->clientName = $clientName;
        return $this;
    }

    public function getClientContact(): ?string
    {
        return $this->clientContact;
    }

    public function setClientContact(string $clientContact): static
    {
        $this->clientContact = $clientContact;
        return $this;
    }

    public function getDepart(): ?string
    {
        return $this->depart;
    }

    public function setDepart(string $depart): static
    {
        $this->depart = $depart;
        return $this;
    }

    public function getDestination(): ?string
    {
        return $this->destination;
    }

    public function setDestination(string $destination): static
    {
        $this->destination = $destination;
        return $this;
    }

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getTime(): ?\DateTimeInterface
    {
        return $this->time;
    }

    public function setTime(\DateTimeInterface $time): static
    {
        $this->time = $time;
        return $this;
    }

    public function getPassengers(): ?int
    {
        return $this->passengers;
    }

    public function setPassengers(int $passengers): static
    {
        $this->passengers = $passengers;
        return $this;
    }

    public function getLuggage(): ?int
    {
        return $this->luggage;
    }

    public function setLuggage(int $luggage): static
    {
        $this->luggage = $luggage;
        return $this;
    }

    public function getVehicle(): ?string
    {
        return $this->vehicle;
    }

    public function setVehicle(string $vehicle): static
    {
        $this->vehicle = $vehicle;
        return $this;
    }

    public function getBoosterSeat(): ?int
    {
        return $this->boosterSeat;
    }

    public function setBoosterSeat(int $boosterSeat): static
    {
        $this->boosterSeat = $boosterSeat;
        return $this;
    }

    public function getBabySeat(): ?int
    {
        return $this->babySeat;
    }

    public function setBabySeat(int $babySeat): static
    {
        $this->babySeat = $babySeat;
        return $this;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function setPrice(float $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusVendeur(): ?string
    {
        return $this->statusVendeur;
    }

    public function setStatusVendeur(?string $statusVendeur): static
    {
        $this->statusVendeur = $statusVendeur;
        return $this;
    }

    public function getChauffeur(): ?Chauffeur
    {
        return $this->chauffeur;
    }

    public function setChauffeur(?Chauffeur $chauffeur): static
    {
        $this->chauffeur = $chauffeur;
        return $this;
    }

    public function getChauffeurAccepteur(): ?Chauffeur
    {
        return $this->chauffeurAccepteur;
    }

    public function setChauffeurAccepteur(?Chauffeur $chauffeur): static
    {
        $this->chauffeurAccepteur = $chauffeur;
        return $this;
    }

    public function getGroupe(): ?Groupe
    {
        return $this->groupe;
    }

    public function setGroupe(?Groupe $groupe): static
    {
        $this->groupe = $groupe;
        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = $visibility;
        return $this;
    }

    /**
     * Vérifie si la course est publique
     */
    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    /**
     * Vérifie si la course est réservée au groupe
     */
    public function isGroupeOnly(): bool
    {
        return $this->visibility === self::VISIBILITY_GROUPE && $this->groupe !== null;
    }

    /**
     * Vérifie si un chauffeur peut voir cette course
     */
    public function isVisibleBy(Chauffeur $chauffeur): bool
    {
        // Course publique = visible par tous
        if ($this->isPublic()) {
            return true;
        }

        // Course de groupe = vérifier l'appartenance
        if ($this->isGroupeOnly() && $this->groupe !== null) {
            foreach ($this->groupe->getMembres() as $membre) {
                if ($membre->getChauffeur() === $chauffeur) {
                    return true;
                }
            }
            // Le propriétaire du groupe peut aussi voir
            if ($this->groupe->getProprietaire() === $chauffeur) {
                return true;
            }
        }

        // Le chauffeur qui a créé la course peut toujours la voir
        if ($this->chauffeur === $chauffeur) {
            return true;
        }

        return false;
    }

    // Getters/Setters pour les coordonnées GPS
    public function getDepartLat(): ?float
    {
        return $this->departLat;
    }

    public function setDepartLat(?float $departLat): static
    {
        $this->departLat = $departLat;
        return $this;
    }

    public function getDepartLng(): ?float
    {
        return $this->departLng;
    }

    public function setDepartLng(?float $departLng): static
    {
        $this->departLng = $departLng;
        return $this;
    }

    public function getDestinationLat(): ?float
    {
        return $this->destinationLat;
    }

    public function setDestinationLat(?float $destinationLat): static
    {
        $this->destinationLat = $destinationLat;
        return $this;
    }

    public function getDestinationLng(): ?float
    {
        return $this->destinationLng;
    }

    public function setDestinationLng(?float $destinationLng): static
    {
        $this->destinationLng = $destinationLng;
        return $this;
    }

    /**
     * Récupérer les coordonnées de départ sous forme de tableau
     */
    public function getDepartureCoords(): ?array
    {
        if ($this->departLat !== null && $this->departLng !== null) {
            return [
                'lat' => $this->departLat,
                'lng' => $this->departLng,
                'address' => $this->depart,
            ];
        }
        return null;
    }

    /**
     * Récupérer les coordonnées d'arrivée sous forme de tableau
     */
    public function getArrivalCoords(): ?array
    {
        if ($this->destinationLat !== null && $this->destinationLng !== null) {
            return [
                'lat' => $this->destinationLat,
                'lng' => $this->destinationLng,
                'address' => $this->destination,
            ];
        }
        return null;
    }

    // ==================== ESCROW PAYMENT ====================

    public function getEscrowPayment(): ?EscrowPayment
    {
        return $this->escrowPayment;
    }

    public function setEscrowPayment(?EscrowPayment $escrowPayment): static
    {
        // Définir le côté propriétaire de la relation
        if ($escrowPayment !== null && $escrowPayment->getRide() !== $this) {
            $escrowPayment->setRide($this);
        }

        $this->escrowPayment = $escrowPayment;
        return $this;
    }

    public function getPaymentStatus(): ?string
    {
        return $this->paymentStatus;
    }

    public function setPaymentStatus(?string $paymentStatus): static
    {
        $this->paymentStatus = $paymentStatus;
        return $this;
    }

    /**
     * Vérifie si le paiement est sécurisé (fonds bloqués)
     */
    public function isPaymentSecured(): bool
    {
        return $this->escrowPayment !== null 
            && $this->escrowPayment->getStatus() === EscrowPayment::STATUS_HELD;
    }

    /**
     * Récupère le montant total bloqué (prix + commission)
     */
    public function getTotalBlockedAmount(): ?float
    {
        return $this->escrowPayment 
            ? (float) $this->escrowPayment->getTotalAmount() 
            : null;
    }

    /**
     * Retourne la date et l'heure combinées de la course
     */
    public function getRideDateTime(): ?\DateTimeInterface
    {
        if (!$this->date || !$this->time) {
            return null;
        }
        
        $dateTime = \DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $this->date->format('Y-m-d') . ' ' . $this->time->format('H:i:s')
        );
        
        return $dateTime ?: null;
    }
}

