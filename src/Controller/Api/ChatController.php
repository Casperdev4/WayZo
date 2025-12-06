<?php

namespace App\Controller\Api;

use App\Entity\Message;
use App\Entity\Conversation;
use App\Entity\Notification;
use App\Repository\MessageRepository;
use App\Repository\ChauffeurRepository;
use App\Repository\CourseRepository;
use App\Repository\RideRepository;
use App\Repository\ConversationRepository;
use App\Service\MercureService;
use App\Service\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
class ChatController extends BaseApiController
{
    public function __construct(
        private MessageRepository $messageRepository,
        private ChauffeurRepository $chauffeurRepository,
        private CourseRepository $courseRepository,
        private RideRepository $rideRepository,
        private ConversationRepository $conversationRepository,
        private EntityManagerInterface $em,
        private MercureService $mercureService,
        private PushNotificationService $pushNotificationService
    ) {}

    /**
     * Liste des conversations (chauffeurs avec qui on a échangé)
     * Inclut les conversations de Course ET de Ride
     */
    #[Route('/chats', name: 'api_chats_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getChats(): JsonResponse
    {
        $user = $this->getChauffeur();
        $conversations = [];
        
        // 1. Récupérer les conversations liées aux Rides
        $rideConversations = $this->conversationRepository->findByUser($user);
        
        foreach ($rideConversations as $conv) {
            $other = $conv->getOtherParticipant($user);
            $lastMessage = $conv->getMessages()->last();
            
            // Compter les messages non lus
            $unreadCount = 0;
            foreach ($conv->getMessages() as $msg) {
                if ($msg->getExpediteur() !== $user && !$msg->isRead()) {
                    $unreadCount++;
                }
            }
            
            $conversations[] = [
                'id' => 'ride-' . $conv->getId(),
                'conversationId' => $conv->getId(),
                'rideId' => $conv->getRide()->getId(),
                'chatType' => 'personal',
                'muted' => false,
                'unread' => $unreadCount,
                'name' => $other ? $other->getPrenom() . ' ' . $other->getNom() : 'Inconnu',
                'userId' => $other?->getId(),
                'avatar' => '/img/avatars/thumb-' . (($other?->getId() ?? 1) % 15 + 1) . '.jpg',
                'lastConversation' => $lastMessage ? $lastMessage->getContenu() : '',
                'lastConversationTime' => $lastMessage ? $lastMessage->getDateEnvoi()?->getTimestamp() : null,
                'ride' => [
                    'id' => $conv->getRide()->getId(),
                    'depart' => $conv->getRide()->getDepart(),
                    'destination' => $conv->getRide()->getDestination(),
                    'date' => $conv->getRide()->getDate()?->format('Y-m-d'),
                ],
            ];
        }
        
        // 2. Récupérer les anciennes conversations de Course (pour compatibilité)
        $sentMessages = $this->messageRepository->findBy(['expediteur' => $user]);
        $processedCourses = [];
        
        foreach ($sentMessages as $message) {
            $course = $message->getCourse();
            if ($course && !in_array($course->getId(), $processedCourses)) {
                $processedCourses[] = $course->getId();
                
                $otherChauffeur = null;
                if ($course->getChauffeurVendeur() && $course->getChauffeurVendeur()->getId() !== $user->getId()) {
                    $otherChauffeur = $course->getChauffeurVendeur();
                } elseif ($course->getChauffeurAccepteur() && $course->getChauffeurAccepteur()->getId() !== $user->getId()) {
                    $otherChauffeur = $course->getChauffeurAccepteur();
                }
                
                if ($otherChauffeur) {
                    $lastMessage = $this->messageRepository->findOneBy(
                        ['course' => $course],
                        ['dateEnvoi' => 'DESC']
                    );
                    
                    $conversations[] = [
                        'id' => 'chat-' . $course->getId(),
                        'courseId' => $course->getId(),
                        'chatType' => 'personal',
                        'muted' => false,
                        'unread' => 0,
                        'name' => $otherChauffeur->getPrenom() . ' ' . $otherChauffeur->getNom(),
                        'userId' => $otherChauffeur->getId(),
                        'avatar' => '/img/avatars/thumb-' . ($otherChauffeur->getId() % 15 + 1) . '.jpg',
                        'lastConversation' => $lastMessage ? $lastMessage->getContenu() : '',
                        'lastConversationTime' => $lastMessage ? $lastMessage->getDateEnvoi()->getTimestamp() : null,
                        'course' => [
                            'id' => $course->getId(),
                            'depart' => $course->getDepart(),
                            'destination' => $course->getArrivee(),
                            'date' => $course->getDate()?->format('Y-m-d'),
                        ],
                    ];
                }
            }
        }
        
        // Trier par date du dernier message
        usort($conversations, function($a, $b) {
            return ($b['lastConversationTime'] ?? 0) - ($a['lastConversationTime'] ?? 0);
        });
        
        return new JsonResponse($conversations);
    }

