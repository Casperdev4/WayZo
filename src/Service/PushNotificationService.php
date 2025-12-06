<?php

namespace App\Service;

use App\Entity\Chauffeur;
use App\Entity\Notification;
use App\Repository\ChauffeurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FCMNotification;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\WebPushConfig;
use Psr\Log\LoggerInterface;

/**
 * Service pour gérer les Push Notifications via Firebase Cloud Messaging (FCM)
 */
class PushNotificationService
{
    public function __construct(
        private ?Messaging $messaging,
        private EntityManagerInterface $entityManager,
        private ChauffeurRepository $chauffeurRepository,
        private LoggerInterface $logger,
        private MercureService $mercureService
    ) {}

    /**
     * Envoyer une notification push à un utilisateur
     */
    public function sendToUser(
        Chauffeur $user,
        string $title,
        string $body,
        array $data = [],
        bool $saveToDb = true
    ): bool {
        $fcmToken = $user->getFcmToken();

        // Sauvegarder en base de données
        if ($saveToDb) {
            $this->saveNotification($user, $title, $body, $data);
        }

        // Publier sur Mercure pour temps réel web
        $this->mercureService->publishNotification($user->getId(), [
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'createdAt' => (new \DateTime())->format('c')
        ]);

        // Si pas de token FCM, on s'arrête ici
        if (!$fcmToken || !$this->messaging) {
            $this->logger->info('No FCM token or messaging not configured', [
                'userId' => $user->getId()
            ]);
            return true; // Notification enregistrée en base et Mercure
        }

        try {
            $message = $this->buildMessage($fcmToken, $title, $body, $data);
            $this->messaging->send($message);

            $this->logger->info('Push notification sent', [
                'userId' => $user->getId(),
                'title' => $title
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to send push notification', [
                'userId' => $user->getId(),
                'error' => $e->getMessage()
            ]);

            // Si le token est invalide, le supprimer
            if (str_contains($e->getMessage(), 'not a valid FCM registration token')) {
                $user->setFcmToken(null);
                $this->entityManager->flush();
            }

            return false;
        }
    }

