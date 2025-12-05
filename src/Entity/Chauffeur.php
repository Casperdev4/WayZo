<?php

namespace App\Entity;

use App\Repository\ChauffeurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: ChauffeurRepository::class)]
class Chauffeur implements UserInterface, PasswordAuthenticatedUserInterface
{
    // Statuts de compte
    public const STATUS_ACTIVE = 'active';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_PENDING = 'pending';
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private ?string $nom = null;

    #[ORM\Column(length: 30)]
    private ?string $prenom = null;

    #[ORM\Column(length: 12)]
    private ?string $tel = null;

    #[ORM\Column(length: 100)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $siret = null;

    #[ORM\Column(length: 100)]
    private ?string $nomSociete = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $permis = null;

    #[ORM\Column(length: 100)]
    private ?string $kbis = null;

    #[ORM\Column(length: 100)]
    private ?string $carteVtc = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $vehicle = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\OneToMany(targetEntity: Course::class, mappedBy: 'chauffeurVendeur')]
    private Collection $coursesVendues;

    #[ORM\OneToMany(targetEntity: Course::class, mappedBy: 'chauffeurAccepteur')]
    private Collection $coursesAcceptees;

    #[ORM\OneToMany(targetEntity: Message::class, mappedBy: 'expediteur')]
    private Collection $messagesEnvoyes;

    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'chauffeurPayeur')]
    private Collection $transactionsPayees;

    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'chauffeurReceveur')]
    private Collection $transactionsRecues;

    #[ORM\ManyToMany(targetEntity: self::class, inversedBy: 'favorisDe')]
    private Collection $favoris;

    #[ORM\ManyToMany(targetEntity: self::class, mappedBy: 'favoris')]
    private Collection $favorisDe;

    #[ORM\Column(length: 255)]
    private ?string $adresse = null;

    #[ORM\Column(length: 30)]
    private ?string $ville = null;

    #[ORM\Column(length: 8)]
    private ?string $codePostal = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $macaron = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pieceIdentite = null;

    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'chauffeur_roles')]
    private Collection $customRoles;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastOnline = null;

    #[ORM\OneToMany(targetEntity: Document::class, mappedBy: 'chauffeur', cascade: ['persist', 'remove'])]
    private Collection $documents;

    /**
     * Rides créés par ce chauffeur (nouvelle entité Ride)
     */
    #[ORM\OneToMany(targetEntity: Ride::class, mappedBy: 'chauffeur')]
    private Collection $rides;

    /**
     * Avis donnés par ce chauffeur
     */
    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'auteur')]
    private Collection $avisDonnes;

    /**
     * Avis reçus par ce chauffeur
     */
    #[ORM\OneToMany(targetEntity: Avis::class, mappedBy: 'chauffeurNote')]
    private Collection $avisRecus;

    /**
     * Moyens de paiement du chauffeur
     */
    #[ORM\OneToMany(targetEntity: PaymentMethod::class, mappedBy: 'chauffeur', cascade: ['persist', 'remove'])]
    private Collection $paymentMethods;

    /**
     * ID client Stripe (pour les paiements)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    /**
     * ID compte Connect Stripe (pour recevoir les paiements)
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeAccountId = null;

    /**
     * Statut du compte Connect Stripe
     */
    #[ORM\Column(type: 'boolean')]
    private bool $stripeAccountComplete = false;

    public function __construct()
    {
        $this->coursesVendues = new ArrayCollection();
        $this->coursesAcceptees = new ArrayCollection();
        $this->messagesEnvoyes = new ArrayCollection();
        $this->transactionsPayees = new ArrayCollection();
        $this->transactionsRecues = new ArrayCollection();
        $this->favoris = new ArrayCollection();
        $this->favorisDe = new ArrayCollection();
        $this->customRoles = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->rides = new ArrayCollection();
        $this->avisDonnes = new ArrayCollection();
        $this->avisRecus = new ArrayCollection();
        $this->paymentMethods = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getPrenom(): ?string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;
        return $this;
    }

    public function getTel(): ?string
    {
        return $this->tel;
    }

    public function setTel(string $tel): static
    {
        $this->tel = $tel;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(string $siret): static
    {
        $this->siret = $siret;
        return $this;
    }

    public function getNomSociete(): ?string
    {
        return $this->nomSociete;
    }

    public function setNomSociete(string $nomSociete): static
    {
        $this->nomSociete = $nomSociete;
        return $this;
    }

    public function getPermis(): ?string
    {
        return $this->permis;
    }

    public function setPermis(string $permis): static
    {
        $this->permis = $permis;
        return $this;
    }

    public function getKbis(): ?string
    {
        return $this->kbis;
    }

    public function setKbis(string $kbis): static
    {
        $this->kbis = $kbis;
        return $this;
    }

    public function getCarteVtc(): ?string
    {
        return $this->carteVtc;
    }

    public function setCarteVtc(string $carteVtc): static
    {
        $this->carteVtc = $carteVtc;
        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    /**  
     * ⚠️ IMPORTANT : utilisé par json_login  
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**  
     * ⚠️ Correction critique : force Symfony à ne PAS utiliser "username"  
     */
    public function getUsername(): string
    {
        return $this->email;
    }

    /**  
     * Pas utilisé avec bcrypt/argon2  
     */
    public function getSalt(): ?string
    {
        return null;
    }

    public function eraseCredentials(): void {}

    public function getAdresse(): ?string
    {
        return $this->adresse;
    }

    public function setAdresse(string $adresse): static
    {
        $this->adresse = $adresse;
        return $this;
    }

    public function getVille(): ?string
    {
        return $this->ville;
    }

    public function setVille(string $ville): static
    {
        $this->ville = $ville;
        return $this;
    }

    public function getCodePostal(): ?string
    {
        return $this->codePostal;
    }

    public function setCodePostal(string $codePostal): static
    {
        $this->codePostal = $codePostal;
        return $this;
    }

    public function getMacaron(): ?string
    {
        return $this->macaron;
    }

    public function setMacaron(?string $macaron): static
    {
        $this->macaron = $macaron;
        return $this;
    }

    public function getPieceIdentite(): ?string
    {
        return $this->pieceIdentite;
    }

    public function setPieceIdentite(?string $pieceIdentite): static
    {
        $this->pieceIdentite = $pieceIdentite;
        return $this;
    }

    public function getVehicle(): ?string
    {
        return $this->vehicle;
    }

    public function setVehicle(?string $vehicle): static
    {
        $this->vehicle = $vehicle;
        return $this;
    }

    public function getDateNaissance(): ?\DateTimeImmutable
    {
        return $this->dateNaissance;
    }

    public function setDateNaissance(?\DateTimeImmutable $dateNaissance): static
    {
        $this->dateNaissance = $dateNaissance;
        return $this;
    }

    /**
     * @return Collection<int, Role>
     */
    public function getCustomRoles(): Collection
    {
        return $this->customRoles;
    }

    public function addCustomRole(Role $role): static
    {
        if (!$this->customRoles->contains($role)) {
            $this->customRoles->add($role);
        }
        return $this;
    }

    public function removeCustomRole(Role $role): static
    {
        $this->customRoles->removeElement($role);
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

    public function getLastOnline(): ?\DateTimeImmutable
    {
        return $this->lastOnline;
    }

    public function setLastOnline(?\DateTimeImmutable $lastOnline): static
    {
        $this->lastOnline = $lastOnline;
        return $this;
    }

    /**
     * Vérifie si le chauffeur a un accès spécifique via ses rôles personnalisés
     */
    public function hasAccessTo(string $module, string $action): bool
    {
        // Super admin a tous les accès
        if (in_array('ROLE_SUPER_ADMIN', $this->roles, true)) {
            return true;
        }

        // Admin a la plupart des accès
        if (in_array('ROLE_ADMIN', $this->roles, true)) {
            return true;
        }

        // Vérifier les rôles personnalisés
        foreach ($this->customRoles as $role) {
            if ($role->hasAccess($module, $action)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtient le rôle principal pour l'affichage
     */
    public function getPrimaryRole(): ?Role
    {
        return $this->customRoles->first() ?: null;
    }

    /**
     * Obtient le nom complet
     */
    public function getFullName(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setChauffeur($this);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        if ($this->documents->removeElement($document)) {
            if ($document->getChauffeur() === $this) {
                $document->setChauffeur(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Avis>
     */
    public function getAvisDonnes(): Collection
    {
        return $this->avisDonnes;
    }

    public function addAvisDonne(Avis $avis): static
    {
        if (!$this->avisDonnes->contains($avis)) {
            $this->avisDonnes->add($avis);
            $avis->setAuteur($this);
        }
        return $this;
    }

    /**
     * @return Collection<int, Avis>
     */
    public function getAvisRecus(): Collection
    {
        return $this->avisRecus;
    }

    public function addAvisRecu(Avis $avis): static
    {
        if (!$this->avisRecus->contains($avis)) {
            $this->avisRecus->add($avis);
            $avis->setChauffeurNote($this);
        }
        return $this;
    }

    /**
     * Calcule la note moyenne du chauffeur
     */
    public function getAverageRating(): ?float
    {
        if ($this->avisRecus->isEmpty()) {
            return null;
        }
        
        $total = 0;
        foreach ($this->avisRecus as $avis) {
            $total += $avis->getNote();
        }
        
        return round($total / $this->avisRecus->count(), 1);
    }

    /**
     * Retourne le nombre d'avis reçus
     */
    public function getAvisCount(): int
    {
        return $this->avisRecus->count();
    }

    /**
     * @return Collection<int, Ride>
     */
    public function getRides(): Collection
    {
        return $this->rides;
    }

    public function addRide(Ride $ride): static
    {
        if (!$this->rides->contains($ride)) {
            $this->rides->add($ride);
            $ride->setChauffeur($this);
        }
        return $this;
    }

    public function removeRide(Ride $ride): static
    {
        if ($this->rides->removeElement($ride)) {
            if ($ride->getChauffeur() === $this) {
                $ride->setChauffeur(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function getPaymentMethods(): Collection
    {
        return $this->paymentMethods;
    }

    public function addPaymentMethod(PaymentMethod $paymentMethod): static
    {
        if (!$this->paymentMethods->contains($paymentMethod)) {
            $this->paymentMethods->add($paymentMethod);
            $paymentMethod->setChauffeur($this);
        }
        return $this;
    }

    public function removePaymentMethod(PaymentMethod $paymentMethod): static
    {
        if ($this->paymentMethods->removeElement($paymentMethod)) {
            if ($paymentMethod->getChauffeur() === $this) {
                $paymentMethod->setChauffeur(null);
            }
        }
        return $this;
    }

    /**
     * Retourne le moyen de paiement par défaut
     */
    public function getDefaultPaymentMethod(): ?PaymentMethod
    {
        foreach ($this->paymentMethods as $method) {
            if ($method->isDefault() && $method->isActive()) {
                return $method;
            }
        }
        return null;
    }

    /**
     * Vérifie si le chauffeur a configuré au moins un moyen de paiement
     */
    public function hasPaymentMethod(): bool
    {
        foreach ($this->paymentMethods as $method) {
            if ($method->isActive()) {
                return true;
            }
        }
        return false;
    }

    // ==================== STRIPE ====================

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;
        return $this;
    }

    public function getStripeAccountId(): ?string
    {
        return $this->stripeAccountId;
    }

    public function setStripeAccountId(?string $stripeAccountId): static
    {
        $this->stripeAccountId = $stripeAccountId;
        return $this;
    }

    public function isStripeAccountComplete(): bool
    {
        return $this->stripeAccountComplete;
    }

    public function setStripeAccountComplete(bool $stripeAccountComplete): static
    {
        $this->stripeAccountComplete = $stripeAccountComplete;
        return $this;
    }

    /**
     * Vérifie si le chauffeur peut recevoir des paiements via Stripe
     */
    public function canReceivePayments(): bool
    {
        return $this->stripeAccountId !== null && $this->stripeAccountComplete;
    }
}