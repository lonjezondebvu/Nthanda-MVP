import { useState } from 'react';
import type { Draft, WorkEvent } from '@/lib/offline-model';
import type { Lesson, Question } from '@/types';
import { ContentBlock, MediaItem } from '@/components/lesson-content';
type Props = {
    draft: Draft;
    lesson: Lesson;
    disabled: boolean;
    onWork: (type: WorkEvent['type'], value: unknown, sectionId?: string | null) => void;
    onDraft: (draft: Draft) => void;
};
function QuestionInput({ question, value, onChange }: {
    question: Question;
    value: unknown;
    onChange: (value: unknown) => void;
}) {
    if (question.kind === 'mc')
        return <div>{question.options?.map(option => <label className="offline-choice" key={option}><input type="radio" name={'offline-' + question.id} checked={value === option} onChange={() => onChange(option)}/>{option}</label>)}</div>;
    if (question.kind === 'multi') {
        const selected = Array.isArray(value) ? value : [];
        return <div>{question.options?.map(option => <label className="offline-choice" key={option}><input type="checkbox" checked={selected.includes(option)} onChange={event => onChange(event.target.checked ? [...selected, option] : selected.filter(item => item !== option))}/>{option}</label>)}</div>;
    }
    if (question.kind === 'match') {
        const answers = value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, string> : {};
        return <div>{question.matchKeys?.map(key => <label key={key}>{key}<select value={answers[key] || ''} onChange={event => onChange({ ...answers, [key]: event.target.value })}><option value="">Choose a match</option>{question.matchOptions?.map(option => <option key={option}>{option}</option>)}</select></label>)}</div>;
    }
    if (question.kind === 'order') {
        const items = Array.isArray(value) ? value as string[] : question.items || [];
        return <ol>{items.map((item, index) => <li key={item + '-' + index}>{item}<button type="button" disabled={index === 0} aria-label={`Move ${item} earlier`} onClick={() => { const next = [...items]; [next[index - 1], next[index]] = [next[index], next[index - 1]]; onChange(next); }}>Earlier</button><button type="button" disabled={index === items.length - 1} aria-label={`Move ${item} later`} onClick={() => { const next = [...items]; [next[index + 1], next[index]] = [next[index], next[index + 1]]; onChange(next); }}>Later</button></li>)}</ol>;
    }
    return <textarea aria-label="Your answer" value={typeof value === 'string' ? value : ''} onChange={event => onChange(event.target.value)}/>;
}
export function OfflineReader({ draft, lesson, disabled, onWork, onDraft }: Props) {
    const [sectionId, setSectionId] = useState(lesson.sections[0]?.id || '');
    const [feedback, setFeedback] = useState<Record<string, boolean>>({});
    const section = lesson.sections.find(item => item.id === sectionId) || lesson.sections[0];
    const answers = draft.answers || {};
    const assessment = answers.assessment && typeof answers.assessment === 'object' && !Array.isArray(answers.assessment) ? answers.assessment as Record<string, unknown> : {};
    const answer = (key: string, value: unknown) => onDraft({ ...draft, answers: { ...answers, [key]: value }, updatedAt: Date.now() });
    const media = (item: Lesson['sections'][number]['media'][number]) => item.url ? <MediaItem key={item.id} media={item}/> : <p className="offline-unavailable" role="status" key={item.id}>{item.description || item.id}: media was not downloaded. Connect and download this lesson with media.</p>;
    const progress = section ? draft.progress[section.id] : undefined;
    return <div className="offline-reader"><nav className="offline-section-nav" aria-label="Lesson sections">{lesson.sections.map(item => <button type="button" aria-current={section?.id === item.id ? 'page' : undefined} key={item.id} onClick={() => setSectionId(item.id)}>{item.title}</button>)}</nav>{lesson.overview?.summary && <p className="offline-summary">{lesson.overview.summary}</p>}{section ? <article><h2>{section.title}</h2>{section.blocks.map(block => { const linked = section.media.filter(item => item.anchor_block_id === block.id && !item.state.deleted && item.state.enabled); const inline = linked.filter(item => item.type === 'pronunciation' && item.placement === 'inline-after-word' && item.url); return <div key={block.id}>{linked.filter(item => item.placement === 'before').map(media)}<ContentBlock block={block} pronunciations={inline}/>{linked.filter(item => item.placement !== 'before' && !inline.includes(item)).map(media)}</div>; })}{section.media.filter(item => !section.blocks.some(block => block.id === item.anchor_block_id) && item.state.enabled && !item.state.deleted).map(media)}
 <fieldset disabled={disabled}><legend>Your section progress</legend><label className="offline-choice"><input type="checkbox" checked={!!progress?.read} onChange={event => onWork('read', event.target.checked, section.id)}/>I have read this section</label><label>Confidence<select value={progress?.confidence ?? ''} onChange={event => onWork('confidence', event.target.value === '' ? null : Number(event.target.value), section.id)}><option value="">Not recorded</option>{[1, 2, 3].map(value => <option value={value} key={value}>{value}  -  {value === 1 ? 'Need help' : value === 3 ? 'Feeling confident' : 'Developing confidence'}</option>)}</select></label></fieldset>
 {section.activities.map(activity => <fieldset key={activity.id} disabled={disabled} className="offline-practice"><legend>{activity.title}</legend>{activity.prompt && <p>{activity.prompt}</p>}{activity.prompt_parts?.map(part => <p key={part.part_id}>{part.text}</p>)}{activity.options && <label>Your practice response<select defaultValue=""><option value="">Choose a response</option>{activity.options.map(option => <option key={option}>{option}</option>)}</select></label>}<button type="button" onClick={() => { setFeedback({ ...feedback, [activity.id]: true }); onWork('activity', activity.id, section.id); }}>Explore feedback</button>{feedback[activity.id] && <p>{activity.feedback || 'Discuss your response with your teacher.'}</p>}<small>Practice has no assessed mark.</small></fieldset>)}
 {section.alternatives.map(alternative => <details key={alternative.id}><summary>{alternative.title || 'Another explanation'}</summary><p>{alternative.text}</p></details>)}</article> : <p>Add a section in the editor to start reading.</p>}
 {!!lesson.written.text.length && <fieldset disabled={disabled}><legend>Written responses</legend>{lesson.written.text.map((text, index) => <p key={index}>{text}</p>)}<label>Your written response<textarea value={typeof answers.written === 'string' ? answers.written : ''} onChange={event => answer('written', event.target.value)}/></label><button type="button" disabled={!String(answers.written || '').trim()} onClick={() => onWork('written', typeof answers.written === 'string' ? answers.written : '')}>Queue written response</button><p>Saved on this device. Submit selected work when connected for teacher review.</p></fieldset>}
 {!!lesson.questions.length && <fieldset disabled={disabled}><legend>Assessment responses</legend>{lesson.questions.map(question => <fieldset key={question.id}><legend>{question.prompt}</legend><QuestionInput question={question} value={assessment[question.id]} onChange={value => answer('assessment', { ...assessment, [question.id]: value })}/></fieldset>)}<button type="button" onClick={() => onWork('assessment', Object.fromEntries(lesson.questions.map(question => [question.id, assessment[question.id] ?? (question.kind === 'order' ? question.items || [] : question.kind === 'multi' ? [] : question.kind === 'match' ? {} : '')])))}>Queue assessment responses</button><p>Answers remain unmarked until submitted when connected.</p></fieldset>}</div>;
}
