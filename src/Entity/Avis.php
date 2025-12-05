<?php

namespace App\Entity;

use App\Repository\AvisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AvisRepository::class)]
#[ORM\Table(name: 'avis')]
#[ORM\HasLifecycleCallbacks]
class Avis
{
    public const NOTE_MIN = 1;
    public const NOTE_MAX = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Note de 1 à 5 étoiles
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private ?int $note = null;

    /**
     * Commentaire optionnel
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentaire = null;

    /**
     * Le chauffeur qui donne l'avis (celui qui a acheté/exécuté la course)
     */
    #[ORM\ManyToOne(targetEntity: Chauffeur::class, inversedBy: 'avisDonnes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Chauffeur $auteur = null;

    /**
     * Le chauffeur qui reçoit l'avis (celui qui a vendu la course)
     */
    #[ORM\ManyToOne(targetEntity: Chauffeur::class, inversedBy: 'avisRecus')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Chauffeur $chauffeurNote = null;

    /**
     * La course liée à cet avis (ancienne entité)
     */
    #[ORM\OneToOne(targetEntity: Course::class, inversedBy: 'avis')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Course $course = null;

    /**
     * La course liée à cet avis (nouvelle entité Ride)
     */
    #[ORM\ManyToOne(targetEntity: Ride::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Ride $ride = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNote(): ?int
    {
        return $this->note;
    }

    public function setNote(int $note): static
    {
        if ($note < self::NOTE_MIN || $note > self::NOTE_MAX) {
            throw new \InvalidArgumentException(
                sprintf('La note doit être entre %d et %d', self::NOTE_MIN, self::NOTE_MAX)
            );
        }
        $this->note = $note;
        return $this;
    }

    public function getCommentaire(): ?string
    {
        return $this->commentaire;
    }

    public function setCommentaire(?string $commentaire): static
    {
        $this->commentaire = $commentaire;
        return $this;
    }

    public function getAuteur(): ?Chauffeur
    {
        return $this->auteur;
    }

    public function setAuteur(?Chauffeur $auteur): static
    {
        $this->auteur = $auteur;
        return $this;
    }

    public function getChauffeurNote(): ?Chauffeur
    {
        return $this->chauffeurNote;
    }

    public function setChauffeurNote(?Chauffeur $chauffeurNote): static
    {
        $this->chauffeurNote = $chauffeurNote;
        return $this;
    }

    public function getCourse(): ?Course
    {
        return $this->course;
    }

    public function setCourse(?Course $course): static
    {
        $this->course = $course;
        return $this;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    /**
     * Retourne les données formatées pour l'API
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'note' => $this->note,
            'commentaire' => $this->commentaire,
            'auteur' => [
                'id' => $this->auteur?->getId(),
                'nom' => $this->auteur?->getNom(),
                'prenom' => $this->auteur?->getPrenom(),
            ],
            'auteurName' => $this->auteur ? $this->auteur->getPrenom() . ' ' . $this->auteur->getNom() : null,
            'chauffeurNote' => [
                'id' => $this->chauffeurNote?->getId(),
                'nom' => $this->chauffeurNote?->getNom(),
                'prenom' => $this->chauffeurNote?->getPrenom(),
            ],
            'chauffeurNoteName' => $this->chauffeurNote ? $this->chauffeurNote->getPrenom() . ' ' . $this->chauffeurNote->getNom() : null,
            'courseId' => $this->course?->getId(),
            'rideId' => $this->ride?->getId(),
            'rideInfo' => $this->ride ? [
                'id' => $this->ride->getId(),
                'depart' => $this->ride->getDepart(),
                'destination' => $this->ride->getDestination(),
            ] : null,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
        ];
    }
}
