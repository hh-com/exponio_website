<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:user:create', description: 'Legt einen Backend-Benutzer an oder setzt dessen Passwort neu.')]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-Mail-Adresse (Login)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Passwort (ohne Angabe wird es abgefragt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        if (!filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            $io->error('Ungültige E-Mail-Adresse.');

            return Command::INVALID;
        }

        $password = (string) $input->getOption('password');
        if ('' === $password) {
            $password = (string) $io->askHidden('Passwort (mind. 10 Zeichen)');
            if ($password !== (string) $io->askHidden('Passwort wiederholen')) {
                $io->error('Die Passwörter stimmen nicht überein.');

                return Command::FAILURE;
            }
        }
        if (mb_strlen($password) < 10) {
            $io->error('Das Passwort muss mindestens 10 Zeichen lang sein.');

            return Command::INVALID;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        $isNew = null === $user;
        $user ??= new User($email);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Benutzer %s %s.', $email, $isNew ? 'angelegt' : 'aktualisiert'));

        return Command::SUCCESS;
    }
}
