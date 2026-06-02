<?php

use App\Models\Review;
use App\Models\User;

uses()->group('feature');

it('shows reviews that meet all thresholds', function () {
    $user = User::factory()->create([
        'min_overall' => 3,
        'min_story' => 3,
        'min_performance' => 3,
    ]);

    $review = Review::factory()->create([
        'rating_overall' => 4,
        'rating_story' => 3,
        'rating_performance' => 5,
    ]);

    $visible = Review::where('id', $review->id)->visibleTo($user)->get();

    expect($visible)->toHaveCount(1);
    expect($visible->first()->id)->toBe($review->id);
});

it('hides reviews when overall rating is below threshold', function () {
    $user = User::factory()->create([
        'min_overall' => 4,
        'min_story' => 1,
        'min_performance' => 1,
    ]);

    $review = Review::factory()->create([
        'rating_overall' => 3,
        'rating_story' => 5,
        'rating_performance' => 5,
    ]);

    $visible = Review::where('id', $review->id)->visibleTo($user)->get();

    expect($visible)->toBeEmpty();
});

it('hides reviews when story rating is below threshold', function () {
    $user = User::factory()->create([
        'min_overall' => 1,
        'min_story' => 4,
        'min_performance' => 1,
    ]);

    $review = Review::factory()->create([
        'rating_overall' => 5,
        'rating_story' => 3,
        'rating_performance' => 5,
    ]);

    $visible = Review::where('id', $review->id)->visibleTo($user)->get();

    expect($visible)->toBeEmpty();
});

it('hides reviews when performance rating is below threshold', function () {
    $user = User::factory()->create([
        'min_overall' => 1,
        'min_story' => 1,
        'min_performance' => 4,
    ]);

    $review = Review::factory()->create([
        'rating_overall' => 5,
        'rating_story' => 5,
        'rating_performance' => 3,
    ]);

    $visible = Review::where('id', $review->id)->visibleTo($user)->get();

    expect($visible)->toBeEmpty();
});

it('hides reviews when multiple thresholds fail', function () {
    $user = User::factory()->create([
        'min_overall' => 4,
        'min_story' => 4,
        'min_performance' => 4,
    ]);

    $review = Review::factory()->create([
        'rating_overall' => 3,
        'rating_story' => 2,
        'rating_performance' => 5,
    ]);

    $visible = Review::where('id', $review->id)->visibleTo($user)->get();

    expect($visible)->toBeEmpty();
});

it('shows all reviews when thresholds are zero', function () {
    $user = User::factory()->create([
        'min_overall' => 0,
        'min_story' => 0,
        'min_performance' => 0,
    ]);

    $reviews = Review::factory()->createMany([
        ['rating_overall' => 1, 'rating_story' => 1, 'rating_performance' => 1],
        ['rating_overall' => 3, 'rating_story' => 2, 'rating_performance' => 4],
        ['rating_overall' => 5, 'rating_story' => 5, 'rating_performance' => 5],
    ]);

    $visible = Review::visibleTo($user)->get();

    expect($visible)->toHaveCount(3);
});

it('filters correctly across multiple users with different thresholds', function () {
    $lowBar = User::factory()->create([
        'min_overall' => 1,
        'min_story' => 1,
        'min_performance' => 1,
    ]);

    $highBar = User::factory()->create([
        'min_overall' => 4,
        'min_story' => 4,
        'min_performance' => 4,
    ]);

    $review = Review::factory()->create([
        'rating_overall' => 3,
        'rating_story' => 4,
        'rating_performance' => 5,
    ]);

    expect(Review::where('id', $review->id)->visibleTo($lowBar)->get())->toHaveCount(1);
    expect(Review::where('id', $review->id)->visibleTo($highBar)->get())->toBeEmpty();
});
