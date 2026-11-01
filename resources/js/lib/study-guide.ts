type GuideSection = {id:string; title:string; blocks:{id:string;type:string;text?:string;items?:string[];headers?:string[];rows?:string[][]}[]; alternatives:{id:string;title:string;text:string}[]};
export type Explanation = {sectionId:string;sectionTitle:string;blockId:string|null;text:string};
const questionWords = new Set(['a','an','and','are','as','at','be','by','can','do','does','explain','for','how','i','in','is','it','me','of','on','please','the','to','what','when','where','which','why','with']);

export function findExplanations(sections:GuideSection[], query:string):Explanation[] {
    const terms = [...new Set((query.toLocaleLowerCase().match(/[\p{L}\p{N}]+/gu) ?? []).filter(term => !questionWords.has(term)))].slice(0,12);
    if (!terms.length) { return []; }
    const candidates = sections.flatMap(section => [
        ...section.blocks.filter(block => block.type !== 'heading').map(block => ({sectionId:section.id,sectionTitle:section.title,blockId:block.id,text:[block.text ?? '',...(block.items ?? []),...(block.headers ?? []),...(block.rows ?? []).flat()].filter(Boolean).join(' ')})),
        ...section.alternatives.map(alternative => ({sectionId:section.id,sectionTitle:section.title,blockId:null,text:alternative.text})),
    ]);
    return candidates.filter(candidate => terms.every(term => `${candidate.sectionTitle} ${candidate.text}`.toLocaleLowerCase().includes(term))).slice(0,6);
}
