<?php

declare(strict_types=1);

namespace PlantGeekz\BotanicalName;

/**
 * Structural parser for botanical names (ICN + ICNCP).
 *
 * It works on the shape of the string only - it never looks names up in a
 * database - so it tells you what the parts are, not whether the name exists.
 *
 * @internal Use BotanicalName::parse().
 */
final class Parser
{
    /** Infraspecific rank markers, normalized to their ICN abbreviation. */
    private const RANKS = [
        'subsp' => 'subsp.', 'ssp' => 'subsp.', 'subspecies' => 'subsp.',
        'var' => 'var.', 'variety' => 'var.', 'varietas' => 'var.',
        'subvar' => 'subvar.',
        'f' => 'f.', 'fo' => 'f.', 'forma' => 'f.',
        'subf' => 'subf.',
        'nothosubsp' => 'nothosubsp.', 'nothovar' => 'nothovar.', 'nothof' => 'nothof.',
        'convar' => 'convar.',
    ];

    /** Lowercase words that may start or continue an author citation. */
    private const AUTHOR_PARTICLES = [
        'de', 'del', 'della', 'di', 'du', 'da', 'dos', 'van', 'von', 'der', 'den',
        'ex', 'et', 'in', 'le', 'la', 'y', 'f.', 'fil.',
    ];

    private const HYBRID_MARKERS = ['×', 'x', 'X'];

    /** @var list<string> */
    private array $warnings = [];

    public function parse(string $input): ?ParsedName
    {
        $this->warnings = [];

        $s = $this->cleanWhitespace($input);
        if ($s === '') {
            return null;
        }
        $s = $this->normalizeQuotes($s);

        $formula = $this->parseFormula($s, $input);
        if ($formula !== false) {
            return $formula;
        }

        // Cultivar group written as "(Capitata Group)".
        $group = null;
        if (preg_match('/\(\s*([^()]+?)\s+(?:Group|Gp\.?)\s*\)/u', $s, $m)) {
            $group = $m[1];
            $s = $this->cleanWhitespace(str_replace($m[0], ' ', $s));
        }

        // Cultivar in single quotes, from the first opening quote to the last
        // closing one, so apostrophes inside stay: 'O'Hara', 'Buddha's Temple',
        // 'Rees' Choice', 'Hugs 'N' Kisses'.
        $cultivar = null;
        if (preg_match("/(?<![\\p{L}\\p{N}])'(.+)'(?![\\p{L}\\p{N}])/u", $s, $m)
            || preg_match('/"(.+?)"/u', $s, $m)) {
            $cultivar = trim($m[1]);
            $s = $this->cleanWhitespace(str_replace($m[0], ' ', $s));
            if ($m[0][0] === '"') {
                $this->warn('quotes_normalized');
            }
        }

        // Bare "(Capitata)" at the end - a group without the word "Group", as
        // seed catalogues write it. Only next to a cultivar: on its own, "(Lehnert)"
        // is far more often an incomplete author citation.
        if ($group === null && $cultivar !== null && preg_match('/\(\s*(\p{Lu}\p{Ll}+(?:[ -]\p{Lu}?\p{Ll}+)*)\s*\)$/u', $s, $m)) {
            $group = $m[1];
            $s = $this->cleanWhitespace(mb_substr($s, 0, mb_strlen($s) - mb_strlen($m[0])));
            $this->warn('group_inferred');
        }

        $tokens = $this->mergeHybridMarkers(explode(' ', $s));

        $tradeName = $this->extractTradeName($tokens);

        if ($cultivar === null) {
            $cultivar = $this->extractCultivarMarker($tokens);
        }
        if ($group === null) {
            $group = $this->extractGroupWord($tokens);
        }

        $tokens = $this->dropStrayTokens($tokens);
        if ($tokens === []) {
            return null;
        }

        // Genus, possibly a nothogenus (×) or graft-chimaera (+).
        $genus = array_shift($tokens);
        $genusHybrid = false;
        $graftChimaera = false;
        if (str_starts_with($genus, '×')) {
            $genusHybrid = true;
            $genus = mb_substr($genus, 1);
        } elseif (str_starts_with($genus, '+')) {
            $graftChimaera = true;
            $genus = mb_substr($genus, 1);
        }
        $genus = $this->fixGenusCase($genus);
        if (preg_match('/^\p{Lu}\.$/u', $genus)) {
            $this->warn('abbreviated_genus');
        } elseif (!preg_match('/^\p{Lu}\p{Ll}[\p{Ll}-]*$/u', $genus)) {
            return null;
        }

        // Specific epithet.
        $epithet = null;
        $speciesHybrid = false;
        if ($tokens !== []) {
            $t = $tokens[0];
            if (in_array($t, ['sp.', 'sp', 'spp.', 'spp'], true)) {
                array_shift($tokens);
                $this->warn('species_unspecified');
            } elseif (!$this->startsInfraspecific($tokens, 0)) {
                $candidate = $t;
                $hybrid = false;
                if (str_starts_with($candidate, '×')) {
                    $hybrid = true;
                    $candidate = mb_substr($candidate, 1);
                }
                $candidate = $this->fixEpithetCase($candidate);
                if ($this->isBareEpithet($candidate)) {
                    array_shift($tokens);
                    $epithet = $candidate;
                    $speciesHybrid = $hybrid;
                }
            }
        }

        // Authors and infraspecific parts.
        $authorship = null;
        $infra = [];
        $buffer = [];

        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if ($this->startsInfraspecific($tokens, $i)) {
                self::flushAuthors($buffer, $authorship, $infra);
                $rank = $this->rankOf($t);
                if ($rank !== $t) {
                    $this->warn('rank_normalized');
                }
                $infra[] = ['rank' => $rank, 'epithet' => $this->fixEpithetCase($tokens[$i + 1]), 'authorship' => null];
                $i++;
                continue;
            }
            if ($buffer === [] && ($epithet !== null || $infra !== [])
                && $this->isEpithet($t) && !in_array($t, self::AUTHOR_PARTICLES, true)) {
                self::flushAuthors($buffer, $authorship, $infra);
                $infra[] = ['rank' => null, 'epithet' => $t, 'authorship' => null];
                $this->warn('rank_missing');
                continue;
            }
            $buffer[] = $t;
        }
        self::flushAuthors($buffer, $authorship, $infra);

