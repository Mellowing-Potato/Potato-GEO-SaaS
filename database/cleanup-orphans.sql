-- ============================================================================
-- GEO 测试孤儿数据清理脚本（放量前收尾 T2）
-- ----------------------------------------------------------------------------
-- ⚠️ 执行纪律（照执行书）：
--   1) 本脚本由 ops 在服务器 `docker exec geo-saas` 内执行；
--   2) 执行前必须先备份（见下方 BACKUP 段），AI 不执行、不 push；
--   3) 清单（渠道 1,18 / 文章 1,6）需经理逐条拍板后方可执行；
--   4) 保留文章 2,3,4,5 默认不动；若审计发现其牵连测试渠道，见下方 RISK 段。
--
-- 作用对象（默认，按执行书现状锚点 2026-07-21）：
--   测试渠道：publishing_channels id ∈ (1, 18)
--   待删文章：articles            id ∈ (1, 6)
--   保留文章：articles            id ∈ (2, 3, 4, 5)  —— 不删
-- ============================================================================

-- ------------------------- 0) 备份（先执行） -------------------------------
-- 在容器内确认 DB 路径（默认 database/database.sqlite），然后：
--   sqlite3 "$DB" ".backup 'storage/app/backups/geo-saas-precleanup-$(date +%Y%m%d-%H%M%S).sqlite'"
-- 或导出选中行 JSON 备查（AI 侧已提供 geo:audit-orphans 只读盘点）。

-- ------------------------- 1) 删除前复核（DRY RUN） -------------------------
-- 先确认行数符合预期，再进入第 2 段。如结果与预期不符，STOP 并回报经理。
SELECT 'channels(1,18)'            AS obj, COUNT(*) AS rows FROM publishing_channels WHERE id IN (1,18);
SELECT 'articles(1,6)'             AS obj, COUNT(*) AS rows FROM articles            WHERE id IN (1,6);
SELECT 'articles(2,3,4,5) keep'    AS obj, COUNT(*) AS rows FROM articles            WHERE id IN (2,3,4,5);
SELECT 'ap_by_test_channel'        AS obj, COUNT(*) AS rows FROM article_publications WHERE publishing_channel_id IN (1,18);
SELECT 'ap_by_keep_article'        AS obj, COUNT(*) AS rows FROM article_publications WHERE article_id IN (2,3,4,5) AND publishing_channel_id IN (1,18);
SELECT 'ap_by_delete_article'      AS obj, COUNT(*) AS rows FROM article_publications WHERE article_id IN (1,6);

-- ------------------------- 2) 实际删除（确认后执行） ------------------------
-- 外键说明（保持 foreign_keys=ON，不关护栏）：
--   article_publications 的三条外键 (article_id / publishing_channel_id / tenant_id)
--   均为 ON DELETE CASCADE，但方向都是「删父表 → 删本 join 行」，不会反向删到 articles。
--   因此删测试渠道 (1,18) 只会连带清掉这些渠道上的 publication 行，
--   保留文章 2,3,4,5 结构上不可能被误删。无需关闭外键，全程保持完整性护栏。

PRAGMA foreign_keys=ON;

-- 2.1 先清日志类（distribution_logs 外键均为 SET NULL，显式删相关行更干净）
DELETE FROM distribution_logs
  WHERE publishing_channel_id IN (1,18)
     OR article_id IN (1,6);

-- 2.2 删待删文章(1,6)的发布记录，以及测试渠道(1,18)上的发布记录
--     （join 表行的删除不会级联删 article，故保留文章 2,3,4,5 安全）
DELETE FROM article_publications
  WHERE article_id IN (1,6)
     OR publishing_channel_id IN (1,18);

-- 2.3 删渠道密钥、项目-渠道绑定
DELETE FROM distribution_channel_secrets WHERE publishing_channel_id IN (1,18);
DELETE FROM project_publishing_channels WHERE publishing_channel_id IN (1,18);

-- 2.4 删渠道本身（其残余 publication 若有，会按 CASCADE 自动清；本行不删 articles）
DELETE FROM publishing_channels WHERE id IN (1,18);

-- 2.5 删待删文章本体
DELETE FROM articles WHERE id IN (1,6);

-- ------------------------- 3) 删除后校验 ------------------------------------
-- 预期：下面查询均应为 0 行；保留文章 2,3,4,5 仍在。
SELECT COUNT(*) AS remaining_channels FROM publishing_channels WHERE id IN (1,18);  -- 期望 0
SELECT COUNT(*) AS remaining_articles FROM articles            WHERE id IN (1,6);     -- 期望 0
SELECT COUNT(*) AS keep_still_there  FROM articles            WHERE id IN (2,3,4,5); -- 期望 4

-- ------------------------- 4) 远端 WordPress 帖（ops 手动） -----------------
-- 由 geo:audit-orphans 输出的 wp_remote_posts（remote_id 列表），
-- ops 在 WP 后台将这些历史 E2E 帖移入回收站或删除。本 SQL 不触碰 WP 库。

-- ============================================================================
-- 说明（若 DRY RUN 显示 ap_by_keep_article > 0）：
--   仅表示保留文章曾发布到测试渠道。第 2.2 步删除的是「测试渠道上的 publication 行」，
--   join 表行的删除不会级联删 article，故保留文章本体始终安全，无需任何解耦操作。
--   唯一验收铁证：第 3 段 keep_still_there = 4。若不为 4，STOP 并回滚（用第 0 段备份）。
-- ============================================================================
