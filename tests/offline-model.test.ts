import {test} from 'node:test';
import assert from 'node:assert/strict';
import {newDocument,queueWork,previewLesson,isDirty,syncUploadIds} from '../resources/js/lib/offline-model.ts';
import type {Draft} from '../resources/js/lib/offline-model.ts';

test('new offline lessons have stable manifest and block identities',()=>{
 const document=newDocument();
 assert.equal(document.sections.length,1);
 assert.ok(document.manifest_id);
 assert.notEqual(document.sections[0].id,document.sections[0].blocks[0].id);
 assert.equal(isDirty(document,structuredClone(document)),false);
 const changed=structuredClone(document);changed.title='Edited';
 assert.equal(isDirty(changed,document),true);
});
test('sync excludes removed attachments and unchanged server animation bytes',()=>{
 const document=newDocument();document.media=[{id:'animation',type:'animation',path:'scene.html'}];
 const draft={attachments:[{id:'removed',sha256:'removed'},{id:'animation',sha256:'same'}]} as unknown as Draft;
 assert.deepEqual(syncUploadIds(draft,document,{document,files:[{path:'source/dist/scene.html',sha256:'same'}]}),[]);
 assert.deepEqual(syncUploadIds(draft,document,{document,files:[{path:'source/dist/scene.html',sha256:'changed'}]}),['animation']);
});
test('repeated offline progress keeps its original base but uses a fresh retry identity',()=>{
 const first=queueWork([], {lesson_id:1,base_sha:'a'.repeat(64),section_id:'s',type:'read',value:true,base_value:false});
 const second=queueWork(first,{lesson_id:1,base_sha:'a'.repeat(64),section_id:'s',type:'read',value:false,base_value:true});
 assert.equal(second.length,1);assert.equal(second[0].base_value,false);assert.notEqual(second[0].id,first[0].id);
});
test('offline preview reflects local text and preserves downloaded practice without leaking hidden media',()=>{
 const document=newDocument();document.sections[0].blocks[0].text='Offline edited text';
 const lesson=previewLesson(document,null,[]);
 assert.equal(lesson.sections[0].blocks[0].text,'Offline edited text');
 assert.deepEqual(lesson.questions,[]);
});