        $warnings = array_values(array_unique($this->warnings));
        sort($warnings);

        return new ParsedName(
            verbatim: $input,
            genus: $genus,
            genusHybrid: $genusHybrid,
            graftChimaera: $graftChimaera,
            epithet: $epithet,
            speciesHybrid: $speciesHybrid,
            authorship: $authorship,
            infraspecific: array_map(
                static fn (array $p) => new Infraspecific($p['rank'], $p['epithet'], $p['authorship']),
                $infra,
            ),
            group: $group,
            tradeName: $tradeName,
            cultivar: $cultivar,
            formula: null,
            warnings: $warnings,
        );
    }

    /**
     * Moves the collected author tokens to the name part they belong to: the
     * species (or genus) until the first infraspecific rank, then that rank.
     *
     * @param list<string> $buffer
     * @param list<array{rank: ?string, epithet: string, authorship: ?string}> $infra
     * @param-out list<string> $buffer
     * @param-out list<array{rank: ?string, epithet: string, authorship: ?string}> $infra
     */
    private static function flushAuthors(array &$buffer, ?string &$authorship, array &$infra): void
    {
        if ($buffer === []) {
            return;
        }
        $author = implode(' ', $buffer);
        $buffer = [];
        $last = array_key_last($infra);
        if ($last === null) {
            $authorship = $authorship === null ? $author : $authorship . ' ' . $author;
        } else {
            $infra[$last]['authorship'] = $author;
        }
    }

    /**
     * "Salix alba × S. fragilis": a standalone hybrid sign followed by a
     * capitalised word. Returns false when the string is not a formula.
     */
    private function parseFormula(string $s, string $input): ParsedName|false|null
    {
        $tokens = explode(' ', $s);
        $parts = [];
        $current = [];
        $quoted = false;
        foreach ($tokens as $i => $t) {
            // A sign inside a cultivar name is not a formula: 'Longfields X Factor'.
            $wasQuoted = $quoted;
            $quoted = $this->quoteStateAfter($t, $quoted);
            if (!$wasQuoted && $i > 0 && in_array($t, self::HYBRID_MARKERS, true)
                && isset($tokens[$i + 1]) && preg_match('/^\p{Lu}/u', $tokens[$i + 1])) {
                $parts[] = implode(' ', $current);
                $current = [];
                if ($t !== '×') {
                    $this->warn('hybrid_marker_normalized');
                }
                continue;
            }
            $current[] = $t;
        }
        if ($parts === []) {
            return false;
        }
        $parts[] = implode(' ', $current);

        $parsed = [];
        foreach ($parts as $part) {
            $p = (new self())->parse($part);
            if ($p === null) {
                return null;
            }
            $parsed[] = $p;
        }

        $warnings = array_values(array_unique($this->warnings));
        sort($warnings);

        return new ParsedName(
            verbatim: $input,
            genus: $parsed[0]->genus,
            genusHybrid: false,
            graftChimaera: false,
            epithet: null,
            speciesHybrid: false,
            authorship: null,
            infraspecific: [],
            group: null,
            tradeName: null,
            cultivar: null,
            formula: $parsed,
            warnings: $warnings,
        );
    }

    /** Whether we are inside a quoted cultivar name after this token. */
    private function quoteStateAfter(string $token, bool $quoted): bool
    {
        $opens = (bool) preg_match('/^[(\[]?[\'"]/u', $token);
        $closes = mb_strlen($token) > 1 && preg_match('/[\'"][)\].,;]?$/u', $token);
        if (!$quoted) {
            return $opens && !$closes;
        }

        return !$closes;
    }

    /**
     * Joins a free-standing hybrid sign to the name it belongs to:
     * "× Chitalpa" → "×Chitalpa", "Mentha x piperita" → "Mentha ×piperita",
     * "+ Crataegomespilus" → "+Crataegomespilus".
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function mergeHybridMarkers(array $tokens): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && in_array($t, self::HYBRID_MARKERS, true)) {
                $genusPosition = $out === [] && preg_match('/^\p{Lu}/u', $next);
                $epithetPosition = $out !== [] && $this->isBareEpithet($next);
                if ($genusPosition || $epithetPosition) {
                    if ($t !== '×') {
                        $this->warn('hybrid_marker_normalized');
                    }
                    $out[] = '×' . $next;
                    $i++;
                    continue;
                }
            }
            if ($next === null && $out !== [] && ($t === '+' || in_array($t, self::HYBRID_MARKERS, true))) {
                $this->warn('stray_characters_removed');
                continue;
            }
            if ($t === '+' && $out === [] && $next !== null) {
                $out[] = '+' . $next;
                $i++;
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /**
     * "Rosa Flower Carpet® 'Noare'" → trade designation "Flower Carpet".
     *
     * @param list<string> $tokens
     */
    private function extractTradeName(array &$tokens): ?string
    {
        foreach ($tokens as $k => $t) {
            if (!preg_match('/[®™]/u', $t)) {
                continue;
            }
            $stripped = (string) preg_replace('/[®™]/u', '', $t);
            $end = $k;
            if ($stripped === '') {
                $end = $k - 1;
            } else {
                $tokens[$k] = $stripped;
            }
            $start = $end + 1;
            while ($start - 1 > 0 && $this->isCapitalisedWord($tokens[$start - 1])) {
                $start--;
            }
            $name = $start <= $end ? implode(' ', array_slice($tokens, $start, $end - $start + 1)) : null;
            // Remove the trade tokens and a lone "®" token.
            $removeTo = $stripped === '' ? $k : $end;
            array_splice($tokens, $start, $removeTo - $start + 1);

            return $name;
        }

        return null;
    }

    /**
     * "Hosta cv. Blue Angel" → cultivar "Blue Angel".
     *
     * @param list<string> $tokens
     */
    private function extractCultivarMarker(array &$tokens): ?string
    {
        foreach ($tokens as $k => $t) {
            if ($k > 0 && in_array(mb_strtolower(rtrim($t, '.')), ['cv', 'cvs'], true) && isset($tokens[$k + 1])) {
                $name = implode(' ', array_slice($tokens, $k + 1));
                array_splice($tokens, $k);
                $this->warn('cultivar_marker_normalized');

                return $name;
            }
        }

        return null;
    }

    /**
     * "Hosta Tardiana Group" → group "Tardiana".
     *
     * @param list<string> $tokens
     */
    private function extractGroupWord(array &$tokens): ?string
    {
        foreach ($tokens as $k => $t) {
            if ($k < 2 || !in_array($t, ['Group', 'Gp', 'Gp.'], true)) {
                continue;
            }
            $start = $k;
            while ($start - 1 > 0 && $this->isCapitalisedWord($tokens[$start - 1])) {
                $start--;
            }
            if ($start === $k) {
                return null;
            }
            $name = implode(' ', array_slice($tokens, $start, $k - $start));
            array_splice($tokens, $start, $k - $start + 1);

            return $name;
        }

        return null;
    }

    /**
     * Removes leftovers with no letters or digits, such as an unmatched quote
     * or bracket. "&" survives because author teams use it.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function dropStrayTokens(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $i => $t) {
            if ($i > 0 && preg_match('/^[^\p{L}\p{N}&]+$/u', $t)) {
                $this->warn('stray_characters_removed');
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /** @param list<string> $tokens */
    private function startsInfraspecific(array $tokens, int $i): bool
    {
        return $this->rankOf($tokens[$i]) !== null
            && isset($tokens[$i + 1])
            && $this->isEpithet($this->fixEpithetCase($tokens[$i + 1], warn: false));
    }

    private function rankOf(string $token): ?string
    {
        if (!preg_match('/^\p{Ll}/u', $token)) {
            return null;
        }

        return self::RANKS[rtrim($token, '.')] ?? null;
    }

    private function isEpithet(string $token): bool
    {
        return (bool) preg_match('/^×?\p{Ll}[\p{Ll}-]+$/u', $token);
    }

    private function isBareEpithet(string $token): bool
    {
        return (bool) preg_match('/^\p{Ll}[\p{Ll}-]+$/u', $token);
    }

    private function isCapitalisedWord(string $token): bool
    {
        return (bool) preg_match('/^[\p{Lu}\p{N}][\p{L}\p{N}\'-]*$/u', $token);
    }

    private function fixGenusCase(string $genus): string
    {
        if (!preg_match('/^\p{L}{2,}$/u', $genus)) {
            return $genus;
        }
        $lower = mb_strtolower($genus);
        $upper = mb_strtoupper($genus);
        if ($genus === $lower || $genus === $upper) {
            $this->warn('case_normalized');

            return mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
        }

        return $genus;
    }

    private function fixEpithetCase(string $epithet, bool $warn = true): string
    {
        if (preg_match('/^\p{Lu}{3,}$/u', $epithet)) {
            if ($warn) {
                $this->warn('case_normalized');
            }

            return mb_strtolower($epithet);
        }

        return $epithet;
    }

    private function cleanWhitespace(string $s): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{2009}\x{202F}]+/u', ' ', $s));
    }

    /** Curly and look-alike quotes → straight ones (fixes iOS smart quotes). */
    private function normalizeQuotes(string $s): string
    {
        $out = strtr($s, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'",
            '`' => "'", "\u{00B4}" => "'", "\u{2032}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2033}" => '"',
        ]);
        if ($out !== $s) {
            $this->warn('quotes_normalized');
        }

        return $out;
    }

    private function warn(string $code): void
    {
        $this->warnings[] = $code;
    }
}
