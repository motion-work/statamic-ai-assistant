<?php

namespace BoldWeb\StatamicAiAssistant\Tests\Services;

use BoldWeb\StatamicAiAssistant\Services\PreferredAssetPaths;
use BoldWeb\StatamicAiAssistant\Tests\TestCase;

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
            ['container' => 'images', 'path' => 'bold-agent-fetched/s1/a.jpg'],
            ['container' => 'docs',   'path' => 'bold-agent-fetched/s1/b.pdf'],
            ['container' => 'images', 'path' => 'bold-agent-fetched/s1/c.jpg'],
            ['container' => 'images', 'path' => 'bold-agent-fetched/s1/d.jpg'],
        ]);

        $this->assertSame(
            ['bold-agent-fetched/s1/a.jpg', 'bold-agent-fetched/s1/c.jpg'],
            $q->takeForContainer('images', 2),
        );
        $this->assertFalse($q->isEmpty()); // 'd.jpg' (images) and 'b.pdf' (docs) remain
    }

    public function test_take_drains_entries_so_they_are_not_returned_again(): void
    {
        $q = new PreferredAssetPaths([
            ['container' => 'images', 'path' => 'a.jpg'],
            ['container' => 'images', 'path' => 'b.jpg'],
        ]);

        $this->assertSame(['a.jpg'], $q->takeForContainer('images', 1));
        $this->assertSame(['b.jpg'], $q->takeForContainer('images', 1));
        $this->assertSame([], $q->takeForContainer('images', 1));
        $this->assertTrue($q->isEmpty());
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
        $this->assertTrue($q->isEmpty());
    }
}
