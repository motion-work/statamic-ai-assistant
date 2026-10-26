<?php

namespace MotionWork\StatamicAiAssistant\Tests\Services;

use MotionWork\StatamicAiAssistant\Services\PreferredAssetPaths;
use MotionWork\StatamicAiAssistant\Tests\TestCase;

class PreferredAssetPathsTest extends TestCase
{
    public function test_empty_when_no_entries(): void
    {
        $q = new PreferredAssetPaths;
        $this->assertTrue($q->isEmpty());
        $this->assertSame([], $q->takeForContainer('images', 5));
    }

    public function test_takes_paths_matching_container_in_order(): void
    {
        $q = new PreferredAssetPaths([
            ['container' => 'images', 'path' => 'ai-agent-fetched/s1/a.jpg'],
            ['container' => 'docs',   'path' => 'ai-agent-fetched/s1/b.pdf'],
            ['container' => 'images', 'path' => 'ai-agent-fetched/s1/c.jpg'],
            ['container' => 'images', 'path' => 'ai-agent-fetched/s1/d.jpg'],
        ]);

        $this->assertSame(
            ['ai-agent-fetched/s1/a.jpg', 'ai-agent-fetched/s1/c.jpg'],
            $q->takeForContainer('images', 2),
        );
        $this->assertFalse($q->isEmpty()); // 'd.jpg' (images) and 'b.pdf' (docs) remain
    }

    public function test_taken_entries_rotate_so_one_image_can_serve_every_field(): void
    {
        // "use this image everywhere": one referenced image, many asset fields
        // (hero + seo_image + …) — every take must yield it, round-robin style.
        $q = new PreferredAssetPaths([
            ['container' => 'images', 'path' => 'a.jpg'],
            ['container' => 'images', 'path' => 'b.jpg'],
        ]);

        $this->assertSame(['a.jpg'], $q->takeForContainer('images', 1));
        $this->assertSame(['b.jpg'], $q->takeForContainer('images', 1));
        $this->assertSame(['a.jpg'], $q->takeForContainer('images', 1)); // cycles, never random
        $this->assertFalse($q->isEmpty());
    }

    public function test_a_single_take_never_duplicates_an_entry(): void
    {
        // Rotation must not pad one gallery with copies of the same image.
        $q = new PreferredAssetPaths([
            ['container' => 'images', 'path' => 'a.jpg'],
        ]);

        $this->assertSame(['a.jpg'], $q->takeForContainer('images', 3));
    }

    public function test_take_returns_empty_when_no_entry_matches_container(): void
    {
        $q = new PreferredAssetPaths([
            ['container' => 'images', 'path' => 'a.jpg'],
        ]);

        $this->assertSame([], $q->takeForContainer('docs', 5));
        // Non-matching takes must not consume the entry.
        $this->assertSame(['a.jpg'], $q->takeForContainer('images', 1));
    }

    public function test_count_caps_at_available(): void
    {
        $q = new PreferredAssetPaths([
            ['container' => 'images', 'path' => 'a.jpg'],
        ]);

        $this->assertSame(['a.jpg'], $q->takeForContainer('images', 10));
        $this->assertFalse($q->isEmpty()); // rotated to the back, not consumed
    }
}
