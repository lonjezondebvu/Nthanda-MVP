import {Head, useForm} from '@inertiajs/react';
import {Shell, PageHeading, Button, Empty} from '@/components/studio';

type Student = {id:number; name:string; email:string; school_class_id:number|null; disabilities:string[]; disability_note:string|null; deactivated:boolean};
type SchoolClassOption = {id:number; name:string};

function StudentRow({student, classes}:{student:Student; classes:SchoolClassOption[]}) {
    const form = useForm({school_class_id:student.school_class_id ? String(student.school_class_id) : '', deactivated:student.deactivated});
    return <form className="student-row" onSubmit={event => {event.preventDefault(); form.transform(data => ({school_class_id:Number(data.school_class_id), deactivated:data.deactivated})); form.patch('/admin/students/'+student.id, {preserveScroll:true});}}>
        <div><strong>{student.name}</strong><p className="muted">{student.email}</p></div>
        <div><p>{student.disabilities.length ? student.disabilities.join(', ') : 'No disability shared'}</p>{student.disability_note && <p className="muted">{student.disability_note}</p>}</div>
        <label>Class<select value={form.data.school_class_id} onChange={event => form.setData('school_class_id', event.target.value)} required><option value="" disabled>Select a class</option>{classes.map(schoolClass => <option key={schoolClass.id} value={schoolClass.id}>{schoolClass.name}</option>)}</select>{form.errors.school_class_id && <span className="field-error">{form.errors.school_class_id}</span>}</label>
        <label className="deactivate-choice"><input type="checkbox" checked={form.data.deactivated} onChange={event => form.setData('deactivated', event.target.checked)}/>Deactivated</label>
        <Button type="submit" size="sm" isDisabled={classes.length === 0 || form.data.school_class_id === ''} isLoading={form.processing}>Save</Button>
    </form>;
}

export default function Students({students, classes}:{students:Student[]; classes:SchoolClassOption[]}) {
    return <Shell><Head title="Students"/><PageHeading eyebrow="LEARNERS" title="Students" description="Change a student's class or deactivate an account. Disability notes stay visible only here."/>
        {students.length === 0 ? <Empty title="No students yet" description="Students appear here after they create an account."/> : <div className="student-list">{students.map(student => <StudentRow key={`${student.id}-${student.school_class_id ?? 'none'}-${student.deactivated}`} student={student} classes={classes}/>)}</div>}
    </Shell>;
}
