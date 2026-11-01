import { type ReactNode, useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { BookOpen01, ArrowRight, CheckCircle, AlertCircle } from '@untitledui/icons';
import { Button } from '@/components/base/buttons/button';
import { Badge } from '@/components/base/badges/badges';
import { LoadingIndicator } from '@/components/application/loading-indicator/loading-indicator';
import {EmptyState} from '@/components/application/empty-state/empty-state';
import {WorkspaceShell} from '@/components/workspace-shell';
import type {Shared} from '@/types';
export {Button, Badge};

export function Shell({children,logo}:{children:ReactNode;logo?:string|null}) {
    const {auth,flash,errors,workspace}=usePage<Shared>().props;
    const [loading,setLoading]=useState(false);
    const {url}=usePage<Shared>();
    useEffect(()=>{const a=router.on('start',()=>setLoading(true));const b=router.on('finish',()=>setLoading(false));return()=>{a();b();};},[]);
    return <WorkspaceShell logo={logo} user={auth.user} path={url.split('?')[0]} onLogout={()=>router.post('/logout')} notices={<>{loading&&<div className="loading-bar" role="status"><LoadingIndicator size="sm"/><span>Loading…</span></div>}{flash.message&&<div className="notification" role="status"><CheckCircle/>{flash.message}</div>}{Object.keys(errors).length>0&&<div className="notification error" role="alert"><AlertCircle/>{Object.values(errors).join(' ')}</div>}</>}>{workspace?.is_trial&&<div className="trial-banner"><div><strong>Trial workspace</strong><p>Imports and edits here are separate from your main library.</p></div>{auth.user?.role==='admin'&&<Button color="secondary" onClick={()=>router.post('/admin/workspace',{mode:'main'})}>Return to main library</Button>}</div>}{children}</WorkspaceShell>;
}
export function PageHeading({eyebrow,title,description,actions}:{eyebrow?:string;title:string;description?:string;actions?:ReactNode}) {
    return <div className="page-heading"><div>{eyebrow&&<p className="eyebrow">{eyebrow}</p>}<h1>{title}</h1>{description&&<p className="muted">{description}</p>}</div><div className="actions">{actions}</div></div>;
}
export function Empty({title,description,action}:{title:string;description:string;action?:ReactNode}) {return <div className="empty card"><EmptyState><EmptyState.Header><EmptyState.FeaturedIcon icon={BookOpen01} color="brand"/></EmptyState.Header><EmptyState.Content><EmptyState.Title>{title}</EmptyState.Title><EmptyState.Description>{description}</EmptyState.Description></EmptyState.Content><EmptyState.Footer>{action}</EmptyState.Footer></EmptyState></div>;}
export function PreviewBanner({blockers}:{blockers?:string[]}) {return <div className="preview-banner"><AlertCircle/><div><strong>Learning preview · review pending</strong><p>{blockers?.join(' · ')||'This lesson is available for pilot preview. Educational and media approval is pending.'}</p></div></div>;}
export function Go({href,children}:{href:string;children:ReactNode}) {return <Button href={href} color="secondary" iconTrailing={ArrowRight}>{children}</Button>;}
