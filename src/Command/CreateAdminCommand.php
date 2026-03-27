<?php

namespace App\Command;

use App\Entity\Admin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin:create', description: 'Create a backend admin user')]
class CreateAdminCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email address')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password')
            ->addOption('name', null, InputOption::VALUE_OPTIONAL, 'Display name')
            ->addOption('department', null, InputOption::VALUE_OPTIONAL, 'Department');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $existing = $this->em->getRepository(Admin::class)->findOneBy(['email' => $email]);

        if ($existing !== null) {
            $io->error(sprintf('Admin with email "%s" already exists.', $email));
            return Command::FAILURE;
        }

        $admin = new Admin($email);
        $admin->setPassword($this->hasher->hashPassword($admin, $input->getArgument('password')));
        $admin->setName($input->getOption('name'));
        $admin->setDepartment($input->getOption('department'));

        $this->em->persist($admin);
        $this->em->flush();

        $io->success(sprintf('Admin "%s" created successfully.', $email));
        return Command::SUCCESS;
    }
}
