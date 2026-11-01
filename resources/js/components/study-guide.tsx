import {useState} from 'react';
import {findExplanations} from '@/lib/study-guide';
import type {Lesson} from '@/types';

export function StudyGuide({lesson}:{lesson:Lesson}) {
    const [query,setQuery] = useState('');
    const results = findExplanations(lesson.sections, query);
    return <details className="study-guide"><summary>Find an explanation in this lesson</summary><div className="study-guide-body"><p>Search a topic or question to find the lesson’s own explanations. Results quote the source; they do not generate answers.</p><label htmlFor="study-guide-query">What would you like to understand?</label><input id="study-guide-query" type="search" maxLength={200} placeholder="Try a term from the lesson…" value={query} onChange={event=>setQuery(event.target.value)}/><div aria-live="polite">{query.trim() && <p className="small muted">{results.length ? `${results.length} matching explanations` : 'No matching explanation. Try a shorter topic or ask your teacher.'}</p>}</div>{results.map((result,index)=><article key={`${result.sectionId}-${index}`}><h3>{result.sectionTitle}</h3><blockquote>{result.text}</blockquote><a href={`/lessons/${lesson.id}/sections/${encodeURIComponent(result.sectionId)}${result.blockId ? `#${encodeURIComponent(result.blockId)}` : ''}`}>Read in context →</a></article>)}<a href={`/glossary?lesson_id=${lesson.id}`}>Open English / Chichewa glossary →</a></div></details>;
}