    /**
     * Envoyer une notification à plusieurs utilisateurs
     */
    public function sendToUsers(
        array $users,
        string $title,
        string $body,
        array $data = [],
        bool $saveToDb = true
    ): array {
        $results = ['success' => 0, 'failed' => 0];

        foreach ($users as $user) {
            if ($this->sendToUser($user, $title, $body, $data, $saveToDb)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Envoyer une notification à tous les chauffeurs avec FCM token
     */
    public function sendToAllUsers(
        string $title,
        string $body,
        array $data = []
    ): array {
        $tokens = $this->chauffeurRepository->getAllFcmTokens();

        if (empty($tokens) || !$this->messaging) {
            return ['success' => 0, 'failed' => 0, 'message' => 'No tokens available'];
        }

        try {
            $message = CloudMessage::new()
                ->withNotification(FCMNotification::create($title, $body))
                ->withData($data);

            $report = $this->messaging->sendMulticast($message, $tokens);

            return [
                'success' => $report->successes()->count(),
                'failed' => $report->failures()->count()
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to send multicast', [
                'error' => $e->getMessage()
            ]);
            return ['success' => 0, 'failed' => count($tokens), 'error' => $e->getMessage()];
        }
    }

    /**
     * Notification: Nouvelle course disponible
     */
    public function notifyNewRide(array $rideData): void
    {
        $title = '🚗 Nouvelle course disponible !';
        $body = sprintf(
            '%s → %s • %s€',
            $rideData['depart'] ?? 'Départ',
            $rideData['destination'] ?? 'Destination',
            $rideData['prix'] ?? '0'
        );

        $data = [
            'type' => 'new_ride',
            'rideId' => (string) ($rideData['id'] ?? ''),
            'click_action' => 'OPEN_RIDE_DETAILS'
        ];

        // Broadcast à tous les utilisateurs avec tokens
        $this->sendToAllUsers($title, $body, $data);
    }

    /**
     * Notification: Course acceptée
     */
    public function notifyRideAccepted(Chauffeur $owner, array $rideData, Chauffeur $acceptor): void
    {
        $title = '✅ Course acceptée !';
        $body = sprintf(
            '%s a accepté votre course %s → %s',
            $acceptor->getPrenom() . ' ' . $acceptor->getNom(),
            $rideData['depart'] ?? '',
            $rideData['destination'] ?? ''
        );

        $this->sendToUser($owner, $title, $body, [
            'type' => 'ride_accepted',
            'rideId' => (string) ($rideData['id'] ?? ''),
            'click_action' => 'OPEN_RIDE_DETAILS'
        ]);
    }

    /**
     * Notification: Nouveau message de chat
     */
    public function notifyNewMessage(Chauffeur $recipient, Chauffeur $sender, string $messagePreview, int $conversationId): void
    {
        $title = sprintf('💬 Message de %s', $sender->getPrenom());
        $body = strlen($messagePreview) > 100 
            ? substr($messagePreview, 0, 97) . '...' 
            : $messagePreview;

        $this->sendToUser($recipient, $title, $body, [
            'type' => 'new_message',
            'conversationId' => (string) $conversationId,
            'senderId' => (string) $sender->getId(),
            'click_action' => 'OPEN_CHAT'
        ]);
    }

    /**
     * Notification: Paiement reçu
     */
    public function notifyPaymentReceived(Chauffeur $seller, float $amount, array $rideData): void
    {
        $title = '💰 Paiement reçu !';
        $body = sprintf(
            'Vous avez reçu %.2f€ pour la course %s → %s',
            $amount,
            $rideData['depart'] ?? '',
            $rideData['destination'] ?? ''
        );

        $this->sendToUser($seller, $title, $body, [
            'type' => 'payment_received',
            'rideId' => (string) ($rideData['id'] ?? ''),
            'amount' => (string) $amount,
            'click_action' => 'OPEN_TRANSACTIONS'
        ]);
    }

    /**
     * Notification: Escrow status change
     */
    public function notifyEscrowStatusChange(Chauffeur $user, string $status, array $rideData): void
    {
        $statusMessages = [
            'held' => ['🔒 Fonds bloqués', 'Les fonds ont été sécurisés pour votre course'],
            'awaiting_validation' => ['⏳ En attente de validation', 'Confirmez la réception pour libérer les fonds'],
            'completed' => ['✅ Paiement finalisé', 'Les fonds ont été transférés'],
            'refunded' => ['↩️ Remboursement effectué', 'Les fonds ont été remboursés'],
            'disputed' => ['⚠️ Litige ouvert', 'Un litige a été ouvert sur cette course']
        ];

        $message = $statusMessages[$status] ?? ['📋 Mise à jour', 'Le statut du paiement a changé'];

        $this->sendToUser($user, $message[0], $message[1], [
            'type' => 'escrow_update',
            'status' => $status,
            'rideId' => (string) ($rideData['id'] ?? ''),
            'click_action' => 'OPEN_RIDE_DETAILS'
        ]);
    }

    /**
     * Notification: Course en approche
     */
    public function notifyRideApproaching(Chauffeur $client, int $minutesAway, array $rideData): void
    {
        $title = '🚗 Chauffeur en approche';
        $body = sprintf('Votre chauffeur arrivera dans environ %d minutes', $minutesAway);

        $this->sendToUser($client, $title, $body, [
            'type' => 'ride_approaching',
            'rideId' => (string) ($rideData['id'] ?? ''),
            'minutesAway' => (string) $minutesAway,
            'click_action' => 'OPEN_TRACKING'
        ]);
    }

    /**
     * Construire le message FCM
     */
    private function buildMessage(string $token, string $title, string $body, array $data): CloudMessage
    {
        return CloudMessage::withTarget('token', $token)
            ->withNotification(FCMNotification::create($title, $body))
            ->withData(array_map('strval', $data))
            ->withAndroidConfig(
                AndroidConfig::fromArray([
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'wayzo_notifications',
                        'icon' => 'ic_notification',
                        'color' => '#3B82F6',
                        'sound' => 'default',
                        'click_action' => $data['click_action'] ?? 'OPEN_APP'
                    ]
                ])
            )
            ->withApnsConfig(
                ApnsConfig::fromArray([
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert'
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body
                            ],
                            'badge' => 1,
                            'sound' => 'default',
                            'category' => $data['type'] ?? 'GENERAL'
                        ]
                    ]
                ])
            )
            ->withWebPushConfig(
                WebPushConfig::fromArray([
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'icon' => '/img/logo-wayzo.png',
                        'badge' => '/img/badge-wayzo.png',
                        'vibrate' => [200, 100, 200],
                        'requireInteraction' => true
                    ]
                ])
            );
    }

    /**
     * Sauvegarder la notification en base
     */
    private function saveNotification(Chauffeur $user, string $title, string $body, array $data): Notification
    {
        $notification = new Notification();
        $notification->setRecipient($user);
        $notification->setTitle($title);
        $notification->setMessage($body);
        $notification->setType($data['type'] ?? 'general');
        $notification->setIsRead(false);
        
        // Stocker les métadonnées additionnelles (conversationId, rideId, etc.)
        $notification->setData($data);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    /**
     * Enregistrer le token FCM d'un utilisateur
     */
    public function registerToken(Chauffeur $user, string $token, string $platform = 'web'): void
    {
        $user->setFcmToken($token);
        $user->setFcmPlatform($platform);
        $this->entityManager->flush();

        $this->logger->info('FCM token registered', [
            'userId' => $user->getId(),
            'platform' => $platform
        ]);
    }

    /**
     * Supprimer le token FCM d'un utilisateur
     */
    public function unregisterToken(Chauffeur $user): void
    {
        $user->setFcmToken(null);
        $user->setFcmPlatform(null);
        $this->entityManager->flush();

        $this->logger->info('FCM token unregistered', [
            'userId' => $user->getId()
        ]);
    }
}
