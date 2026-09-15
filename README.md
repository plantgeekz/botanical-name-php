# Botanical Name

[![Tests](https://github.com/plantgeekz/botanical-name-php/actions/workflows/tests.yml/badge.svg)](https://github.com/plantgeekz/botanical-name-php/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/plantgeekz/botanical-name.svg)](https://packagist.org/packages/plantgeekz/botanical-name)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Parse, normalize and format botanical plant names in PHP - hybrids, infraspecific
ranks, authors, cultivars, cultivar groups and trade designations - with the
italics the naming codes (ICN and ICNCP) ask for.

Built for and used in production by [PlantGeekz](https://plantgeekz.com), the plant
identification and collection app. Also available for JavaScript and TypeScript as
[`@plantgeekz_com/botanical-name`](https://github.com/plantgeekz/botanical-name-js); both
packages run the same test fixtures and give byte-identical results.

```php
use PlantGeekz\BotanicalName\BotanicalName;

$name = BotanicalName::parse('hydrangea x macrophylla ssp. serrata cv. Bluebird');

$name->toString(); // Hydrangea ×macrophylla subsp. serrata 'Bluebird'
$name->toHtml();   // <i>Hydrangea</i> ×<i>macrophylla</i> subsp. <i>serrata</i> &#039;Bluebird&#039;
$name->slug();     // hydrangea-macrophylla-subsp-serrata-bluebird
$name->key();      // hydrangea macrophylla subsp. serrata bluebird
```

## Why

Plant names from spreadsheets, nursery catalogues and user input arrive in every
shape: `x` instead of `×`, `ssp.` instead of `subsp.`, `cv.` instead of quotes, curly
iOS quotes, SHOUTING CSV exports. And most sites italicize the whole name, although
only the genus and epithets should be - never the cultivar, the rank or the author.

The heavyweight parsers ([gnparser](https://github.com/gnames/gnparser),
[GBIF's name-parser](https://github.com/gbif/name-parser)) are Go and Java. This is a
small, dependency-free library for the web: pure PHP, no extensions beyond mbstring,
focused on getting names clean and displayed correctly.

## Install

```bash
composer require plantgeekz/botanical-name
```

Requires PHP 8.2 or newer.

## Usage

### Parse

`BotanicalName::parse()` returns a `ParsedName`, or `null` when the input does not
look like a plant name.

```php
$name = BotanicalName::parse("Pinus mugo Turra subsp. uncinata (DC.) Domin");

$name->genus;                          // "Pinus"
$name->epithet;                        // "mugo"
$name->authorship;                     // "Turra"
$name->infraspecific[0]->rank;         // "subsp."
$name->infraspecific[0]->epithet;      // "uncinata"
$name->infraspecific[0]->authorship;   // "(DC.) Domin"
```

| Property | Example input | Value |
|---|---|---|
| `genus` | `Hosta 'Blue Angel'` | `Hosta` |
| `genusHybrid` | `× Chitalpa tashkentensis` | `true` |
| `graftChimaera` | `+ Crataegomespilus dardarii` | `true` |
| `epithet` | `Passiflora edulis Sims` | `edulis` |
| `speciesHybrid` | `Mentha x piperita` | `true` |
| `authorship` | `Picea abies (L.) H.Karst.` | `(L.) H.Karst.` |
| `infraspecific` | `Rosa canina var. dumalis Baker` | `[Infraspecific('var.', 'dumalis', 'Baker')]` |
| `group` | `Brassica oleracea (Capitata Group)` | `Capitata` |
| `tradeName` | `Rosa Flower Carpet® 'Noare'` | `Flower Carpet` |
| `cultivar` | `Hemerocallis 'Buddha's Temple'` | `Buddha's Temple` |
| `formula` | `Salix alba × S. fragilis` | two `ParsedName`s |
| `warnings` | `Rosa canina ssp. canina` | `['rank_normalized']` |

### Normalize

```php
BotanicalName::normalize('PASSIFLORA EDULIS f. flavicarpa O.Deg.');
// "Passiflora edulis f. flavicarpa O.Deg."
```

`normalize()` keeps the authors; `toString()` leaves them out unless you ask.

### Format

```php
$name = BotanicalName::parse("Magnolia grandiflora L. 'Little Gem'");

$name->toString();                    // Magnolia grandiflora 'Little Gem'
$name->toString(authors: true);       // Magnolia grandiflora L. 'Little Gem'
$name->toString(typographic: true);   // Magnolia grandiflora ‘Little Gem’
$name->toHtml();                      // <i>Magnolia grandiflora</i> &#039;Little Gem&#039;
$name->toHtml(authors: true, tag: 'em');
```

HTML output follows the naming codes: genus, species and infraspecific epithets in
italics; rank markers (`subsp.`, `var.`, `f.`), authors, hybrid signs, groups and
cultivars in roman. Trade designations are wrapped in
`<span class="trade-designation">` so you can set them apart, as the ICNCP
recommends. All text is HTML-escaped.

### Match and de-duplicate

`key()` gives a comparison key without authors, quotes, hybrid signs or diacritics,
so different spellings of the same name meet:

```php
BotanicalName::parse('Hydrangea x macrophylla')->key();   // hydrangea macrophylla
BotanicalName::parse('HYDRANGEA ×MACROPHYLLA')->key();    // hydrangea macrophylla
```

### Warnings

Everything the parser changed is reported in `$name->warnings` (sorted, unique):

| Code | Meaning |
|---|---|
| `abbreviated_genus` | The genus is abbreviated, like `S.` in a hybrid formula |
| `case_normalized` | Upper- or lowercase input was recased |
| `cultivar_marker_normalized` | `cv. Name` became `'Name'` |
| `group_inferred` | `(Capitata) 'Brunswick'` read as a cultivar group |
| `hybrid_marker_normalized` | `x` became `×` |
| `quotes_normalized` | Curly, double or backtick quotes became straight ones |
| `rank_missing` | A trinomial without a rank marker, like `Rosa canina dumalis` |
| `rank_normalized` | A rank marker was rewritten, like `ssp.` → `subsp.` |
| `species_unspecified` | `sp.` or `spp.` was dropped |
| `stray_characters_removed` | Leftovers such as an unmatched quote were dropped |

### JSON

`ParsedName` implements `JsonSerializable`. The shape is identical to the object the
JavaScript package returns, so you can parse on the server and render in the browser.

## Scope

This is a structural parser: it reads the shape of a name and never looks it up, so it
tells you what the parts are, not whether the name is accepted or even exists. For
that you need a taxonomic backbone such as GBIF, Catalogue of Life or
[PlantGeekz](https://plantgeekz.com).

Not covered: zoological and bacterial names, validation of author abbreviations, and
names with several quoted cultivars that are not a hybrid formula. A lone
parenthesised name like `(Lehnert)` is read as an author, not a group, because that is
what it usually is in real data.

## Tests

```bash
composer test
```

The fixtures in [`fixtures/names.json`](fixtures/names.json) are shared with the
JavaScript package. Before release both implementations were run over 30,569 real
names from the PlantGeekz taxonomy (species with authors and cultivars): identical
output, and every clean name came back unchanged.

## License

MIT © [PlantGeekz](https://plantgeekz.com)
