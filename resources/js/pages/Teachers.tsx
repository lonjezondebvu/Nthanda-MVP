import {FormEvent} from 'react';
import {Head, useForm} from '@inertiajs/react';
import {Shell, PageHeading, Button, Empty} from '@/components/studio';
import {Input} from '@/components/base/input/input';

type SchoolClassOption = {id:number; name:string};
type Teacher = {id:number; name:string; email:string; class_ids:number[]; deactivated:boolean};

function ClassChecks({classes, selected, onChange}:{classes:SchoolClassOption[]; selected:string[]; onChange:(values:string[])=>void}) {
    return <fieldset className="disability-fields"><legend>Classes</legend><div className="choice-grid">{classes.map(schoolClass => <label key={schoolClass.id}><input type="checkbox" checked={selected.includes(String(schoolClass.id))} onChange={event => onChange(event.target.checked ? [...selected, String(schoolClass.id)] : selected.filter(id => id !== String(schoolClass.id)))}/>{schoolClass.name}</label>)}</div></fieldset>;
}

function AddTeacher({classes}:{classes:SchoolClassOption[]}) {
    const form = useForm({name:'', email:'', password:'', classes:[] as string[]});
    const submit = (event:FormEvent) => {
        event.preventDefault();
        form.transform(data => ({...data, classes:data.classes.map(Number)}));
        form.post('/admin/teachers', {preserveScroll:true, onSuccess:() => form.reset()});
    };
    return <form className="account-form" onSubmit={submit}>
        <h2>New teacher</h2>
        <Input label="Full name" value={form.data.name} onChange={value => form.setData('name', value)} isRequired isInvalid={!!form.errors.name} hint={form.errors.name}/>
        <Input label="Email address" type="email" value={form.data.email} onChange={value => form.setData('email', value)} isRequired isInvalid={!!form.errors.email} hint={form.errors.email}/>
        <Input label="Password" type="password" value={form.data.password} onChange={value => form.setData('password', value)} isRequired isInvalid={!!form.errors.password} hint={form.errors.password || 'At least 8 characters.'}/>
        <ClassChecks classes={classes} selected={form.data.classes} onChange={values => form.setData('classes', values)}/>
        {form.errors.classes && <p className="field-error">{form.errors.classes}</p>}
        <Button type="submit" isDisabled={classes.length === 0 || form.data.classes.length === 0} isLoading={form.processing}>Add teacher</Button>
    </form>;
}

function TeacherRow({teacher, classes}:{teacher:Teacher; classes:SchoolClassOption[]}) {
    const form = useForm({name:teacher.name, password:'', deactivated:teacher.deactivated, classes:teacher.class_ids.map(String)});
    const submit = (event:FormEvent) => {
        event.preventDefault();
        form.transform(data => ({name:data.name, deactivated:data.deactivated, classes:data.classes.map(Number), password:data.password || null}));
        form.patch('/admin/teachers/'+teacher.id, {preserveScroll:true});
    };
    return <form className="catalogue-card" onSubmit={submit}>
        <Input label="Full name" value={form.data.name} onChange={value => form.setData('name', value)} isRequired isInvalid={!!form.errors.name} hint={form.errors.name}/>
        <p className="muted">{teacher.email}</p>
        <ClassChecks classes={classes} selected={form.data.classes} onChange={values => form.setData('classes', values)}/>
        {form.errors.classes && <p className="field-error">{form.errors.classes}</p>}
        <Input label="New password" type="password" value={form.data.password} onChange={value => form.setData('password', value)} isInvalid={!!form.errors.password} hint={form.errors.password || 'Leave blank to keep the current password.'}/>
        <label className="deactivate-choice"><input type="checkbox" checked={form.data.deactivated} onChange={event => form.setData('deactivated', event.target.checked)}/>Deactivated</label>
        <Button type="submit" size="sm" isDisabled={form.data.classes.length === 0} isLoading={form.processing}>Save</Button>
    </form>;
}

export default function Teachers({teachers, classes}:{teachers:Teacher[]; classes:SchoolClassOption[]}) {
    return <Shell><Head title="Teachers"/>
        <PageHeading eyebrow="STAFF" title="Teachers" description="Create a teacher and assign the classes they look after."/>
        {classes.length === 0 && <Empty title="Add a class first" description="Teachers need at least one class before you can create an account."/>}
        <AddTeacher classes={classes}/>
        <div className="catalogue">{teachers.map(teacher => <TeacherRow key={`${teacher.id}-${teacher.class_ids.join('-')}-${teacher.deactivated}`} teacher={teacher} classes={classes}/>)}</div>
    </Shell>;
}
