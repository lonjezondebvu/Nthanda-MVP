import type {Lesson} from '@/types';
export function OverviewIntro({overview}:{overview:Lesson['overview']}) {
 if(!overview?.introduction)return null;
 return <><p>{overview.summary}</p><details className="alternative"><summary>Introduction and learning goals</summary>{overview.introduction.map((p,i)=><p key={i}>{p}</p>)}<p>By the end, you should be able to:</p><ul>{overview.goals?.map(g=><li key={g}>{g}</li>)}</ul><p>{overview.practical_guidance}</p></details></>;
}
