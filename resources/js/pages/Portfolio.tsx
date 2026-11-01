import {useRef} from 'react';
import {Head, Link, useForm} from '@inertiajs/react';
import {Shell, PageHeading, Button, Badge, Empty} from '@/components/studio';
import '../../css/portfolio.css';

type Entry = {id:number;title:string;reflection:string;lesson_title:string;learner_name:string;status:string;feedback:string|null;reviewer_name:string|null;reviewed_at:string|null;created_at:string;attachment_name:string|null;attachment_url:string|null};
type Props = {canSubmit:boolean;canReview:boolean;lessons:{id:number;title:string}[];entries:{data:Entry[];total:number;prev_page_url:string|null;next_page_url:string|null}};
const statusLabels:Record<string,string> = {submitted:'Awaiting feedback',accepted:'Accepted',needs_another_try:'Try again with feedback'};
function Review({entry}:{entry:Entry}) {
 const form = useForm({status:entry.status === 'submitted' ? 'accepted' : entry.status,feedback:entry.feedback ?? ''});
 return <details className="portfolio-review"><summary>{entry.feedback ? 'Update feedback' : 'Review this work'}</summary><form className="portfolio-form" onSubmit={event => {event.preventDefault();form.patch('/portfolio/'+entry.id+'/review',{preserveScroll:true});}}>
  <label>Outcome<select value={form.data.status} onChange={event => form.setData('status',event.target.value)}><option value="accepted">Accepted</option><option value="needs_another_try">Needs another try</option></select>{form.errors.status && <span role="alert" className="field-error">{form.errors.status}</span>}</label>
  <label>Feedback<textarea required rows={3} maxLength={5000} value={form.data.feedback} onChange={event => form.setData('feedback',event.target.value)} placeholder="Recognise what worked and suggest a specific next step."/>{form.errors.feedback && <span role="alert" className="field-error">{form.errors.feedback}</span>}</label>
  <Button size="sm" type="submit" isLoading={form.processing}>Share feedback</Button>
 </form></details>;
}
export default function Portfolio({canSubmit,canReview,lessons,entries}:Props) {
 const fileInput = useRef<HTMLInputElement>(null);
 const form = useForm<{lesson_id:string;title:string;reflection:string;attachment:File|null}>({lesson_id:'',title:'',reflection:'',attachment:null});
 return <Shell><Head title="Learning portfolio"/><PageHeading eyebrow="LEARNING BEYOND THE PAGE" title="Your ideas, made visible." description={canSubmit ? 'Capture an observation, explain an experiment, or reflect on something you made.' : 'Recognise practical learning and help your learners take their next step.'}/>
  <div className={canSubmit ? 'portfolio-layout' : ''}><section aria-label="Learning portfolio entries"><div className="portfolio-heading"><h2>{canSubmit ? 'My learning journey' : 'Learner portfolios'}</h2><span className="muted">{entries.total} entries</span></div>
   {entries.data.length === 0 ? <Empty title="A fresh page for your learning" description={canSubmit ? 'Choose a lesson and share what you discovered. You can add a photo, audio recording, or PDF.' : 'Practical work from learners in your classes will appear here.'}/> : <div className="portfolio-list">{entries.data.map(entry => <article className="portfolio-card" key={entry.id}>
    <div className="portfolio-heading"><Badge color={entry.status === 'accepted' ? 'success' : 'gray'}>{statusLabels[entry.status] ?? entry.status}</Badge><time dateTime={entry.created_at}>{new Date(entry.created_at).toLocaleDateString()}</time></div><h2>{entry.title}</h2><p className="muted">{entry.lesson_title}{canReview && ' · '+entry.learner_name}</p><p className="portfolio-reflection">{entry.reflection}</p>
    {entry.attachment_url && <a className="portfolio-attachment" href={entry.attachment_url}>Download {entry.attachment_name}</a>}
    {entry.feedback && <aside className="portfolio-feedback" aria-label="Teacher feedback"><strong>Feedback from {entry.reviewer_name ?? 'your teacher'}</strong><p>{entry.feedback}</p>{entry.status === 'needs_another_try' && canSubmit && <small>Use this feedback to submit a new entry with your improvements.</small>}</aside>}
    {canReview && <Review entry={entry}/>}</article>)}</div>}
   <nav className="portfolio-pagination" aria-label="Portfolio pages">{entries.prev_page_url && <Link href={entries.prev_page_url}>Previous page</Link>}{entries.next_page_url && <Link href={entries.next_page_url}>Next page</Link>}</nav>
  </section>{canSubmit && <aside className="portfolio-create"><p className="eyebrow">MAKE IT YOURS</p><h2>Share a discovery</h2><p className="muted">Your work is shared privately with authorised teachers and administrators.</p>{lessons.length === 0 ? <p>Your class needs a lesson before you can add work.</p> : <form className="portfolio-form" onSubmit={event => {event.preventDefault();form.post('/portfolio',{preserveScroll:true,forceFormData:true,onSuccess:() => {form.reset();if(fileInput.current) fileInput.current.value='';}});}}>
   <label>Lesson<select required value={form.data.lesson_id} onChange={event => form.setData('lesson_id',event.target.value)}><option value="">Choose a lesson</option>{lessons.map(lesson => <option key={lesson.id} value={lesson.id}>{lesson.title}</option>)}</select>{form.errors.lesson_id && <span role="alert" className="field-error">{form.errors.lesson_id}</span>}</label>
   <label>Give your work a title<input required maxLength={160} value={form.data.title} onChange={event => form.setData('title',event.target.value)} placeholder="What I discovered about growing plants"/>{form.errors.title && <span role="alert" className="field-error">{form.errors.title}</span>}</label>
   <label>Your reflection<textarea required rows={6} maxLength={10000} value={form.data.reflection} onChange={event => form.setData('reflection',event.target.value)} placeholder="What did you do? What did you notice? What would you try next?"/>{form.errors.reflection && <span role="alert" className="field-error">{form.errors.reflection}</span>}</label>
   <label>Evidence <span className="muted">(optional)</span><input ref={fileInput} type="file" accept=".jpg,.jpeg,.png,.webp,.mp3,.m4a,.ogg,.wav,.pdf" onChange={event => form.setData('attachment',event.target.files?.[0] ?? null)}/><small>One photo, audio recording, or PDF, up to 10 MB.</small>{form.errors.attachment && <span role="alert" className="field-error">{form.errors.attachment}</span>}</label>
   {form.progress && <progress aria-label="Uploading portfolio evidence" value={form.progress.percentage} max={100}>{form.progress.percentage}%</progress>}<Button type="submit" isLoading={form.processing}>Share my work</Button><small className="muted">Internet access is required to submit portfolio work.</small>
  </form>}</aside>}</div>
 </Shell>;
}
