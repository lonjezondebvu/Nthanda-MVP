import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToString} from 'react-dom/server';
import {createServer} from 'vite';

let server;
let ContentBlock;
let MediaItem;

before(async()=>{
 server=await createServer({server:{middlewareMode:true}});
 ({ContentBlock,MediaItem}=await server.ssrLoadModule('/resources/js/components/lesson-content.tsx'));
});

test('video playback lets the browser use the downloaded format instead of assuming MP4',()=>{
 const html=renderToString(createElement(MediaItem,{media:{id:'recording',type:'video',url:'/recording.webm',description:'Teacher recording'}}));
 assert.doesNotMatch(html,/type="video\/mp4"/);
 assert.ok(html.includes('/recording.webm'));
});
after(async()=>{await server?.close();});

test('lesson tables render their headers and every row without crashing',()=>{
 const block={id:'photosynthesis',type:'table',headers:['Factor','Comparison'],rows:[['Light','Covered leaf'],['Chlorophyll','Variegated leaf']]};

 const html=renderToString(createElement(ContentBlock,{block}));

 for(const text of ['Factor','Comparison','Light','Covered leaf','Chlorophyll','Variegated leaf']){
  assert.ok(html.includes(text),`Missing table content: ${text}`);
 }
});
