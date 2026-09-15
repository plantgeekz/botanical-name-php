<?php

declare(strict_types=1);

namespace PlantGeekz\BotanicalName;

/**
 * Turns a ParsedName back into text, HTML, slugs and comparison keys.
 *
 * @internal Use the methods on ParsedName.
 */
final class Formatter
{
    private const ROMAN = 'roman';
    private const ITALIC = 'italic';
    private const TRADE = 'trade';

    /** Latin letters with diacritics → ASCII, identical to the JavaScript package. */
    private const ASCII = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'æ' => 'ae', 'ç' => 'c', 'ć' => 'c', 'č' => 'c', 'ď' => 'd', 'đ' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i', 'ı' => 'i',
        'ł' => 'l', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ő' => 'o', 'œ' => 'oe',
        'ř' => 'r', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ß' => 'ss', 'ť' => 't', 'ţ' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u', 'ű' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    public static function text(ParsedName $name, bool $authors, bool $typographic): string
    {
        if ($name->formula !== null) {
            return implode(' × ', array_map(
                static fn (ParsedName $p) => self::text($p, $authors, $typographic),
                $name->formula,
            ));
        }

        $out = '';
        foreach (self::segments($name, $authors, $typographic) as [$text, , $space]) {
            $out .= ($space && $out !== '' ? ' ' : '') . $text;
        }

        return $out;
    }

    public static function html(ParsedName $name, bool $authors, string $tag, bool $typographic): string
    {
        $tag = $tag === 'em' ? 'em' : 'i';

        if ($name->formula !== null) {
            return implode(' × ', array_map(
                static fn (ParsedName $p) => self::html($p, $authors, $tag, $typographic),
                $name->formula,
            ));
        }

        $out = '';
        $open = false;
        foreach (self::segments($name, $authors, $typographic) as [$text, $style, $space]) {
            $sep = $space && $out !== '' ? ' ' : '';
            $text = self::escape($text);
            if ($style === self::ITALIC) {
                $out .= $open ? $sep . $text : $sep . "<{$tag}>" . $text;
                $open = true;
                continue;
            }
            if ($open) {
                $out .= "</{$tag}>";
                $open = false;
            }
            $out .= $sep . ($style === self::TRADE ? '<span class="trade-designation">' . $text . '</span>' : $text);
        }

        return $open ? $out . "</{$tag}>" : $out;
    }

    public static function slug(ParsedName $name): string
    {
        $s = self::ascii(mb_strtolower(self::text($name, false, false)));
        $s = str_replace(["'", '"'], '', $s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);

        return trim($s, '-');
    }

    public static function key(ParsedName $name): string
    {
        $s = self::ascii(mb_strtolower(self::text($name, false, false)));
        $s = str_replace(["'", '"', '×', '+'], '', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    /**
     * The name as ordered segments: [text, style, space before].
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private static function segments(ParsedName $name, bool $authors, bool $typographic): array
    {
        $seg = [];
        if ($name->genus !== null) {
            if ($name->genusHybrid || $name->graftChimaera) {
                $seg[] = [$name->genusHybrid ? '×' : '+', self::ROMAN, true];
                $seg[] = [$name->genus, self::ITALIC, false];
            } else {
                $seg[] = [$name->genus, self::ITALIC, true];
            }
        }
        if ($name->epithet !== null) {
            if ($name->speciesHybrid) {
                $seg[] = ['×', self::ROMAN, true];
                $seg[] = [$name->epithet, self::ITALIC, false];
            } else {
                $seg[] = [$name->epithet, self::ITALIC, true];
            }
        }
        if ($authors && $name->authorship !== null) {
            $seg[] = [$name->authorship, self::ROMAN, true];
        }
        foreach ($name->infraspecific as $part) {
            if ($part->rank !== null) {
                $seg[] = [$part->rank, self::ROMAN, true];
            }
            if (str_starts_with($part->epithet, '×')) {
                $seg[] = ['×', self::ROMAN, true];
                $seg[] = [mb_substr($part->epithet, 1), self::ITALIC, false];
            } else {
                $seg[] = [$part->epithet, self::ITALIC, true];
            }
            if ($authors && $part->authorship !== null) {
                $seg[] = [$part->authorship, self::ROMAN, true];
            }
        }
        if ($name->group !== null) {
            $seg[] = [$name->group . ' Group', self::ROMAN, true];
        }
        if ($name->tradeName !== null) {
            $seg[] = [$name->tradeName, self::TRADE, true];
        }
        if ($name->cultivar !== null) {
            $seg[] = [
                $typographic
                    ? "\u{2018}" . str_replace("'", "\u{2019}", $name->cultivar) . "\u{2019}"
                    : "'" . $name->cultivar . "'",
                self::ROMAN,
                true,
            ];
        }

        return $seg;
    }

    private static function escape(string $s): string
    {
        // Explicit map so the output is byte-identical to the JavaScript package.
        return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#039;']);
    }

    private static function ascii(string $s): string
    {
        return strtr($s, self::ASCII);
    }
}
