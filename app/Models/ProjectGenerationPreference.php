<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ProjectGenerationPreference extends Model
{
    use BelongsToTenant;
    protected $fillable = ['tenant_id', 'project_id', 'skill_id', 'preferences'];
    protected $casts = ['project_id' => 'integer', 'preferences' => 'array'];
}