    /**
     * Compter le nombre total de messages non lus
     */
    #[Route('/conversations/unread-count', name: 'api_conversations_unread_count', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getUnreadMessagesCount(): JsonResponse
    {
        $user = $this->getChauffeur();
        $totalUnread = 0;

        // Compter depuis les conversations Ride
        $rideConversations = $this->conversationRepository->findByUser($user);
        foreach ($rideConversations as $conv) {
            foreach ($conv->getMessages() as $msg) {
                if ($msg->getExpediteur() !== $user && !$msg->isRead()) {
                    $totalUnread++;
                }
            }
        }

        return new JsonResponse(['count' => $totalUnread]);
    }

    /**
     * Récupérer une conversation complète
     * Supporte les formats: chat-{courseId} et ride-{conversationId}
     */
    #[Route('/conversation/{id}', name: 'api_conversation_get', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getConversation(string $id): JsonResponse
    {
        $user = $this->getChauffeur();
        
        // Format ride-{id} pour les conversations Ride
        if (str_starts_with($id, 'ride-')) {
            $conversationId = (int) str_replace('ride-', '', $id);
            $conversation = $this->conversationRepository->find($conversationId);
            
            if (!$conversation) {
                return new JsonResponse(['error' => 'Conversation non trouvée'], 404);
            }
            
            if (!$conversation->hasParticipant($user)) {
                return new JsonResponse(['error' => 'Accès refusé'], 403);
            }
            
            // Marquer les messages comme lus
            foreach ($conversation->getMessages() as $msg) {
                if ($msg->getExpediteur() !== $user && !$msg->isRead()) {
                    $msg->setIsRead(true);
                }
            }
            $this->em->flush();
            
            $messages = array_map(function($message) use ($user) {
                $sender = $message->getExpediteur();
                return [
                    'id' => 'msg-' . $message->getId(),
                    'sender' => [
                        'id' => $sender?->getId(),
                        'name' => $sender ? $sender->getPrenom() . ' ' . $sender->getNom() : 'Inconnu',
                        'avatarImageUrl' => '/img/avatars/thumb-' . (($sender?->getId() ?? 1) % 15 + 1) . '.jpg',
                    ],
                    'content' => $message->getContenu(),
                    'timestamp' => $message->getDateEnvoi()?->getTimestamp(),
                    'type' => 'regular',
                    'isMyMessage' => $sender === $user,
                ];
            }, $conversation->getMessages()->toArray());
            
            return new JsonResponse([
                'id' => $id,
                'conversation' => $messages,
            ]);
        }
        
        // Format chat-{id} pour les anciennes conversations Course
        $courseId = str_replace('chat-', '', $id);
        $course = $this->courseRepository->find($courseId);
        
        if (!$course) {
            return new JsonResponse(['error' => 'Conversation non trouvée'], 404);
        }
        
        // Vérifier que l'utilisateur participe à cette conversation
        $isParticipant = ($course->getChauffeurVendeur() && $course->getChauffeurVendeur()->getId() === $user->getId())
            || ($course->getChauffeurAccepteur() && $course->getChauffeurAccepteur()->getId() === $user->getId());
            
        if (!$isParticipant) {
            return new JsonResponse(['error' => 'Accès refusé'], 403);
        }
        
        // Récupérer tous les messages de cette conversation
        $messages = $this->messageRepository->findBy(
            ['course' => $course],
            ['dateEnvoi' => 'ASC']
        );
        
        $conversation = array_map(function($message) use ($user) {
            $sender = $message->getExpediteur();
            return [
                'id' => 'msg-' . $message->getId(),
                'sender' => [
                    'id' => $sender->getId(),
                    'name' => $sender->getPrenom() . ' ' . $sender->getNom(),
                    'avatarImageUrl' => '/img/avatars/thumb-' . ($sender->getId() % 15 + 1) . '.jpg',
                ],
                'content' => $message->getContenu(),
                'timestamp' => $message->getDateEnvoi()->getTimestamp(),
                'type' => 'regular',
                'isMyMessage' => $sender->getId() === $user->getId(),
            ];
        }, $messages);
        
        return new JsonResponse([
            'id' => $id,
            'conversation' => $conversation,
        ]);
    }

