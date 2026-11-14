@php
    $mode = $mode ?? 'spa';
    $menus = \App\Support\WorkspaceNavigation::menus();
@endphp
@foreach ($menus as $menu => $groups)
    @continue($mode === 'page' && $menu === 'settings')
    <nav class="workspace-menu" id="{{ $menu === 'work' ? 'workspaceMenu' : 'settingsMenu' }}" aria-label="{{ $menu === 'work' ? '言及工作台一级菜单' : '设置菜单' }}">
        @if ($menu === 'settings')
            <button class="settings-back" id="settingsBackButton" type="button">← 返回工作台</button>
            <h2 class="workspace-settings-title">设置</h2>
        @endif
        @foreach ($groups as $group)
            <div class="workspace-menu-section" data-nav-group="{{ $menu }}-{{ $group['key'] }}" @if (!empty($group['id'])) id="{{ $group['id'] }}" @endif @if (!empty($group['hidden'])) hidden @endif>
                @if ($group['label'])
                    <button class="workspace-menu-group" type="button" aria-expanded="true" aria-controls="shell-{{ $menu }}-{{ $group['key'] }}">{{ $group['label'] }}<span aria-hidden="true">⌄</span></button>
                @endif
                <ul class="workspace-menu-items" id="shell-{{ $menu }}-{{ $group['key'] }}">
                    @foreach ($group['items'] as $item)
                        @continue($item['flag'] && !config('geo.'.$item['flag'], false))
                        @php
                            $route = $item['view'];
                            $href = $item['page'] ? '/'.$item['page'] : ($mode === 'spa' ? '#'.$route : '/#'.$route);
                        @endphp
                        <li>
                            <a class="workspace-menu-link" href="{{ $href }}"
                               @if ($item['id']) id="{{ $item['id'] }}" @endif
                               @if ($item['page'])
                                   @if ($mode === 'page') data-tenant-page="{{ $item['page'] }}" @if (($currentPage ?? '') === $item['page']) aria-current="page" @endif @endif
                               @elseif ($mode === 'spa') data-view-target="{{ $item['view'] }}" aria-current="false"
                               @else data-workspace-view="{{ $route }}" @endif
                               @if ($item['roles']) data-role="publisher" hidden @elseif ($item['hidden']) hidden @endif>{{ $item['label'] }}@if ($item['view'] === 'content-library')<span class="workspace-attention-count" id="workspaceArticleCount" hidden></span>@endif</a>
                            @if ($item['view'] === 'publishing')
                                <a class="workspace-setup" data-shell-setup="channels" hidden></a>
                            @elseif ($item['page'] === 'visibility')
                                <a class="workspace-setup" data-shell-setup="visibility" hidden></a>
                            @elseif ($item['page'] === 'analytics')
                                <a class="workspace-setup" data-shell-setup="beacon" hidden></a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
@endforeach
