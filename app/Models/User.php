<?php

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use App\Support\Permissions;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'phone_verified_at',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mengde_user_id', // Retained historical column; never exposed or used for authentication.
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            // 账户注销冷静期（计划 §9.5）：三个时间戳都要按日期对象读，
            // 否则 status()/测试里拿到的是字符串，算剩余天数得自己 parse。
            'deletion_requested_at' => 'datetime',
            'deletion_purge_at' => 'datetime',
            'deletion_purged_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['admin', 'platform_admin'], true);
    }

    /**
     * 检查用户是否有某个权限（自建 RBAC，不走 Gate）
     */
    public function hasPermission(string $permission): bool
    {
        return Permissions::allows($this->role, $permission);
    }

    /**
     * 检查用户是否有某组权限（全部满足）
     */
    public function hasAllPermissions(array $permissions): bool
    {
        return Permissions::allowsAll($this->role, $permissions);
    }

    /**
     * 获取用户拥有的所有权限列表
     */
    public function getPermissions(): array
    {
        return Permissions::getPermissionsForRole($this->role);
    }
}
