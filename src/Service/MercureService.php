<?php

namespace App\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Psr\Log\LoggerInterface;

/**
 * Service pour gérer les publications en temps réel via Mercure
 */
class MercureService
{
    public function __construct(
        private HubInterface $hub,
        private LoggerInterface $logger
    ) {}

    /**
     * Publier une nouvelle course disponible
     */
    public function publishNewRide(array $rideData): void
    {
        $this->publish(
            topics: ['/rides/new', '/rides/public'],
            data: [
                'type' => 'new_ride',
                'ride' => $rideData,
                'timestamp' => (new \DateTime())->format('c')
            ]
        );
    }

    /**
     * Publier une mise à jour de course
     */
    public function publishRideUpdate(int $rideId, array $rideData, string $updateType = 'update'): void
    {
        $this->publish(
            topics: ["/rides/{$rideId}", '/rides/updates'],
            data: [
                'type' => "ride_{$updateType}",
                'rideId' => $rideId,
                'ride' => $rideData,
                'timestamp' => (new \DateTime())->format('c')
            ]
        );
    }

    /**
     * Publier un changement de statut de course
     */
    public function publishRideStatusChange(int $rideId, string $oldStatus, string $newStatus, array $rideData = []): void
    {
        $this->publish(
            topics: ["/rides/{$rideId}", '/rides/status'],
            data: [
                'type' => 'ride_status_change',
                'rideId' => $rideId,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
                'ride' => $rideData,
                'timestamp' => (new \DateTime())->format('c')
            ]
        );
    }

    /**
     * Publier un nouveau message de chat
     */
    public function publishChatMessage(int $conversationId, array $messageData, array $participantIds): void
    {
        // Topic pour la conversation
        $topics = ["/chat/conversation/{$conversationId}"];
        
        // Topics individuels pour chaque participant
        foreach ($participantIds as $userId) {
            $topics[] = "/user/{$userId}/chat";
        }

        $this->publish(
            topics: $topics,
            data: [
                'type' => 'new_message',
                'conversationId' => $conversationId,
                'message' => $messageData,
                'timestamp' => (new \DateTime())->format('c')
            ]
        );
    }

    /**
     * Publier une notification de frappe (typing indicator)
     */
    public function publishTypingIndicator(int $conversationId, int $userId, string $userName, bool $isTyping): void
    {
        $this->publish(
            topics: ["/chat/conversation/{$conversationId}/typing"],
            data: [
                'type' => 'typing_indicator',
                'conversationId' => $conversationId,
                'userId' => $userId,
                'userName' => $userName,
                'isTyping' => $isTyping,
                'timestamp' => (new \DateTime())->format('c')
            ],
            private: false
        );
    }

    /**
     * Publier une notification à un utilisateur spécifique
     */
    public function publishNotification(int $userId, array $notificationData): void
    {
        $this->publish(
            topics: ["/user/{$userId}/notifications"],
            data: [
                'type' => 'notification',
                'notification' => $notificationData,
                'timestamp' => (new \DateTime())->format('c')
            ],
            private: true
        );
    }

    /**
     * Publier une mise à jour de position GPS (tracking)
     */
    public function publishLocationUpdate(int $rideId, int $chauffeurId, array $location): void
    {
        $this->publish(
            topics: ["/rides/{$rideId}/tracking", "/chauffeur/{$chauffeurId}/location"],
            data: [
                'type' => 'location_update',
                'rideId' => $rideId,
                'chauffeurId' => $chauffeurId,
                'location' => [
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                    'accuracy' => $location['accuracy'] ?? null,
                    'speed' => $location['speed'] ?? null,
                    'heading' => $location['heading'] ?? null,
                ],
                'timestamp' => (new \DateTime())->format('c')
            ]
        );
    }

    /**
     * Publier une mise à jour de paiement escrow
     */
    public function publishEscrowUpdate(int $rideId, int $sellerId, int $buyerId, array $escrowData): void
    {
        $this->publish(
            topics: [
                "/rides/{$rideId}/escrow",
                "/user/{$sellerId}/escrow",
                "/user/{$buyerId}/escrow"
            ],
            data: [
                'type' => 'escrow_update',
                'rideId' => $rideId,
                'escrow' => $escrowData,
                'timestamp' => (new \DateTime())->format('c')
            ],
            private: true
        );
    }

    /**
     * Publier un message à tous les utilisateurs connectés (broadcast)
     */
    public function broadcast(string $channel, array $data): void
    {
        $this->publish(
            topics: ["/broadcast/{$channel}"],
            data: array_merge($data, [
                'type' => 'broadcast',
                'channel' => $channel,
                'timestamp' => (new \DateTime())->format('c')
            ]),
            private: false
        );
    }

    /**
     * Méthode générique pour publier sur Mercure
     */
    private function publish(array $topics, array $data, bool $private = true): void
    {
        try {
            $update = new Update(
                topics: $topics,
                data: json_encode($data),
                private: $private
            );

            $this->hub->publish($update);

            $this->logger->info('Mercure update published', [
                'topics' => $topics,
                'type' => $data['type'] ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to publish Mercure update', [
                'topics' => $topics,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Générer un token JWT pour un utilisateur (pour s'abonner aux topics privés)
     */
    public function generateSubscriptionToken(int $userId, array $additionalTopics = []): string
    {
        $topics = [
            "/user/{$userId}/notifications",
            "/user/{$userId}/chat",
            "/user/{$userId}/escrow",
            '/rides/new',
            '/rides/public',
            '/rides/updates',
        ];

        $topics = array_merge($topics, $additionalTopics);

        // Le token est géré automatiquement par le bundle Mercure
        // Cette méthode retourne les topics autorisés pour l'utilisateur
        return json_encode(['mercure' => ['subscribe' => $topics]]);
    }
}
