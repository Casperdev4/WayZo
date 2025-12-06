<?php

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Notification
{
    // Types de notifications
    public const TYPE_RIDE_ACCEPTED = 'ride_accepted';
    public const TYPE_RIDE_STARTED = 'ride_started';
    public const TYPE_RIDE_COMPLETED = 'ride_completed';
    public const TYPE_RIDE_CANCELLED = 'ride_cancelled';
    public const TYPE_NEW_MESSAGE = 'new_message';
    public const TYPE_SYSTEM = 'system';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Chauffeur::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Chauffeur $recipient = null;

    #[ORM\ManyToOne(targetEntity: Chauffeur::class)]
    private ?Chauffeur $sender = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text')]
    private ?string $message = null;

    #[ORM\Column]
    private bool $isRead = false;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\ManyToOne(targetEntity: Ride::class)]
    private ?Ride $ride = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRecipient(): ?Chauffeur
    {
        return $this->recipient;
    }

    public function setRecipient(?Chauffeur $recipient): static
    {
        $this->recipient = $recipient;
        return $this;
    }

    public function getSender(): ?Chauffeur
    {
        return $this->sender;
    }

    public function setSender(?Chauffeur $sender): static
    {
        $this->sender = $sender;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): static
    {
        $this->isRead = $isRead;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
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

    public function getData(): ?array
    {
        return $this->data;
    }

    public function setData(?array $data): static
    {
        $this->data = $data;
        return $this;
    }
}
