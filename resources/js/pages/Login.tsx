import {useForm, Head, usePage} from '@inertiajs/react';
import {ArrowRight, ShieldTick, WifiOff} from '@untitledui/icons';
import {Button} from '@/components/base/buttons/button';
import {Input} from '@/components/base/input/input';
import '../../css/login.css';
import type {Shared} from '@/types';

export default function Login(){
 const f=useForm({email:'',password:''});
 const {flash}=usePage<Shared>().props;
 return <><Head title="Sign in"/><div className="lms-login login-viewport">
  <section className="lms-introduction" aria-labelledby="lms-introduction-title">
   <img className="lms-corner-star" src="/branding/nthanda-star.png" alt="" aria-hidden="true"/>
   <a className="lms-brand" href="/login" aria-label="Nthanda Lesson Studio"><img src="/branding/nthanda-logo.png" alt=""/><span><strong>Nthanda</strong><small>LESSON STUDIO</small></span></a>
   <div className="lms-introduction-content">
    <p className="lms-eyebrow">A SPACE TO LEARN & GROW</p>
    <h2 id="lms-introduction-title">Great learning.<br/><span>New possibilities.</span></h2>
    <p className="lms-introduction-description">Bring your lessons, ideas and progress together. Wherever learning takes you.</p>
   </div>
   <div className="lms-introduction-footer"><span>NTHANDA · MALAWI</span><span>Made for curious minds.</span></div>
  </section>
  <main className="lms-signin"><div className="lms-portal-bar"><span>Lesson Studio</span><span className="lms-portal-pilot">Learning portal</span></div>
   <div className="lms-signin-content">
    <div className="lms-signin-heading"><p className="lms-form-eyebrow">WELCOME TO NTHANDA</p><h1>Sign in to learn.</h1><p>Your lessons and workspace, ready when you are.</p></div>
    <form onSubmit={e=>{e.preventDefault();f.post('/login');}}>
     <Input label="Email address" type="email" value={f.data.email} onChange={v=>f.setData('email',v)} isRequired autoComplete="username" placeholder="Enter your email address" isInvalid={!!f.errors.email} hint={f.errors.email}/>
     <Input label="Password" type="password" value={f.data.password} onChange={v=>f.setData('password',v)} isRequired autoComplete="current-password" isInvalid={!!f.errors.password} hint={f.errors.password}/>
     {flash.message&&<p className="field-error" role="alert">{flash.message}</p>}
     <Button type="submit" size="lg" isLoading={f.processing} iconTrailing={ArrowRight}>Sign in</Button>
    </form>
    <p className="lms-register-link"><a href="/forgot-password">Forgot your password?</a></p>
    <p className="lms-register-link">New student? <a href="/register">Create an account</a></p>
    <div className="lms-access-help"><ShieldTick aria-hidden="true"/><p><strong>Need help signing in?</strong><span>Contact your administrator for account or password support.</span></p></div>
    <div className="lms-access-divider"><span>Or keep learning offline</span></div>
    <a className="lms-offline-access" href="/offline"><span className="lms-offline-icon"><WifiOff aria-hidden="true"/></span><span><strong>Open offline workspace</strong><small>Continue with lessons saved on this device.</small></span><ArrowRight aria-hidden="true"/></a>
   </div>
   <footer className="lms-signin-footer"><span>© {new Date().getFullYear()} Nthanda</span><span>Lesson Studio</span></footer>
  </main>
 </div></>;
}
