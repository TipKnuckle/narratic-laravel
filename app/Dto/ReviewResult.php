<?php

namespace App\Dto;

class ReviewResult
{
    public function __construct(
        public readonly string $externalId,
        public readonly ?string $title,
        public readonly string $submittedAt,
        public readonly string $authorName,
        public readonly string $format,
        public readonly ?string $body,
        /** @var array|null */
        public readonly ?array $guidedResponses,
        public readonly ?int $ratingOverall,
        public readonly ?int $ratingStory,
        public readonly ?int $ratingPerformance,
        public readonly ?string $relatedUrl,
    ) {}

    public static function fromApiResponse(array $data): self
    {
        $ratings = $data['ratings'] ?? [];

        return new self(
            externalId: $data['id'],
            title: $data['title'] ?? null,
            submittedAt: $data['submission_date'],
            authorName: $data['author_name'],
            format: strtolower($data['format'] ?? 'freeform'),
            body: $data['body'] ?? null,
            guidedResponses: $data['guided_responses'] ?? null,
            ratingOverall: isset($ratings['overall_rating']) ? (int) $ratings['overall_rating'] : null,
            ratingStory: isset($ratings['story_rating']) ? (int) $ratings['story_rating'] : null,
            ratingPerformance: isset($ratings['performance_rating']) ? (int) $ratings['performance_rating'] : null,
            relatedUrl: $data['related_url'] ?? null,
        );
    }
}
