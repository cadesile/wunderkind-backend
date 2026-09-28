<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Competition\ActiveCompetition;
use App\Entity\Competition\CompetitionTemplate;
use App\Enum\Competition\ActiveCompetitionStatus;
use App\Enum\Competition\CompetitionDuration;
use App\Message\ResolveNewCompetitionAudienceForPushMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;

class CompetitionProvisionInstancesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->em->getConnection()->executeStatement(
            'TRUNCATE competition_entrant, active_competition, competition_template CASCADE',
        );
    }

    private function transport(): mixed
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function runCommand(): void
    {
        $application = new Application(self::$kernel);
        $command     = $application->find('app:competition:provision-instances');
        (new CommandTester($command))->execute([]);
    }

    public function testProvisioningANewInstanceDispatchesAudienceResolution(): void
    {
        $template = new CompetitionTemplate('Provision Cup', 'provision-cup-' . uniqid('', true), 4, CompetitionDuration::ONE_DAY);
        $this->em->persist($template);
        $this->em->flush();

        $this->runCommand();

        $instance = $this->em->getRepository(ActiveCompetition::class)->findOneBy(['template' => $template]);
        $this->assertNotNull($instance, 'A new instance should have been provisioned.');
        $this->assertSame(ActiveCompetitionStatus::REGISTERING, $instance->getStatus());

        $messages = array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof ResolveNewCompetitionAudienceForPushMessage));

        $this->assertCount(1, $messages);
        $this->assertSame((string) $instance->getId(), $messages[0]->activeCompetitionId);
    }

    public function testAnAlreadyOpenTemplateIsNotReprovisionedOrRenotified(): void
    {
        $template = new CompetitionTemplate('Already Open Cup', 'already-open-cup-' . uniqid('', true), 4, CompetitionDuration::ONE_DAY);
        $this->em->persist($template);
        $existing = new ActiveCompetition($template);
        $this->em->persist($existing);
        $this->em->flush();

        $this->runCommand();

        $instances = $this->em->getRepository(ActiveCompetition::class)->findBy(['template' => $template]);
        $this->assertCount(1, $instances, 'No second REGISTERING instance should be created for the same template.');

        $messages = array_values(array_filter(array_map(
            static fn (Envelope $e) => $e->getMessage(),
            $this->transport()->getSent(),
        ), static fn ($m) => $m instanceof ResolveNewCompetitionAudienceForPushMessage));
        $this->assertSame([], $messages, 'No audience resolution should fire for a template that was already open.');
    }
}
