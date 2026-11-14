<aside class="workspace-sidebar geo-page-sidebar" id="geoPageSidebar" data-nav-mode="work" inert aria-label="言及工作台一级菜单">
    <div class="workspace-sidebar-scroll">
        <div class="project-nav-section" id="pcContext">
            {{-- 独立页只展示项目上下文；切换项目仍回主工作台，不提供下拉切换。 --}}
            <a class="project-card" data-workspace-view="projects" href="/#projects" id="geoPageCurrentProject">
                <span class="project-card-label">当前项目</span>
                <strong id="geoPageProjectName">当前项目</strong>
                <span id="geoPageProjectStage" hidden></span>
            </a>
            <a class="workspace-menu-link" data-workspace-view="projects/list" href="/#projects/list">全部项目</a>
        </div>
        @include('partials.workspace-nav', ['mode' => 'page'])
    </div>
    <div class="workspace-sidebar-bottom">
        <a class="workspace-menu-link workspace-trash-link" id="globalTrashNav" href="/#projects/trash" data-workspace-view="projects/trash">回收站</a>
        <a class="workspace-menu-link" id="openSettingsButton" href="/#personal-settings" data-workspace-view="personal-settings">设置</a>
    </div>
</aside>
