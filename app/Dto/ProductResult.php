<?php

namespace App\Dto;

use App\Enums\Region;

class ProductResult
{
    public function __construct(
        public readonly string $asin,
        public readonly string $title,
        public readonly ?string $subtitle,
        public readonly string $description,
        public readonly ?int $runtimeMinutes,
        public readonly ?string $coverImageUrl,
        public readonly ?string $releaseDate,
        public readonly Region $region,
        /** @var string[] */
        public readonly array $authors,
        /** @var string[] */
        public readonly array $narrators,
        public readonly ?RatingResult $rating,
    ) {}

    public static function fromApiResponse(array $data, Region $region): self
    {
        return new self(
            asin: $data['asin'],
            title: $data['title'],
            subtitle: $data['subtitle'] ?? null,
            description: $data['publisher_summary'] ?? '',
            runtimeMinutes: $data['runtime_length_min'] ?? null,
            coverImageUrl: $data['product_images'][500] ?? null,
            releaseDate: $data['release_date'] ?? null,
            region: $region,
            authors: array_map(fn (array $a) => $a['name'], $data['authors'] ?? []),
            narrators: array_map(fn (array $n) => $n['name'], $data['narrators'] ?? []),
            rating: isset($data['rating']) ? RatingResult::fromApiResponse($data['rating'], $data['asin']) : null,
        );
    }
}
