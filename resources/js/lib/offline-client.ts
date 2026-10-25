import type {Profile,Draft,CachedFile} from './offline-model';
import type {OfflineBlob} from './offline-package';
import type {Lesson,Progress} from '@/types';
import type {OfflineDocument} from './offline-package';
import {getRecord,putRecord,listRecords} from './offline-storage';
export type Snapshot={lesson:Lesson;document:OfflineDocument;base_id:number;base_sha:string;base_token:string;head:{id:number;sha:string};files:Array<Omit<CachedFile,'localUrl'>>;progress:Progress};
export class ApiError extends Error{status:number;constructor(message:string,status:number){super(message);this.status=status;}}
export async function jsonResponse(response:Response){
 let data;try{data=await response.json();}catch{throw new ApiError('Invalid server response. Sign in again or retry when connected.',response.status);}
 if(!response.ok)throw new ApiError(Object.values(data.errors??{}).flat().join(' ')||data.message||'The request failed.',response.status);
 return data;
}
export const session=async():Promise<Profile>=>jsonResponse(await fetch('/offline-api/session',{headers:{Accept:'application/json'},cache:'no-store'}));
export const fetchSnapshot=async(id:number):Promise<Snapshot>=>jsonResponse(await fetch(`/offline-api/lessons/${id}`,{headers:{Accept:'application/json'},cache:'no-store'}));
export const recordKey=(draft:Pick<Draft,'profileKey'|'id'>)=>`${draft.profileKey}/${draft.id}`;
export function freshDraft(profile:Profile,document:OfflineDocument):Draft{return {id:crypto.randomUUID(),profileKey:profile.key,document,baseDocument:null,base_id:null,base_sha:null,lesson:null,files:[],attachments:[],progress:{},baseProgress:{},events:[],dirty:true,updatedAt:Date.now(),journal:[],complete:true};}
export async function post(profile:Profile|null,path:string,payload:Record<string,unknown>,media?:Map<string,Blob>){
 const account=await session();if(account.key!==profile?.key)throw new Error('Sign in to the account and workspace that owns these downloads. Local drafts can be copied to your signed-in teacher account.');
 const headers:Record<string,string>={Accept:'application/json','X-CSRF-TOKEN':account.csrf_token??''};let body:BodyInit;
 if(media){const form=new FormData();for(const [key,value] of Object.entries(payload)){if(value===null)form.append(key,'');else if(typeof value==='object')form.append(key,JSON.stringify(value));else if(typeof value==='boolean')form.append(key,value?'1':'0');else form.append(key,String(value));}for(const [id,file] of media)form.append(`media[${id}]`,file,id);body=form;}
 else{headers['Content-Type']='application/json';const encoded={...payload};for(const key of ['document','base_document','resolutions'])if(encoded[key]!==null&&typeof encoded[key]==='object')encoded[key]=JSON.stringify(encoded[key]);body=JSON.stringify(encoded);}
 return jsonResponse(await fetch(path,{method:'POST',headers,body}));
}
export async function attachBlobs(draft:Draft,blobs:OfflineBlob[]):Promise<Draft>{
 const attachments=[];for(const blob of blobs){const key=`${recordKey(draft)}/${blob.id}`;await putRecord('blobs',key,blob);attachments.push({id:blob.id,path:blob.path,sha256:blob.sha256,mime:blob.mime,key});}return {...draft,attachments};
}
export async function uploadedBlobs(draft:Draft,selectedIds?:string[]):Promise<Map<string,Blob>>{
 const files=new Map<string,Blob>();for(const attachment of draft.attachments){if(selectedIds&&!selectedIds.includes(attachment.id))continue;const value=await getRecord<OfflineBlob>('blobs',attachment.key);if(!value)throw new Error(`Missing local media ${attachment.path}. Restore it before syncing.`);files.set(attachment.id,value.file);}if(selectedIds?.some(id=>!files.has(id)))throw new Error('The pending sync needs media from its complete ZIP backup. Restore that backup before retrying.');return files;
}
export function saveFile(blob:Blob,name:string){const url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=name;link.click();setTimeout(()=>URL.revokeObjectURL(url),60000);}
export async function pruneDeviceMedia(profileKey:string):Promise<void>{
 if(typeof caches==='undefined')return;const prefix=`/offline-media/${encodeURIComponent(profileKey)}/`;
 const drafts=(await listRecords<Draft>('drafts')).filter(draft=>draft.profileKey===profileKey);const used=new Set(drafts.flatMap(draft=>[...draft.files.map(file=>file.localUrl),...draft.attachments.filter(file=>file.mime==='text/html').map(file=>prefix+file.sha256)]));
 const cache=await caches.open('nthanda-media-v1');for(const request of await cache.keys()){const path=new URL(request.url).pathname;if(path.startsWith(prefix)&&!used.has(path))await cache.delete(request);}
}
