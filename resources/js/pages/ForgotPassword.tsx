import {Head, Link, useForm, usePage} from '@inertiajs/react';
import {Button} from '@/components/base/buttons/button';
import {Input} from '@/components/base/input/input';
import type {Shared} from '@/types';

export default function ForgotPassword() {
 const form = useForm({email: ''});
 const {flash} = usePage<Shared>().props;
 return <><Head title="Reset your password"/><main className="lms-signin" style={{minHeight:'100vh'}}><div className="lms-signin-content">
  <Link className="lms-brand" href="/login"><img src="/branding/nthanda-logo.png" alt=""/><span><strong>Nthanda</strong><small>LEARNING PORTAL</small></span></Link>
  <div className="lms-signin-heading"><p className="lms-form-eyebrow">ACCOUNT RECOVERY</p><h1>Find your way back.</h1><p>Enter your account email and we’ll send you a link to choose a new password.</p></div>
  <form onSubmit={event => {event.preventDefault(); form.post('/forgot-password');}}>
   <Input label="Email address" type="email" autoComplete="email" isRequired value={form.data.email} onChange={value => form.setData('email', value)} isInvalid={!!form.errors.email} hint={form.errors.email}/>
   {flash.message && <p role="status">{flash.message}</p>}
   <Button type="submit" size="lg" isLoading={form.processing}>Send reset link</Button>
  </form>
  <p className="lms-register-link"><Link href="/login">Back to sign in</Link></p>
 </div></main></>;
}
