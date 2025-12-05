<?php

namespace App\Entity;

use App\Repository\PaymentMethodRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentMethodRepository::class)]
class PaymentMethod
{
    // Types de moyens de paiement
    public const TYPE_CARD = 'card';
    public const TYPE_BANK_ACCOUNT = 'bank_account';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Chauffeur::class, inversedBy: 'paymentMethods')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Chauffeur $chauffeur = null;

    #[ORM\Column(length: 20)]
    private ?string $type = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $label = null;

    // === CHAMPS CARTE BANCAIRE ===
    
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $cardType = null; // VISA, MASTERCARD, etc.

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cardHolderName = null;

    #[ORM\Column(length: 4, nullable: true)]
    private ?string $cardLast4 = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $cardExpMonth = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $cardExpYear = null;

    // === CHAMPS COMPTE BANCAIRE ===

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $bankName = null;

    #[ORM\Column(length: 34, nullable: true)]
    private ?string $iban = null;

    #[ORM\Column(length: 11, nullable: true)]
    private ?string $bic = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $accountHolderName = null;

    // === CHAMPS COMMUNS ===

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    // === GETTERS/SETTERS CARTE ===

    public function getCardType(): ?string
    {
        return $this->cardType;
    }

    public function setCardType(?string $cardType): static
    {
        $this->cardType = $cardType;
        return $this;
    }

    public function getCardHolderName(): ?string
    {
        return $this->cardHolderName;
    }

    public function setCardHolderName(?string $cardHolderName): static
    {
        $this->cardHolderName = $cardHolderName;
        return $this;
    }

    public function getCardLast4(): ?string
    {
        return $this->cardLast4;
    }

    public function setCardLast4(?string $cardLast4): static
    {
        $this->cardLast4 = $cardLast4;
        return $this;
    }

    public function getCardExpMonth(): ?string
    {
        return $this->cardExpMonth;
    }

    public function setCardExpMonth(?string $cardExpMonth): static
    {
        $this->cardExpMonth = $cardExpMonth;
        return $this;
    }

    public function getCardExpYear(): ?string
    {
        return $this->cardExpYear;
    }

    public function setCardExpYear(?string $cardExpYear): static
    {
        $this->cardExpYear = $cardExpYear;
        return $this;
    }

    // === GETTERS/SETTERS COMPTE BANCAIRE ===

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): static
    {
        $this->bankName = $bankName;
        return $this;
    }

    public function getIban(): ?string
    {
        return $this->iban;
    }

    public function setIban(?string $iban): static
    {
        $this->iban = $iban;
        return $this;
    }

    public function getBic(): ?string
    {
        return $this->bic;
    }

    public function setBic(?string $bic): static
    {
        $this->bic = $bic;
        return $this;
    }

    public function getAccountHolderName(): ?string
    {
        return $this->accountHolderName;
    }

    public function setAccountHolderName(?string $accountHolderName): static
    {
        $this->accountHolderName = $accountHolderName;
        return $this;
    }

    // === GETTERS/SETTERS COMMUNS ===

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Retourne l'IBAN masqué (ex: FR76 **** **** **** **** 1234)
     */
    public function getMaskedIban(): ?string
    {
        if (!$this->iban) {
            return null;
        }
        $clean = str_replace(' ', '', $this->iban);
        if (strlen($clean) < 8) {
            return $this->iban;
        }
        return substr($clean, 0, 4) . ' **** **** **** **** ' . substr($clean, -4);
    }

    /**
     * Convertit en tableau pour l'API
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'isDefault' => $this->isDefault,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt?->format('Y-m-d H:i:s'),
        ];

        if ($this->type === self::TYPE_CARD) {
            $data['card'] = [
                'type' => $this->cardType,
                'holderName' => $this->cardHolderName,
                'last4' => $this->cardLast4,
                'expMonth' => $this->cardExpMonth,
                'expYear' => $this->cardExpYear,
            ];
        } elseif ($this->type === self::TYPE_BANK_ACCOUNT) {
            $data['bankAccount'] = [
                'bankName' => $this->bankName,
                'iban' => $this->getMaskedIban(),
                'bic' => $this->bic,
                'accountHolderName' => $this->accountHolderName,
            ];
        }

        return $data;
    }
}
