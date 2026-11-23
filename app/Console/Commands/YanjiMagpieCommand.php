<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Outbound\LocalMagpieDiscovery;
use App\Support\LocalMagpieAuthorization;
use App\Support\TenantContext;
use Illuminate\Console\Command;

final class YanjiMagpieCommand extends Command
{
    protected $signature = 'yanji:magpie {--tenant= : 实际工作区 ID} {--probe : 明确同意本次本机窄探测} {--authorize : 探测成功后授权该工作区}';
    protected $description = '可选探测本机 Magpie，仅 GET 127.0.0.1:3425/api/hello，不调用模型';

    public function handle(LocalMagpieDiscovery $discovery, LocalMagpieAuthorization $authorization): int
    {
        $id = filter_var($this->option('tenant'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->error('请用 --tenant 指定已存在的工作区 ID。');
            return self::FAILURE;
        }
        $consent = (bool) $this->option('probe');
        if ($this->option('authorize') && ! $consent) {
            $this->error('--authorize 必须与 --probe 一起明确指定。未发送请求。');
            return self::FAILURE;
        }
        if (! $consent && $this->input->isInteractive()) {
            $consent = $this->confirm('是否仅探测 http://127.0.0.1:3425/api/hello？不携带密钥，不调用模型。', false);
        }
        if (! $consent) {
            $this->info('已跳过 Magpie 探测，第三方直连配置保持可用。');
            return self::SUCCESS;
        }
        $previous = TenantContext::get();
        try {
            $binding = \App\Support\InstanceKey::assertReady($this->laravel->environmentFilePath(), true);
            $tenant = Tenant::find($id);
            if ($tenant === null) {
                $this->error('请用 --tenant 指定已存在的工作区 ID。');
                return self::FAILURE;
            }
            TenantContext::set($tenant);
            $result = $discovery->probe(true);
            $this->info('已检测到本机 Magpie '.$result['version'].'。');
            $grant = (bool) $this->option('authorize');
            if (! $grant && $this->input->isInteractive()) {
                $grant = $this->confirm('是否授权工作区 '.$tenant->id.' 使用该本机服务？不会替换或激活已保存的模型。', false);
            }
            if ($grant) {
                $authorization->grant($this->laravel->environmentFilePath(), (int) $tenant->id, $binding);
                $this->call('config:clear');
                if (is_file($this->laravel->getCachedConfigPath())) {
                    throw new \RuntimeException('实例配置缓存无法失效。');
                }
                $this->info('已合并工作区授权。请重启本实例，再到模型配置页保存文本或视觉模型。');
            }
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof \App\Services\AI\MagpieException
                ? $exception->getMessage() : '未能安全完成 Magpie 授权，未修改模型设置。');
            return self::FAILURE;
        } finally {
            $previous === null ? TenantContext::forget() : TenantContext::set($previous);
        }
    }
}