    /**
     * Envoyer un message
     * Supporte les formats: chat-{courseId} et ride-{conversationId}
     */
    #[Route('/conversation/{id}/message', name: 'api_conversation_send', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendMessage(string $id, Request $request): JsonResponse
    {
        $user = $this->getChauffeur();
        $data = json_decode($request->getContent(), true);
        $content = $data['content'] ?? '';
        
        if (empty(trim($content))) {
            return new JsonResponse(['error' => 'Message vide'], 400);
        }
        
        // Format ride-{id} pour les conversations Ride
        if (str_starts_with($id, 'ride-')) {
            $conversationId = (int) str_replace('ride-', '', $id);
            $conversation = $this->conversationRepository->find($conversationId);
            
            if (!$conversation) {
                return new JsonResponse(['error' => 'Conversation non trouvée'], 404);
            }
            
            if (!$conversation->hasParticipant($user)) {
                return new JsonResponse(['error' => 'Accès refusé'], 403);
            }
            
            // Créer le message
            $message = new Message();
            $message->setContenu($content);
            $message->setDateEnvoi(new \DateTime());
            $message->setExpediteur($user);
            $message->setConversation($conversation);
            
            $conversation->setLastMessageAt(new \DateTime());
            
            $this->em->persist($message);
            
            // Créer une notification pour l'autre participant
            $other = $conversation->getOtherParticipant($user);
            if ($other) {
                $notification = new Notification();
                $notification->setRecipient($other);
                $notification->setSender($user);
                $notification->setType(Notification::TYPE_NEW_MESSAGE);
                $notification->setTitle('Nouveau message');
                $notification->setMessage($user->getPrenom() . ' vous a envoyé un message');
                $notification->setRide($conversation->getRide());
                $this->em->persist($notification);

                // 🔥 Publier en temps réel via Mercure
                $this->mercureService->publishChatMessage(
                    $conversation->getId(),
                    [
                        'id' => 'msg-' . $message->getId(),
                        'sender' => [
                            'id' => $user->getId(),
                            'name' => $user->getPrenom() . ' ' . $user->getNom(),
                            'avatarImageUrl' => '/img/avatars/thumb-' . ($user->getId() % 15 + 1) . '.jpg',
                        ],
                        'content' => $message->getContenu(),
                        'timestamp' => $message->getDateEnvoi()->getTimestamp(),
                        'type' => 'regular',
                    ],
                    [$user->getId(), $other->getId()]
                );

                // 📱 Envoyer une push notification
                $this->pushNotificationService->notifyNewMessage(
                    $other,
                    $user,
                    $message->getContenu(),
                    $conversation->getId()
                );
            }
            
            $this->em->flush();
            
            return new JsonResponse([
                'id' => 'msg-' . $message->getId(),
                'sender' => [
                    'id' => $user->getId(),
                    'name' => $user->getPrenom() . ' ' . $user->getNom(),
                    'avatarImageUrl' => '/img/avatars/thumb-' . ($user->getId() % 15 + 1) . '.jpg',
                ],
                'content' => $message->getContenu(),
                'timestamp' => $message->getDateEnvoi()->getTimestamp(),
                'type' => 'regular',
                'isMyMessage' => true,
            ], 201);
        }
        
        // Format chat-{id} pour les anciennes conversations Course
        $courseId = str_replace('chat-', '', $id);
        $course = $this->courseRepository->find($courseId);
        
        if (!$course) {
            return new JsonResponse(['error' => 'Conversation non trouvée'], 404);
        }
        
        // Créer le message
        $message = new Message();
        $message->setContenu($content);
        $message->setDateEnvoi(new \DateTime());
        $message->setExpediteur($user);
        $message->setCourse($course);
        
        $this->em->persist($message);
        $this->em->flush();
        
        return new JsonResponse([
            'id' => 'msg-' . $message->getId(),
            'sender' => [
                'id' => $user->getId(),
                'name' => $user->getPrenom() . ' ' . $user->getNom(),
                'avatarImageUrl' => '/img/avatars/thumb-' . ($user->getId() % 15 + 1) . '.jpg',
            ],
            'content' => $message->getContenu(),
            'timestamp' => $message->getDateEnvoi()->getTimestamp(),
            'type' => 'regular',
            'isMyMessage' => true,
        ], 201);
    }

