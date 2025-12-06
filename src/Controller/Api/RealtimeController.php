<?php

namespace App\Controller\Api;

use App\Entity\Chauffeur;
use App\Service\MercureService;
use App\Service\PushNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mercure\Authorization;

#[Route('/api/realtime')]
#[IsGranted('ROLE_USER')]
class RealtimeController extends AbstractController
{
    public function __construct(
        private MercureService $mercureService,
        private PushNotificationService $pushNotificationService,
        private Authorization $authorization
    ) {}

    /**
     * Récupérer le chauffeur connecté (helper typé)
     */
    private function getChauffeur(): Chauffeur
    {
        /** @var Chauffeur $user */
        $user = $this->getUser();
        return $user;
    }

    /**
     * Obtenir la configuration Mercure pour le client
     */
    #[Route('/config', name: 'api_realtime_config', methods: ['GET'])]
    public function getConfig(Request $request): JsonResponse
    {
        $user = $this->getChauffeur();
        
        // Topics autorisés pour cet utilisateur
        $topics = [
            "/user/{$user->getId()}/notifications",
            "/user/{$user->getId()}/chat",
            "/user/{$user->getId()}/escrow",
            '/rides/new',
            '/rides/public',
            '/rides/updates',
            '/rides/status',
        ];

        // Générer le cookie d'autorisation Mercure
        $response = new JsonResponse([
            'mercureUrl' => $_ENV['MERCURE_PUBLIC_URL'] ?? 'http://localhost:3000/.well-known/mercure',
            'topics' => $topics,
            'userId' => $user->getId()
        ]);

        // Ajouter le cookie d'autorisation Mercure
        $this->authorization->setCookie($request, $response, $topics);

        return $response;
    }

    /**
     * S'abonner à des topics supplémentaires (ex: suivi d'une course spécifique)
     */
    #[Route('/subscribe', name: 'api_realtime_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $topics = $data['topics'] ?? [];
        $user = $this->getChauffeur();

        // Valider et filtrer les topics
        $allowedTopics = [];
        foreach ($topics as $topic) {
            // Vérifier que l'utilisateur a le droit de s'abonner à ce topic
            if ($this->isTopicAllowed($topic, $user)) {
                $allowedTopics[] = $topic;
            }
        }

        $response = new JsonResponse([
            'subscribedTopics' => $allowedTopics
        ]);

        if (!empty($allowedTopics)) {
            $this->authorization->setCookie($request, $response, $allowedTopics);
        }

        return $response;
    }

    /**
     * Enregistrer le token FCM pour les push notifications
     */
    #[Route('/fcm/register', name: 'api_realtime_fcm_register', methods: ['POST'])]
    public function registerFcmToken(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;
        $platform = $data['platform'] ?? 'web'; // web, android, ios

        if (!$token) {
            return $this->json(['error' => 'Token FCM requis'], 400);
        }

        $user = $this->getChauffeur();
        $this->pushNotificationService->registerToken($user, $token, $platform);

        return $this->json([
            'success' => true,
            'message' => 'Token FCM enregistré',
            'platform' => $platform
        ]);
    }

    /**
     * Supprimer le token FCM (déconnexion)
     */
    #[Route('/fcm/unregister', name: 'api_realtime_fcm_unregister', methods: ['POST'])]
    public function unregisterFcmToken(): JsonResponse
    {
        $user = $this->getChauffeur();
        $this->pushNotificationService->unregisterToken($user);

        return $this->json([
            'success' => true,
            'message' => 'Token FCM supprimé'
        ]);
    }

    /**
     * Tester l'envoi d'une notification push (debug)
     */
    #[Route('/fcm/test', name: 'api_realtime_fcm_test', methods: ['POST'])]
    public function testPushNotification(): JsonResponse
    {
        $user = $this->getChauffeur();

        $result = $this->pushNotificationService->sendToUser(
            $user,
            '🧪 Test Notification',
            'Ceci est une notification de test WayZo !',
            ['type' => 'test', 'click_action' => 'OPEN_APP'],
            false // Ne pas sauvegarder en base
        );

        return $this->json([
            'success' => $result,
            'hasFcmToken' => $user->getFcmToken() !== null
        ]);
    }

    /**
     * Indicateur de frappe pour le chat
     */
    #[Route('/typing', name: 'api_realtime_typing', methods: ['POST'])]
    public function sendTypingIndicator(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $conversationId = $data['conversationId'] ?? null;
        $isTyping = $data['isTyping'] ?? true;

        if (!$conversationId) {
            return $this->json(['error' => 'conversationId requis'], 400);
        }

        $user = $this->getChauffeur();
        
        $this->mercureService->publishTypingIndicator(
            $conversationId,
            $user->getId(),
            $user->getPrenom() . ' ' . $user->getNom(),
            $isTyping
        );

        return $this->json(['success' => true]);
    }

    /**
     * Vérifier si un topic est autorisé pour l'utilisateur
     */
    private function isTopicAllowed(string $topic, Chauffeur $user): bool
    {
        $userId = $user->getId();

        // Topics publics
        $publicPatterns = [
            '/rides/new',
            '/rides/public',
            '/rides/updates',
            '/rides/status',
            '/broadcast/*',
        ];

        foreach ($publicPatterns as $pattern) {
            if (fnmatch($pattern, $topic)) {
                return true;
            }
        }

        // Topics privés de l'utilisateur
        $privatePatterns = [
            "/user/{$userId}/*",
            "/chauffeur/{$userId}/*",
        ];

        foreach ($privatePatterns as $pattern) {
            if (fnmatch($pattern, $topic)) {
                return true;
            }
        }

        // Topics liés aux courses (vérifier si l'utilisateur est impliqué)
        if (preg_match('#^/rides/(\d+)(/.*)?$#', $topic, $matches)) {
            // TODO: Vérifier si l'utilisateur est impliqué dans cette course
            return true;
        }

        // Topics liés aux conversations (vérifier si l'utilisateur participe)
        if (preg_match('#^/chat/conversation/(\d+)(/.*)?$#', $topic, $matches)) {
            // TODO: Vérifier si l'utilisateur participe à cette conversation
            return true;
        }

        return false;
    }
}
