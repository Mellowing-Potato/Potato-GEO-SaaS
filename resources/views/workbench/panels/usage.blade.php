<section class="panel workspace-view" id="usagePanel" data-workspace-view="usage" hidden>
    <div class="panel-heading">
        <div><p class="eyebrow">用量</p><h2>用量明细</h2></div>
        <button class="button secondary small" id="reloadUsageButton" type="button">刷新</button>
    </div>
    <p class="muted" id="usageMessage" role="status">模型调用次数与 tokens。</p>
    <label for="usagePeriod">统计期间</label>
    <select id="usagePeriod"><option value="month">本月</option><option value="day">今天</option><option value="week">本周</option><option value="year">本年</option><option value="all">全部</option></select>
    <div id="usageSummary" class="usage-cards"></div>
    <div id="usageCalls"></div>
    <div class="pagination"><button id="usagePrevious" type="button" disabled>上一页</button><span id="usagePage"></span><button id="usageNext" type="button" disabled>下一页</button></div>
</section>
