import {test} from 'node:test';
import assert from 'node:assert/strict';
import {restoreDraftState} from '../resources/js/lib/offline-restore.ts';
import {newDocument} from '../resources/js/lib/offline-model.ts';
import type {Draft} from '../resources/js/lib/offline-model.ts';
test('portable state preserves an uncertain sync receipt and rejects another owner',()=>{
 const document=newDocument(),profile='1:main';
 const draft={document,profileKey:profile,attachments:[],baseDocument:null} as unknown as Draft;
 const payload={profile_key:profile,base_id:null,base_sha:null,document,resolutions:{},as_copy:false,expected_head_sha:null,version:'offline-1'};
 const operation={id:crypto.randomUUID(),version:'offline-1',fingerprint:JSON.stringify(payload),uploadIds:['M1']};
 const state={profile_key:profile,pending_operation:operation,journal:[{id:'edit',time:1,message:'Edited locally'}]};
 const restored=restoreDraftState(draft,state);
 assert.deepEqual(restored.operation,operation);assert.equal(restored.journal[0].message,'Edited locally');
 assert.throws(()=>restoreDraftState(draft,{...state,profile_key:'2:main'}),/another account/);
});
test('restored learning content preserves public questions while discarding private or malformed fields',()=>{
 const document=newDocument();const draft={document,profileKey:'1:main',attachments:[],baseDocument:null} as unknown as Draft;
 const restored=restoreDraftState(draft,{lesson:{questions:[{id:'q',kind:'mc',prompt:'Which?',options:['a'],criterionId:'c',domain:'knowledge',sectionIds:[],maxMarks:1,answer:'secret'},{id:'broken',prompt:{bad:true}}],written:{text:['Explain your answer.']},sections:[]}});
 assert.equal(restored.lesson?.questions.length,1);assert.equal((restored.lesson?.questions[0] as unknown as Record<string,unknown>).answer,undefined);assert.equal(restored.lesson?.written.text[0],'Explain your answer.');
});
test('restoring a signed base preserves its exact document for conflict verification',()=>{
 const document=newDocument();const draft={document,profileKey:'1:main',attachments:[],baseDocument:null} as unknown as Draft;
 const base=structuredClone(document);base.overview.summary='  Preserve source whitespace  ';
 const restored=restoreDraftState(draft,{profile_key:'1:main',base_id:2,base_sha:'a'.repeat(64),base_token:'b'.repeat(64),base_document:base});
 assert.deepEqual(restored.baseDocument,base);assert.equal(restored.base_token,'b'.repeat(64));
});
