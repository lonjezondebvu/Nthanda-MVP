import {FormEvent, useState} from 'react';
import {Head, Link, router, useForm} from '@inertiajs/react';
import {Shell, PageHeading, Button, Badge, Empty} from '@/components/studio';
import '../../css/teaching.css';
import {TextArea} from '@/components/base/textarea/textarea';

type Written = {id:number; response:string; status:string; comment:string|null; created_at:string|null};
type LessonRow = {id:number; title:string; sections_read:number; automatic:'achieved'|'not_yet'|null; written:Written[]};
type StudentRow = {pending:number; id:number; name:string; deactivated:boolean; lessons:LessonRow[]};

function ReviewForm({item}:{item:Written}) {
    const form = useForm({decision:item.status === 'accepted' || item.status === 'needs_another_try' ? item.status : 'accepted', comment:item.comment ?? ''});
    const submit = (event:FormEvent) => {
        event.preventDefault();
        form.patch('/teach/attempts/'+item.id, {preserveScroll:true});
    };
    return <form className="review-form" onSubmit={submit}>
        <label>Decision<select value={form.data.decision} onChange={event => form.setData('decision', event.target.value)}><option value="accepted">Accepted</option><option value="needs_another_try">Needs another try</option></select>{form.errors.decision && <span className="field-error">{form.errors.decision}</span>}</label>
        <TextArea label="Comment for the student" value={form.data.comment} onChange={value => form.setData('comment', value)} rows={4} isRequired isInvalid={!!form.errors.comment} hint={form.errors.comment}/>
        <Button type="submit" size="sm" isLoading={form.processing}>{item.status === 'pending' ? 'Send review' : 'Update review'}</Button>
    </form>;
}

function automaticLabel(value:LessonRow['automatic']): string {
    if (value === 'achieved') return 'Achieved';
    if (value === 'not_yet') return 'Not yet';
    return 'Not submitted';
}

type Pagination = {previous:string|null;next:string|null;total:number};
export default function ClassDesk({schoolClass, students, selectedStudent, lastServerActivity, filters, summary, pagination, workPagination}:{schoolClass:{id:number; name:string}; students:StudentRow[];selectedStudent:number|null;lastServerActivity:string|null;filters:{search:string;pending:boolean};summary:{students:number;pending:number;lessons:number};pagination:Pagination;workPagination:Pagination}) {
    const [search, setSearch] = useState(filters.search);
    const selected = students.find(student => student.id === selectedStudent);
    const visit = (data:Record<string,string|number|boolean>) => router.get('/teach/classes/'+schoolClass.id, {search:filters.search, pending:filters.pending ? 1 : 0, ...data}, {preserveScroll:true});
    return <Shell><Head title={schoolClass.name}/>
        <PageHeading eyebrow="CLASS DESK" title={schoolClass.name} description="Give each learner the feedback they need to move forward." actions={<div className="teaching-actions"><Button href="/assignments">Assignments</Button><Button href={`/teach/classes/${schoolClass.id}/report`} color="secondary">Export progress</Button><Button href="/teach" color="secondary">All classes</Button></div>}/>
        <div className="review-summary"><div><strong>{summary.students}</strong><span>Learners in your class</span></div><div><strong>{summary.pending}</strong><span>Responses awaiting feedback</span></div><div><strong>{summary.lessons}</strong><span>Available lessons</span></div></div>
        <div className="review-workspace"><aside className="student-roster" aria-label="Choose a learner"><form className="review-search" onSubmit={event => {event.preventDefault(); visit({search});}}><label htmlFor="learner-search">Find a learner</label><input id="learner-search" type="search" value={search} onChange={event => setSearch(event.target.value)} placeholder="Search by name"/><Button type="submit" color="secondary" size="sm">Search</Button><label className="pending-filter"><input type="checkbox" checked={filters.pending} onChange={event => visit({pending:event.target.checked ? 1 : 0, search})}/>Waiting for review only</label></form>
        {students.length === 0 ? <p className="muted">No learners match these filters.</p> : students.map(student => <button type="button" className="student-choice" key={student.id} aria-pressed={student.id === selectedStudent} onClick={() => visit({student:student.id, page:Number(new URLSearchParams(window.location.search).get('page') ?? 1)})}><span><strong>{student.name}</strong><small>{student.deactivated ? 'Account deactivated' : student.pending ? 'Feedback needed' : 'No pending responses'}</small></span>{student.pending > 0 && <Badge color="warning">{student.pending}</Badge>}</button>)}
        <nav className="teaching-pagination" aria-label="Learner pages">{pagination.previous && <Link href={pagination.previous}>Previous</Link>}{pagination.next && <Link href={pagination.next}>Next</Link>}</nav></aside>
        <section className="review-panel" aria-label="Learner work">{!selected ? <Empty title="No learner selected" description="Choose a learner to see their work, or adjust your filters."/> : <><h2>{selected.name}</h2><p className="muted">{lastServerActivity ? <>Last activity saved to server: <time dateTime={lastServerActivity}>{new Date(lastServerActivity).toLocaleString()}</time></> : 'No learning activity saved to the server yet.'}</p><p className="muted">Reading and assessment results are shown separately. Offline work appears here after the learner synchronises.</p>{selected.lessons.length === 0 ? <p className="muted">No lessons have been filed for this class yet.</p> : selected.lessons.map(lesson => <article className="desk-lesson" key={lesson.id}><div className="desk-lesson-heading"><h3>{lesson.title}</h3><Link href={'/lessons/'+lesson.id}>Open lesson</Link></div><p className="muted">{lesson.sections_read} sections read · Automatic check: {automaticLabel(lesson.automatic)}</p>{lesson.automatic === 'not_yet' && <Badge color="warning">Needs practice</Badge>}{lesson.written.map(item => <div className="written-review" key={item.id}><div className="teaching-section-heading"><Badge color={item.status === 'accepted' ? 'success' : item.status === 'needs_another_try' ? 'warning' : 'gray'}>{item.status === 'accepted' ? 'Accepted' : item.status === 'needs_another_try' ? 'Needs another try' : 'Waiting for review'}</Badge>{item.created_at && <time className="muted" dateTime={item.created_at}>{new Date(item.created_at).toLocaleDateString()}</time>}</div><p>{item.response}</p><ReviewForm key={item.id+':'+item.status} item={item}/></div>)}</article>)}<nav className="teaching-pagination" aria-label="Written response pages"><span className="muted">{workPagination.total} {filters.pending ? 'pending' : 'written'} responses</span>{workPagination.previous && <Link href={workPagination.previous}>Previous responses</Link>}{workPagination.next && <Link href={workPagination.next}>More responses</Link>}</nav></>}</section></div>
    </Shell>;
}
