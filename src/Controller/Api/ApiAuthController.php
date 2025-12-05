<?php

namespace App\Controller\Api;

use App\Entity\Chauffeur;
use App\Repository\ChauffeurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class ApiAuthController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private ChauffeurRepository $chauffeurRepository
    ) {}

    /**
     * Inscription API
     */
    #[Route('/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request, ValidatorInterface $validator): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validation des champs requis
        $requiredFields = ['email', 'password', 'firstName', 'lastName', 'phone'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                return $this->json(['error' => "Le champ $field est requis"], 400);
            }
        }

        // Vérifier si l'email existe déjà
        $existingUser = $this->chauffeurRepository->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return $this->json(['error' => 'Cet email est déjà utilisé'], 400);
        }

        // Créer le chauffeur
        $chauffeur = new Chauffeur();
        $chauffeur->setEmail($data['email']);
        $chauffeur->setPrenom($data['firstName']);
        $chauffeur->setNom($data['lastName']);
        $chauffeur->setTel($data['phone']);
        $chauffeur->setSiret($data['siret'] ?? '');
        $chauffeur->setAdresse($data['address'] ?? '');
        $chauffeur->setVille($data['city'] ?? '');
        $chauffeur->setCodePostal($data['postalCode'] ?? '');
        $chauffeur->setVehicle($data['vehicle'] ?? '');
        $chauffeur->setNomSociete($data['companyName'] ?? '');
        $chauffeur->setRoles(['ROLE_CHAUFFEUR']);
        $chauffeur->setStatus('pending'); // En attente de validation

        if (!empty($data['dob'])) {
            try {
                $chauffeur->setDateNaissance(new \DateTimeImmutable($data['dob']));
            } catch (\Exception $e) {
                return $this->json(['error' => 'Date de naissance invalide'], 400);
            }
        }

        // Hash du mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword($chauffeur, $data['password']);
        $chauffeur->setPassword($hashedPassword);

        // Validation de l'entité
        $errors = $validator->validate($chauffeur);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return $this->json(['error' => implode(', ', $errorMessages)], 400);
        }

        $this->entityManager->persist($chauffeur);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Inscription réussie. Votre compte est en attente de validation.',
            'user' => [
                'id' => $chauffeur->getId(),
                'email' => $chauffeur->getEmail(),
                'fullName' => $chauffeur->getFullName(),
            ]
        ], 201);
    }

    /**
     * Déconnexion API (invalide le token côté client)
     */
    #[Route('/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        // Note: Le JWT est stateless, la déconnexion se fait côté client
        // en supprimant le token. Cette route permet de standardiser l'API.
        return $this->json([
            'success' => true,
            'message' => 'Déconnexion réussie'
        ]);
    }

    /**
     * Mot de passe oublié - Envoie un email avec token de réinitialisation
     */
    #[Route('/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request, MailerInterface $mailer): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['email'])) {
            return $this->json(['error' => 'Email requis'], 400);
        }

        $chauffeur = $this->chauffeurRepository->findOneBy(['email' => $data['email']]);

        // Pour des raisons de sécurité, on renvoie toujours un succès
        // même si l'email n'existe pas (éviter l'énumération des emails)
        if ($chauffeur) {
            // Générer un token de réinitialisation
            $resetToken = bin2hex(random_bytes(32));
            $chauffeur->setResetToken($resetToken);
            $chauffeur->setResetTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
            $this->entityManager->flush();

            // Envoyer l'email
            try {
                $resetUrl = $this->getParameter('app.frontend_url') . '/reset-password?token=' . $resetToken;
                
                $email = (new Email())
                    ->from('noreply@wayzo.fr')
                    ->to($chauffeur->getEmail())
                    ->subject('WayZo - Réinitialisation de votre mot de passe')
                    ->html("
                        <h2>Réinitialisation de mot de passe</h2>
                        <p>Bonjour {$chauffeur->getPrenom()},</p>
                        <p>Vous avez demandé à réinitialiser votre mot de passe.</p>
                        <p><a href='{$resetUrl}'>Cliquez ici pour réinitialiser votre mot de passe</a></p>
                        <p>Ce lien expire dans 1 heure.</p>
                        <p>Si vous n'avez pas fait cette demande, ignorez cet email.</p>
                        <p>L'équipe WayZo</p>
                    ");

                $mailer->send($email);
            } catch (\Exception $e) {
                // Log l'erreur mais ne pas la révéler à l'utilisateur
            }
        }

        return $this->json([
            'success' => true,
            'message' => 'Si cet email existe, vous recevrez un lien de réinitialisation.'
        ]);
    }

    /**
     * Réinitialisation du mot de passe avec le token
     */
    #[Route('/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (empty($data['token']) || empty($data['password'])) {
            return $this->json(['error' => 'Token et nouveau mot de passe requis'], 400);
        }

        if (strlen($data['password']) < 8) {
            return $this->json(['error' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
        }

        $chauffeur = $this->chauffeurRepository->findOneBy(['resetToken' => $data['token']]);

        if (!$chauffeur) {
            return $this->json(['error' => 'Token invalide ou expiré'], 400);
        }

        // Vérifier l'expiration
        if ($chauffeur->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            return $this->json(['error' => 'Token expiré. Veuillez refaire une demande.'], 400);
        }

        // Mettre à jour le mot de passe
        $hashedPassword = $this->passwordHasher->hashPassword($chauffeur, $data['password']);
        $chauffeur->setPassword($hashedPassword);
        $chauffeur->setResetToken(null);
        $chauffeur->setResetTokenExpiresAt(null);

        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé avec succès. Vous pouvez maintenant vous connecter.'
        ]);
    }

    /**
     * Récupérer les informations de l'utilisateur connecté
     */
    #[Route('/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof Chauffeur) {
            return $this->json(['error' => 'Non authentifié'], 401);
        }

        return $this->json([
            'success' => true,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'firstName' => $user->getPrenom(),
                'lastName' => $user->getNom(),
                'fullName' => $user->getFullName(),
                'phone' => $user->getTel(),
                'roles' => $user->getRoles(),
                'status' => $user->getStatus(),
                'stripeAccountId' => $user->getStripeAccountId(),
                'stripeAccountComplete' => $user->isStripeAccountComplete(),
            ]
        ]);
    }
}
