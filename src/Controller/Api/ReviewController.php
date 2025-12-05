<?php

namespace App\Controller\Api;

use App\Entity\Avis;
use App\Repository\AvisRepository;
use App\Repository\RideRepository;
use App\Repository\ChauffeurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/reviews')]
class ReviewController extends AbstractController
{
    public function __construct(
        private AvisRepository $avisRepository,
        private RideRepository $rideRepository,
        private ChauffeurRepository $chauffeurRepository,
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Créer un avis pour une course (Ride)
     */
    #[Route('/ride/{rideId}', name: 'api_review_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function createReview(int $rideId, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $ride = $this->rideRepository->find($rideId);
        
        if (!$ride) {
            return $this->json(['error' => 'Course non trouvée'], 404);
        }
        
        // Vérifier que la course est terminée
        if ($ride->getStatus() !== 'terminée') {
            return $this->json(['error' => 'Vous ne pouvez noter que les courses terminées'], 400);
        }
        
        // Vérifier que l'utilisateur fait partie de la course
        $isOwner = $ride->getChauffeur() === $user;
        $isAcceptor = $ride->getChauffeurAccepteur() === $user;
        
        if (!$isOwner && !$isAcceptor) {
            return $this->json(['error' => 'Non autorisé'], 403);
        }
        
        // Vérifier qu'un avis n'existe pas déjà
        if ($this->avisRepository->existsForRideAndAuteur($rideId, $user->getId())) {
            return $this->json(['error' => 'Vous avez déjà noté cette course'], 400);
        }
        
        $data = json_decode($request->getContent(), true);
        $note = $data['note'] ?? null;
        $commentaire = $data['commentaire'] ?? null;
        
        if (!$note || $note < 1 || $note > 5) {
            return $this->json(['error' => 'La note doit être entre 1 et 5'], 400);
        }
        
        // Déterminer le chauffeur à noter
        $chauffeurNote = $isOwner ? $ride->getChauffeurAccepteur() : $ride->getChauffeur();
        
        if (!$chauffeurNote) {
            return $this->json(['error' => 'Aucun chauffeur à noter'], 400);
        }
        
        $avis = new Avis();
        $avis->setNote((int) $note);
        $avis->setCommentaire($commentaire);
        $avis->setAuteur($user);
        $avis->setChauffeurNote($chauffeurNote);
        $avis->setRide($ride);
        
        $this->entityManager->persist($avis);
        $this->entityManager->flush();
        
        return $this->json([
            'success' => true,
            'message' => 'Avis enregistré avec succès',
            'review' => $avis->toArray()
        ], 201);
    }

    /**
     * Vérifier si l'utilisateur peut noter une course
     */
    #[Route('/ride/{rideId}/can-review', name: 'api_review_can_review', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function canReview(int $rideId): JsonResponse
    {
        $user = $this->getUser();
        $ride = $this->rideRepository->find($rideId);
        
        if (!$ride) {
            return $this->json(['canReview' => false, 'reason' => 'Course non trouvée']);
        }
        
        // Vérifier que la course est terminée
        if ($ride->getStatus() !== 'terminée') {
            return $this->json(['canReview' => false, 'reason' => 'Course non terminée']);
        }
        
        // Vérifier que l'utilisateur fait partie de la course
        $isOwner = $ride->getChauffeur() === $user;
        $isAcceptor = $ride->getChauffeurAccepteur() === $user;
        
        if (!$isOwner && !$isAcceptor) {
            return $this->json(['canReview' => false, 'reason' => 'Non participant']);
        }
        
        // Vérifier qu'un avis n'existe pas déjà
        if ($this->avisRepository->existsForRideAndAuteur($rideId, $user->getId())) {
            return $this->json(['canReview' => false, 'reason' => 'Déjà noté', 'alreadyReviewed' => true]);
        }
        
        // Déterminer le chauffeur à noter
        $chauffeurNote = $isOwner ? $ride->getChauffeurAccepteur() : $ride->getChauffeur();
        
        return $this->json([
            'canReview' => true,
            'chauffeurToReview' => [
                'id' => $chauffeurNote?->getId(),
                'name' => $chauffeurNote ? $chauffeurNote->getPrenom() . ' ' . $chauffeurNote->getNom() : null,
            ]
        ]);
    }

    /**
     * Récupérer les avis d'un chauffeur
     */
    #[Route('/chauffeur/{chauffeurId}', name: 'api_reviews_chauffeur', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getChauffeurReviews(int $chauffeurId, Request $request): JsonResponse
    {
        $chauffeur = $this->chauffeurRepository->find($chauffeurId);
        
        if (!$chauffeur) {
            return $this->json(['error' => 'Chauffeur non trouvé'], 404);
        }
        
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(50, max(1, (int) $request->query->get('limit', 10)));
        
        $result = $this->avisRepository->findByChaufffeurPaginated($chauffeur, $page, $limit);
        $distribution = $this->avisRepository->getRatingDistribution($chauffeur);
        $avgRating = $this->avisRepository->getAverageRating($chauffeur);
        
        return $this->json([
            'chauffeur' => [
                'id' => $chauffeur->getId(),
                'name' => $chauffeur->getPrenom() . ' ' . $chauffeur->getNom(),
                'avgRating' => $avgRating,
                'totalReviews' => $result['total'],
            ],
            'distribution' => $distribution,
            'reviews' => array_map(fn(Avis $a) => $a->toArray(), $result['avis']),
            'pagination' => [
                'page' => $result['page'],
                'totalPages' => $result['totalPages'],
                'total' => $result['total'],
            ]
        ]);
    }

    /**
     * Récupérer mes avis (donnés et reçus)
     */
    #[Route('/my-reviews', name: 'api_reviews_my', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getMyReviews(): JsonResponse
    {
        $user = $this->getUser();
        
        // Avis reçus
        $avisRecus = $this->avisRepository->findBy(
            ['chauffeurNote' => $user],
            ['createdAt' => 'DESC'],
            20
        );
        
        // Avis donnés
        $avisDonnes = $this->avisRepository->findBy(
            ['auteur' => $user],
            ['createdAt' => 'DESC'],
            20
        );
        
        $avgRating = $this->avisRepository->getAverageRating($user);
        $distribution = $this->avisRepository->getRatingDistribution($user);
        
        return $this->json([
            'stats' => [
                'avgRating' => $avgRating,
                'totalReceived' => count($avisRecus),
                'totalGiven' => count($avisDonnes),
                'distribution' => $distribution,
            ],
            'received' => array_map(fn(Avis $a) => $a->toArray(), $avisRecus),
            'given' => array_map(fn(Avis $a) => $a->toArray(), $avisDonnes),
        ]);
    }
}
