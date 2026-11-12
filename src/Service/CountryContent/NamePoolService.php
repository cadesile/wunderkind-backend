<?php

declare(strict_types=1);

namespace App\Service\CountryContent;

use App\Entity\Location\CountryProfile;
use App\Enum\Country;
use App\Service\CountryContent\Suggestion\NamePoolSuggesterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores, clears and tops up a country's person-name pools.
 *
 * The write half of NamePoolProvider, kept out of the controller so the admin button and the
 * command run the same code.
 *
 * THE RULE WORTH KNOWING: a list identical to the shipped constant is stored as NULL rather
 * than as a copy. Without it, opening England's form and pressing Save — which does nothing
 * and looks like it does nothing — silently converts it from "follows the shipped pool" to
 * "pinned to a copy of the shipped pool as it was that day", and a later pull request adding
 * English names would never reach it. NamingContentService makes the same move for the same
 * reason.
 */
final class NamePoolService
{
    /** The two lists, in the order the form shows them. */
    public const LISTS = ['firstNames', 'lastNames'];

    public function __construct(
        private readonly NamePoolProvider $provider,
        private readonly NamePoolValidator $validator,
        private readonly NamePoolSuggesterInterface $suggester,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Validate and store what the operator typed.
     *
     * A list the validator refused is left exactly as it was, so a bad paste into one field
     * cannot cost the other.
     *
     * @param array<string, list<string>> $input
     */
    public function save(CountryProfile $country, array $input): ValidatedNamePool
    {
        $verdict = $this->validator->validate($input);

        foreach ($verdict->accepted as $list => $names) {
            $this->write($country, $list, $this->asOverride($country, $list, $names));
        }

        $this->flushAndForget($country);

        return $verdict;
    }

    /** Put a country back on the shipped pools. */
    public function clear(CountryProfile $country): void
    {
        foreach (self::LISTS as $list) {
            $this->write($country, $list, null);
        }

        $this->flushAndForget($country);
    }

    /**
     * Ask the model for the shortfall on one list, and store what the validator accepts.
     *
     * TOPS UP, NEVER REPLACES. The ask is target minus what is already effective, and the
     * names already held go to the model as exclusions — so a country at target costs
     * nothing and an authored pool is extended rather than overwritten.
     *
     * @param 'firstNames'|'lastNames' $list
     *
     * @return array{added: int, held: int, verdict: ValidatedNamePool, inputTokens: ?int, outputTokens: ?int}
     */
    public function topUp(CountryProfile $country, string $list, int $target): array
    {
        $nationality = $this->nationalityFor($country);
        $held        = $this->effective($nationality, $list);
        $want        = $target - \count($held);

        if ($want <= 0) {
            return [
                'added'        => 0,
                'held'         => \count($held),
                'verdict'      => new ValidatedNamePool(),
                'inputTokens'  => null,
                'outputTokens' => null,
            ];
        }

        $suggestion = $this->suggester->suggest($country, $list, $held, $want);

        // MERGED, then validated as a whole. Validating the increment alone would let a
        // three-name reply through on a country that already has a hundred and fifty, and
        // would dedupe the new names against each other but not against what is held.
        $merged  = array_merge($held, $suggestion->names);
        $verdict = $this->validator->validate([$list => $merged]);

        $added = 0;

        if (isset($verdict->accepted[$list])) {
            $stored = $verdict->accepted[$list];
            $added  = max(0, \count($stored) - \count($held));

            $this->write($country, $list, $this->asOverride($country, $list, $stored));
            $this->flushAndForget($country);
        }

        $this->logger->info('Name pool top-up', [
            'country'       => $country->getCode(),
            'list'          => $list,
            'provider'      => $this->suggester->provider(),
            'model'         => $this->suggester->model(),
            'promptVersion' => $this->suggester->promptVersion(),
            'held'          => \count($held),
            'asked'         => $want,
            'offered'       => \count($suggestion->names),
            'added'         => $added,
            'rejected'      => $verdict->rejected,
            'inputTokens'   => $suggestion->inputTokens,
            'outputTokens'  => $suggestion->outputTokens,
        ]);

        return [
            'added'        => $added,
            'held'         => \count($held),
            'verdict'      => $verdict,
            'inputTokens'  => $suggestion->inputTokens,
            'outputTokens' => $suggestion->outputTokens,
        ];
    }

    public function suggesterIsConfigured(): bool
    {
        return $this->suggester->isConfigured();
    }

    /**
     * What this country effectively holds for a list today — override or shipped.
     *
     * @return list<string>
     */
    public function effective(string $nationality, string $list): array
    {
        return $list === 'firstNames'
            ? $this->provider->firstNames($nationality)
            : $this->provider->lastNames($nationality);
    }

    public function nationalityFor(CountryProfile $country): string
    {
        return Country::tryFrom($country->getCode())?->nationality() ?? '';
    }

    /**
     * Store an override, or null when the list is simply the shipped one.
     *
     * @param  list<string>      $names
     * @return list<string>|null
     */
    private function asOverride(CountryProfile $country, string $list, array $names): ?array
    {
        $nationality = $this->nationalityFor($country);

        $shipped = $list === 'firstNames'
            ? NamePoolRegistry::firstNames($nationality)
            : NamePoolRegistry::lastNames($nationality);

        return $names === $shipped ? null : $names;
    }

    /** @param list<string>|null $names */
    private function write(CountryProfile $country, string $list, ?array $names): void
    {
        match ($list) {
            'firstNames' => $country->setFirstNames($names),
            'lastNames'  => $country->setLastNames($names),
            default      => throw new \InvalidArgumentException(sprintf('Unknown name list %s.', $list)),
        };
    }

    /**
     * Flush, then drop the memo for this nationality.
     *
     * The provider caches for the life of the process, so without this an admin handler that
     * saves and then re-renders the form in the same request would show the operator what
     * they had before they pressed Save.
     */
    private function flushAndForget(CountryProfile $country): void
    {
        $this->em->flush();
        $this->provider->forget($this->nationalityFor($country));
    }
}
