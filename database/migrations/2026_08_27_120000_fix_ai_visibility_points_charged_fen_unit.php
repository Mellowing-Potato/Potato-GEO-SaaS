<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 修复 ai_visibility_runs.points_charged_fen 的单位错标（虚高 100 倍）。
 *
 * 病因：AiVisibilityService::chargePoints() 曾按「积分与分 1:1」把网关回执里的
 * points_deducted（单位是积分）直存进这一列（单位是分）。网关口径是
 * 1 分 = 100 积分，同一笔流水里 charged_points=292 与 charged_fen=2.9165 本来就是一个数。
 * 结果客户看到 292 分＝¥2.92，实扣只有 2.9165 分＝¥0.029。
 *
 * 回填方式：不做「除以 100」这种一次性换算，而是从 metadata.points_deducted
 * （原始积分整数，与钱包流水同源）重算。这样迁移可重复执行、结果恒等，
 * 也不怕有人在上线前先手工改过某一行——重算只会把它算回同一个正确值。
 *
 * 生产实查（2026-08-27）：只有 run id=1 一行（292.00 → 2.92）。
 */
return new class extends Migration
{
    /** 网关口径：1 分 = 100 积分（同 GeoPointBalanceGate::POINTS_PER_FEN）。 */
    private const POINTS_PER_FEN = 100;

    public function up(): void
    {
        $fixed = 0;
        $skipped = 0;

        DB::table('ai_visibility_runs')
            ->select('id', 'points_charged_fen', 'metadata')
            ->whereNotNull('points_charged_fen')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$fixed, &$skipped) {
                foreach ($rows as $row) {
                    $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : $row->metadata;
                    $points = is_array($metadata) ? ($metadata['points_deducted'] ?? null) : null;

                    if (! is_numeric($points)) {
                        // 拿不到原始积分就不猜——宁可留着让人查，也不写一个编出来的数。
                        $skipped++;

                        continue;
                    }

                    $correctFen = round((float) $points / self::POINTS_PER_FEN, 2);
                    if (abs($correctFen - (float) $row->points_charged_fen) < 0.005) {
                        continue; // 已经是对的（新码写的行）。
                    }

                    DB::table('ai_visibility_runs')
                        ->where('id', $row->id)
                        ->update(['points_charged_fen' => $correctFen]);
                    $fixed++;
                }
            });

        Log::info('Fixed ai_visibility_runs.points_charged_fen unit (points → fen).', [
            'rows_fixed' => $fixed,
            'rows_skipped_no_raw_points' => $skipped,
        ]);
    }

    public function down(): void
    {
        // 不回滚：把正确的分再乘回 100 只会重新制造那个 100 倍的错值。
    }
};
