import re,os,subprocess,sys
files=[f for f in subprocess.check_output(['git','ls-files','*.md']).decode().split('\n') if f and not f.startswith('docs/studies/issue-32-retry-comparison/results')]
bad=[]
for f in files:
    txt=open(f,encoding='utf8').read()
    txt=re.sub(r'```.*?```','',txt,flags=re.S)
    for m in re.finditer(r'\[[^\]]*\]\(([^)\s]+)\)',txt):
        t=m.group(1)
        if re.match(r'(https?:|mailto:|#)',t): continue
        p=t.split('#')[0]
        if not p: continue
        full=os.path.normpath(os.path.join(os.path.dirname(f),p))
        if not os.path.exists(full): bad.append((f,t))
for b in bad: print(*b)
print(len(files),'files',len(bad),'broken')
