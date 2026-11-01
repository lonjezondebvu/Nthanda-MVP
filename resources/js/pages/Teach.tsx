import {Head} from '@inertiajs/react';
import {Shell, PageHeading, Button, Empty} from '@/components/studio';

type ClassCard = {id:number; name:string; students:number; pending:number};

export default function Teach({classes}:{classes:ClassCard[]}) {
    return <Shell><Head title="Class desk"/>
        <PageHeading eyebrow="YOUR CLASSES" title="Class desk" description="See who needs your support, review written work, and plan the next lesson." actions={<Button href="/assignments">Manage assignments</Button>}/>
        {classes.length === 0 ? <Empty title="No class assigned yet" description="An administrator needs to assign you to a class before students appear here."/> : <div className="catalogue">{classes.map(schoolClass => <section className="catalogue-card class-desk-card" key={schoolClass.id}><div><h2>{schoolClass.name}</h2><p className="muted">{schoolClass.students} students · {schoolClass.pending} written {schoolClass.pending === 1 ? 'response' : 'responses'} waiting</p></div><Button href={'/teach/classes/'+schoolClass.id} color="secondary">Open class</Button></section>)}</div>}
    </Shell>;
}
