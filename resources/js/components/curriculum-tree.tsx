import {useId, type ReactNode} from 'react';
import {router, useForm} from '@inertiajs/react';
import {Button} from '@/components/studio';
import '@/../css/curriculum.css';

export type CatalogueLesson = {id:number; title:string; version:string; manifest_id:string; active_count:number; unit_id:number|null};
export type CatalogueUnit = {id:number; title:string; lessons:CatalogueLesson[]};
export type CatalogueSubject = {id:number; name:string; units:CatalogueUnit[]};
export type CatalogueClass = {id:number; name:string; subjects:CatalogueSubject[]};

function unitChoices(classes:CatalogueClass[]): {id:number; label:string}[] {
    return classes.flatMap(schoolClass => schoolClass.subjects.flatMap(subject => subject.units.map(unit => ({id:unit.id, label:`${schoolClass.name} · ${subject.name} · ${unit.title}`}))));
}

function Field({label, children}:{label:string; children:(id:string)=>ReactNode}) {
    const id = useId();
    return <label htmlFor={id}>{label}{children(id)}</label>;
}

function AddClass() {
    const form = useForm({name:''});
    return <form className="catalogue-add" onSubmit={event => {event.preventDefault(); form.post('/admin/classes', {preserveScroll:true, onSuccess:() => form.reset()});}}>
        <Field label="New class">{id => <input id={id} value={form.data.name} onChange={event => form.setData('name', event.target.value)} required maxLength={120}/>}</Field>
        <Button type="submit" size="sm" isLoading={form.processing}>Add class</Button>
        {form.errors.name && <p className="field-error">{form.errors.name}</p>}
    </form>;
}

function ClassEditor({item}:{item:CatalogueClass}) {
    const form = useForm({name:item.name});
    return <form className="catalogue-row" onSubmit={event => {event.preventDefault(); form.patch('/admin/classes/'+item.id, {preserveScroll:true});}}>
        <Field label="Class">{id => <input id={id} value={form.data.name} onChange={event => form.setData('name', event.target.value)} required maxLength={120}/>}</Field>
        <div className="catalogue-actions">
            <Button type="submit" size="sm" isLoading={form.processing}>Save</Button>
            <Button type="button" color="secondary-destructive" size="sm" onClick={() => router.delete('/admin/classes/'+item.id, {preserveScroll:true, onBefore:() => confirm('Delete this class?')})}>Delete</Button>
        </div>
        {form.errors.name && <p className="field-error">{form.errors.name}</p>}
    </form>;
}

function AddSubject({classId}:{classId:number}) {
    const form = useForm({name:''});
    return <form className="catalogue-add" onSubmit={event => {event.preventDefault(); form.post('/admin/classes/'+classId+'/subjects', {preserveScroll:true, onSuccess:() => form.reset()});}}>
        <Field label="New subject">{id => <input id={id} value={form.data.name} onChange={event => form.setData('name', event.target.value)} required maxLength={120}/>}</Field>
        <Button type="submit" size="sm" isLoading={form.processing}>Add subject</Button>
        {form.errors.name && <p className="field-error">{form.errors.name}</p>}
    </form>;
}

function SubjectEditor({item}:{item:CatalogueSubject}) {
    const form = useForm({name:item.name});
    return <form className="catalogue-row" onSubmit={event => {event.preventDefault(); form.patch('/admin/subjects/'+item.id, {preserveScroll:true});}}>
        <Field label="Subject">{id => <input id={id} value={form.data.name} onChange={event => form.setData('name', event.target.value)} required maxLength={120}/>}</Field>
        <div className="catalogue-actions">
            <Button type="submit" size="sm" isLoading={form.processing}>Save</Button>
            <Button type="button" color="secondary-destructive" size="sm" onClick={() => router.delete('/admin/subjects/'+item.id, {preserveScroll:true, onBefore:() => confirm('Delete this subject?')})}>Delete</Button>
        </div>
        {form.errors.name && <p className="field-error">{form.errors.name}</p>}
    </form>;
}