    /**
     * Liste des contacts (tous les chauffeurs)
     */
    #[Route('/contacts', name: 'api_contacts_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getChatContacts(): JsonResponse
    {
        $user = $this->getChauffeur();
        $chauffeurs = $this->chauffeurRepository->findAll();
        
        $contacts = [];
        foreach ($chauffeurs as $chauffeur) {
            // Exclure l'utilisateur courant
            if ($chauffeur->getId() === $user->getId()) {
                continue;
            }
            
            $contacts[] = [
                'id' => $chauffeur->getId(),
                'name' => $chauffeur->getPrenom() . ' ' . $chauffeur->getNom(),
                'email' => $chauffeur->getEmail(),
                'img' => '/img/avatars/thumb-' . ($chauffeur->getId() % 15 + 1) . '.jpg',
            ];
        }
        
        return new JsonResponse($contacts);
    }

    /**
     * Détails d'un contact
     */
    #[Route('/contacts/{id}', name: 'api_contact_details', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getContactDetails(int $id): JsonResponse
    {
        $chauffeur = $this->chauffeurRepository->find($id);
        
        if (!$chauffeur) {
            return new JsonResponse(['error' => 'Contact non trouvé'], 404);
        }
        
        return new JsonResponse([
            'id' => $chauffeur->getId(),
            'name' => $chauffeur->getPrenom() . ' ' . $chauffeur->getNom(),
            'email' => $chauffeur->getEmail(),
            'phone' => $chauffeur->getTel(),
            'img' => '/img/avatars/thumb-' . ($chauffeur->getId() % 15 + 1) . '.jpg',
            'personalInfo' => [
                'company' => $chauffeur->getNomSociete(),
                'siret' => $chauffeur->getSiret(),
                'vehicle' => $chauffeur->getVehicle(),
            ],
        ]);
    }

    /**
     * Démarrer une nouvelle conversation (pour une course)
     */
    #[Route('/chats/new', name: 'api_chat_new', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function startNewChat(Request $request): JsonResponse
    {
        $user = $this->getChauffeur();
        $data = json_decode($request->getContent(), true);
        
        $courseId = $data['courseId'] ?? null;
        $recipientId = $data['recipientId'] ?? null;
        
        if (!$courseId) {
            return new JsonResponse(['error' => 'courseId requis'], 400);
        }
        
        $course = $this->courseRepository->find($courseId);
        if (!$course) {
            return new JsonResponse(['error' => 'Course non trouvée'], 404);
        }
        
        // Déterminer le destinataire
        $otherChauffeur = null;
        if ($course->getChauffeurVendeur() && $course->getChauffeurVendeur()->getId() !== $user->getId()) {
            $otherChauffeur = $course->getChauffeurVendeur();
        } elseif ($course->getChauffeurAccepteur() && $course->getChauffeurAccepteur()->getId() !== $user->getId()) {
            $otherChauffeur = $course->getChauffeurAccepteur();
        }
        
        if (!$otherChauffeur) {
            return new JsonResponse(['error' => 'Aucun destinataire trouvé'], 400);
        }
        
        return new JsonResponse([
            'id' => 'chat-' . $course->getId(),
            'courseId' => $course->getId(),
            'chatType' => 'personal',
            'user' => [
                'id' => $otherChauffeur->getId(),
                'name' => $otherChauffeur->getPrenom() . ' ' . $otherChauffeur->getNom(),
                'avatarImageUrl' => '/img/avatars/thumb-' . ($otherChauffeur->getId() % 15 + 1) . '.jpg',
            ],
        ], 201);
    }

    // =====================================
    // ROUTES POUR LES CONVERSATIONS (RIDES)
    // =====================================

