import {Head, Link, useForm} from '@inertiajs/react';
import {Button} from '@/components/base/buttons/button';
import {Input} from '@/components/base/input/input';

export default function ResetPassword({token, email}: {token: string; email: string}) {
 const form = useForm({token, email, password: '', password_confirmation: ''});
 return <><Head title="Choose a new password"/><main className="lms-signin" style={{minHeight:'100vh'}}><div className="lms-signin-content">
  <Link className="lms-brand" href="/login"><img src="/branding/nthanda-logo.png" alt=""/><span><strong>Nthanda</strong><small>LEARNING PORTAL</small></span></Link>
  <div className="lms-signin-heading"><p className="lms-form-eyebrow">A FRESH START</p><h1>Choose a new password.</h1><p>Use at least eight characters. A longer, unique passphrase helps keep your learning account safe.</p></div>
  <form onSubmit={event => {event.preventDefault(); form.post('/reset-password', {onFinish: () => form.reset('password', 'password_confirmation')});}}>
   <Input label="Email address" type="email" autoComplete="username" isRequired value={form.data.email} onChange={value => form.setData('email', value)} isInvalid={!!form.errors.email} hint={form.errors.email}/>
   <Input label="New password" type="password" autoComplete="new-password" isRequired value={form.data.password} onChange={value => form.setData('password', value)} isInvalid={!!form.errors.password} hint={form.errors.password}/>
   <Input label="Confirm new password" type="password" autoComplete="new-password" isRequired value={form.data.password_confirmation} onChange={value => form.setData('password_confirmation', value)} isInvalid={!!form.errors.password_confirmation} hint={form.errors.password_confirmation}/>
   {form.errors.token && <p role="alert">{form.errors.token}</p>}
   <Button type="submit" size="lg" isLoading={form.processing}>Reset password</Button>
  </form>
  <p className="lms-register-link"><Link href="/forgot-password">Request a new reset link</Link> · <Link href="/login">Sign in</Link></p>
 </div></main></>;
}
