import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { readEntrySource } from './helpers/entry-source.mjs';
import { workbenchTemplateSource } from './helpers/workbench-templates.mjs';

/**
 * 账户注销 · 前端契约（计划 §9.5；用户口径 2026-09-21）。
 *
 * 这些断言守的不是"有没有这段代码"，而是**前后端约定不许各写一份**：
 * 端点路径、字段名、确认文案、冷静期天数来源，两边漂一个就是"点了没反应"或"以为提交了其实没提交"。
 * 服务端口径在 tests/Feature/AccountDeletionRequestTest.php，这里只管前端那一半。
 */
const entry = readEntrySource();
const template = workbenchTemplateSource();

// 1. 端点与字段名：与 routes/api.php + AuthController::requestDeletion 逐字对齐
assert.match(entry, /api\('\/api\/v1\/account\/deletion'\)/, '注销状态读取端点丢了');
assert.match(entry, /api\('\/api\/v1\/account\/deletion',\s*\{\s*\n\s*method:\s*'POST'/, '注销申请 POST 端点丢了');
assert.match(entry, /confirm_text:\s*'注销账户'/, '确认文案字段名/取值与后端校验不一致');
assert.match(entry, /payload\.password\s*=\s*password/, '密码确认没有随请求发出');

// 2. 表单与状态槽必须在页面上（改 id 等于页面变成哑巴，测试要当场红）
for (const id of ['geoDeletionForm', 'geoDeletionConfirm', 'geoDeletionPassword', 'geoDeletionSubmit', 'geoDeletionStatus', 'geoDeletionPending', 'geoDeletionPendingMessage', 'geoDeletionMessage', 'geoDeletionGraceDays']) {
  assert.ok(template.includes(`id="${id}"`), `账户注销缺少 #${id}`);
}

// 3. 冷静期天数只能来自服务端响应，不许前端写死一个数字（写死就会与 config 漂）
assert.match(entry, /Number\(data\.grace_days\)/, '冷静期天数没有读服务端返回的 grace_days');
assert.ok(!/还有 15 天/.test(entry), '冷静期天数被写死在文案里');

// 4. 确认门槛：必须逐字填「注销账户」，且提交前有二次确认弹窗
assert.match(entry, /!==\s*'注销账户'/, '逐字确认没有被前端拦住');
assert.match(entry, /confirmWorkspaceAction/, '提交注销前没有二次确认');

// 5. 报错不许吞：状态读不到就撤下表单（宁可暂时不可用，也不能让人以为提交成功了）
assert.match(entry, /form\.hidden = true;\s*\n\s*pending\.hidden = true;/m, '状态读取失败时没有撤下表单');
assert.match(entry, /geoDeletionUnavailable/, '状态读取失败没有给出可用性提示');

// 6. 注册进"账号安全"这一页的加载器：进页面必须回读真实状态
assert.match(entry, /registerViewLoader\('account-security',\s*loadAccountDeletion\)/, '注销状态没有挂到账号安全页的加载器');

// 7. 「本次登录撤销了注销申请」的提示要贯穿三条路径：两条登录路径各写一次标记，
//    工作台侧读一次、提示一次、清掉（不清就会出现"刷新又弹一遍"的假提示）。
const loginPage = readFileSync(new URL('../../public/geo-login.js', import.meta.url), 'utf8');
assert.match(loginPage, /if \(result\.account_restored\) sessionStorage\.setItem\('geo_account_restored'/, '登录页没有把"刚恢复过"记录下来（客户会以为注销申请莫名消失）');
assert.match(entry, /sessionStorage\.setItem\("geo_account_restored",\s*payload\.account_restored_at/, '工作台登录路径（persistSession）没有记录"刚恢复过"');
assert.match(entry, /consumeAccountRestoredNotice\(\)/, '没有人消费"刚恢复过"这个标记 —— 提示永远不出现');
assert.match(entry, /sessionStorage\.removeItem\(RESTORED_NOTICE_KEY\)/, '标记没有被清掉 —— 刷新会重复弹提示');
assert.match(entry, /注销申请已撤销，账户已恢复。/, '恢复提示的文案丢了');

console.log('账户注销前端契约成立：端点/字段/确认文案/冷静期来源/恢复提示与服务端一致。');
