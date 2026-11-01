import {FormEvent} from 'react';
import {Head, Link, useForm} from '@inertiajs/react';
import {Shell, PageHeading, Button, Badge, Empty} from '@/components/studio';
import '../../css/teaching.css';

type Assignment = {id:number; title:string; instructions:string|null; due_at:string|null; archived:boolean; lesson_id:number; lesson_title:string; class_name:string};
type Props = {canManage:boolean; classes:{id:number;name:string}[]; lessons:{id:number;title:string;school_class_id:number}[]; assignments:{data:Assignment[];prev_page_url:string|null;next_page_url:string|null;total:number}};
function ArchiveAction({assignment}:{assignment:Assignment}) {
    const form = useForm({archived:!assignment.archived});
    return <Button size="sm" color="secondary" isLoading={form.processing} onClick={() => form.patch('/teach/assignments/'+assignment.id, {preserveScroll:true})}>{assignment.archived ? 'Restore' : 'Archive'}</Button>;
}
export default function Assignments({canManage, classes, lessons, assignments}:Props) {
    const form = useForm({school_class_id:classes[0]?.id.toString() ?? '', lesson_id:'', title:'', instructions:'', due_at:''});
    const submit = (event:FormEvent) => {
        event.preventDefault();
        form.transform(data => ({...data, due_at:data.due_at ? new Date(data.due_at).toISOString() : null}));
        form.post('/teach/classes/'+form.data.school_class_id+'/assignments', {preserveScroll:true, onSuccess:() => form.reset('title', 'instructions', 'due_at', 'lesson_id')});
    };
    return <Shell><Head title="Assignments"/><PageHeading eyebrow="LEARNING WITH PURPOSE" title="Assignments" description={canManage ? 'Give your class a clear next step, with instructions and a due date.' : 'Your teacher’s next steps, all in one place.'}/>
        <div className={canManage ? 'assignment-layout' : ''}>
            <section aria-label="Class assignments"><div className="teaching-section-heading"><h2>{canManage ? 'Shared with classes' : 'Your learning tasks'}</h2><span className="muted">{assignments.total} assignments</span></div>
                {assignments.data.length === 0 ? <Empty title="No assignments yet" description={canManage ? 'Share your first assignment using the form.' : 'Your teacher’s assignments will appear here.'}/> : <div className="assignment-list">{assignments.data.map(item => <article className="assignment-card" key={item.id}><div className="teaching-section-heading"><Badge color={item.archived ? 'gray' : 'success'}>{item.archived ? 'Archived' : 'Assigned'}</Badge><span className="muted">{item.class_name}</span></div><h2>{item.title}</h2><p className="muted">{item.lesson_title}</p>{item.instructions && <p className="assignment-instructions">{item.instructions}</p>}<div className="teaching-section-heading"><span className="assignment-due">{item.due_at ? 'Due '+new Date(item.due_at).toLocaleString([], {dateStyle:'medium', timeStyle:'short'}) : 'No due date'}</span><div className="teaching-actions"><Button href={'/lessons/'+item.lesson_id} size="sm">Open lesson</Button>{canManage && <ArchiveAction key={String(item.archived)} assignment={item}/>}</div></div></article>)}</div>}
                <nav className="teaching-pagination" aria-label="Assignment pages">{assignments.prev_page_url && <Link href={assignments.prev_page_url}>Previous page</Link>}{assignments.next_page_url && <Link href={assignments.next_page_url}>Next page</Link>}</nav>
            </section>
            {canManage && <aside className="assignment-create"><h2>Create assignment</h2><p className="muted">Visible to learners in the selected class.</p>{classes.length === 0 ? <p>You need an assigned class to share work.</p> : <form className="teaching-form" onSubmit={submit}>
                <label>Class<select required value={form.data.school_class_id} onChange={event => {form.setData('school_class_id', event.target.value); form.setData('lesson_id', '');}}>{classes.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                <label>Lesson<select required value={form.data.lesson_id} onChange={event => form.setData('lesson_id', event.target.value)}><option value="">Choose a lesson</option>{lessons.filter(item => item.school_class_id === Number(form.data.school_class_id)).map(item => <option key={item.id} value={item.id}>{item.title}</option>)}</select>{form.errors.lesson_id && <span role="alert" className="field-error">{form.errors.lesson_id}</span>}</label>
                <label>Assignment title<input required maxLength={160} value={form.data.title} onChange={event => form.setData('title', event.target.value)} placeholder="For example, explain how leaves make food"/>{form.errors.title && <span role="alert" className="field-error">{form.errors.title}</span>}</label>
                <label>Instructions<textarea rows={5} maxLength={5000} value={form.data.instructions} onChange={event => form.setData('instructions', event.target.value)}/>{form.errors.instructions && <span role="alert" className="field-error">{form.errors.instructions}</span>}</label>
                <label>Due date and time <span className="muted">(optional, your local time)</span><input type="datetime-local" value={form.data.due_at} onChange={event => form.setData('due_at', event.target.value)}/>{form.errors.due_at && <span role="alert" className="field-error">{form.errors.due_at}</span>}</label><Button type="submit" isLoading={form.processing}>Share assignment</Button>
            </form>}</aside>}
        </div>
    </Shell>;
}
