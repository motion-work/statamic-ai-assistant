<?php

namespace MotionWork\StatamicAiAssistant\Tests\Support;

use MotionWork\StatamicAiAssistant\Support\HostAllowlist;
use PHPUnit\Framework\TestCase;

class HostAllowlistTest extends TestCase
{
    public function test_exact_subdomain_and_parent_matches_are_allowed(): void
    {
        $hosts = ['example.com'];

        $this->assertTrue(HostAllowlist::matches('https://example.com/a', $hosts));
        $this->assertTrue(HostAllowlist::matches('https://www.example.com/a', $hosts));
        $this->assertTrue(HostAllowlist::matches('https://cdn.example.com/img.jpg', $hosts), 'CDN subdomain of the provided site');
        // Parent-domain match: user gave a subdomain, asset on the bare domain.
        $this->assertTrue(HostAllowlist::matches('https://example.com/x', ['images.example.com']));
    }

    public function test_foreign_hosts_and_empty_allowlist_are_rejected(): void
    {
        $this->assertFalse(HostAllowlist::matches('https://upload.wikimedia.org/x.jpg', ['example.com']));
        $this->assertFalse(HostAllowlist::matches('https://example.com/a', []), 'empty allowlist allows nothing');
        $this->assertFalse(HostAllowlist::matches('', ['example.com']));
        $this->assertFalse(HostAllowlist::matches('not a url', ['example.com']));
    }
}
