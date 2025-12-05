<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class PricingController extends BaseApiController
{
    /**
     * Récupérer les plans tarifaires WayZo
     */
    #[Route('/api/pricing', name: 'api_pricing_plans', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getPricingPlans(): JsonResponse
    {
        // Plans tarifaires pour chauffeurs VTC
        $plans = [
            [
                'id' => 'starter',
                'title' => 'Starter',
                'price' => [
                    'monthly' => 0,
                    'annually' => 0,
                ],
                'isPopular' => false,
                'features' => [
                    ['text' => 'Jusqu\'à 10 courses/mois', 'invalid' => false],
                    ['text' => 'Commission 15%', 'invalid' => false],
                    ['text' => 'Marketplace publique', 'invalid' => false],
                    ['text' => 'Support email', 'invalid' => false],
                    ['text' => 'Groupes de confiance', 'invalid' => true],
                    ['text' => 'Suivi GPS temps réel', 'invalid' => true],
                    ['text' => 'Statistiques avancées', 'invalid' => true],
                ],
            ],
            [
                'id' => 'pro',
                'title' => 'Professionnel',
                'price' => [
                    'monthly' => 29,
                    'annually' => 290,
                ],
                'isPopular' => true,
                'features' => [
                    ['text' => 'Courses illimitées', 'invalid' => false],
                    ['text' => 'Commission 10%', 'invalid' => false],
                    ['text' => 'Marketplace publique', 'invalid' => false],
                    ['text' => 'Support prioritaire', 'invalid' => false],
                    ['text' => 'Jusqu\'à 3 groupes de confiance', 'invalid' => false],
                    ['text' => 'Suivi GPS temps réel', 'invalid' => false],
                    ['text' => 'Statistiques avancées', 'invalid' => true],
                ],
            ],
            [
                'id' => 'enterprise',
                'title' => 'Entreprise',
                'price' => [
                    'monthly' => 79,
                    'annually' => 790,
                ],
                'isPopular' => false,
                'features' => [
                    ['text' => 'Courses illimitées', 'invalid' => false],
                    ['text' => 'Commission 5%', 'invalid' => false],
                    ['text' => 'Marketplace publique', 'invalid' => false],
                    ['text' => 'Support dédié 24/7', 'invalid' => false],
                    ['text' => 'Groupes illimités', 'invalid' => false],
                    ['text' => 'Suivi GPS temps réel', 'invalid' => false],
                    ['text' => 'Statistiques avancées', 'invalid' => false],
                    ['text' => 'API personnalisée', 'invalid' => false],
                    ['text' => 'Multi-chauffeurs', 'invalid' => false],
                ],
            ],
        ];

        return new JsonResponse($plans);
    }

    /**
     * Récupérer le plan actuel de l'utilisateur
     */
    #[Route('/api/pricing/current', name: 'api_pricing_current', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getCurrentPlan(): JsonResponse
    {
        $user = $this->getChauffeur();
        
        // Pour l'instant, tous les utilisateurs sont sur le plan Starter
        return new JsonResponse([
            'plan' => 'starter',
            'validUntil' => null,
            'features' => [
                'maxRides' => 10,
                'commission' => 15,
                'maxGroups' => 0,
                'gpsTracking' => false,
                'advancedStats' => false,
            ],
        ]);
    }
}
