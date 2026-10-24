import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';
import '../css/studio.css';
const pages=import.meta.glob('./pages/*.tsx');
createInertiaApp({title:t=>t?`${t} · Nthanda Lesson Studio`:'Nthanda Lesson Studio',resolve:async name=>{const page=await pages[`./pages/${name}.tsx`]();return page as never;},setup({el,App,props}){createRoot(el).render(<App {...props}/>);},progress:{color:'#216851'}});
