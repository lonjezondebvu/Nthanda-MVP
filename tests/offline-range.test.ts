import {test} from 'node:test';
import assert from 'node:assert/strict';
import {rangeResponse} from '../public/offline-range.js';

test('cached media serves byte ranges, suffixes and rejects invalid ranges',async()=>{
 const response=new Response('0123456789',{headers:{'Content-Type':'video/mp4'}});
 const middle=await rangeResponse(response.clone(),'bytes=2-5');
 assert.equal(middle.status,206);
 assert.equal(middle.headers.get('Content-Range'),'bytes 2-5/10');
 assert.equal(await middle.text(),'2345');
 const suffix=await rangeResponse(response.clone(),'bytes=-3');
 assert.equal(await suffix.text(),'789');
 assert.equal((await rangeResponse(response.clone(),'bytes=20-30')).status,416);
 assert.equal((await rangeResponse(response.clone(),'bytes=0-1,3-4')).status,416);
});
