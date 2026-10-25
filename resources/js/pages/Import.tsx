import {useState} from 'react';
import {Head,router} from '@inertiajs/react';
import {UploadCloud02,File06,ShieldTick,ArrowLeft} from '@untitledui/icons';
import {Shell,PageHeading,Button} from '@/components/studio';

type ImportEvent={stage:string;percent?:number;message:string;current?:number;total?:number;url?:string};
const labels:Record<string,string>={validate:'Checking ZIP',extract:'Extracting files',manifest:'Reading manifest',sections:'Importing sections',assessments:'Checking assessments',media:'Processing media',save:'Saving curriculum',complete:'Import complete'};

export default function Import(){
 const [file,setFile]=useState<File|null>(null);
 const [drag,setDrag]=useState(false);
 const [processing,setProcessing]=useState(false);
 const [upload,setUpload]=useState(0);
 const [events,setEvents]=useState<ImportEvent[]>([]);
 const [error,setError]=useState('');
 const [result,setResult]=useState<string|null>(null);
 const latest=events.at(-1);
 const percent=latest?.stage==='complete'?100:latest?35+(latest.percent??0)*0.65:upload*0.35;
 const choose=(value:File|null)=>{if(!processing){setFile(value);setError('');setEvents([]);setResult(null);setUpload(0);}};
 const submit=()=>{
  if(!file||processing)return;
  if(!file.name.toLowerCase().endsWith('.zip')||file.size>128*1024*1024){setError('Choose a ZIP archive no larger than 128 MiB.');return;}
  setProcessing(true);setError('');setEvents([]);setResult(null);setUpload(0);
  const xhr=new XMLHttpRequest();
  let offset=0;let terminal=false;let destination:string|null=null;
  const fail=(message:string)=>{terminal=true;setError(message);setProcessing(false);};
  const consume=()=>{
   const text=xhr.responseText;
   let newline=text.indexOf('\n',offset);
   while(newline>=0){
    const line=text.slice(offset,newline).trim();offset=newline+1;
    if(line){
     try{
      const event=JSON.parse(line) as ImportEvent;
      if(typeof event.stage==='string'){
       if(event.stage==='error'){fail(event.message);}
       else {setEvents(previous=>[...previous,event].slice(-200));
        if(event.stage==='complete'&&event.url){terminal=true;destination=event.url;setResult(event.url);setProcessing(false);}
       }
      }
     }catch{/* A non-stream error response is handled when the request ends. */}
    }
    newline=text.indexOf('\n',offset);
   }
  };
  xhr.open('POST','/admin/import-stream');
  xhr.setRequestHeader('Accept','application/json');
  const csrf=document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  if(csrf)xhr.setRequestHeader('X-CSRF-TOKEN',csrf);
  xhr.timeout=300000;
  xhr.upload.onprogress=event=>{if(event.lengthComputable)setUpload(Math.round(event.loaded/event.total*100));};
  xhr.onprogress=consume;
  xhr.onload=()=>{
   consume();
   if(destination){router.visit(destination);return;}
   if(!terminal){let message='The import stopped before completion. Retry the ZIP; completed imports are detected automatically.';
    try {const response=JSON.parse(xhr.responseText);message=Object.values(response.errors??{}).flat().join(' ')||response.message||message;}catch{if(xhr.status===413)message='The server rejected this upload because it is too large.';if(xhr.status===419)message='Your session expired. Refresh this page and try again.';}
    fail(message);
   }
  };
  xhr.onerror=()=>fail('Connection lost. Retry the import when the server is available.');
  xhr.ontimeout=()=>fail('The import timed out. Retry the ZIP to check whether it was saved.');
  const body=new FormData();body.append('package',file);xhr.send(body);
 };
 return <Shell><Head title="Import curriculum"/><Button href="/admin/lessons" color="link-gray" iconLeading={ArrowLeft}>Curriculum library</Button><PageHeading eyebrow="BRING YOUR CONTENT TOGETHER" title="Import a curriculum ZIP" description="We read the manifest and connect its sections, assessments and media."/><div className="import-layout"><form className="card padded" onSubmit={event=>{event.preventDefault();submit();}}><h2>Curriculum package</h2><p className="muted">Supported manifest: nthanda-pilot-2.0. Upload an edited ZIP with a new version number to keep previous versions.</p><label className={'dropzone '+(drag?'dragging':'')} onDragOver={event=>{event.preventDefault();setDrag(true);}} onDragLeave={()=>setDrag(false)} onDrop={event=>{event.preventDefault();setDrag(false);choose(event.dataTransfer.files[0]??null);}}><span className="icon-tile"><UploadCloud02/></span><strong>{file?file.name:'Click to upload or drag and drop'}</strong><span>ZIP archive · up to 128 MiB</span><input aria-label="Curriculum ZIP package" type="file" accept=".zip,application/zip" disabled={processing} onChange={event=>choose(event.target.files?.[0]??null)}/></label>{file&&<div className="selected-file"><File06/><div><strong>{file.name}</strong><small>{(file.size/1048576).toFixed(1)} MiB</small></div></div>}{(processing||events.length>0)&&<section className="import-progress" aria-label="Import progress"><div role="status" aria-live="polite"><strong>{latest?labels[latest.stage]??latest.stage:`Uploading ZIP · ${upload}%`}</strong><span>{Math.round(percent)}%</span></div><progress aria-label="Overall import progress" value={percent} max="100"/><p className="import-current">{latest?.message??'Sending your package to the server…'}{latest?.total?` (${latest.current} of ${latest.total})`:''}</p><details><summary>Files and import activity ({events.length}{events.length===200?'+':''})</summary><ol className="import-log">{events.map((event,index)=><li key={index}><strong>{labels[event.stage]??event.stage}</strong><span>{event.message}</span></li>)}</ol></details></section>}{error&&<p role="alert" className="error-text">{error}</p>}{result&&<div className="notification" role="status">Curriculum ready. Review the imported content, edit it, or download the ZIP.</div>}<div className="form-footer">{!processing&&<Button href="/admin/lessons" color="secondary">Back to library</Button>}{result?<Button onClick={()=>router.visit(result)}>Open imported curriculum</Button>:<Button type="submit" iconLeading={UploadCloud02} isDisabled={!file||processing} isLoading={processing}>{processing?'Importing curriculum…':'Validate and import'}</Button>}</div></form><aside className="import-guide"><span className="icon-tile"><ShieldTick/></span><h2>From ZIP to curriculum.</h2><ol><li><strong>Upload & validate</strong><p>See upload progress and each file extracted. We check the manifest, references and package limits.</p></li><li><strong>Import content</strong><p>Follow the section names, assessment checks and media processing as they happen.</p></li><li><strong>Review, edit & download</strong><p>Open the completed curriculum. Save edits as a new version, download a ZIP, or upload changes made elsewhere.</p></li></ol><p className="small">An identical ZIP opens its existing version. A changed ZIP must use a new lesson_version in its manifest.</p></aside></div></Shell>;
}
