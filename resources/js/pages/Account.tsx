import {FormEvent} from 'react';
import {Head, useForm} from '@inertiajs/react';
import {Shell, PageHeading, Button} from '@/components/studio';
import {Input} from '@/components/base/input/input';
import {ClassSelect, DisabilityFields, type DisabilityOption} from '@/components/student-fields';

type Profile = {name:string; email:string; school_class_id:number|null; disabilities:string[]; disability_note:string|null};

export default function Account({classes, disabilities, profile}:{classes:{id:number; name:string}[]; disabilities:DisabilityOption[]; profile:Profile}) {
    const form = useForm({
        name:profile.name,
        school_class_id:profile.school_class_id ? String(profile.school_class_id) : '',
        disabilities:profile.disabilities,
        disability_note:profile.disability_note ?? '',
        current_password:'',
        password:'',
        password_confirmation:'',
    });
    const submit = (event:FormEvent) => {
        event.preventDefault();
        form.transform(data => ({...data, school_class_id:Number(data.school_class_id), disability_note:data.disability_note || null, password:data.password || null, password_confirmation:data.password ? data.password_confirmation : null, current_password:data.password ? data.current_password : null}));
        form.patch('/account');
    };
    return <Shell><Head title="Your account"/><PageHeading eyebrow="YOUR DETAILS" title="Your account" description="Update your name, class, disability information, or password."/>
        <form className="account-form" onSubmit={submit}>
            <Input label="Full name" value={form.data.name} onChange={value => form.setData('name', value)} isRequired autoComplete="name" isInvalid={!!form.errors.name} hint={form.errors.name}/>
            <p className="muted">Email: {profile.email}</p>
            <ClassSelect id="account-class" classes={classes} value={form.data.school_class_id} error={form.errors.school_class_id} onChange={value => form.setData('school_class_id', value)}/>
            <DisabilityFields options={disabilities} selected={form.data.disabilities} note={form.data.disability_note} error={form.errors.disabilities} noteError={form.errors.disability_note} onChange={values => form.setData('disabilities', values)} onNote={value => form.setData('disability_note', value)}/>
            <Input label="Current password" type="password" value={form.data.current_password} onChange={value => form.setData('current_password', value)} autoComplete="current-password" isInvalid={!!form.errors.current_password} hint={form.errors.current_password || 'Required only when you change your password.'}/>
            <Input label="New password" type="password" value={form.data.password} onChange={value => form.setData('password', value)} autoComplete="new-password" isInvalid={!!form.errors.password} hint={form.errors.password}/>
            <Input label="Confirm new password" type="password" value={form.data.password_confirmation} onChange={value => form.setData('password_confirmation', value)} autoComplete="new-password" isInvalid={!!form.errors.password_confirmation} hint={form.errors.password_confirmation}/>
            <Button type="submit" isDisabled={classes.length === 0} isLoading={form.processing}>Save account</Button>
        </form>
    </Shell>;
}
