<?php

declare(strict_types=1);

namespace PlantGeekz\BotanicalName\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PlantGeekz\BotanicalName\BotanicalName;

/**
 * Runs the shared fixtures in fixtures/names.json. The JavaScript package runs
 * the same file, so both implementations must agree byte for byte.
 */
final class FixturesTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function fixtures(): iterable
    {
        /** @var list<array<string, mixed>> $cases */
        $cases = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/names.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($cases as $i => $case) {
            $label = $case['note'] ?? $case['input'];
            yield sprintf('#%d %s', $i, is_string($label) ? $label : '') => [$case];
        }
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('fixtures')]
    public function testFixture(array $case): void
    {
        self::assertIsString($case['input']);
        $name = BotanicalName::parse($case['input']);

        if (array_key_exists('parse', $case) && $case['parse'] === null) {
            self::assertNull($name, 'Expected the input to be rejected');

            return;
        }
        self::assertNotNull($name, 'Expected the input to parse');

        if (isset($case['expect'])) {
            self::assertSubset($case['expect'], $name->toArray(), 'parse');
        }

        $outputs = [
            'text' => fn () => $name->toString(),
            'textWithAuthors' => fn () => $name->toString(authors: true),
            'textTypographic' => fn () => $name->toString(typographic: true),
            'html' => fn () => $name->toHtml(),
            'htmlWithAuthors' => fn () => $name->toHtml(authors: true),
            'slug' => fn () => $name->slug(),
            'key' => fn () => $name->key(),
        ];
        foreach ($outputs as $field => $produce) {
            if (array_key_exists($field, $case)) {
                self::assertSame($case[$field], $produce(), $field);
            }
        }
    }

    public function testNormalizeKeepsAuthors(): void
    {
        self::assertSame(
            'Hydrangea ×macrophylla subsp. serrata (Thunb.) Makino',
            BotanicalName::normalize('hydrangea x macrophylla ssp. serrata (Thunb.) Makino'),
        );
        self::assertNull(BotanicalName::normalize(''));
    }

    public function testEmTagForItalics(): void
    {
        self::assertSame('<em>Rosa canina</em>', BotanicalName::parse('Rosa canina')?->toHtml(tag: 'em'));
    }

    public function testJsonMatchesToArray(): void
    {
        $name = BotanicalName::parse('Rosa canina L.');
        self::assertNotNull($name);
        self::assertSame(json_encode($name->toArray()), json_encode($name));
    }

    /**
     * Every key in $expected must match; keys it leaves out are not checked.
     * Lists must have the same length and match element by element.
     */
    private static function assertSubset(mixed $expected, mixed $actual, string $path): void
    {
        if (is_array($expected) && array_is_list($expected) && $expected !== []) {
            self::assertIsArray($actual, $path);
            self::assertCount(count($expected), $actual, $path);
            foreach ($expected as $i => $value) {
                self::assertSubset($value, $actual[$i], "{$path}[{$i}]");
            }

            return;
        }
        if (is_array($expected) && $expected !== []) {
            self::assertIsArray($actual, $path);
            foreach ($expected as $key => $value) {
                self::assertArrayHasKey($key, $actual, "{$path}.{$key}");
                self::assertSubset($value, $actual[$key], "{$path}.{$key}");
            }

            return;
        }
        self::assertSame($expected, $actual, $path);
    }
}
