export type Pronunciation = {id:string;term?:string;anchor_word?:string;aliases?:string[]};
export function pronunciationSegments<T extends Pronunciation>(text:string, media:T[]): {text:string;media?:T}[] {
 const terms=media.flatMap(m=>[m.anchor_word||m.term,...(m.aliases||[])].filter((t):t is string=>!!t).map(term=>({term,media:m}))).sort((a,b)=>b.term.length-a.term.length);
 if(!terms.length)return [{text}];
 const escape=(t:string)=>t.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
 const regex=new RegExp('\\b('+terms.map(t=>escape(t.term)).join('|')+')\\b','gi');
 const result:{text:string;media?:T}[]=[];let last=0;
 for(const match of text.matchAll(regex)) {
   const index=match.index!;if(index>last)result.push({text:text.slice(last,index)});
   result.push({text:match[0],media:terms.find(t=>t.term.toLowerCase()===match[0].toLowerCase())?.media});last=index+match[0].length;
 }
 if(last<text.length)result.push({text:text.slice(last)});
 return result;
}
