import {test} from 'node:test';
import assert from 'node:assert/strict';
import {findExplanations} from '../resources/js/lib/study-guide.ts';
const sections = [{id:'S1',title:'Roots',blocks:[{id:'B1',type:'paragraph',text:'Roots absorb water and minerals.'}],alternatives:[{id:'A1',title:'Think about roots',text:'Roots keep a plant in the soil.'}]},{id:'S2',title:'Leaves',blocks:[{id:'B2',type:'list',items:['Leaves use light.','Leaves make food.']}],alternatives:[]}];
test('study guide returns exact lesson excerpts with source references',()=>{
 const results=findExplanations(sections,'How do roots absorb water?');
 assert.equal(results.length,1);
 assert.equal(results[0].text,'Roots absorb water and minerals.');
 assert.equal(results[0].sectionId,'S1');
 assert.equal(results[0].blockId,'B1');
});
test('study guide does not invent answers and handles punctuation literally',()=>{
 assert.deepEqual(findExplanations(sections,'quantum physics'),[]);
 assert.deepEqual(findExplanations(sections,'[.*]'),[]);
 assert.deepEqual(findExplanations(sections,''),[]);
 assert.equal(findExplanations(sections,'light')[0].sectionId,'S2');
});
