@php
    $workspaceTopbarSpa = ($mode ?? 'spa') === 'spa';
    $workspaceDesktopMode = \App\Support\PortableDesktopAccess::enabled();
    $workspaceHelpUrl = trim((string) config('brand.help_url', ''));
    $workspaceWebsiteUrl = (string) config('instance.website_url', '');
    $workspaceSearchIndex = [];
    foreach (\App\Support\WorkspaceNavigation::menus() as $menu => $groups) {
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if ($item['hidden'] || ($item['flag'] && !config('geo.'.$item['flag'], false))) continue;
                if ($workspaceDesktopMode && $item['view'] === 'account-security') continue;
                $workspaceSearchIndex[] = [
                    'label' => $item['label'], 'view' => $item['view'], 'page' => $item['page'],
                    'group' => ($menu === 'settings' ? '设置 · ' : '').($group['label'] ?: '项目'),
                    'roles' => $item['roles'], 'platformOnly' => $group['key'] === 'platform',
                ];
            }
        }
    }
@endphp
<header class="topbar workspace-topbar" inert>
    <div class="topbar-context">
        <div class="topbar-brand">
            <x-yanji-logo variant="compact" :label="config('brand.name')" class="topbar-logo" width="128" height="44" />
            <span class="topbar-title">工作台</span>
        </div>
        <span class="topbar-divider" aria-hidden="true"></span>
        <span class="topbar-workspace-id" id="workspaceIdentity" hidden></span>
        @if($workspaceTopbarSpa)
            <button class="workspace-menu-toggle" id="workspaceMobileMenuToggle" type="button" aria-label="打开工作台菜单" aria-controls="workspaceSidebar" aria-expanded="false">菜单</button>
        @else
            <button class="geo-page-menu-toggle" id="geoPageMenuToggle" type="button" aria-label="打开工作台菜单" aria-controls="geoPageSidebar" aria-expanded="false" disabled>菜单</button>
        @endif
    </div>
    <span class="topbar-spacer"></span>
    <div class="workspace-global-actions">
        <button class="workspace-search" id="workspaceSearchButton" type="button" aria-haspopup="dialog" aria-controls="workspaceCommandPalette" aria-label="搜索页面、文章和操作（⌘K / Ctrl+K）">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg><span>搜索页面、文章…</span><kbd>⌘K</kbd>
        </button>
        <div class="workspace-create">
            <button class="workspace-create-button" id="workspaceNewButton" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="workspaceNewMenu">＋ 新建</button>
            <div class="workspace-create-menu" id="workspaceNewMenu" role="menu" aria-label="新建" hidden>
                <button type="button" role="menuitem" data-shell-action="write">写新文章<span>内容生产</span></button>
                <button type="button" role="menuitem" data-shell-action="skill">执行技能<span>诊断、拓词等</span></button>
                <button type="button" role="menuitem" data-shell-action="import">导入 URL</button>
                <button type="button" role="menuitem" data-shell-action="project">新建项目</button>
            </div>
        </div>
    </div>

    @if($workspaceHelpUrl !== '')
        <a class="workspace-help" href="{{ $workspaceHelpUrl }}" aria-label="帮助文档" title="帮助文档">?</a>
    @endif
    <details class="topbar-user" id="accountMenu">
        <summary id="accountMenuButton" aria-label="打开用户菜单">
            <span class="topbar-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 21v-2a7 7 0 0 1 14 0v2"/></svg></span>
            <span id="userLabel">—</span><svg class="topbar-chevron" viewBox="0 0 16 16" aria-hidden="true"><path d="m4 6 4 4 4-4"/></svg>
        </summary>
        <div class="topbar-user-panel">
            <p class="topbar-user-caption">{{ $workspaceDesktopMode ? '本机工作区' : '当前登录' }}</p><p id="accountMenuIdentity"></p>
            <p class="topbar-user-help" id="accountMenuWorkspace"></p>
            @unless($workspaceDesktopMode)<p class="topbar-user-help" id="accountMenuPhone"></p>@endunless
            <a id="accountLink" href="{{ $workspaceTopbarSpa ? '#personal-settings' : '/#personal-settings' }}" data-shell-view="personal-settings">个人设置</a>
            @unless($workspaceDesktopMode)<a id="accountSecurityLink" href="{{ $workspaceTopbarSpa ? '#account-security' : '/#account-security' }}" data-shell-view="account-security">账号安全</a>@endunless
            @if($workspaceTopbarSpa)
                <button type="button" data-shell-view="usage">用量明细</button>
                <button type="button" data-shell-view="personal-settings">全部设置</button>
            @else
                <a href="/#usage" data-shell-view="usage">用量明细</a>
                <a href="/#personal-settings" data-shell-view="personal-settings">全部设置</a>
            @endif
            @if($workspaceHelpUrl !== '')
                <a href="{{ $workspaceHelpUrl }}">帮助文档</a>
            @endif
            @if($workspaceWebsiteUrl)<a href="{{ $workspaceWebsiteUrl }}">返回官网</a>@endif
            @unless($workspaceDesktopMode)<button class="button secondary small" id="accountLogoutButton" type="button">退出登录</button>@endunless
        </div>
    </details>
</header>
<script type="application/json" id="workspaceNavigationIndex" nonce="{{ $cspNonce }}">@json($workspaceSearchIndex)</script>
<dialog class="workspace-command" id="workspaceCommandPalette" aria-label="搜索页面、文章和操作">
    <div class="workspace-command-input"><input id="workspaceCommandInput" type="search" autocomplete="off" placeholder="搜索页面、文章，或输入要做的事" aria-label="搜索页面、文章和操作" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="workspaceCommandResults"><kbd>Esc</kbd></div>
    <ul id="workspaceCommandResults" class="workspace-command-results" role="listbox" aria-label="搜索结果"></ul>
</dialog>
