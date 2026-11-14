<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KeywordLibrary extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id',
        'keyword',
        'note',
        'category',
        'search_volume',
        'usage_count',
    ];

    protected function casts(): array
    {
        return [
            'search_volume' => 'integer',
            'usage_count' => 'integer',
        ];
    }
}
