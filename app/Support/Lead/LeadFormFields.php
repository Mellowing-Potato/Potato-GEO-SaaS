<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Support/Lead/LeadFormFields.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Support\Lead;

use App\Models\LeadForm;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LeadFormFields
{
    private const RESERVED_FIELD_NAMES = [
        'source_url',
        'website',
    ];

    /**
     * 评审修复 A3：默认字段文案直接写死中文，不走 __()。
     * 原因有二：① 本仓 lang/ 下根本没有 admin.php，__() 落空后把裸 key
     * （admin.lead_forms.defaults.name）当成文案落库、印到公开页上；
     * ② 这些文案会随表单存进 lead_forms.fields，是「数据」不是「界面」——
     * 服务器 locale 一换、同一张表的默认值就漂移，不该有这种行为。
     *
     * @return list<array{name:string,label:string,type:string,required:bool,options:list<string>}>
     */
    public static function defaultFields(): array
    {
        return [
            ['name' => 'name', 'label' => '姓名', 'type' => 'text', 'required' => true, 'options' => []],
            ['name' => 'phone', 'label' => '电话', 'type' => 'phone', 'required' => false, 'options' => []],
            ['name' => 'email', 'label' => '邮箱', 'type' => 'email', 'required' => false, 'options' => []],
            ['name' => 'message', 'label' => '留言内容', 'type' => 'textarea', 'required' => true, 'options' => []],
        ];
    }

    /**
     * @param  array<int|string, mixed>  $postedFields
     * @return list<array{name:string,label:string,type:string,required:bool,options:list<string>}>
     */
    public static function normalizePosted(array $postedFields): array
    {
        $fields = [];
        foreach ($postedFields as $postedField) {
            if (! is_array($postedField)) {
                continue;
            }

            $label = trim((string) ($postedField['label'] ?? ''));
            $name = self::normalizeName((string) ($postedField['name'] ?? ''), $label);
            if ($name === '' && $label !== '') {
                $name = 'field_'.(count($fields) + 1);
            }

            $type = in_array((string) ($postedField['type'] ?? 'text'), LeadForm::FIELD_TYPES, true)
                ? (string) ($postedField['type'] ?? 'text')
                : 'text';
            $options = self::normalizeOptions($postedField['options'] ?? '');

            if ($label === '' || $name === '') {
                continue;
            }

            if (in_array($type, ['select', 'checkbox'], true) && $options === []) {
                // 评审修复 A3：同上，默认选项文案写死，不走不存在的翻译 key。
                $options = $type === 'checkbox' ? ['同意'] : ['选项一'];
            }

            $fields[] = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'required' => ! empty($postedField['required']),
                'options' => $options,
            ];
        }

        return array_values(collect($fields)->unique('name')->all());
    }

    /**
     * 按表单声明的字段校验访客提交；声明之外的字段一律丢弃（不存库、不回落）。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string|bool>
     */
    public static function validateSubmission(LeadForm $form, array $input): array
    {
        $rules = [];
        $attributes = [];
        $fields = $form->normalizedFields();

        foreach ($fields as $field) {
            $name = $field['name'];
            $attributes[$name] = $field['label'];
            $rules[$name] = self::rulesForField($field);
        }

        $validator = Validator::make($input, $rules, [], $attributes);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = $validator->validated();
        $payload = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            if ($field['type'] === 'checkbox') {
                $payload[$name] = ! empty($validated[$name]);

                continue;
            }

            $payload[$name] = trim((string) ($validated[$name] ?? ''));
        }

        return $payload;
    }

    private static function normalizeName(string $name, string $label): string
    {
        $raw = trim($name) !== '' ? trim($name) : Str::slug($label, '_');
        $raw = Str::lower(Str::ascii($raw));
        $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?? '';
        $raw = trim($raw, '_');
        if (in_array($raw, self::RESERVED_FIELD_NAMES, true)) {
            $raw .= '_field';
        }

        return mb_substr($raw, 0, 60);
    }

    /**
     * @return list<string>
     */
    private static function normalizeOptions(mixed $options): array
    {
        if (is_string($options)) {
            $options = preg_split('/\R|[|,，、]+/u', $options) ?: [];
        }

        if (! is_array($options)) {
            return [];
        }

        return collect($options)
            ->map(fn (mixed $option): string => trim((string) $option))
            ->filter()
            ->unique()
            ->values()
            ->take(20)
            ->all();
    }

    /**
     * @param  array{name:string,label:string,type:string,required:bool,options:list<string>}  $field
     * @return list<mixed>
     */
    private static function rulesForField(array $field): array
    {
        $rules = [$field['required'] ? 'required' : 'nullable'];

        if ($field['type'] === 'checkbox') {
            $rules[] = 'boolean';

            return $rules;
        }

        $rules[] = 'string';
        $rules[] = $field['type'] === 'textarea' ? 'max:5000' : 'max:500';

        if ($field['type'] === 'email') {
            $rules[] = 'email';
        }

        if ($field['type'] === 'phone') {
            $rules[] = 'regex:/^[0-9+\-\s().]{5,30}$/';
        }

        if ($field['type'] === 'select' && $field['options'] !== []) {
            $rules[] = Rule::in($field['options']);
        }

        return $rules;
    }
}
