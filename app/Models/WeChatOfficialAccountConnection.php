<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class WeChatOfficialAccountConnection extends Model
{
    use BelongsToTenant;

    protected $table = 'wechat_official_account_connections';

    protected $guarded = ['id'];

    protected $hidden = ['app_secret'];

    protected function casts(): array
    {
        return ['app_secret' => 'encrypted', 'verified_at' => 'datetime'];
    }

    public function publicData(): array
    {
        return $this->only(['id', 'account_id', 'app_id', 'author', 'revision', 'verified_at']);
    }
}
