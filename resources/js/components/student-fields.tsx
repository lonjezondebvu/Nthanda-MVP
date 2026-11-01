import type {ReactNode} from 'react';

export type DisabilityOption = {value:string; label:string};

const exclusive = new Set(['prefer_not_to_say', 'none']);

export function DisabilityFields({options, selected, note, error, noteError, onChange, onNote}:{options:DisabilityOption[]; selected:string[]; note:string; error?:string; noteError?:string; onChange:(values:string[])=>void; onNote:(value:string)=>void}) {
    const toggle = (value:string, checked:boolean) => {
        if (!checked) {
            onChange(selected.filter(item => item !== value));
            return;
        }
        if (exclusive.has(value)) {
            onChange([value]);
            return;
        }
        onChange([...selected.filter(item => !exclusive.has(item)), value]);
    };
    return <fieldset className="disability-fields">
        <legend>Disability</legend>
        <p className="muted">Optional. Only you and administrators can see this.</p>
        <div className="choice-grid">{options.map(option => <label key={option.value}><input type="checkbox" checked={selected.includes(option.value)} onChange={event => toggle(option.value, event.target.checked)}/>{option.label}</label>)}</div>
        {error && <p className="field-error">{error}</p>}
        {selected.includes('other') && <label className="note-field">Short note<textarea value={note} onChange={event => onNote(event.target.value)} maxLength={500} required/>{noteError && <span className="field-error">{noteError}</span>}</label>}
    </fieldset>;
}

export function ClassSelect({classes, value, error, onChange, id}:{classes:{id:number; name:string}[]; value:string; error?:string; onChange:(value:string)=>void; id:string}): ReactNode {
    return <label htmlFor={id}>Class<select id={id} value={value} onChange={event => onChange(event.target.value)} required><option value="" disabled>{classes.length ? 'Select your class' : 'No classes yet'}</option>{classes.map(schoolClass => <option key={schoolClass.id} value={schoolClass.id}>{schoolClass.name}</option>)}</select>{error && <span className="field-error">{error}</span>}{classes.length === 0 && <span className="muted">An administrator needs to add a class before you can continue.</span>}</label>;
}
