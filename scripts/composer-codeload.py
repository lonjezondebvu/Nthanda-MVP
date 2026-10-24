"""Use GitHub's equivalent official codeload URL for locked GitHub archives."""
import json,re
from pathlib import Path
p=Path('composer.lock');d=json.loads(p.read_text(encoding='utf-8'))
count=0
for package in d['packages']+d.get('packages-dev',[]):
    source=package.get('source',{});m=re.match(r'https://github.com/([^/]+/[^/]+?)(?:\.git)?$',source.get('url',''))
    if m and package.get('dist',{}).get('type')=='zip':
        package['dist']['url']=f"https://codeload.github.com/{m[1]}/zip/{source['reference']}";count+=1
p.write_text(json.dumps(d,indent=4)+'\n',encoding='utf-8')
print('Official codeload URLs set for',count,'locked packages; versions and references unchanged.')
