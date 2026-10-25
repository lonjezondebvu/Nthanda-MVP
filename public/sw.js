import {rangeResponse} from './offline-range.js';
const PREFIX='nthanda-shell-';
const MEDIA='nthanda-media-v1';
self.addEventListener('install',event=>event.waitUntil((async()=>{
 const response=await fetch('/offline-assets',{cache:'no-store'});if(!response.ok)throw new Error('Offline build unavailable');
 const {version,urls}=await response.json();const cache=await caches.open(PREFIX+version);
 await cache.addAll(urls);await self.skipWaiting();
})()));
self.addEventListener('activate',event=>event.waitUntil((async()=>{
 const names=(await caches.keys()).filter(name=>name.startsWith(PREFIX));
 for(const name of names.slice(0,-1))await caches.delete(name);
 await self.clients.claim();
})()));
async function shellMatch(request){
 const names=(await caches.keys()).filter(name=>name.startsWith(PREFIX)).reverse();
 for(const name of names){const match=await (await caches.open(name)).match(request);if(match)return match;}
}
self.addEventListener('fetch',event=>{
 const request=event.request;const url=new URL(request.url);
 if(request.method!=='GET'||url.origin!==self.location.origin)return;
 if(url.pathname.startsWith('/offline-media/')){
  event.respondWith((async()=>{const response=await (await caches.open(MEDIA)).match(url.pathname);if(!response)return new Response('This file is not downloaded on this device.',{status:404});return request.headers.has('Range')?rangeResponse(response,request.headers.get('Range')):response;})());return;
 }
 if(url.pathname==='/offline'){
  event.respondWith(fetch(request).then(async response=>response.ok?response:await shellMatch('/offline')??response).catch(async()=>await shellMatch('/offline')??new Response('Open the app online once to prepare offline access.',{status:503})));return;
 }
 if(url.pathname.startsWith('/build/')||url.pathname==='/branding/nthanda-logo.png'||url.pathname==='/manifest.webmanifest'||url.pathname==='/offline-range.js'){
  event.respondWith((async()=>await shellMatch(request)??fetch(request))());return;
 }
 if(request.mode==='navigate')event.respondWith(fetch(request).catch(async()=>await shellMatch('/offline')??new Response('Open the app online once to prepare offline access.',{status:503})));
});
