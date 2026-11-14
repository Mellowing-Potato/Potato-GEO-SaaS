<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Models/LeadForm.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Models;

use App\Support\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class LeadForm extends Model
{
    use BelongsToTenant;

    private const RESERVED_FIELD_NAMES = [
        'source_url',
        'website',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** 通知报文形态：企业微信 / 钉钉群机器人 */
    public const NOTIFY_FORMAT_ROBOT = 'robot';

    /** 通知报文形态：结构化 JSON，给自建接收端 */
    public const NOTIFY_FORMAT_JSON = 'json';

    public const NOTIFY_FORMATS = [
        self::NOTIFY_FORMAT_ROBOT,
        self::NOTIFY_FORMAT_JSON,
    ];

    public const FIELD_TYPES = [
        'text',
        'phone',
        'email',
        'textarea',
        'select',
        'checkbox',
    ];

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'status',
        'description',
        'submit_button_label',
        'success_message',
        'fields',
        'notify_webhook_url',
        'notify_webhook_format',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'submit_button_label' => '提交',
        'notify_webhook_format' => self::NOTIFY_FORMAT_ROBOT,
    ];

    /**
     * webhook 地址本身就是凭据 —— 企业微信机器人的地址里带 key，谁拿到谁就能往群里发消息。
     * 所以它只写不读：列表/详情接口一律回掩码版，日志里也只准出现 host。
     */
    protected $hidden = [
        'notify_webhook_url',
    ];

    protected function casts(): array
    {
        return [
            'fields' => 'array',
        ];
    }

    public function notifiesOnSubmission(): bool
    {
        return trim((string) $this->notify_webhook_url) !== '';
    }

    /**
     * 掩码：只留协议、host 和路径末段的头 4 个字符，其余打点。
     * 目的是让租户能认出「我填的是哪个群」，同时拿不回完整凭据。
     */
    public function maskedNotifyWebhookUrl(): ?string
    {
        $url = trim((string) $this->notify_webhook_url);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return '（地址格式异常）';
        }

        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        // query 里往往就是 key=xxx，一个字符都不回显。
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $tail = ltrim(substr(strrchr($path, '/') ?: $path, 1), '/');
        $hint = $tail === '' ? '' : '/'.mb_substr($tail, 0, 4).'…';

        return $scheme.'://'.$host.$hint;
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(LeadSubmission::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return list<array{name:string,label:string,type:string,required:bool,options:list<string>}>
     */
    public function normalizedFields(): array
    {
        return collect(is_array($this->fields) ? $this->fields : [])
            ->filter(static fn (mixed $field): bool => is_array($field))
            ->values()
            ->map(static function (array $field, int $index): array {
                $type = in_array((string) ($field['type'] ?? 'text'), self::FIELD_TYPES, true)
                    ? (string) ($field['type'] ?? 'text')
                    : 'text';
                $label = trim((string) ($field['label'] ?? ''));
                $name = self::normalizeFieldName((string) ($field['name'] ?? ''), $label, $index);

                return [
                    'name' => $name,
                    'label' => $label,
                    'type' => $type,
                    'required' => ! empty($field['required']),
                    'options' => collect($field['options'] ?? [])
                        ->map(fn (mixed $option): string => trim((string) $option))
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $field): bool => $field['name'] !== '' && $field['label'] !== '')
            ->unique('name')
            ->values()
            ->all();
    }

    private static function normalizeFieldName(string $name, string $label, int $index): string
    {
        $raw = trim($name) !== '' ? trim($name) : Str::slug($label, '_');
        $raw = Str::lower(Str::ascii($raw));
        $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?? '';
        $raw = trim($raw, '_');

        if ($raw === '' && $label !== '') {
            $raw = 'field_'.($index + 1);
        }

        if (in_array($raw, self::RESERVED_FIELD_NAMES, true)) {
            $raw .= '_field';
        }

        return mb_substr($raw, 0, 60);
    }
}
