<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 修正 knowledge_documents.metadata.extraction.cost_fen 的单位错标（虚高 100 倍）。
 *
 * 病因与可见性监测那处同源：网关扣点回执里的 points_deducted 单位是「积分」，
 * 而这个键名带 _fen、单位是「分」，1 分 = 100 积分。旧码按「1 点 = 1 分」直存，
 * 于是 ¥0.076 的实扣被记成了 7.60 元。
 *
 * 回填方式：**不做「除以 100」这种一次性换算**，而是回到权威原始值重算 ——
 * 同一笔扣费在 model_call_logs.metadata.points_deducted 里留着原始积分整数
 * （assist_kind='ai-assist-kb-vision'，document_id 指回文档）。
 * 这样这个迁移可以重复执行结果不变，也不怕有人在上线前先手工改过某一行。
 *
 * 顺带把原始积分补进 metadata.extraction.points_deducted（与新码写法一致），
 * 让「钱包实际扣点与文档 metadata 对得上」这条验收不用心算换算。
 *
 * JSON 一律在 PHP 里解析，不用 PG / SQLite 各自的 JSON 操作符（CI 跑 sqlite）。
 */
return new class extends Migration
{
    /** 网关积分口径：1 分 = 100 积分（与 GeoPointBalanceGate::POINTS_PER_FEN 同源）。 */
    private const POINTS_PER_FEN = 100;

    public function up(): void
    {
        $pointsByDocument = $this->rawPointsByDocument();

        $fixed = 0;
        $skipped = 0;

        DB::table('knowledge_documents')
            ->select('id', 'metadata')
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($pointsByDocument, &$fixed, &$skipped) {
                foreach ($rows as $row) {
                    $metadata = $this->decode($row->metadata);
                    if (! is_array($metadata) || ! isset($metadata['extraction']) || ! is_array($metadata['extraction'])) {
                        continue;
                    }
                    if (! array_key_exists('cost_fen', $metadata['extraction'])) {
                        continue;
                    }
                    if ($metadata['extraction']['cost_fen'] === null) {
                        continue; // 当时没扣成，本来就是 null，没有单位问题。
                    }

                    $points = $pointsByDocument[(int) $row->id] ?? null;
                    if (! is_numeric($points)) {
                        // 找不到那笔扣费流水就不猜 —— 宁可留着让人查，也不写一个编出来的数。
                        $skipped++;

                        continue;
                    }

                    $correctFen = round((float) $points / self::POINTS_PER_FEN, 2);
                    $alreadyCorrect = abs($correctFen - (float) $metadata['extraction']['cost_fen']) < 0.005
                        && ($metadata['extraction']['points_deducted'] ?? null) === (int) $points;

                    if ($alreadyCorrect) {
                        continue; // 新码写的行，或者已经回填过。
                    }

                    $metadata['extraction']['cost_fen'] = $correctFen;
                    $metadata['extraction']['points_deducted'] = (int) $points;

                    DB::table('knowledge_documents')
                        ->where('id', $row->id)
                        ->update(['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)]);
                    $fixed++;
                }
            });

        Log::info('Fixed knowledge_documents.metadata.extraction.cost_fen unit (points → fen).', [
            'rows_fixed' => $fixed,
            'rows_skipped_no_charge_log' => $skipped,
        ]);
    }

    public function down(): void
    {
        // 不回滚：把正确的分再乘回 100 只会重新制造那个 100 倍的错值。
    }

    /**
     * 从多模态扣费流水里取每篇文档的原始积分。
     *
     * @return array<int, float|int>
     */
    private function rawPointsByDocument(): array
    {
        $map = [];

        DB::table('model_call_logs')
            ->select('id', 'metadata')
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$map) {
                foreach ($rows as $row) {
                    $metadata = $this->decode($row->metadata);
                    if (! is_array($metadata)) {
                        continue;
                    }
                    if (($metadata['assist_kind'] ?? null) !== 'ai-assist-kb-vision') {
                        continue;
                    }
                    $documentId = $metadata['document_id'] ?? null;
                    $points = $metadata['points_deducted'] ?? null;
                    if (! is_numeric($documentId) || ! is_numeric($points)) {
                        continue;
                    }
                    // 同一篇文档若有多笔（重试），取 id 最大的那笔 —— chunk 按 id 升序，直接覆盖。
                    $map[(int) $documentId] = $points;
                }
            });

        return $map;
    }

    private function decode(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true) : $value;
    }
};
