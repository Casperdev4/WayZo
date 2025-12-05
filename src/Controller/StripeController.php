<?php

namespace App\Controller;

use App\Entity\Chauffeur;
use App\Service\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/stripe')]
class StripeController extends AbstractController
{
    public function __construct(
        private StripeService $stripeService,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Récupère la clé publique Stripe
     */
    #[Route('/config', name: 'api_stripe_config', methods: ['GET'])]
    public function getConfig(): JsonResponse
    {
        return $this->json([
            'publicKey' => $this->stripeService->getPublicKey()
        ]);
    }

    /**
     * Crée un SetupIntent pour ajouter un moyen de paiement
     */
    #[Route('/setup-intent', name: 'api_stripe_setup_intent', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function createSetupIntent(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        try {
            // Récupérer ou créer le client Stripe
            $customerId = $this->stripeService->getOrCreateCustomer($user);
            
            // Sauvegarder l'ID client si nouveau
            if ($user->getStripeCustomerId() !== $customerId) {
                $user->setStripeCustomerId($customerId);
                $this->entityManager->flush();
            }

            // Créer le SetupIntent
            $setupIntent = $this->stripeService->createSetupIntent($customerId);

            return $this->json([
                'success' => true,
                'clientSecret' => $setupIntent['client_secret']
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Liste les moyens de paiement Stripe du chauffeur
     */
    #[Route('/payment-methods', name: 'api_stripe_payment_methods', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function listPaymentMethods(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $customerId = $user->getStripeCustomerId();
        
        if (!$customerId) {
            return $this->json([
                'success' => true,
                'cards' => [],
                'sepaDebits' => []
            ]);
        }

        try {
            $cards = $this->stripeService->listPaymentMethods($customerId, 'card');
            $sepaDebits = $this->stripeService->listPaymentMethods($customerId, 'sepa_debit');

            return $this->json([
                'success' => true,
                'cards' => $cards,
                'sepaDebits' => $sepaDebits
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Supprime un moyen de paiement Stripe
     */
    #[Route('/payment-methods/{paymentMethodId}', name: 'api_stripe_delete_payment_method', methods: ['DELETE'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function deletePaymentMethod(string $paymentMethodId): JsonResponse
    {
        try {
            $this->stripeService->detachPaymentMethod($paymentMethodId);

            return $this->json([
                'success' => true,
                'message' => 'Moyen de paiement supprimé'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Définit un moyen de paiement par défaut
     */
    #[Route('/payment-methods/{paymentMethodId}/default', name: 'api_stripe_set_default', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function setDefaultPaymentMethod(string $paymentMethodId): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $customerId = $user->getStripeCustomerId();
        
        if (!$customerId) {
            return $this->json(['error' => 'Aucun compte Stripe associé'], 400);
        }

        try {
            $this->stripeService->setDefaultPaymentMethod($customerId, $paymentMethodId);

            return $this->json([
                'success' => true,
                'message' => 'Moyen de paiement défini par défaut'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    // ==================== STRIPE CONNECT (Pour recevoir des paiements) ====================

    /**
     * Crée un compte Connect pour le chauffeur
     */
    #[Route('/connect/create', name: 'api_stripe_connect_create', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function createConnectAccount(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        // Vérifier si un compte existe déjà
        if ($user->getStripeAccountId()) {
            return $this->json([
                'error' => 'Un compte Stripe Connect existe déjà'
            ], 400);
        }

        try {
            $result = $this->stripeService->createConnectAccount($user);
            
            $user->setStripeAccountId($result['account_id']);
            $this->entityManager->flush();

            return $this->json([
                'success' => true,
                'accountId' => $result['account_id']
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Génère un lien d'onboarding pour le compte Connect
     */
    #[Route('/connect/onboarding-link', name: 'api_stripe_connect_onboarding', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function getOnboardingLink(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $accountId = $user->getStripeAccountId();
        
        if (!$accountId) {
            return $this->json(['error' => 'Aucun compte Stripe Connect'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $baseUrl = $data['baseUrl'] ?? 'http://localhost:5173';

        try {
            $link = $this->stripeService->createAccountLink(
                $accountId,
                $baseUrl . '/settings?tab=payment&stripe=refresh',
                $baseUrl . '/settings?tab=payment&stripe=success'
            );

            return $this->json([
                'success' => true,
                'url' => $link
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Vérifie le statut du compte Connect
     */
    #[Route('/connect/status', name: 'api_stripe_connect_status', methods: ['GET'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function getConnectStatus(): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $accountId = $user->getStripeAccountId();
        
        if (!$accountId) {
            return $this->json([
                'success' => true,
                'hasAccount' => false,
                'status' => null
            ]);
        }

        try {
            $status = $this->stripeService->getAccountStatus($accountId);
            
            // Mettre à jour le statut dans la BDD
            $isComplete = $status['charges_enabled'] && $status['payouts_enabled'];
            if ($user->isStripeAccountComplete() !== $isComplete) {
                $user->setStripeAccountComplete($isComplete);
                $this->entityManager->flush();
            }

            return $this->json([
                'success' => true,
                'hasAccount' => true,
                'status' => $status,
                'isComplete' => $isComplete
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    // ==================== PAIEMENTS ====================

    /**
     * Crée un PaymentIntent pour payer une course
     */
    #[Route('/payment-intent', name: 'api_stripe_payment_intent', methods: ['POST'])]
    #[IsGranted('ROLE_CHAUFFEUR')]
    public function createPaymentIntent(Request $request): JsonResponse
    {
        $user = $this->getUser();
        
        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Accès non autorisé'], 403);
        }

        $data = json_decode($request->getContent(), true);
        
        if (!isset($data['amount']) || $data['amount'] <= 0) {
            return $this->json(['error' => 'Montant invalide'], 400);
        }

        try {
            // Montant en centimes
            $amount = (int) ($data['amount'] * 100);
            $customerId = $user->getStripeCustomerId();
            $paymentMethodId = $data['paymentMethodId'] ?? null;

            $paymentIntent = $this->stripeService->createPaymentIntent(
                $amount,
                'eur',
                $customerId,
                $paymentMethodId,
                [
                    'chauffeur_id' => $user->getId(),
                    'ride_id' => $data['rideId'] ?? null
                ]
            );

            return $this->json([
                'success' => true,
                'clientSecret' => $paymentIntent['client_secret'],
                'paymentIntentId' => $paymentIntent['id']
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
