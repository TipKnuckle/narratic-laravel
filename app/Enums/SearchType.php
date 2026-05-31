<?php

namespace App\Enums;

enum SearchType: string
{
    case Author = 'author';
    case Narrator = 'narrator';
    case Title = 'title';
    case Publisher = 'publisher';

    public function queryParameter(): string
    {
        return $this->value;
    }

    public function sortBy(): string
    {
        return match ($this) {
            self::Title => 'Relevance',
            self::Author, self::Narrator, self::Publisher => '-ReleaseDate',
        };
    }
}
