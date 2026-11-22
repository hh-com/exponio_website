<?php

namespace App\Command;

use App\Duplicate\DuplicateOutcome;
use App\Recognition\RecognitionService;
use App\Repository\ItemRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:recognize', description: 'Erkennt ein einzelnes Objekt (erneut) per KI.')]
final class RecognizeCommand extends Command
{
    public function __construct(
        private readonly ItemRepository $items,
        private readonly RecognitionService $recognition,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Objekt-ID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $id = (int) $input->getArgument('id');
        $item = $this->items->find($id);
        if (null === $item) {
            $io->error('Objekt nicht gefunden.');

            return Command::FAILURE;
        }
        if (!$this->recognition->recognize($item)) {
            $io->error((string) $item->getAiError());

            return Command::FAILURE;
        }
        $io->success(sprintf('#%d: %s (Konfidenz %.0f %%, Status: %s)', $id, $item->getTitle(), 100 * (float) $item->getAiConfidence(), $item->getStatus()->value));
        $duplicate = $this->recognition->lastDuplicate();
        if (DuplicateOutcome::SKIPPED !== $duplicate->result) {
            $io->note('Duplikatprüfung: '.$duplicate->describe());
        }
        $io->writeln((string) json_encode($item->getAiResult(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
