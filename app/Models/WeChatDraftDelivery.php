<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WeChatDraftDelivery extends Model
{
    use BelongsToTenant;

    protected $table = 'wechat_draft_deliveries';

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'cover_path'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'sent_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function publicData(): array
    {
        $data = $this->only([
            'id', 'publication_id', 'account_id', 'app_id', 'status', 'publication_title',
            'content_fingerprint', 'article_version', 'media_id', 'last_error', 'sent_at', 'verified_at',
        ]);
        if ($this->status === 'sending' && $this->updated_at->lt(now()->subMinutes(6))) {
            $data['status'] = 'unknown';
            $data['last_error'] = '发送任务中断，请核对微信草稿结果，勿重复发送。';
        }
        if ($this->status === 'preparing' && $this->updated_at->lt(now()->subMinutes(6))) {
            $data['status'] = 'failed';
            $data['last_error'] = '草稿准备任务中断，尚未创建草稿，可以重试。';
        }

        return $data;
    }
}
