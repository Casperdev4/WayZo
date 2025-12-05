<?php

namespace App\Command;

use App\Service\EscrowService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:escrow:process-auto-validations',
    description: 'Traite les validations automatiques des paiements escrow (24h écoulées)',
)]
class EscrowProcessAutoValidationsCommand extends Command
{
    public function __construct(
        private EscrowService $escrowService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Traitement des validations automatiques Escrow');

        try {
            $count = $this->escrowService->processAutoValidations();

            if ($count > 0) {
                $io->success("$count paiement(s) libéré(s) automatiquement.");
            } else {
                $io->info('Aucun paiement en attente de validation automatique.');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Erreur : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
