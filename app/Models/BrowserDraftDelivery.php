<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BrowserDraftDelivery extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'request_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'expires_at' => 'datetime', 'verified_at' => 'datetime',
            'account_id' => 'integer', 'created_by' => 'integer', 'publication_revision' => 'integer', 'article_version' => 'integer'];
    }

    public function publicData(): array
    {
        return $this->only(['id', 'platform', 'status', 'publication_title', 'article_version', 'content_fingerprint',
            'account_id', 'account_identity', 'last_error', 'verified_at']) + [
                'draft_url' => $this->draft_id ? 'https://card.weibo.com/article/v5/editor#/draft/'.$this->draft_id : null,
                'method' => 'browser_extension_readback',
            ];
    }
}
