<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The single source of truth for country codes and everything derived from them.
 *
 * Before this enum the same list lived in four places, each carrying a
 * different slice of the data and drifting independently:
 *   - ClubInitializationService::COUNTRY_TO_NATIONALITY  (code → demonym, 19 entries)
 *   - WarmPoolCommand::SUPPORTED_COUNTRIES               (code → demonym, byte-identical copy)
 *   - ClubController::LOCALE_BY_COUNTRY                  (code → locale, 9 entries)
 *   - DashboardController worldpack screen                (code → English name, 9 entries)
 *
 * Two distinct notions are deliberately kept apart here, because conflating
 * them is the bug this enum exists to prevent:
 *
 *   - {@see isGenerationCapable()} — the world generator can build a league
 *     pyramid and NPC clubs for this country. A property of the code.
 *   - `StarterConfig::$enabledCountries` — the country is offered to players
 *     right now. An admin-editable runtime toggle, and a subset of the above.
 *
 * A country can be generation-capable without being playable; the landing page
 * must advertise the second list, never this one.
 */
enum Country: string
{
    case EN = 'EN';
    case IT = 'IT';
    case DE = 'DE';
    case ES = 'ES';
    case BR = 'BR';
    case AR = 'AR';
    case NL = 'NL';
    case FR = 'FR';
    case PT = 'PT';
    case NG = 'NG';
    case GH = 'GH';
    case JP = 'JP';
    case KR = 'KR';
    case SE = 'SE';
    case DK = 'DK';
    case IE = 'IE';
    case CI = 'CI';
    case SN = 'SN';
    case CN = 'CN';

    /** Display name, e.g. for the landing page and admin dropdowns. */
    public function label(): string
    {
        return match ($this) {
            self::EN => 'England',
            self::IT => 'Italy',
            self::DE => 'Germany',
            self::ES => 'Spain',
            self::BR => 'Brazil',
            self::AR => 'Argentina',
            self::NL => 'Netherlands',
            self::FR => 'France',
            self::PT => 'Portugal',
            self::NG => 'Nigeria',
            self::GH => 'Ghana',
            self::JP => 'Japan',
            self::KR => 'South Korea',
            self::SE => 'Sweden',
            self::DK => 'Denmark',
            self::IE => 'Ireland',
            self::CI => 'Ivory Coast',
            self::SN => 'Senegal',
            self::CN => 'China',
        };
    }

    /**
     * The nationality string used throughout the player pool.
     *
     * These values are matched verbatim against generated player nationalities
     * and against `WorldRegion`'s demonym table — changing one of them silently
     * breaks pool warming and skin-tone weighting, so treat them as data, not copy.
     */
    public function nationality(): string
    {
        return match ($this) {
            self::EN => 'English',
            self::IT => 'Italian',
            self::DE => 'German',
            self::ES => 'Spanish',
            self::BR => 'Brazilian',
            self::AR => 'Argentine',
            self::NL => 'Dutch',
            self::FR => 'French',
            self::PT => 'Portuguese',
            self::NG => 'Nigerian',
            self::GH => 'Ghanaian',
            self::JP => 'Japanese',
            self::KR => 'South Korean',
            self::SE => 'Swedish',
            self::DK => 'Danish',
            self::IE => 'Irish',
            self::CI => 'Ivorian',
            self::SN => 'Senegalese',
            self::CN => 'Chinese',
        };
    }

    /**
     * ICU locale used to collate generated club names, so accented characters
     * sort next to their unaccented neighbours rather than after all ASCII
     * (which is what PHP's byte-wise sort() does).
     */
    public function locale(): string
    {
        return match ($this) {
            self::ES => 'es_ES',
            self::EN => 'en_GB',
            self::DE => 'de_DE',
            self::IT => 'it_IT',
            self::FR => 'fr_FR',
            self::BR => 'pt_BR',
            self::AR => 'es_AR',
            self::NL => 'nl_NL',
            self::PT => 'pt_PT',
            self::NG, self::GH, self::IE => 'en_GB',
            self::JP => 'ja_JP',
            self::KR => 'ko_KR',
            self::SE => 'sv_SE',
            self::DK => 'da_DK',
            self::CI, self::SN => 'fr_FR',
            self::CN => 'zh_CN',
        };
    }

    /**
     * Whether league structures and NPC clubs can be generated for this country.
     *
     * Independent of whether players may currently pick it — see the class
     * docblock. Mirrors the country dropdowns on the admin Generate screen.
     */
    public function isGenerationCapable(): bool
    {
        return match ($this) {
            self::ES, self::EN, self::DE, self::IT, self::FR,
            self::BR, self::AR, self::NL, self::PT => true,
            default => false,
        };
    }

    /** @return list<self> */
    public static function generationCapable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $c): bool => $c->isGenerationCapable()));
    }

    /** @return array<string, string> code => label, for admin dropdowns. */
    public static function generationCapableLabels(): array
    {
        $out = [];
        foreach (self::generationCapable() as $country) {
            $out[$country->value] = $country->label();
        }

        return $out;
    }

    /** @return array<string, string> code => nationality, for the whole set. */
    public static function nationalityMap(): array
    {
        $out = [];
        foreach (self::cases() as $country) {
            $out[$country->value] = $country->nationality();
        }

        return $out;
    }
}
