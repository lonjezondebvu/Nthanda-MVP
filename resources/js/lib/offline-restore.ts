import type {Draft} from './offline-model';
import {previewLesson,isDirty} from './offline-model.ts';
import {validateDocument} from './offline-package.ts';
import type {Lesson,Question,Media,Activity,Progress} from '@/types';
const object=(value:unknown):Record<string,any>=>value&&typeof value==='object'&&!Array.isArray(value)?value as Record<string,any>:{};
const strings=(value:unknown):string[]=>Array.isArray(value)?value.filter(item=>typeof item==='string'):[];
const uuid=(value:unknown)=>typeof value==='string'&&/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value);
function progress(value:unknown):Progress{return Object.fromEntries(Object.entries(object(value)).map(([id,raw])=>{const record=object(raw);return [id,{read:record.read===true,confidence:[1,2,3].includes(record.confidence)?record.confidence:null,activities:strings(record.activities)}];}));}
function publicLesson(value:unknown,draft:Draft):Lesson{
 const raw=object(value),lesson=previewLesson(draft.document,null,[]);lesson.id=draft.base_id??0;lesson.version=typeof raw.version==='string'?raw.version:'Restored device copy';
 lesson.questions=(Array.isArray(raw.questions)?raw.questions:[]).flatMap((value:unknown)=>{
  const q=object(value);if(typeof q.id!=='string'||typeof q.prompt!=='string'||!['mc','short','multi','match','order'].includes(q.kind)||typeof q.maxMarks!=='number'||q.maxMarks<=0)return [];
  const question:Question={id:q.id,prompt:q.prompt,kind:q.kind,maxMarks:q.maxMarks,criterionId:typeof q.criterionId==='string'?q.criterionId:'',domain:typeof q.domain==='string'?q.domain:'',sectionIds:strings(q.sectionIds)};
  for(const key of ['options','matchKeys','matchOptions','items'] as const)if(q[key]!==undefined)question[key]=strings(q[key]);return [question];
 });
 lesson.written={text:strings(object(raw.written).text)};const policies=object(raw.policies);lesson.policies={automatic:typeof policies.automatic==='string'?policies.automatic:'Submit when connected.',written:typeof policies.written==='string'?policies.written:'Teacher review.'};
 for(const section of lesson.sections){const source=(Array.isArray(raw.sections)?raw.sections:[]).find((item:unknown)=>object(item).id===section.id);if(!source)continue;
  section.activities=(Array.isArray(source.activities)?source.activities:[]).flatMap((value:unknown)=>{const activity=object(value);if(typeof activity.id!=='string'||typeof activity.title!=='string'||typeof activity.feedback!=='string')return [];const safe:Activity={id:activity.id,title:activity.title,feedback:activity.feedback};if(typeof activity.prompt==='string')safe.prompt=activity.prompt;if(Array.isArray(activity.options))safe.options=strings(activity.options);if(Array.isArray(activity.prompt_parts))safe.prompt_parts=activity.prompt_parts.flatMap((value:unknown)=>{const part=object(value);return typeof part.part_id==='string'&&typeof part.text==='string'?[{part_id:part.part_id,text:part.text}]:[];});return [safe];});
  section.alternatives=(Array.isArray(source.alternatives)?source.alternatives:[]).flatMap((value:unknown)=>{const item=object(value);return typeof item.id==='string'&&typeof item.text==='string'?[{id:item.id,title:typeof item.title==='string'?item.title:'Another explanation',text:item.text}]:[];});
  section.media=(Array.isArray(source.media)?source.media:[]).flatMap((value:unknown)=>{const item=object(value);if(typeof item.id!=='string'||typeof item.anchor_block_id!=='string'||!['image','audio','video','animation','pronunciation'].includes(item.type))return [];const media:Media={id:item.id,type:item.type,anchor_block_id:item.anchor_block_id,description:typeof item.description==='string'?item.description:item.id,placement:typeof item.placement==='string'?item.placement:'after',url:'',state:{enabled:true,deleted:false,position:null}};if(typeof item.term==='string')media.term=item.term;if(Array.isArray(item.aliases))media.aliases=strings(item.aliases);return [media];});
 }
 return lesson;
}
export function restoreDraftState(draft:Draft,value:unknown):Draft{
 const state=object(value);if(state.profile_key&&state.profile_key!==draft.profileKey)throw new Error('This backup belongs to another account/workspace. Select that workspace before restoring it.');
 const restored={...draft};
 if(state.base_document){validateDocument(state.base_document);restored.baseDocument=structuredClone(state.base_document);if(!Number.isSafeInteger(state.base_id)||state.base_id<=0||typeof state.base_sha!=='string'||!/^[a-f0-9]{64}$/.test(state.base_sha))throw new Error('Backup is missing its base revision.');restored.base_id=state.base_id;restored.base_sha=state.base_sha;if(typeof state.base_token!=='string'||!/^[a-f0-9]{64}$/.test(state.base_token))throw new Error('Backup is missing its signed base snapshot. Download the lesson again before syncing.');restored.base_token=state.base_token;}
 restored.progress=progress(state.progress);restored.baseProgress=progress(state.base_progress);
 restored.events=(Array.isArray(state.events)?state.events:[]).filter((event:unknown)=>{const item=object(event);return uuid(item.id)&&Number.isSafeInteger(item.lesson_id)&&typeof item.base_sha==='string'&&['read','confidence','activity','written','assessment'].includes(item.type)&&(item.section_id===null||typeof item.section_id==='string');});
 restored.journal=(Array.isArray(state.journal)?state.journal:[]).filter((item:unknown)=>{const record=object(item);return typeof record.id==='string'&&typeof record.time==='number'&&typeof record.message==='string';}).slice(-300);
 const answers=object(state.answers);restored.answers={written:typeof answers.written==='string'?answers.written:'',assessment:object(answers.assessment)};
 if(state.lesson)restored.lesson=publicLesson(state.lesson,restored);
 if(state.pending_operation){const operation=object(state.pending_operation);let payload;try{payload=JSON.parse(operation.fingerprint);}catch{throw new Error('Backup has an invalid pending sync operation.');}if(!uuid(operation.id)||typeof operation.version!=='string'||payload.profile_key!==draft.profileKey||!payload.document||(!Array.isArray(operation.uploadIds)&&operation.uploadIds!==undefined))throw new Error('Backup pending sync identity does not match this workspace.');validateDocument(payload.document);restored.operation={id:operation.id,version:operation.version,fingerprint:operation.fingerprint,uploadIds:operation.uploadIds?strings(operation.uploadIds):[]};}
 restored.dirty=isDirty(restored.document,restored.baseDocument);return restored;
}