    /**
     * Liste des conversations liées aux Rides
     */
    #[Route('/conversations', name: 'api_ride_conversations_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getRideConversations(): JsonResponse
    {
        $user = $this->getChauffeur();
        $conversations = $this->conversationRepository->findByUser($user);
        
        $data = array_map(function(Conversation $conv) use ($user) {
            $other = $conv->getOtherParticipant($user);
            $lastMessage = $conv->getMessages()->last();
            
            // Compter les messages non lus
            $unreadCount = 0;
            foreach ($conv->getMessages() as $msg) {
                if ($msg->getExpediteur() !== $user && !$msg->isRead()) {
                    $unreadCount++;
                }
            }
            
            return [
                'id' => $conv->getId(),
                'ride' => [
                    'id' => $conv->getRide()->getId(),
                    'depart' => $conv->getRide()->getDepart(),
                    'destination' => $conv->getRide()->getDestination(),
                    'date' => $conv->getRide()->getDate()?->format('Y-m-d'),
                ],
                'participant' => [
                    'id' => $other?->getId(),
                    'name' => $other ? $other->getPrenom() . ' ' . $other->getNom() : 'Inconnu',
                ],
                'lastMessage' => $lastMessage ? [
                    'content' => $lastMessage->getContenu(),
                    'createdAt' => $lastMessage->getDateEnvoi()?->format('c'),
                    'isMe' => $lastMessage->getExpediteur() === $user,
                ] : null,
                'unreadCount' => $unreadCount,
                'createdAt' => $conv->getCreatedAt()?->format('c'),
            ];
        }, $conversations);
        
        return new JsonResponse($data);
    }

    /**
     * Récupérer ou créer une conversation pour une course (Ride)
     */
    #[Route('/conversations/ride/{rideId}', name: 'api_conversation_ride', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getOrCreateConversationForRide(int $rideId): JsonResponse
    {
        $user = $this->getChauffeur();
        $ride = $this->rideRepository->find($rideId);
        
        if (!$ride) {
            return new JsonResponse(['error' => 'Course non trouvée'], 404);
        }
        
        // Vérifier que l'utilisateur fait partie de la course
        $isOwner = $ride->getChauffeur() === $user;
        $isAcceptor = $ride->getChauffeurAccepteur() === $user;
        
        if (!$isOwner && !$isAcceptor) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }
        
        // La conversation ne peut exister que si la course est acceptée
        if (!$ride->getChauffeurAccepteur()) {
            return new JsonResponse(['error' => 'Course non acceptée'], 400);
        }
        
        // Chercher une conversation existante
        $conversation = $this->conversationRepository->findByRide($ride);
        
        // Créer si elle n'existe pas
        if (!$conversation) {
            $conversation = new Conversation();
            $conversation->setChauffeur1($ride->getChauffeur());
            $conversation->setChauffeur2($ride->getChauffeurAccepteur());
            $conversation->setRide($ride);
            
            $this->em->persist($conversation);
            $this->em->flush();
        }
        
        $other = $conversation->getOtherParticipant($user);
        
