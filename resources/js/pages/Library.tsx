import {Head, router, usePage} from '@inertiajs/react';
import {useState} from 'react';
import {UploadCloud02, BookOpen01} from '@untitledui/icons';
import {Shell, PageHeading, Button, Badge, Empty} from '@/components/studio';
import {CurriculumTree, type CatalogueClass, type CatalogueLesson} from '@/components/curriculum-tree';
import type {Lesson, Shared} from '@/types';

type Entry = Pick<Lesson, 'id'|'title'|'manifest_id'|'version'|'active_count'|'publication'|'syllabus'|'logo_url'> & {unit_id:number|null};

export default function Library({lessons, admin, catalogue, unplaced, needsClass}:{lessons:Entry[]; admin:boolean; catalogue:CatalogueClass[]; unplaced:CatalogueLesson[]; needsClass:boolean}) {
    const {workspace} = usePage<Shared>().props;
    const [search, setSearch] = useState('');
    const query = search.trim().toLocaleLowerCase();
    const matches = (value:string) => value.toLocaleLowerCase().includes(query);
    const filtered = catalogue.map(schoolClass => ({...schoolClass, subjects:schoolClass.subjects.map(subject => ({...subject, units:subject.units.map(unit => ({...unit, lessons:unit.lessons.filter(lesson => matches([schoolClass.name, subject.name, unit.title, lesson.title].join(' ')))})).filter(unit => !query || unit.lessons.length > 0)})).filter(subject => !query || subject.units.length > 0)})).filter(schoolClass => !query || schoolClass.subjects.length > 0);
    const filteredUnplaced = unplaced.filter(lesson => matches(lesson.title));
    return <Shell logo={lessons[0]?.logo_url}><Head title="Lesson library"/>
        <PageHeading eyebrow={admin ? 'CURRICULUM WORKSPACE' : 'EXPLORE YOUR SUBJECTS'} title="Lesson library" description={admin ? 'Organise quality learning, one class at a time.' : 'Find your next lesson. Pick up where curiosity takes you.'} actions={admin ? <Button href="/admin/import" iconLeading={UploadCloud02}>Import curriculum</Button> : <Button href="/today" color="secondary">My learning</Button>}/>
        <div className="library-summary"><BookOpen01 aria-hidden="true"/><div><strong>{lessons.length} {lessons.length === 1 ? 'lesson' : 'lessons'} ready to explore</strong><p>{admin ? 'Keep lessons organised by class, subject and unit.' : 'Learn online, or save lessons to continue without a connection.'}</p></div><Button href="/offline" color="link-color">Manage downloads</Button></div>
        {!needsClass && <div className="catalogue-search"><label className="sr-only" htmlFor="library-search">Search lessons, subjects or units</label><input id="library-search" type="search" placeholder="Search lessons, subjects or units…" value={search} onChange={event => setSearch(event.target.value)}/>{search && <Button color="secondary" onClick={() => setSearch('')}>Clear</Button>}</div>}
        <div className="section-heading"><div><h2>{admin ? 'Your curriculum' : 'Your subjects'} <Badge color="gray">{filtered.length} {filtered.length === 1 ? 'class' : 'classes'}</Badge></h2></div></div>
        {needsClass ? <Empty title="Choose your class to get started" description="Select your class in your account to see lessons prepared for you." action={<Button href="/account">Choose my class</Button>}/> : query && filtered.length === 0 && filteredUnplaced.length === 0 ? <Empty title="No matching lessons" description="Try another lesson title, subject or unit." action={<Button color="secondary" onClick={() => setSearch('')}>Show all lessons</Button>}/> : <CurriculumTree admin={admin} classes={filtered} unplaced={filteredUnplaced}/>}
        {admin && <details className="library-trials"><summary>Trial workspace tools</summary><p className="muted">Try imports in a separate workspace before using your main library.</p><div className="actions"><Button color="secondary" onClick={() => router.post('/admin/workspace', {mode:'new'})}>Start empty trial</Button>{!workspace?.is_trial && workspace?.can_resume && <Button color="secondary" onClick={() => router.post('/admin/workspace', {mode:'resume'})}>Resume trial</Button>}</div></details>}
    </Shell>;
}
