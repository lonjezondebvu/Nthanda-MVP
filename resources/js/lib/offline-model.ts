import type {OfflineDocument,OfflineBlock} from './offline-package';
import type {Lesson,Media,Progress,State} from '@/types';
export type Profile={key:string;user:{id:number;name:string;role:string};workspace:string;can_edit:boolean;csrf_token?:string};
export type CachedFile={url:string;path:string;mime:string;bytes:number;sha256?:string;localUrl:string};
export type WorkEvent={id:string;lesson_id:number;base_sha:string;section_id:string|null;type:'read'|'confidence'|'activity'|'written'|'assessment';value:unknown;base_value?:unknown};
export type Draft={answers?:Record<string,unknown>;id:string;profileKey:string;document:OfflineDocument;baseDocument:OfflineDocument|null;base_id:number|null;base_sha:string|null;base_token?:string;lesson:Lesson|null;files:CachedFile[];attachments:Array<{id:string;path:string;sha256:string;mime:string;key:string}>;progress:Progress;baseProgress:Progress;events:WorkEvent[];dirty:boolean;updatedAt:number;journal:Array<{id:string;time:number;message:string}>;operation?:{id:string;version:string;fingerprint:string;uploadIds?:string[]};complete:boolean};
export type Conflict={path:string;base:unknown;local:unknown;server:unknown;base_missing:boolean;local_missing:boolean;server_missing:boolean};
export type Resolution={choice:'local'|'server'|'manual';value?:unknown};
export type Review={head:{id:number;sha:string}|null;document:OfflineDocument;conflicts:Conflict[]};
const active:State={enabled:true,deleted:false,position:null};
export const identity=(prefix:string)=>`${prefix}-${crypto.randomUUID()}`;
export const newBlock=(type:OfflineBlock['type']='paragraph'):OfflineBlock=>({id:identity('B'),type,...(type==='list'?{items:['']} : type==='table'?{headers:['Column 1','Column 2'],rows:[['','']]}:{text:''})});
export function newDocument():OfflineDocument{
 const id=identity('S');return {manifest_id:identity('LESSON'),title:'Untitled lesson',subject:'General',form:1,language:'en',overview:{summary:''},sections:[{id,title:'First section',criterion_ids:[`C-${id}`],blocks:[newBlock()]}],media:[]};
}
function canonical(value:unknown):unknown{
 if(Array.isArray(value))return value.map(canonical);
 if(value&&typeof value==='object')return Object.fromEntries(Object.entries(value).sort(([a],[b])=>a.localeCompare(b)).map(([key,item])=>[key,canonical(item)]));
 return value;
}
export const isDirty=(document:OfflineDocument,base:OfflineDocument|null)=>!base||JSON.stringify(canonical(document))!==JSON.stringify(canonical(base));
export function syncUploadIds(draft:Draft,document:OfflineDocument,remote:{document:OfflineDocument;files:Array<{path:string;sha256?:string}>}|null):string[]{
 const references=document.media.flatMap(media=>{const accessibility=media.accessibility as Record<string,unknown>|undefined;return [{id:media.id,path:media.path},...(['captions','transcript'] as const).flatMap(kind=>typeof accessibility?.[`${kind}_path`]==='string'?[{id:`${media.id}:${kind}`,path:accessibility[`${kind}_path`] as string}]:[])];});
 return draft.attachments.filter(attachment=>{
  const ref=references.find(ref=>ref.id===attachment.id);if(!ref)return false;
  const known=remote?.document.media.some(media=>media.id===ref.id&&media.path===ref.path||(['captions','transcript'] as const).some(kind=>`${media.id}:${kind}`===ref.id&&(media.accessibility as Record<string,unknown>|undefined)?.[`${kind}_path`]===ref.path));
  if(!known)return true;
  const original=remote?.files.find(file=>file.path===`source/dist/${ref.path}`||file.path===`dist/${ref.path}`);
  return !original?.sha256||original.sha256!==attachment.sha256;
 }).map(attachment=>attachment.id);
}
export function queueWork(events:WorkEvent[],value:Omit<WorkEvent,'id'>):WorkEvent[]{
 const replace=['read','confidence'].includes(value.type);
 const existing=replace?events.find(event=>event.lesson_id===value.lesson_id&&event.section_id===value.section_id&&event.type===value.type):null;
 const next={...value,base_value:existing?existing.base_value:value.base_value,id:crypto.randomUUID()};
 return [...events.filter(event=>!existing||event.id!==existing.id),next];
}
export function previewLesson(document:OfflineDocument,downloaded:Lesson|null,files:CachedFile[],attachmentUrls:Record<string,string>={},baseDocument:OfflineDocument|null=null):Lesson{
 const localUrl=(url:string|undefined|null)=>files.find(file=>file.url===url)?.localUrl??'';
 return {...downloaded,id:downloaded?.id??0,manifest_id:document.manifest_id,title:document.title,version:downloaded?.version??'Local draft',overview:document.overview,syllabus:{subject:document.subject,form:Number(document.form),edition:downloaded?.syllabus.edition??'Offline draft'},publication:downloaded?.publication??{status:'draft',blockers:['Local draft; review before syncing.']},active_count:document.sections.length,questions:downloaded?.questions??[],written:downloaded?.written??{text:[]},policies:downloaded?.policies??{automatic:'Submit answers when connected.',written:'Teacher review when connected.'},logo_url:localUrl(downloaded?.logo_url)||null,review_flags:[],sections:document.sections.map((section,index)=>{
  const original=downloaded?.sections.find(item=>item.id===section.id);
  const media:Media[]=(original?.media??[]).filter(item=>!baseDocument?.media.some(base=>base.id===item.id)||document.media.some(current=>current.id===item.id)).map(item=>({...item,url:attachmentUrls[item.id]??localUrl(item.url),captions_url:attachmentUrls[`${item.id}:captions`]??localUrl(item.captions_url),transcript_url:attachmentUrls[`${item.id}:transcript`]??localUrl(item.transcript_url)}));
  for(const item of document.media){
   if(!attachmentUrls[item.id])continue;
   const references=item.references??[{section_id:item.section_id,anchor_block_id:item.anchor_block_id,placement:item.placement}];
   for(const reference of references){
    if(reference.section_id!==section.id||media.some(existing=>existing.id===item.id&&existing.anchor_block_id===reference.anchor_block_id))continue;
    media.push({id:item.id,type:item.type as Media['type'],description:String((item.accessibility as {description?:string})?.description??item.id),term:typeof item.term==='string'?item.term:undefined,aliases:Array.isArray(item.aliases)?item.aliases as string[]:undefined,anchor_block_id:reference.anchor_block_id??section.blocks[0]?.id??'',placement:String(reference.placement??'after'),url:attachmentUrls[item.id],captions_url:attachmentUrls[`${item.id}:captions`],transcript_url:attachmentUrls[`${item.id}:transcript`],state:active});
   }
  }
  return {...section,order:index+1,state:original?.state??active,blocks:section.blocks.map(block=>({...block,state:active})),media,activities:original?.activities??[],alternatives:original?.alternatives??[]};
 })};
}

