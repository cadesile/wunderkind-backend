<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FacilityTemplate;
use App\Entity\GameEventTemplate;
use App\Entity\PlayerArchetype;
use App\Enum\EventCategory;
use App\Repository\FacilityTemplateRepository;
use App\Repository\GameEventTemplateRepository;
use App\Repository\PlayerArchetypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\UuidV7;

class NarrativeImportExportService
{
    private const EXPORT_VERSION = 1;

    public function __construct(
        private readonly GameEventTemplateRepository $eventTemplateRepository,
        private readonly FacilityTemplateRepository  $facilityTemplateRepository,
        private readonly PlayerArchetypeRepository   $archetypeRepository,
        private readonly EntityManagerInterface      $em,
    ) {}

    // ── Export ────────────────────────────────────────────────────────────────

    public function export(): array
    {
        return [
            'version'           => self::EXPORT_VERSION,
            'exportedAt'        => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'eventTemplates'    => $this->exportEventTemplates(),
            'facilityTemplates' => $this->exportFacilityTemplates(),
            'playerArchetypes'  => $this->exportPlayerArchetypes(),
        ];
    }

    private function exportEventTemplates(): array
    {
        return array_map(fn (GameEventTemplate $t) => [
            'slug'             => $t->getSlug(),
            'category'         => $t->getCategory()->value,
            'weight'           => $t->getWeight(),
            'title'            => $t->getTitle(),
            'bodyTemplate'     => $t->getBodyTemplate(),
            'impacts'          => $t->getImpacts(),
            'firingConditions' => $t->getFiringConditions(),
            'severity'         => $t->getSeverity(),
            'chainedEvents'    => $t->getChainedEvents(),
        ], $this->eventTemplateRepository->findBy([], ['category' => 'ASC', 'slug' => 'ASC']));
    }

    private function exportFacilityTemplates(): array
    {
        return array_map(fn (FacilityTemplate $t) => $t->toArray(),
            $this->facilityTemplateRepository->findBy([], ['sortOrder' => 'ASC', 'slug' => 'ASC']));
    }

    private function exportPlayerArchetypes(): array
    {
        return array_map(fn (PlayerArchetype $a) => [
            'name'         => $a->getName(),
            'description'  => $a->getDescription(),
            'traitMapping' => $a->getTraitMapping(),
        ], $this->archetypeRepository->findBy([], ['name' => 'ASC']));
    }

    // ── Import ────────────────────────────────────────────────────────────────

    public function clearAll(): void
    {
        foreach ($this->eventTemplateRepository->findAll() as $e) {
            $this->em->remove($e);
        }
        foreach ($this->facilityTemplateRepository->findAll() as $f) {
            $this->em->remove($f);
        }
        foreach ($this->archetypeRepository->findAll() as $a) {
            $this->em->remove($a);
        }
        $this->em->flush();
    }

    /**
     * @return array{created: int, updated: int, errors: string[]}
     */
    public function import(array $data): array
    {
        $result = ['created' => 0, 'updated' => 0, 'errors' => []];

        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            $result['errors'][] = 'Unsupported export version — expected version ' . self::EXPORT_VERSION;
            return $result;
        }

        foreach ($data['eventTemplates'] ?? [] as $row) {
            try {
                $this->upsertEventTemplate($row)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'eventTemplate[' . ($row['slug'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        foreach ($data['facilityTemplates'] ?? [] as $row) {
            try {
                $this->upsertFacilityTemplate($row)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'facilityTemplate[' . ($row['slug'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        foreach ($data['playerArchetypes'] ?? [] as $row) {
            try {
                $this->upsertPlayerArchetype($row)
                    ? $result['created']++
                    : $result['updated']++;
            } catch (\Throwable $e) {
                $result['errors'][] = 'playerArchetype[' . ($row['name'] ?? '?') . ']: ' . $e->getMessage();
            }
        }

        $this->em->flush();

        return $result;
    }

    /** @return bool true = created, false = updated */
    private function upsertEventTemplate(array $row): bool
    {
        $slug = trim($row['slug'] ?? '');
        if ($slug === '') throw new \InvalidArgumentException('Missing slug.');

        $template = $this->eventTemplateRepository->findOneBy(['slug' => $slug]);
        $created  = $template === null;

        if ($created) {
            $template = new GameEventTemplate();
            $template->setSlug($slug);
            $this->em->persist($template);
        }

        $category = EventCategory::tryFrom($row['category'] ?? '');
        if ($category === null) throw new \InvalidArgumentException("Unknown category '{$row['category']}'.");

        $template->setCategory($category);
        $template->setWeight((int) ($row['weight'] ?? 1));
        $template->setTitle($row['title'] ?? '');
        $template->setBodyTemplate($row['bodyTemplate'] ?? '');
        $template->setImpacts($row['impacts'] ?? []);
        $template->setFiringConditions($row['firingConditions'] ?? null);
        $template->setSeverity($row['severity'] ?? null);
        $template->setChainedEvents($row['chainedEvents'] ?? null);

        return $created;
    }

    /** @return bool true = created, false = updated */
    private function upsertFacilityTemplate(array $row): bool
    {
        $slug = trim($row['slug'] ?? '');
        if ($slug === '') throw new \InvalidArgumentException('Missing slug.');

        $template = $this->facilityTemplateRepository->findOneBy(['slug' => $slug]);
        $created  = $template === null;

        if ($created) {
            $template = new FacilityTemplate();
            $this->em->persist($template);
        }

        $template->setSlug($slug);
        $template->setLabel($row['label'] ?? $slug);
        $template->setDescription($row['description'] ?? '');
        $template->setCategory($row['category'] ?? 'TRAINING');
        $template->setBaseCost((int) ($row['baseCost'] ?? 0));
        $template->setWeeklyUpkeepBase((int) ($row['weeklyUpkeepBase'] ?? 0));
        $template->setReputationBonus((float) ($row['reputationBonus'] ?? 0));
        $template->setMaxLevel((int) ($row['maxLevel'] ?? 5));
        $template->setDecayBase((float) ($row['decayBase'] ?? 2.0));
        $template->setSortOrder((int) ($row['sortOrder'] ?? 0));
        $template->touch();

        return $created;
    }

    /** @return bool true = created, false = updated */
    private function upsertPlayerArchetype(array $row): bool
    {
        $name = trim($row['name'] ?? '');
        if ($name === '') throw new \InvalidArgumentException('Missing name.');

        $archetype = $this->archetypeRepository->findOneBy(['name' => $name]);
        $created   = $archetype === null;

        if ($created) {
            $archetype = new PlayerArchetype();
            $this->em->persist($archetype);
        }

        $archetype->setName($name);
        $archetype->setDescription($row['description'] ?? '');
        $archetype->setTraitMapping($row['traitMapping'] ?? []);

        return $created;
    }
}
