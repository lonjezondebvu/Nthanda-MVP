import {test} from 'node:test';
import assert from 'node:assert/strict';
import {pronunciationSegments} from '../resources/js/lib/pronunciation.ts';
test('recordings follow matched whole terms and aliases without changing teaching text',()=>{
 const text='The apex and petiole connect. Monocots are different from dicots.';
 const segments=pronunciationSegments(text,[{id:'apex',term:'apex'},{id:'petiole',term:'petiole'},{id:'monocot',term:'monocot',aliases:['monocots']}]);
 assert.equal(segments.map(s=>s.text).join(''),text);
 assert.deepEqual(segments.filter(s=>s.media).map(s=>[s.text,s.media?.id]),[['apex','apex'],['petiole','petiole'],['Monocots','monocot']]);
 assert.equal(pronunciationSegments('apexes',[{id:'apex',term:'apex'}]).some(s=>s.media),false);
});
