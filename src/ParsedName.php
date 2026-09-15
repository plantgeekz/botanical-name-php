<?php

declare(strict_types=1);

namespace PlantGeekz\BotanicalName;

/**
 * A botanical name split into its parts.
 *
 * toArray() has the same shape as the object returned by the JavaScript
 * package (@plantgeekz_com/botanical-name), so both can share test fixtures.
 */
final readonly class ParsedName implements \JsonSerializable, \Stringable
{
    /**
     * @param list<Infraspecific>   $infraspecific
     * @param list<ParsedName>|null $formula  Parents of a hybrid formula ("A × B"), otherwise null.
     * @param list<string>          $warnings Sorted codes for everything that was normalized.
     */
    public function __construct(
        public string $verbatim,
        public ?string $genus,
        public bool $genusHybrid,
        public bool $graftChimaera,
        public ?string $epithet,
        public bool $speciesHybrid,
        public ?string $authorship,
        public array $infraspecific,
        public ?string $group,
        public ?string $tradeName,
        public ?string $cultivar,
        public ?array $formula,
        public array $warnings,
    ) {
    }

    public function isFormula(): bool
    {
        return $this->formula !== null;
    }

    public function isCultivar(): bool
    {
        return $this->cultivar !== null;
    }

    /**
     * Plain-text name: "Hydrangea macrophylla subsp. serrata 'Bluebird'".
     *
     * @param bool $authors     Include author citations.
     * @param bool $typographic Use ‘curly’ cultivar quotes instead of straight ones.
     */
    public function toString(bool $authors = false, bool $typographic = false): string
    {
        return Formatter::text($this, $authors, $typographic);
    }

    /**
     * HTML with botanical italics: genus and epithets in italics; rank markers,
     * authors, hybrid signs, groups and cultivars in roman (ICN + ICNCP).
     * All text is HTML-escaped.
     *
     * @param string $tag Element used for italics, "i" or "em".
     */
    public function toHtml(bool $authors = false, string $tag = 'i', bool $typographic = false): string
    {
        return Formatter::html($this, $authors, $tag, $typographic);
    }

    /** URL slug: "hydrangea-macrophylla-bluebird". */
    public function slug(): string
    {
        return Formatter::slug($this);
    }

    /** Comparison key for matching and de-duplication: "hydrangea macrophylla bluebird". */
    public function key(): string
    {
        return Formatter::key($this);
    }

    /**
     * @return array{
     *     verbatim: string, genus: ?string, genusHybrid: bool, graftChimaera: bool,
     *     epithet: ?string, speciesHybrid: bool, authorship: ?string,
     *     infraspecific: list<array{rank: ?string, epithet: string, authorship: ?string}>,
     *     group: ?string, tradeName: ?string, cultivar: ?string,
     *     formula: list<array<string, mixed>>|null, warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'verbatim' => $this->verbatim,
            'genus' => $this->genus,
            'genusHybrid' => $this->genusHybrid,
            'graftChimaera' => $this->graftChimaera,
            'epithet' => $this->epithet,
            'speciesHybrid' => $this->speciesHybrid,
            'authorship' => $this->authorship,
            'infraspecific' => array_map(static fn (Infraspecific $p) => $p->toArray(), $this->infraspecific),
            'group' => $this->group,
            'tradeName' => $this->tradeName,
            'cultivar' => $this->cultivar,
            'formula' => $this->formula === null
                ? null
                : array_map(static fn (ParsedName $p) => $p->toArray(), $this->formula),
            'warnings' => $this->warnings,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
