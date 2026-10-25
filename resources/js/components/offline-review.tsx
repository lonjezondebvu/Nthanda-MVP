import type { Draft, Review, Resolution, WorkEvent } from '@/lib/offline-model';
import type { Progress } from '@/types';
type ReviewProps = {
    draft: Draft;
    review: Review;
    choices: Record<string, Resolution>;
    manual: Record<string, string>;
    version: string;
    asCopy: boolean;
    disabled: boolean;
    onChoice: (path: string, resolution: Resolution | null) => void;
    onManual: (path: string, text: string, originalValue: unknown) => void;
    onVersion: (version: string) => void;
    onCopy: (asCopy: boolean) => void;
    onRecheck: () => void;
    onSync: () => void;
    onBack: () => void;
};
const printable = (value: unknown) => value === undefined ? 'Not present' : typeof value === 'string' ? value : JSON.stringify(value, null, 2);
export function OfflineReview({ draft, review, choices, manual, version, asCopy, disabled, onChoice, onManual, onVersion, onCopy, onRecheck, onSync, onBack }: ReviewProps) {
    const unresolved = review.conflicts.filter(conflict => !choices[conflict.path]).length;
    return <section className="offline-review"><h2>Review lesson changes</h2><p>Compare each overlapping change before creating the next lesson revision. Nothing is sent until you choose Sync reviewed lesson.</p><p role="status">{review.conflicts.length ? `${unresolved} of ${review.conflicts.length} conflicts still need a choice.` : 'No overlapping changes found.'}</p><fieldset disabled={disabled}><legend>Destination</legend><label>New version<input value={version} onChange={event => onVersion(event.target.value)} required/></label><label className="offline-choice"><input type="checkbox" checked={asCopy} onChange={event => onCopy(event.target.checked)}/>Save as a separate lesson copy</label></fieldset>
 {review.conflicts.map(conflict => { const selected = choices[conflict.path]; const original = conflict.local_missing ? (conflict.server_missing ? conflict.base : conflict.server) : conflict.local; let invalid = false; if ((selected?.choice === 'manual' || (!selected && Object.hasOwn(manual, conflict.path))) && typeof original !== 'string') {
        try {
            JSON.parse(manual[conflict.path] ?? '');
        }
        catch {
            invalid = true;
        }
    } return <fieldset className="offline-conflict" key={conflict.path} disabled={disabled}><legend>{conflict.path}</legend><div className="offline-conflict-values">{([['Original', conflict.base, conflict.base_missing], ['Your draft', conflict.local, conflict.local_missing], ['Server', conflict.server, conflict.server_missing]] as const).map(([label, value, missing]) => <div key={label}><h3>{label}</h3><pre>{missing ? 'Missing / deleted' : printable(value)}</pre></div>)}</div><div className="offline-actions"><button type="button" aria-pressed={selected?.choice === 'local'} onClick={() => onChoice(conflict.path, { choice: 'local' })}>Use draft{conflict.local_missing ? ' deletion' : ''}</button><button type="button" aria-pressed={selected?.choice === 'server'} onClick={() => onChoice(conflict.path, { choice: 'server' })}>Use server{conflict.server_missing ? ' deletion' : ''}</button><button type="button" aria-pressed={selected?.choice === 'manual'} onClick={() => { onChoice(conflict.path, { choice: 'manual', value: original }); onManual(conflict.path, manual[conflict.path] ?? (typeof original === 'string' ? original : JSON.stringify(original ?? null, null, 2)), original); }}>Edit manually</button>{selected && <button type="button" onClick={() => onChoice(conflict.path, null)}>Clear choice</button>}</div>{(selected?.choice === 'manual' || (!selected && Object.hasOwn(manual, conflict.path))) && <label>{typeof original === 'string' ? 'Replacement text' : 'Replacement JSON (null removes this value)'}<textarea aria-invalid={invalid} value={manual[conflict.path] ?? ''} onChange={event => onManual(conflict.path, event.target.value, original)}/>{invalid && <span role="alert">Enter valid JSON before syncing.</span>}</label>}</fieldset>; })}
 <details><summary>Proposed lesson document</summary><pre>{JSON.stringify(review.document, null, 2)}</pre></details><details><summary>Local change journal ({draft.journal.length})</summary><ol>{draft.journal.map(entry => <li key={entry.id}><time dateTime={new Date(entry.time).toISOString()}>{new Date(entry.time).toLocaleString()}</time>  -  {entry.message}</li>)}</ol></details><div className="offline-actions"><button type="button" disabled={disabled} onClick={onBack}>Back to draft</button><button type="button" disabled={disabled} onClick={onRecheck}>Recheck latest server revision</button><button type="button" disabled={disabled || unresolved > 0 || !version.trim() || review.conflicts.some(conflict => { if (choices[conflict.path]?.choice !== 'manual')
        return false; const original = conflict.local_missing ? conflict.server : conflict.local; if (typeof original === 'string')
        return false; try {
        JSON.parse(manual[conflict.path] ?? '');
        return false;
    }
    catch {
        return true;
    } })} onClick={onSync}>Sync reviewed lesson</button></div></section>;
}
type WorkProps = {
    draft: Draft;
    selected: string[];
    serverProgress: Record<number, Progress>;
    force: boolean;
    disabled: boolean;
    onSelected: (selected: string[]) => void;
    onForce: (force: boolean) => void;
    onSync: () => void;
    onBack: () => void;
};
function progressValue(event: WorkEvent, progress: Progress | undefined): unknown { const section = event.section_id ? progress?.[event.section_id] : undefined; return event.type === 'read' ? section?.read ?? false : event.type === 'confidence' ? section?.confidence ?? null : undefined; }
export function OfflineWorkReview({ draft, selected, serverProgress, force, disabled, onSelected, onForce, onSync, onBack }: WorkProps) {
    const selectedEvents = draft.events.filter(event => selected.includes(event.id));
    const overlaps = selectedEvents.some(event => ['read', 'confidence'].includes(event.type) && JSON.stringify(progressValue(event, serverProgress[event.lesson_id])) !== JSON.stringify(event.base_value ?? (event.type === 'read' ? false : null)) && JSON.stringify(progressValue(event, serverProgress[event.lesson_id])) !== JSON.stringify(event.value));
    return <section className="offline-work-review"><h2>Review learner work</h2><p>Select the entries to submit. Unchecked work stays on this device.</p><fieldset disabled={disabled}><legend>Queued work ({draft.events.length})</legend>{draft.events.length ? draft.events.map(event => <div className="offline-work-event" key={event.id}><label className="offline-choice"><input type="checkbox" checked={selected.includes(event.id)} onChange={change => onSelected(change.target.checked ? [...selected, event.id] : selected.filter(id => id !== event.id))}/>{event.type}  -  {event.section_id || 'Whole lesson'}</label><pre>{printable(event.value)}</pre>{['read', 'confidence'].includes(event.type) && <div className="offline-conflict-values"><div><strong>Original saved progress</strong><pre>{printable(event.base_value ?? progressValue(event, draft.baseProgress))}</pre></div><div><strong>Current server progress</strong><pre>{printable(progressValue(event, serverProgress[event.lesson_id]))}</pre></div></div>}</div>) : <p>No work is queued.</p>}<label className="offline-choice"><input type="checkbox" checked={force} onChange={event => onForce(event.target.checked)}/>I choose to replace conflicting server read/confidence values with the selected device values.</label>{overlaps && !force && <p role="status">Server progress changed. Review it and explicitly choose whether to replace it before submitting.</p>}</fieldset><p>Written and assessment responses are submitted for server marking. This device does not award marks.</p><div className="offline-actions"><button type="button" disabled={disabled} onClick={onBack}>Back</button><button type="button" disabled={disabled || !selectedEvents.length || (overlaps && !force)} onClick={onSync}>Submit selected work ({selectedEvents.length})</button></div></section>;
}