function AddUnit({subjectId}:{subjectId:number}) {
    const form = useForm({title:''});
    return <form className="catalogue-add" onSubmit={event => {event.preventDefault(); form.post('/admin/subjects/'+subjectId+'/units', {preserveScroll:true, onSuccess:() => form.reset()});}}>
        <Field label="New unit">{id => <input id={id} value={form.data.title} onChange={event => form.setData('title', event.target.value)} required maxLength={160}/>}</Field>
        <Button type="submit" size="sm" isLoading={form.processing}>Add unit</Button>
        {form.errors.title && <p className="field-error">{form.errors.title}</p>}
    </form>;
}

function UnitEditor({item}:{item:CatalogueUnit}) {
    const form = useForm({title:item.title});
    return <form className="catalogue-row" onSubmit={event => {event.preventDefault(); form.patch('/admin/units/'+item.id, {preserveScroll:true});}}>
        <Field label="Unit">{id => <input id={id} value={form.data.title} onChange={event => form.setData('title', event.target.value)} required maxLength={160}/>}</Field>
        <div className="catalogue-actions">
            <Button type="submit" size="sm" isLoading={form.processing}>Save</Button>
            <Button type="button" color="secondary-destructive" size="sm" onClick={() => router.delete('/admin/units/'+item.id, {preserveScroll:true, onBefore:() => confirm('Delete this unit?')})}>Delete</Button>
        </div>
        {form.errors.title && <p className="field-error">{form.errors.title}</p>}
    </form>;
}

function LessonEditor({lesson, classes, admin}:{lesson:CatalogueLesson; classes:CatalogueClass[]; admin:boolean}) {
    const form = useForm({title:lesson.title, unit_id:lesson.unit_id ? String(lesson.unit_id) : ''});
    if (!admin) {
        return <div className="catalogue-lesson"><div><h3>{lesson.title}</h3><p className="muted">Version {lesson.version} · {lesson.active_count} sections</p></div><Button href={'/lessons/'+lesson.id} color="secondary" size="sm">Open lesson</Button></div>;
    }
    return <form className="catalogue-row" onSubmit={event => {event.preventDefault(); form.transform(data => ({title:data.title, unit_id:data.unit_id === '' ? null : Number(data.unit_id)})); form.patch('/admin/lessons/'+lesson.id+'/placement', {preserveScroll:true});}}>
        <Field label="Lesson title">{id => <input id={id} value={form.data.title} onChange={event => form.setData('title', event.target.value)} required maxLength={255}/>}</Field>
        <Field label="Unit">{id => <select id={id} value={form.data.unit_id} onChange={event => form.setData('unit_id', event.target.value)}><option value="">Not placed</option>{unitChoices(classes).map(unit => <option key={unit.id} value={unit.id}>{unit.label}</option>)}</select>}</Field>
        <div className="catalogue-actions">
            <Button type="submit" size="sm" isLoading={form.processing}>Save</Button>
            <Button href={'/admin/lessons/'+lesson.id} color="secondary" size="sm">Manage</Button>
            <Button href={'/lessons/'+lesson.id} color="link-color" size="sm">Preview</Button>
            <Button type="button" color="secondary-destructive" size="sm" onClick={() => router.delete('/admin/lessons/'+lesson.id, {onBefore:() => confirm('Delete this lesson package? A package with learner progress stays in the library.')})}>Delete</Button>
        </div>
        {form.errors.title && <p className="field-error">{form.errors.title}</p>}
        {form.errors.unit_id && <p className="field-error">{form.errors.unit_id}</p>}
    </form>;
}

function EditPanel({label, children}:{label:string; children:ReactNode}) {
    return <details className="curriculum-edit"><summary>{label}</summary><div className="curriculum-editor">{children}</div></details>;
}

function LessonRow({lesson, classes, admin}:{lesson:CatalogueLesson; classes:CatalogueClass[]; admin:boolean}) {
    return <article className="curriculum-lesson">
        <div className="curriculum-lesson-main"><span className="curriculum-file" aria-hidden="true">↗</span><div><h4>{lesson.title}</h4><p>{lesson.active_count} sections <span aria-hidden="true">·</span> Version {lesson.version}</p></div>
        <Button href={(admin ? '/admin/lessons/' : '/lessons/')+lesson.id} color="secondary" size="sm">{admin ? 'Manage lesson' : 'Open lesson'}</Button></div>
        {admin && <EditPanel label={`Edit details · ${lesson.title}`}><LessonEditor lesson={lesson} classes={classes} admin/></EditPanel>}
    </article>;
}

