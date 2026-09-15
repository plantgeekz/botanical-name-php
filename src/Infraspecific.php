<?php

declare(strict_types=1);

namespace PlantGeekz\BotanicalName;

/**
 * One infraspecific part of a name, e.g. "subsp. uncinata (DC.) Domin".
 */
final readonly class Infraspecific
{
    public function __construct(
        /** Normalized rank marker ("subsp.", "var.", "f." …), or null when the input left it out. */
        public ?string $rank,
        public string $epithet,
        public ?string $authorship = null,
    ) {
    }

    /** @return array{rank: ?string, epithet: string, authorship: ?string} */
    public function toArray(): array
    {
        return [
            'rank' => $this->rank,
            'epithet' => $this->epithet,
            'authorship' => $this->authorship,
        ];
    }
}
