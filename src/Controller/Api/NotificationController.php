<?php

namespace App\Controller\Api;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/notifications')]
class NotificationController extends AbstractController
{
    public function __construct(
        private NotificationRepository $notificationRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Compte les notifications non lues
     */
    #[Route('/count', name: 'api_notification_count', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function count(): JsonResponse
    {
        $user = $this->getUser();
        $count = $this->notificationRepository->countUnreadByUser($user);
        
        return $this->json(['count' => $count]);
    }

    /**
     * Liste des notifications
     */
    #[Route('', name: 'api_notifications_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        $notifications = $this->notificationRepository->findByUser($user);
        
        $data = array_map(function(Notification $notif) {
            return [
                'id' => $notif->getId(),
                'type' => $notif->getType(),
                'title' => $notif->getTitle(),
                'message' => $notif->getMessage(),
                'isRead' => $notif->isRead(),
                'createdAt' => $notif->getCreatedAt()?->format('c'),
                'data' => $notif->getData(), // 🔥 Inclure les métadonnées
                'sender' => $notif->getSender() ? [
                    'id' => $notif->getSender()->getId(),
                    'name' => $notif->getSender()->getPrenom() . ' ' . $notif->getSender()->getNom(),
                ] : null,
                'ride' => $notif->getRide() ? [
                    'id' => $notif->getRide()->getId(),
                    'depart' => $notif->getRide()->getDepart(),
                    'destination' => $notif->getRide()->getDestination(),
                ] : null,
            ];
        }, $notifications);
        
        return $this->json($data);
    }

    /**
     * Marquer une notification comme lue
     */
    #[Route('/{id}/read', name: 'api_notification_read', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function markAsRead(Notification $notification): JsonResponse
    {
        $user = $this->getUser();
        
        if ($notification->getRecipient() !== $user) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }
        
        $notification->setIsRead(true);
        $this->entityManager->flush();
        
        return $this->json(['success' => true]);
    }

    /**
     * Marquer toutes les notifications comme lues
     */
    #[Route('/read-all', name: 'api_notifications_read_all', methods: ['PUT'])]
    #[IsGranted('ROLE_USER')]
    public function markAllAsRead(): JsonResponse
    {
        $user = $this->getUser();
        $notifications = $this->notificationRepository->findUnreadByUser($user);
        
        foreach ($notifications as $notification) {
            $notification->setIsRead(true);
        }
        
        $this->entityManager->flush();
        
        return $this->json(['success' => true, 'count' => count($notifications)]);
    }
}