export function CurriculumTree({admin, classes, unplaced}:{admin:boolean; classes:CatalogueClass[]; unplaced:CatalogueLesson[]}) {
    return <div className="curriculum-browser">
        {admin && <div className="curriculum-toolbar"><div><strong>Curriculum structure</strong><p>Class → Subject → Unit → Lesson</p></div><EditPanel label="+ Add class"><AddClass/></EditPanel></div>}
        {classes.length === 0 && <p className="curriculum-empty">{admin ? 'Create your first class to start organising lessons.' : 'Your class does not have lessons yet.'}</p>}
        {classes.map(schoolClass => <details className="curriculum-class" key={schoolClass.id} open>
            <summary className="curriculum-branch"><span className="curriculum-chevron" aria-hidden="true">›</span><span className="curriculum-class-icon" aria-hidden="true">C</span><span className="curriculum-name"><small>CLASS</small><strong>{schoolClass.name}</strong></span><span className="curriculum-count">{schoolClass.subjects.length} {schoolClass.subjects.length === 1 ? 'subject' : 'subjects'}</span></summary>
            <div className="curriculum-class-body">
                {admin && <div className="curriculum-controls"><EditPanel label={`Edit ${schoolClass.name}`}><ClassEditor item={schoolClass}/></EditPanel><EditPanel label="+ Add subject"><AddSubject classId={schoolClass.id}/></EditPanel></div>}
                {schoolClass.subjects.length === 0 && <p className="curriculum-empty">No subjects yet.</p>}
                {schoolClass.subjects.map(subject => <details className="curriculum-subject" key={subject.id} open>
                    <summary className="curriculum-branch"><span className="curriculum-chevron" aria-hidden="true">›</span><span className="curriculum-name"><small>SUBJECT</small><strong>{subject.name}</strong></span><span className="curriculum-count">{subject.units.length} {subject.units.length === 1 ? 'unit' : 'units'}</span></summary>
                    <div className="curriculum-subject-body">
                        {admin && <div className="curriculum-controls"><EditPanel label={`Edit ${subject.name}`}><SubjectEditor item={subject}/></EditPanel><EditPanel label="+ Add unit"><AddUnit subjectId={subject.id}/></EditPanel></div>}
                        {subject.units.length === 0 && <p className="curriculum-empty">No units yet.</p>}
                        {subject.units.map(unit => <details className="curriculum-unit" key={unit.id} open>
                            <summary className="curriculum-branch"><span className="curriculum-chevron" aria-hidden="true">›</span><span className="curriculum-name"><small>UNIT</small><strong>{unit.title}</strong></span><span className="curriculum-count">{unit.lessons.length} {unit.lessons.length === 1 ? 'lesson' : 'lessons'}</span></summary>
                            <div className="curriculum-unit-body">
                                {admin && <EditPanel label={`Edit ${unit.title}`}><UnitEditor item={unit}/></EditPanel>}
                                {unit.lessons.length === 0 && <p className="curriculum-empty">Import a lesson, then assign it to this unit.</p>}
                                {unit.lessons.map(lesson => <LessonRow key={`${lesson.id}-${lesson.unit_id}-${lesson.title}`} lesson={lesson} classes={classes} admin={admin}/>)}
                            </div>
                        </details>)}
                    </div>
                </details>)}
            </div>
        </details>)}
        {admin && <section className="curriculum-unplaced"><div className="curriculum-unplaced-heading"><h3>Unassigned lessons</h3><span className="curriculum-count">{unplaced.length}</span></div><p>Assign these lessons to a unit before learners can access them.</p>
            {unplaced.length === 0 ? <div className="curriculum-filed"><span aria-hidden="true">✓</span> All lessons are organised</div> : unplaced.map(lesson => <LessonRow key={`${lesson.id}-unplaced-${lesson.title}`} lesson={lesson} classes={classes} admin/>)}
        </section>}
    </div>;
}
