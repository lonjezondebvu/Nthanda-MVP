"""Copy unmodified MIT components from the official Untitled UI checkout."""
from pathlib import Path
import shutil, re, subprocess
src=Path('storage/untitled-ui-source'); dest=Path('resources/js'); seen=set()
def copy(relative):
    if relative in seen:return
    seen.add(relative)
    origin=src/relative
    if not origin.exists():return
    target=dest/relative;target.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(origin,target)
    for dep in re.findall(r'from [\"\'](@/[^\"\']+|\.[^\"\']+)[\"\']',origin.read_text(encoding='utf-8')):
        stem=src/dep[2:] if dep.startswith('@/') else origin.parent/dep
        for candidate in [stem.with_suffix('.tsx'),stem.with_suffix('.ts'),stem/'index.tsx',stem/'index.ts']:
            if candidate.exists():copy(candidate.resolve().relative_to(src.resolve()).as_posix());break
for file in ['components/base/buttons/button.tsx','components/base/input/input.tsx','components/base/badges/badges.tsx','components/base/toggle/toggle.tsx','components/base/textarea/textarea.tsx','components/application/modals/modal.tsx','components/application/loading-indicator/loading-indicator.tsx','components/application/table/table.tsx','components/application/empty-state/empty-state.tsx','components/application/app-navigation/base-components/nav-item.tsx']:
    copy(file)
for name in ['theme.css','typography.css']:
    target=Path('resources/css')/name;shutil.copy2(src/'styles'/name,target)
Path('docs/third-party').mkdir(parents=True,exist_ok=True)
shutil.copy2(src/'LICENSE','docs/third-party/untitled-ui-LICENSE')
commit=subprocess.check_output(['git','-C',str(src),'rev-parse','HEAD'],text=True).strip()
Path('docs/third-party/untitled-ui.md').write_text(f'# Untitled UI provenance\n\nOfficial source: https://github.com/untitleduico/react\n\nCommit: `{commit}`\n\nMIT license. Selected components and their dependencies are copied without changes. Official theme and typography tokens are in resources/css; Nthanda brand overrides are in app.css. Domain navigation, tables and states compose these free foundations; paid application components are not included.\n')
print('Copied',len(seen),'official files at',commit)
