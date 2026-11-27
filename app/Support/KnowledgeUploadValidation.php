<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** Reject incomplete oversized uploads before hashing or entering ingestion. */
final class KnowledgeUploadValidation
{
    public const MAX_UPLOAD_KB = 10240;

    public static function sizeError(Request $request): ?string
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        // PHP discards the whole form above post_max_size. Do not misreport
        // that as “no file selected”, or hash the empty form as a valid upload.
        $postDiscarded = $contentLength > 0
            && $request->post() === [] && $request->allFiles() === [];
        // file() converts Symfony's upload into Illuminate's UploadedFile.
        $file = $request->file('file');
        $tooLarge = $file instanceof UploadedFile && $file->getError() === UPLOAD_ERR_INI_SIZE;
        if (! $postDiscarded && ! $tooLarge) {
            return null;
        }

        $limitMb = intdiv(self::MAX_UPLOAD_KB, 1024);
        $size = $contentLength >= 1048576
            ? round($contentLength / 1048576, 1).' MB'
            : max(1, (int) round($contentLength / 1024)).' KB';

        return $contentLength > 0
            ? sprintf('文件约 %s，超过 %d MB 上限，请压缩或拆分后再传。', $size, $limitMb)
            : sprintf('文件超过 %d MB 上限，请压缩或拆分后再传。', $limitMb);
    }
}
