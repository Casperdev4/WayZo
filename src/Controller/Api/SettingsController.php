<?php

namespace App\Controller\Api;

use App\Repository\ChauffeurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Route('/api/settings')]
class SettingsController extends BaseApiController
{
    public function __construct(
        private ChauffeurRepository $chauffeurRepository,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $passwordHasher
    ) {}

    /**
     * Récupérer le profil de l'utilisateur connecté
     */
    #[Route('/profile', name: 'api_settings_profile', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getProfile(): JsonResponse
    {
        $user = $this->getChauffeur();
        
        return new JsonResponse([
            'id' => $user->getId(),
            'firstName' => $user->getPrenom(),
            'lastName' => $user->getNom(),
            'email' => $user->getEmail(),
            'phoneNumber' => $user->getTel(),
            'dialCode' => '+33',
            'country' => 'FR',
            'company' => $user->getNomSociete(),
            'siret' => $user->getSiret(),
            'kbis' => $user->getKbis(),
            'carteVtc' => $user->getCarteVtc(),
            'permis' => $user->getPermis(),
            'vehicle' => $user->getVehicle(),
            'dateNaissance' => $user->getDateNaissance()?->format('Y-m-d'),
            'img' => '/img/avatars/thumb-' . ($user->getId() % 15 + 1) . '.jpg',
            'address' => '',
            'postcode' => '',
            'city' => '',
        ]);
    }

    /**
     * Mettre à jour le profil
     */
    #[Route('/profile', name: 'api_settings_profile_update', methods: ['PUT', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->getChauffeur();
        $data = json_decode($request->getContent(), true);
        
        // Mise à jour des champs modifiables
        if (isset($data['firstName'])) $user->setPrenom($data['firstName']);
        if (isset($data['lastName'])) $user->setNom($data['lastName']);
        if (isset($data['phoneNumber'])) $user->setTel($data['phoneNumber']);
        if (isset($data['company'])) $user->setNomSociete($data['company']);
        if (isset($data['vehicle'])) $user->setVehicle($data['vehicle']);
        if (isset($data['permis'])) $user->setPermis($data['permis']);
        
        if (!empty($data['dateNaissance'])) {
            $user->setDateNaissance(new \DateTimeImmutable($data['dateNaissance']));
        }
        
        $this->em->flush();
        
        return new JsonResponse([
            'message' => 'Profil mis à jour avec succès',
        ]);
    }

    /**
     * Changer le mot de passe
     */
    #[Route('/password', name: 'api_settings_password', methods: ['PUT', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->getChauffeur();
        $data = json_decode($request->getContent(), true);
        
        $currentPassword = $data['currentPassword'] ?? '';
        $newPassword = $data['newPassword'] ?? '';
        $confirmNewPassword = $data['confirmNewPassword'] ?? '';
        
        // Vérifier le mot de passe actuel
        if (!$this->passwordHasher->isPasswordValid($user, $currentPassword)) {
            return new JsonResponse(['error' => 'Mot de passe actuel incorrect'], 400);
        }
        
        // Vérifier que les nouveaux mots de passe correspondent
        if ($newPassword !== $confirmNewPassword) {
            return new JsonResponse(['error' => 'Les mots de passe ne correspondent pas'], 400);
        }
        
        // Vérifier la complexité du mot de passe
        if (strlen($newPassword) < 6) {
            return new JsonResponse(['error' => 'Le mot de passe doit contenir au moins 6 caractères'], 400);
        }
        
        // Hasher et sauvegarder le nouveau mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);
        
        $this->em->flush();
        
        return new JsonResponse([
            'message' => 'Mot de passe modifié avec succès',
        ]);
    }

    /**
     * Récupérer les préférences de notification
     */
    #[Route('/notifications', name: 'api_settings_notifications', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getNotifications(): JsonResponse
    {
        // TODO: Stocker les préférences en BDD
        return new JsonResponse([
            'newRide' => true,
            'rideAccepted' => true,
            'rideCompleted' => true,
            'newMessage' => true,
            'payment' => true,
            'marketing' => false,
            'newsletter' => false,
        ]);
    }

    /**
     * Mettre à jour les préférences de notification
     */
    #[Route('/notifications', name: 'api_settings_notifications_update', methods: ['PUT', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateNotifications(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // TODO: Sauvegarder en BDD
        
        return new JsonResponse([
            'message' => 'Préférences mises à jour',
        ]);
    }

    /**
     * Récupérer les infos de facturation
     */
    #[Route('/billing', name: 'api_settings_billing', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function getBilling(): JsonResponse
    {
        $user = $this->getChauffeur();
        
        return new JsonResponse([
            'iban' => '', // TODO: Ajouter champ IBAN à l'entité
            'bic' => '',
            'accountHolder' => $user->getPrenom() . ' ' . $user->getNom(),
            'billingAddress' => '',
            'invoices' => [], // TODO: Historique des factures
        ]);
    }

    /**
     * Mettre à jour les infos de facturation
     */
    #[Route('/billing', name: 'api_settings_billing_update', methods: ['PUT', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateBilling(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // TODO: Sauvegarder l'IBAN en BDD
        
        return new JsonResponse([
            'message' => 'Informations de facturation mises à jour',
        ]);
    }

    /**
     * Uploader un document (permis, carte VTC, etc.)
     */
    #[Route('/documents', name: 'api_settings_documents_upload', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function uploadDocument(Request $request): JsonResponse
    {
        $user = $this->getChauffeur();
        $file = $request->files->get('file');
        $type = $request->request->get('type'); // permis, carteVtc, kbis
        
        if (!$file) {
            return new JsonResponse(['error' => 'Aucun fichier envoyé'], 400);
        }
        
        $allowedTypes = ['permis', 'carteVtc', 'kbis'];
        if (!in_array($type, $allowedTypes)) {
            return new JsonResponse(['error' => 'Type de document invalide'], 400);
        }
        
        // Générer un nom unique
        $filename = $type . '-' . $user->getId() . '-' . uniqid() . '.' . $file->guessExtension();
        
        // Déplacer le fichier
        $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/documents';
        $file->move($uploadsDir, $filename);
        
        // Mettre à jour l'entité
        $path = '/uploads/documents/' . $filename;
        match($type) {
            'permis' => $user->setPermis($path),
            'carteVtc' => $user->setCarteVtc($path),
            'kbis' => $user->setKbis($path),
        };
        
        $this->em->flush();
        
        return new JsonResponse([
            'message' => 'Document uploadé avec succès',
            'path' => $path,
        ]);
    }
}

