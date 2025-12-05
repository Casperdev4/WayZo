<?php

namespace App\Service;

use App\Entity\Chauffeur;
use App\Entity\PaymentMethod;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class StripeService
{
    private StripeClient $stripe;
    private string $publicKey;

    public function __construct(
        string $stripeSecretKey,
        string $stripePublicKey
    ) {
        $this->stripe = new StripeClient($stripeSecretKey);
        $this->publicKey = $stripePublicKey;
    }

    /**
     * Retourne la clé publique Stripe
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Crée un client Stripe pour un chauffeur
     */
    public function createCustomer(Chauffeur $chauffeur): string
    {
        try {
            $customer = $this->stripe->customers->create([
                'email' => $chauffeur->getEmail(),
                'name' => $chauffeur->getPrenom() . ' ' . $chauffeur->getNom(),
                'metadata' => [
                    'chauffeur_id' => $chauffeur->getId(),
                    'type' => 'chauffeur'
                ]
            ]);

            return $customer->id;
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur Stripe: ' . $e->getMessage());
        }
    }

    /**
     * Récupère ou crée un client Stripe
     */
    public function getOrCreateCustomer(Chauffeur $chauffeur): string
    {
        $stripeCustomerId = $chauffeur->getStripeCustomerId();
        
        if ($stripeCustomerId) {
            try {
                // Vérifier que le client existe toujours
                $this->stripe->customers->retrieve($stripeCustomerId);
                return $stripeCustomerId;
            } catch (ApiErrorException $e) {
                // Client n'existe plus, on en crée un nouveau
            }
        }

        return $this->createCustomer($chauffeur);
    }

    /**
     * Crée un compte Connect pour recevoir des paiements (pour les chauffeurs)
     */
    public function createConnectAccount(Chauffeur $chauffeur): array
    {
        try {
            $account = $this->stripe->accounts->create([
                'type' => 'express',
                'country' => 'FR',
                'email' => $chauffeur->getEmail(),
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_type' => 'individual',
                'metadata' => [
                    'chauffeur_id' => $chauffeur->getId()
                ]
            ]);

            return [
                'account_id' => $account->id,
                'account' => $account
            ];
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur création compte Connect: ' . $e->getMessage());
        }
    }

    /**
     * Crée un lien d'onboarding pour le compte Connect
     */
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        try {
            $accountLink = $this->stripe->accountLinks->create([
                'account' => $accountId,
                'refresh_url' => $refreshUrl,
                'return_url' => $returnUrl,
                'type' => 'account_onboarding',
            ]);

            return $accountLink->url;
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur création lien onboarding: ' . $e->getMessage());
        }
    }

    /**
     * Vérifie le statut d'un compte Connect
     */
    public function getAccountStatus(string $accountId): array
    {
        try {
            $account = $this->stripe->accounts->retrieve($accountId);
            
            return [
                'id' => $account->id,
                'charges_enabled' => $account->charges_enabled,
                'payouts_enabled' => $account->payouts_enabled,
                'details_submitted' => $account->details_submitted,
                'requirements' => $account->requirements?->toArray() ?? []
            ];
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur récupération compte: ' . $e->getMessage());
        }
    }

    /**
     * Crée un SetupIntent pour ajouter un moyen de paiement
     */
    public function createSetupIntent(string $customerId): array
    {
        try {
            $setupIntent = $this->stripe->setupIntents->create([
                'customer' => $customerId,
                'payment_method_types' => ['card', 'sepa_debit'],
            ]);

            return [
                'client_secret' => $setupIntent->client_secret,
                'id' => $setupIntent->id
            ];
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur création SetupIntent: ' . $e->getMessage());
        }
    }

    /**
     * Attache un moyen de paiement à un client
     */
    public function attachPaymentMethod(string $paymentMethodId, string $customerId): array
    {
        try {
            $paymentMethod = $this->stripe->paymentMethods->attach(
                $paymentMethodId,
                ['customer' => $customerId]
            );

            return $this->formatPaymentMethod($paymentMethod);
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur attachement moyen de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Liste les moyens de paiement d'un client
     */
    public function listPaymentMethods(string $customerId, string $type = 'card'): array
    {
        try {
            $paymentMethods = $this->stripe->paymentMethods->all([
                'customer' => $customerId,
                'type' => $type,
            ]);

            return array_map(
                fn($pm) => $this->formatPaymentMethod($pm),
                $paymentMethods->data
            );
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur liste moyens de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Supprime un moyen de paiement
     */
    public function detachPaymentMethod(string $paymentMethodId): bool
    {
        try {
            $this->stripe->paymentMethods->detach($paymentMethodId);
            return true;
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur suppression moyen de paiement: ' . $e->getMessage());
        }
    }

    /**
     * Définit le moyen de paiement par défaut d'un client
     */
    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): bool
    {
        try {
            $this->stripe->customers->update($customerId, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId
                ]
            ]);
            return true;
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur définition moyen par défaut: ' . $e->getMessage());
        }
    }

    /**
     * Crée un PaymentIntent pour une course
     */
    public function createPaymentIntent(
        int $amount,
        string $currency = 'eur',
        ?string $customerId = null,
        ?string $paymentMethodId = null,
        array $metadata = []
    ): array {
        try {
            $params = [
                'amount' => $amount, // En centimes
                'currency' => $currency,
                'metadata' => $metadata,
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ];

            if ($customerId) {
                $params['customer'] = $customerId;
            }

            if ($paymentMethodId) {
                $params['payment_method'] = $paymentMethodId;
            }

            $paymentIntent = $this->stripe->paymentIntents->create($params);

            return [
                'id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount
            ];
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur création PaymentIntent: ' . $e->getMessage());
        }
    }

    /**
     * Confirme un PaymentIntent
     */
    public function confirmPaymentIntent(string $paymentIntentId, string $paymentMethodId): array
    {
        try {
            $paymentIntent = $this->stripe->paymentIntents->confirm($paymentIntentId, [
                'payment_method' => $paymentMethodId
            ]);

            return [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount
            ];
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur confirmation paiement: ' . $e->getMessage());
        }
    }

    /**
     * Récupère un PaymentIntent
     */
    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        try {
            $paymentIntent = $this->stripe->paymentIntents->retrieve($paymentIntentId);

            return [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'metadata' => $paymentIntent->metadata?->toArray() ?? []
            ];
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur récupération PaymentIntent: ' . $e->getMessage());
        }
    }

    /**
     * Crée un transfert vers un compte Connect (paiement au chauffeur)
     */
    public function createTransfer(
        int $amount,
        string $destinationAccountId,
        string $currency = 'eur',
        array $metadata = []
    ): array {
        try {
            $transfer = $this->stripe->transfers->create([
                'amount' => $amount,
                'currency' => $currency,
                'destination' => $destinationAccountId,
                'metadata' => $metadata
            ]);

            return [
                'id' => $transfer->id,
                'amount' => $transfer->amount,
                'status' => 'completed'
            ];
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur création transfert: ' . $e->getMessage());
        }
    }

    /**
     * Crée un remboursement
     */
    public function createRefund(string $paymentIntentId, ?int $amount = null): array
    {
        try {
            $params = ['payment_intent' => $paymentIntentId];
            
            if ($amount !== null) {
                $params['amount'] = $amount;
            }

            $refund = $this->stripe->refunds->create($params);

            return [
                'id' => $refund->id,
                'amount' => $refund->amount,
                'status' => $refund->status
            ];
        } catch (ApiErrorException $e) {
            throw new \Exception('Erreur création remboursement: ' . $e->getMessage());
        }
    }

    /**
     * Construit un événement webhook
     */
    public function constructWebhookEvent(string $payload, string $signature, string $webhookSecret): \Stripe\Event
    {
        return \Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret);
    }

    /**
     * Formate un moyen de paiement Stripe
     */
    private function formatPaymentMethod($paymentMethod): array
    {
        $data = [
            'id' => $paymentMethod->id,
            'type' => $paymentMethod->type,
            'created' => $paymentMethod->created,
        ];

        if ($paymentMethod->type === 'card') {
            $card = $paymentMethod->card;
            $data['card'] = [
                'brand' => $card->brand,
                'last4' => $card->last4,
                'exp_month' => $card->exp_month,
                'exp_year' => $card->exp_year,
                'funding' => $card->funding
            ];
        } elseif ($paymentMethod->type === 'sepa_debit') {
            $sepa = $paymentMethod->sepa_debit;
            $data['sepa_debit'] = [
                'bank_code' => $sepa->bank_code,
                'country' => $sepa->country,
                'last4' => $sepa->last4
            ];
        }

        return $data;
    }
}
