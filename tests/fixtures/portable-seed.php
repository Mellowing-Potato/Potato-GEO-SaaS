<?php

use App\Models\KnowledgeBase;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Models\TenantAiKey;
use App\Models\Task;
use App\Models\User;
use App\Services\Auth\LocalAccounts;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Yanji\Portable\Instance;

[, $root, $action] = $argv;
if (!is_file($root.'/.portable-acceptance')) {
    throw new RuntimeException('This fixture requires an acceptance-test instance.');
}
$release = realpath($root.'/current');
require $release.'/app/portable/src/Instance.php';
$instance = new Instance($root);
foreach ($instance->environment() as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
require $release.'/app/vendor/autoload.php';
$app = require $release.'/app/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$user = User::withoutGlobalScopes()->where('role', 'platform_admin')->firstOrFail();
TenantContext::set($user->tenant);
if ($action === 'verify-key') {
    $saved = TenantAiKey::withoutGlobalScopes()->where('tenant_id',$user->tenant_id)->where('slot','embedding')->firstOrFail();
    exit(hash_equals('sk-test-portable-embedding', $saved->getDecryptedKey()) ? 0 : 1);
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$other = app(LocalAccounts::class)->create('other@example.com', $input['other_password'], 'Test');
$project = Project::forceCreate(['tenant_id'=>$user->tenant_id,'owner_id'=>$user->id,'name'=>'Fixture project','status'=>'active']);
$task = Task::create(['tenant_id'=>$user->tenant_id,'project_id'=>$project->id,'user_id'=>$user->id,
    'name'=>'Fixture schedule','skill_id'=>Project::GEO_ANALYSIS_SKILL_ID,'cadence'=>'daily','status'=>'active',
    'next_run_at'=>now()->subMinute(),'query'=>'fixture','auto_publish'=>false,'auto_distribute'=>false]);
$report = SkillExecution::forceCreate(['tenant_id'=>$user->tenant_id,'user_id'=>$user->id,'project_id'=>$project->id,
    'skill_id'=>Project::GEO_ANALYSIS_SKILL_ID,'skill_name'=>'Fixture report','status'=>'completed',
    'category'=>'geo-strategy','input_data'=>[],'output_data'=>[]]);
$path = 'executions/'.$report->id.'/report.md';
$report->update(['output_data'=>['markdown'=>$path]]);
Storage::disk('reports')->put($path, 'owner-fixture');
$base = KnowledgeBase::create(['tenant_id'=>$user->tenant_id,'project_id'=>$project->id,'name'=>'Fixture knowledge','status'=>'active']);
$port = filter_var($input['embedding_port'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
if (!$port) {
    throw new RuntimeException('Invalid loopback fixture port');
}
TenantAiKey::create(['tenant_id'=>$user->tenant_id,'provider'=>'custom','slot'=>'embedding','is_active'=>true,
    'encrypted_key'=>'sk-test-portable-embedding','metadata'=>['base_url'=>'http://127.0.0.1:'.$port.'/v1',
    'model'=>'fixture-embedding','dimensions'=>1024]]);
echo json_encode(['report'=>$report->id,'task'=>$task->id, 'kb'=>$base->id,'project'=>$project->id,'tenant'=>$user->tenant->subdomain,
    'other_tenant'=>$other->tenant->subdomain], JSON_THROW_ON_ERROR)."\n";
