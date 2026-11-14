<?php

namespace Tests\Unit;

use App\Services\Publishing\ManualPublicationDuplicateDetector;
use PHPUnit\Framework\TestCase;

class ManualPublicationLongContentFingerprintTest extends TestCase
{
    public function test_article_changes_after_old_2000_character_limit_change_fingerprint(): void
    {
        $detector = new ManualPublicationDuplicateDetector;
        $prefix = str_repeat('科研', 1000);

        $this->assertNotSame(
            $detector->fingerprint($prefix.'甲'),
            $detector->fingerprint($prefix.'乙'),
        );
    }
}
