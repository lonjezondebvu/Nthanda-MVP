import {useState,useRef} from 'react';
import {VolumeMax} from '@untitledui/icons';
import {Table} from '@/components/application/table/table';
import {pronunciationSegments} from '@/lib/pronunciation';
import {router} from '@inertiajs/react';
import {Button} from '@/components/base/buttons/button';
import {Input} from '@/components/base/input/input';
import type {Block,Media,Activity} from '@/types';
function PronunciationButton({media}:{media:Media}) {
 const audio=useRef<HTMLAudioElement>(null);const [failed,setFailed]=useState(false);
 return <span className="inline-pronunciation"><Button size="sm" color="tertiary" iconLeading={VolumeMax} aria-label={'Hear '+media.term} onClick={()=>{audio.current?.play().catch(()=>setFailed(true));}}/><audio ref={audio} preload="none" src={media.url} onError={()=>setFailed(true)}/>{failed&&<span role="status">Recording unavailable</span>}</span>;
}
export function ContentBlock({block,pronunciations=[]}:{block:Block;pronunciations?:Media[]}){
 const combined=[block.text||'',...(block.items||[]),...(block.headers||[]),...(block.rows||[]).flat()].join(' ');
 const used=new Set(pronunciationSegments(combined,pronunciations).flatMap(s=>s.media?[s.media.id]:[]));
 const unmatched=pronunciations.filter((m,i,a)=>!used.has(m.id)&&a.findIndex(x=>x.id===m.id)===i);
 const text=(value:string)=>pronunciationSegments(value,pronunciations).map((part,i)=><span key={i}>{part.text}{part.media&&<PronunciationButton media={part.media}/>}</span>);
 const body=(()=>{switch(block.type){case 'heading':return <h2>{text(block.text||'')}</h2>;case 'paragraph':return <p>{text(block.text||'')}</p>;case 'list':return <ul>{block.items?.map((v,i)=><li key={i}>{text(v)}</li>)}</ul>;case 'table':return <div className="table-scroll"><Table aria-label="Lesson content table"><Table.Header>{block.headers?.map((h,i)=><Table.Head key={i} id={`column-${i}`} isRowHeader={i===0} label={h}/>)}</Table.Header><Table.Body>{block.rows?.map((r,i)=><Table.Row key={i} id={`row-${i}`} textValue={r.join(' ')}>{r.map((c,j)=><Table.Cell key={j}>{text(c)}</Table.Cell>)}</Table.Row>)}</Table.Body></Table></div>;}})();
 return <>{body}{unmatched.map(m=><MediaItem key={m.id} media={m}/>)}</>;
}
export function MediaItem({media:m}:{media:Media}){
 const [failed,setFailed]=useState(false);
 if(failed)return <div className="media-error" role="status">{m.description} could not load. Your progress has not been marked complete.</div>;
 return <figure className={'media media-'+m.type}>{m.type==='image'?<img src={m.url} alt={m.description} loading="lazy" onError={()=>setFailed(true)}/>:m.type==='video'?<video controls preload="metadata" aria-label={m.description} onError={()=>setFailed(true)}><source src={m.url}/>{m.captions_url&&<track kind="captions" src={m.captions_url} srcLang="en" label="English" default/>}</video>:m.type==='animation'?<iframe src={m.url} title={m.description} sandbox="allow-scripts" loading="lazy" referrerPolicy="no-referrer"/>:<div className="audio-control"><label>{m.type==='pronunciation'?'Pronunciation · ':''}{m.term||m.description}</label><audio controls preload="none" src={m.url} aria-label={m.term||m.description} onError={()=>setFailed(true)}/></div>}<figcaption>{m.description}{m.transcript_url&&<> · <a href={m.transcript_url} target="_blank" rel="noreferrer">Read transcript</a></>}</figcaption></figure>;
}
export function Practice({activity:a,lessonId,sectionId,explored}:{activity:Activity;lessonId:number;sectionId:string;explored:boolean}){
 const [response,setResponse]=useState('');const [feedback,setFeedback]=useState(false);
 return <div className="practice"><span className="eyebrow">PRACTICE · NO ASSESSED MARK</span><h3>{a.title}</h3>{a.prompt&&<p>{a.prompt}</p>}{a.prompt_parts?.map(p=><p key={p.part_id}>{p.text}</p>)}{a.options?<fieldset><legend>Your choice</legend>{a.options.map(o=><label key={o} className="choice"><input type="radio" name={a.id} checked={response===o} onChange={()=>setResponse(o)}/>{o}</label>)}</fieldset>:<Input label="Your reflection (optional, kept on this page)" value={response} onChange={setResponse}/>}<Button color="secondary" size="sm" onClick={()=>{setFeedback(true);router.post(`/lessons/${lessonId}/progress`,{section_id:sectionId,activity_id:a.id},{preserveScroll:true});}}>{explored?'Revisit feedback':'Explore feedback'}</Button>{feedback&&<p className="practice-feedback">{a.feedback}</p>}</div>;
}