        return new JsonResponse([
            'id' => $conversation->getId(),
            'ride' => [
                'id' => $ride->getId(),
                'depart' => $ride->getDepart(),
                'destination' => $ride->getDestination(),
            ],
            'participant' => [
                'id' => $other?->getId(),
                'name' => $other ? $other->getPrenom() . ' ' . $other->getNom() : 'Inconnu',
            ],
        ]);
    }

    /**
     * Récupérer ou créer une conversation directe avec un utilisateur
     */
    #[Route('/conversations/user/{userId}', name: 'api_conversation_user', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function getOrCreateConversationWithUser(int $userId): JsonResponse
    {
        $user = $this->getChauffeur();
        
        // Vérifier qu'on ne discute pas avec soi-même
        if ($user->getId() === $userId) {
            return new JsonResponse(['error' => 'Impossible de discuter avec vous-même'], 400);
        }
        
        // Trouver l'autre utilisateur
        $otherUser = $this->chauffeurRepository->find($userId);
        if (!$otherUser) {
            return new JsonResponse(['error' => 'Utilisateur non trouvé'], 404);
        }
        
        // Chercher une conversation existante entre ces deux utilisateurs
        $conversation = $this->conversationRepository->findDirectConversationBetween($user, $otherUser);
        
        // Créer si elle n'existe pas
        if (!$conversation) {
            $conversation = new Conversation();
            $conversation->setChauffeur1($user);
            $conversation->setChauffeur2($otherUser);
            // Pas de ride associé - c'est une conversation directe
            
            $this->em->persist($conversation);
            $this->em->flush();
        }
        
        return new JsonResponse([
            'id' => $conversation->getId(),
            'participant' => [
                'id' => $otherUser->getId(),
                'name' => $otherUser->getPrenom() . ' ' . $otherUser->getNom(),
                'avatar' => '/img/avatars/thumb-' . ($otherUser->getId() % 15 + 1) . '.jpg',
            ],
            'createdAt' => $conversation->getCreatedAt()?->format('c'),
        ], 201);
    }

    /**
     * Récupérer les messages d'une conversation
     */
    #[Route('/conversations/{id<\d+>}', name: 'api_conversation_messages', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getConversationMessages(Conversation $conversation): JsonResponse
    {
        $user = $this->getChauffeur();
        
        if (!$conversation->hasParticipant($user)) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }
        
        // Marquer les messages comme lus
        foreach ($conversation->getMessages() as $message) {
            if ($message->getExpediteur() !== $user && !$message->isRead()) {
                $message->setIsRead(true);
            }
        }
        $this->em->flush();
        
        $other = $conversation->getOtherParticipant($user);
        
        $messages = array_map(function(Message $msg) use ($user) {
            return [
                'id' => $msg->getId(),
                'content' => $msg->getContenu(),
                'createdAt' => $msg->getDateEnvoi()?->format('c'),
                'isMe' => $msg->getExpediteur() === $user,
                'sender' => [
                    'id' => $msg->getExpediteur()?->getId(),
                    'name' => $msg->getExpediteur() ? $msg->getExpediteur()->getPrenom() . ' ' . $msg->getExpediteur()->getNom() : 'Inconnu',
                ],
            ];
        }, $conversation->getMessages()->toArray());
        
        return new JsonResponse([
            'id' => $conversation->getId(),
            'ride' => [
                'id' => $conversation->getRide()->getId(),
                'depart' => $conversation->getRide()->getDepart(),
                'destination' => $conversation->getRide()->getDestination(),
                'date' => $conversation->getRide()->getDate()?->format('Y-m-d'),
                'status' => $conversation->getRide()->getStatus(),
            ],
            'participant' => [
                'id' => $other?->getId(),
                'name' => $other ? $other->getPrenom() . ' ' . $other->getNom() : 'Inconnu',
            ],
            'messages' => $messages,
        ]);
    }

    /**
     * Envoyer un message dans une conversation
     */
    #[Route('/conversations/{id<\d+>}/messages', name: 'api_conversation_send_message', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function sendConversationMessage(Conversation $conversation, Request $request): JsonResponse
    {
        $user = $this->getChauffeur();
        
        if (!$conversation->hasParticipant($user)) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }
        
        $data = json_decode($request->getContent(), true);
        $content = $data['content'] ?? '';
        
        if (empty(trim($content))) {
            return new JsonResponse(['error' => 'Message vide'], 400);
        }
        
        $message = new Message();
        $message->setConversation($conversation);
        $message->setExpediteur($user);
        $message->setContenu($content);
        $message->setDateEnvoi(new \DateTime());
        
        $conversation->setLastMessageAt(new \DateTime());
        
        $this->em->persist($message);
        
        // Créer une notification pour l'autre participant
        $other = $conversation->getOtherParticipant($user);
        if ($other) {
            $notification = new Notification();
            $notification->setRecipient($other);
            $notification->setSender($user);
            $notification->setType(Notification::TYPE_NEW_MESSAGE);
            $notification->setTitle('Nouveau message');
            $notification->setMessage($user->getPrenom() . ' vous a envoyé un message');
            $notification->setRide($conversation->getRide());
            $this->em->persist($notification);

            // 🔥 Publier en temps réel via Mercure
            $this->mercureService->publishChatMessage(
                $conversation->getId(),
                [
                    'id' => 'msg-' . $message->getId(),
                    'sender' => [
                        'id' => $user->getId(),
                        'name' => $user->getPrenom() . ' ' . $user->getNom(),
                        'avatarImageUrl' => '/img/avatars/thumb-' . ($user->getId() % 15 + 1) . '.jpg',
                    ],
                    'content' => $message->getContenu(),
                    'timestamp' => $message->getDateEnvoi()->getTimestamp(),
                    'type' => 'regular',
                ],
                [$user->getId(), $other->getId()]
            );

            // 📱 Envoyer une push notification
            $this->pushNotificationService->notifyNewMessage(
                $other,
                $user,
                $message->getContenu(),
                $conversation->getId()
            );
        }
        
        $this->em->flush();
        
        return new JsonResponse([
            'success' => true,
            'message' => [
                'id' => 'msg-' . $message->getId(),
                'sender' => [
                    'id' => $user->getId(),
                    'name' => $user->getPrenom() . ' ' . $user->getNom(),
                    'avatarImageUrl' => '/img/avatars/thumb-' . ($user->getId() % 15 + 1) . '.jpg',
                ],
                'content' => $message->getContenu(),
                'timestamp' => $message->getDateEnvoi()->getTimestamp(),
                'createdAt' => $message->getDateEnvoi()?->format('c'),
                'isMe' => true,
            ],
        ]);
    }
}
