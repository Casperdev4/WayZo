<?php

namespace App\Controller;

use App\Entity\PaymentMethod;
use App\Entity\Chauffeur;
use App\Repository\PaymentMethodRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/payment-methods')]
class PaymentMethodController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PaymentMethodRepository $paymentMethodRepository
    ) {}

    /**
     * Liste tous les moyens de paiement du chauffeur connecté
     */
    #[Route('', name: 'api_payment_methods_list', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $paymentMethods = $this->paymentMethodRepository->findByChauffeur($user);
        
        return $this->json([
            'success' => true,
            'data' => array_map(fn($pm) => $pm->toArray(), $paymentMethods)
        ]);
    }

    /**
     * Récupère un moyen de paiement spécifique
     */
    #[Route('/{id}', name: 'api_payment_methods_show', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur || $paymentMethod->getChauffeur() !== $user) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        return $this->json([
            'success' => true,
            'data' => $paymentMethod->toArray()
        ]);
    }

    /**
     * Ajoute une carte bancaire
     */
    #[Route('/card', name: 'api_payment_methods_add_card', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function addCard(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        
        // Validation des données requises
        $requiredFields = ['cardHolderName', 'last4', 'expMonth', 'expYear', 'cardBrand'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return $this->json(['error' => "Le champ '$field' est requis"], 400);
            }
        }

        // Validation du mois et de l'année d'expiration
        $expMonth = (int) $data['expMonth'];
        $expYear = (int) $data['expYear'];
        
        if ($expMonth < 1 || $expMonth > 12) {
            return $this->json(['error' => 'Mois d\'expiration invalide'], 400);
        }

        $currentYear = (int) date('Y');
        $currentMonth = (int) date('m');
        
        if ($expYear < $currentYear || ($expYear === $currentYear && $expMonth < $currentMonth)) {
            return $this->json(['error' => 'Carte expirée'], 400);
        }

        // Validation du type de carte
        $validBrands = ['visa', 'mastercard', 'amex', 'other'];
        if (!in_array(strtolower($data['cardBrand']), $validBrands)) {
            return $this->json(['error' => 'Type de carte invalide'], 400);
        }

        // Validation des 4 derniers chiffres
        if (!preg_match('/^\d{4}$/', $data['last4'])) {
            return $this->json(['error' => 'Les 4 derniers chiffres doivent être des chiffres'], 400);
        }

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setChauffeur($user)
            ->setType(PaymentMethod::TYPE_CARD)
            ->setCardHolderName($data['cardHolderName'])
            ->setCardLast4($data['last4'])
            ->setCardExpMonth(str_pad((string) $expMonth, 2, '0', STR_PAD_LEFT))
            ->setCardExpYear(str_pad((string) ($expYear % 100), 2, '0', STR_PAD_LEFT))
            ->setCardType(strtoupper($data['cardBrand']))
            ->setLabel($data['label'] ?? null)
            ->setIsActive(true);

        // Si c'est le premier moyen de paiement ou si demandé, le définir par défaut
        $isDefault = $data['isDefault'] ?? false;
        $existingMethods = $this->paymentMethodRepository->countByChauffeur($user);
        
        if ($existingMethods === 0 || $isDefault) {
            $this->setAsDefault($paymentMethod);
        }

        $this->entityManager->persist($paymentMethod);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Carte bancaire ajoutée avec succès',
            'data' => $paymentMethod->toArray()
        ], 201);
    }

    /**
     * Ajoute un compte bancaire (IBAN)
     */
    #[Route('/bank-account', name: 'api_payment_methods_add_bank', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function addBankAccount(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        
        // Validation des données requises
        $requiredFields = ['iban', 'accountHolderName'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return $this->json(['error' => "Le champ '$field' est requis"], 400);
            }
        }

        // Nettoyage et validation de l'IBAN
        $iban = strtoupper(preg_replace('/\s+/', '', $data['iban']));
        
        if (!$this->validateIban($iban)) {
            return $this->json(['error' => 'IBAN invalide'], 400);
        }

        // Validation du BIC si fourni
        $bic = null;
        if (!empty($data['bic'])) {
            $bic = strtoupper(preg_replace('/\s+/', '', $data['bic']));
            if (!preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
                return $this->json(['error' => 'BIC invalide'], 400);
            }
        }

        $paymentMethod = new PaymentMethod();
        $paymentMethod->setChauffeur($user)
            ->setType(PaymentMethod::TYPE_BANK_ACCOUNT)
            ->setIban($iban)
            ->setBic($bic)
            ->setBankName($data['bankName'] ?? null)
            ->setAccountHolderName($data['accountHolderName'])
            ->setLabel($data['label'] ?? null)
            ->setIsActive(true);

        // Si c'est le premier moyen de paiement ou si demandé, le définir par défaut
        $isDefault = $data['isDefault'] ?? false;
        $existingMethods = $this->paymentMethodRepository->countByChauffeur($user);
        
        if ($existingMethods === 0 || $isDefault) {
            $this->setAsDefault($paymentMethod);
        }

        $this->entityManager->persist($paymentMethod);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Compte bancaire ajouté avec succès',
            'data' => $paymentMethod->toArray()
        ], 201);
    }

    /**
     * Met à jour un moyen de paiement
     */
    #[Route('/{id}', name: 'api_payment_methods_update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function update(PaymentMethod $paymentMethod, Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur || $paymentMethod->getChauffeur() !== $user) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);

        // Mise à jour du label
        if (isset($data['label'])) {
            $paymentMethod->setLabel($data['label']);
        }

        // Mise à jour du statut par défaut
        if (isset($data['isDefault']) && $data['isDefault']) {
            $this->setAsDefault($paymentMethod);
        }

        // Mise à jour du statut actif
        if (isset($data['isActive'])) {
            $paymentMethod->setIsActive((bool) $data['isActive']);
        }

        // Pour les cartes : mise à jour des informations d'expiration
        if ($paymentMethod->getType() === PaymentMethod::TYPE_CARD && isset($data['expMonth']) && isset($data['expYear'])) {
            $expMonth = (int) $data['expMonth'];
            $expYear = (int) $data['expYear'];
            
            if ($expMonth >= 1 && $expMonth <= 12) {
                $paymentMethod->setCardExpMonth(str_pad((string) $expMonth, 2, '0', STR_PAD_LEFT));
            }
            
            if ($expYear >= (int) date('Y')) {
                $paymentMethod->setCardExpYear(str_pad((string) ($expYear % 100), 2, '0', STR_PAD_LEFT));
            }
        }

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Moyen de paiement mis à jour',
            'data' => $paymentMethod->toArray()
        ]);
    }

    /**
     * Définit un moyen de paiement comme défaut
     */
    #[Route('/{id}/set-default', name: 'api_payment_methods_set_default', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function setDefault(PaymentMethod $paymentMethod): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur || $paymentMethod->getChauffeur() !== $user) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        if (!$paymentMethod->isActive()) {
            return $this->json(['error' => 'Impossible de définir un moyen de paiement inactif comme défaut'], 400);
        }

        $this->setAsDefault($paymentMethod);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Moyen de paiement défini par défaut',
            'data' => $paymentMethod->toArray()
        ]);
    }

    /**
     * Supprime un moyen de paiement
     */
    #[Route('/{id}', name: 'api_payment_methods_delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function delete(PaymentMethod $paymentMethod): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur || $paymentMethod->getChauffeur() !== $user) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $wasDefault = $paymentMethod->isDefault();
        
        $this->entityManager->remove($paymentMethod);
        $this->entityManager->flush();

        // Si c'était le moyen par défaut, définir un autre comme défaut
        if ($wasDefault) {
            $remaining = $this->paymentMethodRepository->findByChauffeur($user);
            if (!empty($remaining)) {
                $remaining[0]->setIsDefault(true);
                $this->entityManager->flush();
            }
        }

        return $this->json([
            'success' => true,
            'message' => 'Moyen de paiement supprimé'
        ]);
    }

    /**
     * Vérifie si le chauffeur a configuré un moyen de paiement
     */
    #[Route('/check', name: 'api_payment_methods_check', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function checkPaymentMethod(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $hasPaymentMethod = $user->hasPaymentMethod();
        $defaultMethod = $user->getDefaultPaymentMethod();

        return $this->json([
            'success' => true,
            'hasPaymentMethod' => $hasPaymentMethod,
            'defaultMethod' => $defaultMethod?->toArray()
        ]);
    }

    /**
     * Définit un moyen de paiement comme défaut en retirant le statut des autres
     */
    private function setAsDefault(PaymentMethod $paymentMethod): void
    {
        $chauffeur = $paymentMethod->getChauffeur();
        
        // Retirer le statut par défaut de tous les autres
        foreach ($chauffeur->getPaymentMethods() as $pm) {
            $pm->setIsDefault(false);
        }
        
        $paymentMethod->setIsDefault(true);
    }

    /**
     * Valide un IBAN (validation basique)
     */
    private function validateIban(string $iban): bool
    {
        // Supprimer les espaces
        $iban = str_replace(' ', '', $iban);
        
        // Vérifier la longueur minimale
        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return false;
        }
        
        // Vérifier le format : 2 lettres + 2 chiffres + reste alphanumérique
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return false;
        }
        
        // Réorganiser : déplacer les 4 premiers caractères à la fin
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        
        // Remplacer les lettres par des chiffres (A=10, B=11, ..., Z=35)
        $numericIban = '';
        foreach (str_split($rearranged) as $char) {
            if (ctype_alpha($char)) {
                $numericIban .= (ord($char) - ord('A') + 10);
            } else {
                $numericIban .= $char;
            }
        }
        
        // Vérifier que le reste de la division par 97 est égal à 1
        return bcmod($numericIban, '97') === '1';
    }
}
