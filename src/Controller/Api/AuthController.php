<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class AuthController extends AbstractController
{
    #[Route('/api/debug-login', name: 'api_debug_login', methods: ['POST'])]
    public function debugLogin(Request $request): JsonResponse
    {
        // Voir EXACTEMENT ce que symfony reçoit
        $data = $request->toArray();

        return new JsonResponse([
            'received' => $data
        ]);
    }

    // NOTE: La route /api/login est gérée par le json_login dans security.yaml
    // Ne PAS ajouter de contrôleur ici, sinon ça override le comportement JWT
}





