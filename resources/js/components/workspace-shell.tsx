import {type ReactNode, useEffect, useRef, useState} from 'react';
import {BookOpen01, UploadCloud02, LogOut01, LayersThree01, Menu01, Home02, Users01, GraduationHat01, Calendar, User01, WifiOff} from '@untitledui/icons';
import {NavItemBase} from '@/components/application/app-navigation/base-components/nav-item';
import '../../css/workspace.css';

type WorkspaceShellProps = {children:ReactNode; logo?:string|null; user?:{name:string;role:string}|null; path:string; notices?:ReactNode; headerActions?:ReactNode; onLogout?:()=>void; logoutDisabled?:boolean};

export function WorkspaceShell({children, logo, user, path, notices, headerActions, onLogout, logoutDisabled=false}:WorkspaceShellProps) {
    const [sidebarCollapsed, setSidebarCollapsed] = useState(() => typeof window !== 'undefined' && window.innerWidth < 900);
    const [mobile,setMobile] = useState(() => typeof window !== 'undefined' && window.innerWidth < 900);
    const sidebarRef = useRef<HTMLElement>(null);
    const closeNavigation = () => { setSidebarCollapsed(true); requestAnimationFrame(() => document.getElementById('workspace-menu')?.focus()); };
    const [online, setOnline] = useState(() => typeof navigator === 'undefined' || navigator.onLine);
    const teacher = user?.role === 'teacher' || user?.role === 'admin';
    const home = user?.role === 'admin' ? '/admin/lessons' : user?.role === 'teacher' ? '/teach' : '/today';
    const library = user?.role === 'admin' ? '/admin/lessons' : '/lessons';
    const pageLabel = path === '/glossary' ? 'Bilingual glossary' : path === '/portfolio' ? 'Learning portfolio' : path.endsWith('/publication') ? 'Publication review' : path === '/today' ? 'My learning' : path === '/assignments' ? 'Assignments' : path === '/offline' ? 'Downloads & offline' : path === '/admin/import' ? 'Import curriculum' : path === '/admin/students' ? 'Students' : path === '/admin/teachers' ? 'Teachers' : path.startsWith('/teach') ? 'Class desk' : path === '/account' ? 'Your account' : path.endsWith('/edit') ? 'Edit curriculum' : path.includes('/assessment') ? 'Assessments' : path.includes('/sections/') ? 'Lesson player' : path.startsWith('/admin/lessons/') ? 'Manage lesson' : path.startsWith('/lessons/') ? 'Lesson overview' : 'Lesson library';
    useEffect(() => {
        const update = () => setOnline(navigator.onLine);
        const escape = (event:KeyboardEvent) => {
            if (!mobile || sidebarCollapsed) { return; }
            if (event.key === 'Escape') { closeNavigation(); }
            if (event.key === 'Tab') {
                const controls = sidebarRef.current?.querySelectorAll<HTMLElement>('a[href],button:not([disabled])');
                if (!controls?.length) { return; }
                const first = controls[0], last = controls[controls.length-1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        };
        window.addEventListener('online', update); window.addEventListener('offline', update); window.addEventListener('keydown', escape);
        return () => { window.removeEventListener('online', update); window.removeEventListener('offline', update); window.removeEventListener('keydown', escape); };
    }, [mobile, sidebarCollapsed]);
    useEffect(() => {
        const media = window.matchMedia('(max-width:899px)');
        const resize = () => { setMobile(media.matches); setSidebarCollapsed(media.matches); };
        media.addEventListener('change',resize);
        return () => media.removeEventListener('change',resize);
    }, []);
    useEffect(() => { if (mobile && !sidebarCollapsed) { sidebarRef.current?.querySelector<HTMLButtonElement>('button')?.focus(); } }, [mobile,sidebarCollapsed]);
    const navItems = [
        ...(!teacher && user ? [{href:'/today', label:'My learning', icon:Home02, current:path === '/today'}] : []),
        ...(teacher ? [{href:'/teach', label:'Class desk', icon:GraduationHat01, current:path.startsWith('/teach')}] : []),
        {href:library, label:'Lesson library', icon:BookOpen01, current:path.startsWith(library)},
        ...(user ? [{href:'/assignments', label:'Assignments', icon:Calendar, current:path === '/assignments'}] : []),
        ...(user ? [{href:'/portfolio', label:'Learning portfolio', icon:GraduationHat01, current:path === '/portfolio'}, {href:'/glossary', label:'Bilingual glossary', icon:BookOpen01, current:path === '/glossary'}] : []),
        {href:'/offline', label:'Downloads & offline', icon:LayersThree01, current:path === '/offline'},
        ...(user?.role === 'admin' ? [
            {href:'/admin/import', label:'Import curriculum', icon:UploadCloud02, current:path === '/admin/import'},
            {href:'/admin/students', label:'Students', icon:Users01, current:path === '/admin/students'},
            {href:'/admin/teachers', label:'Teachers', icon:GraduationHat01, current:path === '/admin/teachers'},
            {href:'/today', label:'Learning preview', icon:Home02, current:path === '/today' || path.startsWith('/lessons')},
        ] : []),
        ...(user?.role === 'learner' ? [{href:'/account', label:'Your account', icon:User01, current:path === '/account'}] : []),
    ];
    return <div className={`studio professional-workspace${sidebarCollapsed ? ' sidebar-collapsed' : ''}`}>
        <a href="#workspace-main" className="skip-content">Skip to content</a>
        {!sidebarCollapsed && <button type="button" className="sidebar-scrim" aria-label="Close navigation" onClick={closeNavigation}/>}
        <aside ref={sidebarRef} id="studio-sidebar" className="sidebar" role={mobile ? 'dialog' : undefined} aria-modal={mobile && !sidebarCollapsed ? true : undefined} aria-label="Workspace navigation">{mobile && <button type="button" className="mobile-nav-close" onClick={closeNavigation}>Close navigation ×</button>}
            <a href={home} className="brand"><img src={logo || '/branding/nthanda-logo.png'} alt="Nthanda"/><span>Nthanda<small>LEARN. EXPLORE. GROW.</small></span></a>
            <div className="workspace-label">{teacher ? 'TEACHING WORKSPACE' : 'YOUR LEARNING SPACE'}</div>
            <nav aria-label="Main navigation">{navItems.map(item => <NavItemBase key={item.label} type="link" current={item.current} href={item.href} icon={item.icon} aria-label={item.label}>{item.label}</NavItemBase>)}</nav>
            <div className="sidebar-bottom"><div className="workspace-offline-tip"><LayersThree01 aria-hidden="true"/><strong>Learning travels with you.</strong><p>Save lessons before you go offline.</p><a href="/offline">Manage downloads →</a></div><div className="account"><span className="avatar">{user?.name[0] ?? 'N'}</span><div><strong>{user?.name ?? 'Local workspace'}</strong><small>{user?.role ?? 'Offline access'}</small></div></div></div>
        </aside>
        <div className="workspace" inert={mobile && !sidebarCollapsed}>
            <header className="topbar studio-appbar">
                <div className="appbar-leading">
                    <button type="button" className="appbar-menu-button" id="workspace-menu" title={sidebarCollapsed ? 'Open navigation' : 'Close navigation'} aria-label={sidebarCollapsed ? 'Open navigation' : 'Close navigation'} aria-expanded={!sidebarCollapsed} aria-controls="studio-sidebar" onClick={() => setSidebarCollapsed(value => !value)}><Menu01 aria-hidden="true"/></button>
                    <div className="appbar-context"><span>{teacher ? 'Teaching workspace' : 'Learning workspace'}<span className="appbar-path-divider" aria-hidden="true">/</span></span><strong>{pageLabel}</strong></div>
                </div>
                {headerActions && <div className="appbar-status">{headerActions}</div>}
                <div className="appbar-account">
                    {user && <div className="appbar-identity" title={`${user.name} · ${user.role}`}><span className="appbar-avatar" aria-hidden="true">{user.name.trim().split(/\s+/).map(part => part[0]).slice(0,2).join('').toUpperCase()}</span><div className="appbar-person"><strong>{user.name}</strong><span>{user.role === 'admin' ? 'Administrator' : user.role}</span></div></div>}
                    {onLogout && <button type="button" className="appbar-logout" aria-label="Log out" title="Log out" disabled={logoutDisabled} onClick={onLogout}><LogOut01 aria-hidden="true"/></button>}
                </div>
            </header>
            {!online && path !== '/offline' && <div className="connection-notice" role="status"><WifiOff aria-hidden="true"/><span>Your device is offline. Open a downloaded lesson to keep learning.</span><a href="/offline">Open downloads</a></div>}
            {notices}<main id="workspace-main" tabIndex={-1}>{children}</main>
            <footer className="workspace-footer"><span>© {new Date().getFullYear()} Nthanda</span><span>Made for curiosity. Built for understanding.</span><a href="/offline">Learn offline</a></footer>
        </div>
    </div>;
}
