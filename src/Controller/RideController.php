<?php

namespace App\Controller;

use App\Entity\Ride;
use App\Entity\Chauffeur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\SecurityBundle\Security;
use App\Repository\RideRepository;

class RideController extends AbstractController
{
    #[Route('/proposer-course', name: 'add_ride')]
    public function addRide(Request $request, EntityManagerInterface $em, Security $security): Response
    {
        if ($request->isMethod('POST')) {
            $chauffeur = $security->getUser();

            if (!$chauffeur instanceof Chauffeur) {
                $this->addFlash('error', 'Vous devez être connecté en tant que chauffeur.');
                return $this->redirectToRoute('app_login');
            }

            $ride = new Ride();
            $ride->setClientName($request->request->get('clientName'));
            $ride->setClientContact($request->request->get('clientContact'));
            $ride->setDepart($request->request->get('origin'));
            $ride->setDestination($request->request->get('destination'));
            $ride->setDate(new \DateTime($request->request->get('date')));
            $ride->setTime(new \DateTime($request->request->get('time')));
            $ride->setPassengers((int)$request->request->get('passengers'));
            $ride->setLuggage((int)$request->request->get('luggage'));
            $ride->setVehicle($request->request->get('vehicle'));
            $ride->setBoosterSeat((int)$request->request->get('boosterSeat'));
            $ride->setBabySeat((int)$request->request->get('babySeat'));
            $ride->setPrice((float)$request->request->get('price'));
            $ride->setComment($request->request->get('comment'));
            $ride->setStatus('disponible');
            $ride->setChauffeur($chauffeur);

            $em->persist($ride);
            $em->flush();

            return $this->redirectToRoute('ride_list');
        }

        return $this->render('ride/add_ride.html.twig');
    }

    #[Route('/courses-disponibles', name: 'ride_list')]
    public function rideList(RideRepository $rideRepository): Response
    {
        $rides = $rideRepository->findBy(['status' => 'disponible']);

        return $this->render('ride/ride_list.html.twig', [
            'rides' => $rides,
        ]);
    }

    #[Route('/accepter-course/{id}', name: 'accept_ride', methods: ['POST'])]
    public function acceptRide(int $id, EntityManagerInterface $em): Response
    {
        $ride = $em->getRepository(Ride::class)->find($id);

        if (!$ride || $ride->getStatus() !== 'disponible') {
            $this->addFlash('error', 'Cette course n\'est plus disponible.');
            return $this->redirectToRoute('ride_list');
        }

        $ride->setStatus('acceptée');
        $ride->setChauffeurAccepteur($this->getUser());

        $em->flush();

        $this->addFlash('success', 'Vous avez accepté la course.');
        return $this->redirectToRoute('in_progress');
    }

    #[Route('/courses-en-cours', name: 'in_progress')]
    public function inProgress(Security $security, RideRepository $rideRepository): Response
    {
        $chauffeur = $security->getUser();

        $rides = $rideRepository->createQueryBuilder('r')
            ->where('r.chauffeurAccepteur = :chauffeur')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('chauffeur', $chauffeur)
            ->setParameter('statuses', ['acceptée', 'en_cours', 'prise_en_charge'])
            ->orderBy('r.date', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('ride/in_progress.html.twig', [
            'rides' => $rides,
        ]);
    }

    #[Route('/ride/update-status/{id}/{status}', name: 'update_ride_status', methods: ['POST'])]
    public function updateRideStatus(int $id, string $status, RideRepository $rideRepository, EntityManagerInterface $em, Security $security): Response
    {
        $chauffeur = $security->getUser();
        $ride = $rideRepository->find($id);

        if (!$ride || $ride->getChauffeurAccepteur() !== $chauffeur) {
            throw $this->createNotFoundException('Course non trouvée ou accès non autorisé.');
        }

        $ride->setStatus($status);

        if ($status === 'client déposé') {
            // Si le chauffeur est différent du vendeur, on marque comme vendue
            if ($ride->getChauffeur() !== null && $ride->getChauffeur() !== $chauffeur) {
                $ride->setStatusVendeur('course vendue');
            }

            // Si le chauffeur est à la fois vendeur et accepteur, on marque aussi comme vendue
            if ($ride->getChauffeur() === $chauffeur && $ride->getChauffeurAccepteur() === $chauffeur) {
                $ride->setStatusVendeur('course vendue');
            }
        }

        $em->flush();

        return $this->redirectToRoute($status === 'client déposé' ? 'ride_history' : 'in_progress');
    }

    #[Route('/historique', name: 'ride_history')]
    public function rideHistory(Security $security, RideRepository $rideRepository): Response
    {
        $chauffeur = $security->getUser();

        // Courses acceptées par le chauffeur (effectuées)
        $coursesAcceptees = $rideRepository->createQueryBuilder('r')
            ->where('r.chauffeurAccepteur = :chauffeur')
            ->andWhere('r.status = :status')
            ->setParameter('chauffeur', $chauffeur)
            ->setParameter('status', 'client déposé')
            ->getQuery()
            ->getResult();

        // Courses vendues par le chauffeur (même si c’est lui qui les a faites)
        $coursesVendues = $rideRepository->createQueryBuilder('r')
            ->where('r.chauffeur = :chauffeur')
            ->andWhere('r.status = :status')
            ->setParameter('chauffeur', $chauffeur)
            ->setParameter('status', 'client déposé')
            ->getQuery()
            ->getResult();

        return $this->render('ride/history.html.twig', [
            'coursesAcceptees' => $coursesAcceptees,
            'coursesVendues' => $coursesVendues,
        ]);
    }

    #[Route('/ride/delete/{id}', name: 'delete_ride', methods: ['POST'])]
    public function deleteRide(int $id, EntityManagerInterface $em, Security $security): Response
    {
        $chauffeur = $security->getUser();
        $ride = $em->getRepository(Ride::class)->find($id);

        if (!$ride || $ride->getChauffeur() !== $chauffeur) {
            throw $this->createNotFoundException('Course non trouvée ou accès non autorisé.');
        }

        $em->remove($ride);
        $em->flush();

        return $this->redirectToRoute('ride_list');
    }
}








