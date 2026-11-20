<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

final class ModelSlotBinding extends Model
{
    use BelongsToTenant;

    public const SLOTS = ['text', 'vision'];

    protected $fillable = ['tenant_id', 'slot', 'source_type', 'profile_id'];
}
