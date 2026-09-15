<?php

declare(strict_types=1);

namespace PlantGeekz\BotanicalName;

/**
 * Parse, normalize and format botanical plant names.
 *
 *     $name = BotanicalName::parse("Hydrangea x macrophylla ssp. serrata cv. Bluebird");
 *     $name->toString();  // Hydrangea ×macrophylla subsp. serrata 'Bluebird'
 *     $name->toHtml();    // <i>Hydrangea</i> ×<i>macrophylla</i> subsp. <i>serrata</i> 'Bluebird'
 *
 * Made by PlantGeekz - https://plantgeekz.com
 */
final class BotanicalName
{
    /** Splits a name into its parts, or returns null when it does not look like a plant name. */
    public static function parse(string $input): ?ParsedName
    {
        return (new Parser())->parse($input);
    }

    /**
     * Cleans up a name and keeps its authors:
     * "passiflora edulis f. flavicarpa" → "Passiflora edulis f. flavicarpa".
     */
    public static function normalize(string $input): ?string
    {
        return self::parse($input)?->toString(authors: true);
    }
}
