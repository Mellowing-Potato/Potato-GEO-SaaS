<?php
/**
 * 平台级敏感词基线库 · 候选清单（唯一权威数据源 · 候选态）
 *
 * ⚠️ 候选态：签字之前，本文件只是候选，不建迁移、不灌库、不写真实政治/违法词到任何持久化层。
 *    法务/老板在由本文件生成的 docs/敏感词基线-候选清单-2026-08-31.md 上签字后，
 *    才由独立的 seeder 从本文件读取灌库（读同一份，杜绝两份不一致）。
 *
 * 词源（老板第 3 条 / 派单 §四.2）：以公开维护的中文敏感词表为起点。
 *   暂定上游：ffinly/sensitive-stop-words（Apache-2.0，已分 色情/政治/涉枪涉爆 等类）。
 *   ⚠️ 当前环境的代理无法直连 GitHub 自动抓取，本批只放入「可逐条溯源」的非法类种子
 *      （毒品/枪爆/赌博/色情，依《刑法》《枪支管理法》及上游词表类别），政治类留空待补充。
 *      政治类完整词表需老板提供词表文件或确认可抓取来源后，由工兵补全并经法务定稿。
 *
 * 字段：
 *   word      —— 词本身，严禁含空格/标点（扫描器对内容去分隔符折叠，但词本身带分隔符会让折叠退化）
 *   category  —— politics（政治时政）| illegal（违法暴力：毒品/枪爆/赌博/色情）
 *   severity  —— blocked（本批只收 blocking 级）
 *   source    —— 该词出自哪份公开词表的哪个文件（可溯源）
 *   note      —— 为什么留它 / 依据
 */

return [
    // ───────────────────────── 非法类 · 毒品 ─────────────────────────
    ['word' => '海洛因', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '麻醉类毒品，《刑法》第347条明确禁制'],
    ['word' => '冰毒', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '甲基苯丙胺，法定违禁毒品'],
    ['word' => '摇头丸', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '苯丙胺类毒品'],
    ['word' => 'K粉', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '氯胺酮俗称，法定管制'],
    ['word' => '可卡因', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '法定违禁毒品'],
    ['word' => '吗啡', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '阿片类毒品'],
    ['word' => '鸦片', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '阿片类毒品原植物制品'],
    ['word' => '大麻', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '受管制毒品（依属地法律）'],
    ['word' => '芬太尼', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '强效合成阿片，法定严管'],
    ['word' => '麻古', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '冰毒片剂，甲基苯丙胺类'],
    ['word' => '氯胺酮', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '第一类精神药品，法定管制'],
    ['word' => '罂粟', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '毒品原植物，禁种禁制'],
    ['word' => '苯丙胺', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '中枢兴奋剂类毒品'],
    ['word' => '美沙酮', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '麻醉镇痛类管制药品'],
    ['word' => '曲马多', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '第二类精神药品，滥用属违法'],

    // ───────────────────────── 非法类 · 枪爆 ─────────────────────────
    ['word' => '手枪', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '《枪支管理法》管制枪支'],
    ['word' => '步枪', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '管制枪支'],
    ['word' => '冲锋枪', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '管制枪支'],
    ['word' => '机枪', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '管制枪支'],
    ['word' => '子弹', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '弹药'],
    ['word' => '炸药', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '易爆物，严管'],
    ['word' => '雷管', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '爆炸物引信'],
    ['word' => '手榴弹', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '军用爆炸物'],
    ['word' => '炸弹', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '爆炸装置'],
    ['word' => '枪支', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '《枪支管理法》管制'],
    ['word' => '猎枪', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '民用猎枪亦属管制'],
    ['word' => '气枪', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '仿真/气压枪支属管制'],
    ['word' => '管制刀具', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '管制器具'],
    ['word' => '弩', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '管制器具'],
    ['word' => '黑火药', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '易制爆化学品'],
    ['word' => '火药', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '易制爆化学品'],

    // ───────────────────────── 非法类 · 赌博 ─────────────────────────
    ['word' => '赌博', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '《刑法》第303条赌博罪'],
    ['word' => '赌场', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '赌博场所'],
    ['word' => '博彩', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '赌博性博彩'],
    ['word' => '百家乐', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '常见赌博玩法'],
    ['word' => '老虎机', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '赌博机具'],
    ['word' => '六合彩', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '非法私彩'],
    ['word' => '外围赌球', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '非法体育赌博'],
    ['word' => '网络赌博', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '线上赌博'],
    ['word' => '私彩', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '非法彩票'],
    ['word' => '赌球', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '体育赌博'],
    ['word' => '赌债', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 涉枪涉爆违法信息关键词.txt', 'note' => '赌博衍生非法债务'],

    // ───────────────────────── 非法类 · 色情 ─────────────────────────
    ['word' => '色情', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '淫秽色情内容'],
    ['word' => '淫秽', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '《刑法》第364条淫秽物品'],
    ['word' => '裸聊', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '涉黄直播/聊天'],
    ['word' => '黄片', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '淫秽影片'],
    ['word' => '成人影片', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '涉黄影视'],
    ['word' => '春药', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '违禁性药'],
    ['word' => '性爱视频', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '涉黄影像'],
    ['word' => '招嫖', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '卖淫招揽'],
    ['word' => '卖淫', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '违法色情交易'],
    ['word' => '嫖娼', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '违法色情交易'],
    ['word' => '色情网站', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '涉黄站点'],
    ['word' => '成人内容', 'category' => 'illegal', 'severity' => 'blocked', 'source' => 'sensitive-stop-words (Apache-2.0) · 色情类.txt', 'note' => '涉黄内容'],

    // ───────────────────────── 政治类 · 待补充 ─────────────────────────
    // 政治类当前为空：本环境代理无法直连 GitHub 自动抓取上游公开词表，
    // 且老板要求「不许凭印象编 / 来源说不清的不要收」。政治类完整词表须由老板提供词表文件
    // 或确认可抓取来源后，由工兵补全并经法务定稿，再生成签字版 markdown。
];
