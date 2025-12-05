<?php

namespace App\Command;

use App\Repository\RideRepository;
use App\Service\GeocodingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:recalculate-distances',
    description: 'Recalcule les distances de toutes les courses'
)]
class RecalculateDistancesCommand extends Command
{
    public function __construct(
        private RideRepository $rideRepository,
        private GeocodingService $geocodingService,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $rides = $this->rideRepository->findAll();
        $updated = 0;
        
        $io->progressStart(count($rides));
        
        foreach ($rides as $ride) {
            $coords = $this->geocodingService->geocodeRide($ride->getDepart(), $ride->getDestination());
            
            // Mettre à jour les coordonnées si nécessaires
            if ($coords['departure']) {
                $ride->setDepartLat($coords['departure']['lat']);
                $ride->setDepartLng($coords['departure']['lng']);
            }
            
            if ($coords['arrival']) {
                $ride->setDestinationLat($coords['arrival']['lat']);
                $ride->setDestinationLng($coords['arrival']['lng']);
            }
            
            // Mettre à jour la distance
            if (isset($coords['distance']) && $coords['distance'] > 0) {
                $ride->setDistance($coords['distance']);
                $updated++;
            }
            
            $io->progressAdvance();
            
            // Pause pour respecter les limites de l'API Nominatim
            usleep(1100000); // 1.1 seconde entre chaque requête
        }
        
        $this->entityManager->flush();
        
        $io->progressFinish();
        $io->success("$updated courses mises à jour avec leur distance.");
        
        return Command::SUCCESS;
    }
}
