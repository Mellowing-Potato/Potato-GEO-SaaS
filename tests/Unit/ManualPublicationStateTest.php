<?php

namespace Tests\Unit;

use App\Models\ManualPublication;
use Tests\TestCase;

/**
 * 单 7 · 手动发布状态机（执行书 §三-2：七态、三可重开）。
 *
 * 上游参考 tests/Unit/ManualPublicationStateTest.php（判据借来）。
 */
class ManualPublicationStateTest extends TestCase
{
    public function test_allowed_next_statuses_follow_the_documented_state_machine(): void
    {
        $this->assertSame(
            [ManualPublication::STATUS_READY, ManualPublication::STATUS_CANCELLED],
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_DRAFT),
        );
        $this->assertSame(
            [ManualPublication::STATUS_IN_PROGRESS, ManualPublication::STATUS_CANCELLED],
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_READY),
        );
        $this->assertSame(
            [
                ManualPublication::STATUS_COMPLETED,
                ManualPublication::STATUS_FAILED,
                ManualPublication::STATUS_SKIPPED,
                ManualPublication::STATUS_CANCELLED,
            ],
            ManualPublication::allowedNextStatuses(ManualPublication::STATUS_IN_PROGRESS),
        );
    }

    public function test_failed_skipped_cancelled_can_reopen_to_ready(): void
    {
        foreach (ManualPublication::REOPENABLE_STATUSES as $status) {
            $this->assertSame(
                [ManualPublication::STATUS_READY],
                ManualPublication::allowedNextStatuses($status),
                "状态 [{$status}] 必须可重开到 ready",
            );
        }
    }

    public function test_completed_is_terminal(): void
    {
        $this->assertSame([], ManualPublication::allowedNextStatuses(ManualPublication::STATUS_COMPLETED));
    }

    public function test_is_reopen_transition_only_from_reopenable_states_to_ready(): void
    {
        $publication = new ManualPublication;

        $publication->status = ManualPublication::STATUS_FAILED;
        $this->assertTrue($publication->isReopenTransition(ManualPublication::STATUS_READY));

        $publication->status = ManualPublication::STATUS_COMPLETED;
        $this->assertFalse($publication->isReopenTransition(ManualPublication::STATUS_READY));

        $publication->status = ManualPublication::STATUS_DRAFT;
        $this->assertFalse($publication->isReopenTransition(ManualPublication::STATUS_READY));
    }

    public function test_can_transition_to_rejects_invalid_jumps(): void
    {
        $publication = new ManualPublication;

        // draft 不能直接跳到 completed
        $publication->status = ManualPublication::STATUS_DRAFT;
        $this->assertFalse($publication->canTransitionTo(ManualPublication::STATUS_COMPLETED));
        $this->assertFalse($publication->canTransitionTo(ManualPublication::STATUS_IN_PROGRESS));
        $this->assertTrue($publication->canTransitionTo(ManualPublication::STATUS_READY));

        // completed 之后哪儿都去不了
        $publication->status = ManualPublication::STATUS_COMPLETED;
        $this->assertFalse($publication->canTransitionTo(ManualPublication::STATUS_READY));
        $this->assertFalse($publication->canTransitionTo(ManualPublication::STATUS_CANCELLED));
    }
}
