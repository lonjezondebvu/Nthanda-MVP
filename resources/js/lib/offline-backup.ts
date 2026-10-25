import type {Draft} from './offline-model';
import type {OfflineBlob,OfflineMedia} from './offline-package';
import {writeOfflineArchive} from './offline-package';
import {getRecord,sha256} from './offline-storage';
export function deviceState(draft:Draft){return {profile_key:draft.profileKey,base_id:draft.base_id,base_sha:draft.base_sha,base_token:draft.base_token,base_document:draft.baseDocument,progress:draft.progress,base_progress:draft.baseProgress,events:draft.events,answers:draft.answers,journal:draft.journal,pending_operation:draft.operation,lesson:draft.lesson};}
export async function draftArchive(draft:Draft):Promise<Blob>{
 const document=structuredClone(draft.document),files=new Map<string,Blob>();const cache=await caches.open('nthanda-media-v1');
 const obtain=async(id:string,path:string)=>{
  const attachment=draft.attachments.find(item=>item.id===id||item.path===path);if(attachment){const value=await getRecord<OfflineBlob>('blobs',attachment.key);if(value)return value.file;}
  const normalized=draft.lesson?.sections.flatMap(section=>section.media).find(item=>item.id===id);
  const file=draft.files.find(item=>item.path===`source/dist/${path}`||item.path===`dist/${path}`||item.url===normalized?.url);
  if(file){const response=await cache.match(file.localUrl);if(response)return response.blob();}
  return null;
 };
 const groups=new Set<string>(),media:OfflineMedia[]=[];
 for(const item of document.media){
  const group=item.path.replace(/\.part\d+$/,'');if(group!==item.path){if(groups.has(group))continue;groups.add(group);}
  const blob=await obtain(item.id,item.path);if(!blob){if(item.required!==false)throw new Error(`Download required media before making a complete ZIP backup: ${item.path}. You can export a state file without media.`);continue;}
  const path=group;const current:OfflineMedia={...item,path,bytes:blob.size,sha256:await sha256(blob)};files.set(path,blob);
  const accessibility=current.accessibility as Record<string,unknown>|undefined;
  for(const [key,suffix] of [['captions_path','captions'],['transcript_path','transcript']] as const){if(typeof accessibility?.[key]==='string'){const value=await obtain(`${item.id}:${suffix}`,accessibility[key] as string);if(!value)throw new Error(`Missing ${suffix} for ZIP backup. Export state separately, or download the complete lesson.`);files.set(accessibility[key] as string,value);accessibility[`${suffix}_bytes`]=value.size;accessibility[`${suffix}_sha256`]=await sha256(value);}}
  media.push(current);
 }
 for(const section of draft.lesson?.sections??[]){for(const item of section.media){if(media.some(known=>known.id===item.id)||draft.baseDocument?.media.some(base=>base.id===item.id))continue;const blob=await obtain(item.id,'');if(!blob)continue;const extension=({'image/svg+xml':'svg','image/png':'png','image/jpeg':'jpg','audio/mpeg':'mp3','audio/mp4':'m4a','video/mp4':'mp4','text/html':'html'} as Record<string,string>)[blob.type.split(';')[0]]??'bin';const path=`media/offline/${await sha256(new Blob([item.id]))}.${extension}`;files.set(path,blob);media.push({id:item.id,type:item.type,path,section_id:section.id,anchor_block_id:item.anchor_block_id,placement:item.placement,required:true,production_status:'produced',review_status:'pending',reviewer:null,approved_source_version:null,accessibility:{description:item.description},sha256:await sha256(blob),bytes:blob.size});}}
 document.media=media;
 const referenced=new Set(media.flatMap(item=>{const accessibility=item.accessibility as Record<string,unknown>|undefined;return [item.id,...(['captions','transcript'] as const).filter(kind=>typeof accessibility?.[`${kind}_path`]==='string').map(kind=>`${item.id}:${kind}`)];}));
 const pendingUploads=[];
 for(const id of draft.operation?.uploadIds??[]){if(referenced.has(id))continue;const attachment=draft.attachments.find(item=>item.id===id);const value=attachment?await getRecord<OfflineBlob>('blobs',attachment.key):null;if(!value)throw new Error('A pending sync upload is missing. Restore its media before exporting the complete backup.');const archivePath=`pending-sync/${value.sha256}.bin`;files.set(archivePath,value.file);pendingUploads.push({id,path:value.path,archive_path:archivePath,sha256:value.sha256,mime:value.mime,bytes:value.file.size});}
 return writeOfflineArchive(document,files,{...deviceState(draft),pending_uploads:pendingUploads});
}
