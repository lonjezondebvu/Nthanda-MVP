import {FormEvent} from 'react';
import {Head, useForm} from '@inertiajs/react';
import {ArrowRight} from '@untitledui/icons';
import {Button} from '@/components/base/buttons/button';
import {Input} from '@/components/base/input/input';
import {ClassSelect, DisabilityFields, type DisabilityOption} from '@/components/student-fields';

type SchoolClassOption = {id:number; name:string};

export default function Register({classes, disabilities}:{classes:SchoolClassOption[]; disabilities:DisabilityOption[]}) {
    const form = useForm({name:'', email:'', password:'', password_confirmation:'', school_class_id:'', disabilities:[] as string[], disability_note:''});
    const submit = (event:FormEvent) => {
        event.preventDefault();
        form.transform(data => ({...data, school_class_id:Number(data.school_class_id), disability_note:data.disability_note || null}));
        form.post('/register');
    };
    return <><Head title="Create account"/><div className="lms-login">
        <section className="lms-introduction" aria-labelledby="lms-register-title">
            <img className="lms-corner-star" src="/branding/nthanda-star.png" alt="" aria-hidden="true"/>
            <a className="lms-brand" href="/login" aria-label="Nthanda Lesson Studio"><img src="/branding/nthanda-logo.png" alt=""/><span><strong>Nthanda</strong><small>LESSON STUDIO</small></span></a>
            <div className="lms-introduction-content">
                <p className="lms-eyebrow">A SPACE TO LEARN & GROW</p>
                <h2 id="lms-register-title">Your class.<br/><span>Your lessons.</span></h2>
                <p className="lms-introduction-description">Create a student account, choose your class, and tell us about any support you want us to know.</p>
            </div>
            <div className="lms-introduction-footer"><span>NTHANDA · MALAWI</span><span>Made for curious minds.</span></div>
        </section>
        <main className="lms-signin"><div className="lms-portal-bar"><span>Lesson Studio</span><span className="lms-portal-pilot">Student registration</span></div>
            <div className="lms-signin-content">
                <div className="lms-signin-heading"><p className="lms-form-eyebrow">JOIN NTHANDA</p><h1>Create your account.</h1><p>You will only see lessons filed for your class.</p></div>
                <form onSubmit={submit}>
                    <Input label="Full name" value={form.data.name} onChange={value => form.setData('name', value)} isRequired autoComplete="name" placeholder="Enter your name" isInvalid={!!form.errors.name} hint={form.errors.name}/>
                    <Input label="Email address" type="email" value={form.data.email} onChange={value => form.setData('email', value)} isRequired autoComplete="username" placeholder="Enter your email address" isInvalid={!!form.errors.email} hint={form.errors.email}/>
                    <Input label="Password" type="password" value={form.data.password} onChange={value => form.setData('password', value)} isRequired autoComplete="new-password" isInvalid={!!form.errors.password} hint={form.errors.password || 'At least 8 characters.'}/>
                    <Input label="Confirm password" type="password" value={form.data.password_confirmation} onChange={value => form.setData('password_confirmation', value)} isRequired autoComplete="new-password" isInvalid={!!form.errors.password_confirmation} hint={form.errors.password_confirmation}/>
                    <ClassSelect id="register-class" classes={classes} value={form.data.school_class_id} error={form.errors.school_class_id} onChange={value => form.setData('school_class_id', value)}/>
                    <DisabilityFields options={disabilities} selected={form.data.disabilities} note={form.data.disability_note} error={form.errors.disabilities} noteError={form.errors.disability_note} onChange={values => form.setData('disabilities', values)} onNote={value => form.setData('disability_note', value)}/>
                    <Button type="submit" size="lg" isDisabled={classes.length === 0} isLoading={form.processing} iconTrailing={ArrowRight}>Create account</Button>
                </form>
                <p className="lms-register-link">Already have an account? <a href="/login">Sign in</a></p>
            </div>
            <footer className="lms-signin-footer"><span>© {new Date().getFullYear()} Nthanda</span><span>Lesson Studio</span></footer>
        </main>
    </div></>;
}
