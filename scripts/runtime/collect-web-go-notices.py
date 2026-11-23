#!/usr/bin/env python3
"""Read every active Go/native/assembly source in a go list -deps -json stream and retain original legal comment text."""
import argparse,collections,hashlib,json,re
from pathlib import Path
p=argparse.ArgumentParser(description=__doc__)
p.add_argument('go_list_json',type=Path)
p.add_argument('notices',type=Path)
p.add_argument('source_index',type=Path)
a=p.parse_args()
fields='GoFiles CgoFiles CFiles CXXFiles MFiles HFiles FFiles SFiles SwigFiles SwigCXXFiles'.split()
terms=re.compile(r'copyright|permission is hereby granted|redistribut(?:e|ion)|all rights reserved|spdx-license-identifier|without (?:any )?warranty|licen[sc](?:ed|e)|public domain|patent grant',re.I)
tokens=re.compile(r"""(?P<literal>`[^`]*`|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')|(?P<comment>/\*.*?\*/|//[^\r\n]*(?:\r?\n[ \t]*//[^\r\n]*)*)""",re.S)
asm_comments=re.compile(r'^[ \t]*[;#][ \t]+[^\r\n]*(?:\r?\n[ \t]*[;#][ \t]+[^\r\n]*)*',re.M)
def sha(b):return hashlib.sha256(b).hexdigest()
text=a.go_list_json.read_text(encoding='utf-8-sig');decoder=json.JSONDecoder();i=0;groups=collections.defaultdict(set);rows={};package_count=0;syso=[]
while i<len(text):
 while i<len(text) and text[i].isspace():i+=1
 if i==len(text):break
 pkg,i=decoder.raw_decode(text,i);package_count+=1;src=Path(pkg['Dir']);base=pkg['ImportPath']
 for n in pkg.get('SysoFiles',[]):syso.append(base+'/'+n)
 for field in fields:
  for n in pkg.get(field,[]):
   f=src/n
   if not f.is_file():raise FileNotFoundError('Missing active source: '+base+'/'+n)
   reference=base+'/'+n;raw=f.read_bytes();s=raw.decode('utf-8').replace('\r\n','\n').replace('\r','\n')
   if reference in rows:assert rows[reference]['sha256']==sha(raw);continue
   matches={m.group().strip() for m in tokens.finditer(s) if m.lastgroup=='comment' and terms.search(m.group())}
   if field in ('SFiles','FFiles'):
    matches.update(m.group().strip() for m in asm_comments.finditer(s) if terms.search(m.group()))
   rows[reference]={'field':field,'sha256':sha(raw),'notice_sha256':sorted(sha(x.encode()) for x in matches)}
   for c in matches:groups[c].add(reference)
a.notices.parent.mkdir(exist_ok=True,parents=True);a.source_index.parent.mkdir(exist_ok=True,parents=True)
with a.notices.open('w',encoding='utf-8',newline='\n') as out:
 out.write('Copyright, licenses and attributions found in files of the compiled Go package graph. Full comments are grouped by their referenced source files.\n')
 for comment,files in sorted(groups.items(),key=lambda kv:(min(kv[1]),kv[0])):
  out.write('\nFiles: '+', '.join(sorted(files))+'\n'+comment+'\n')
a.source_index.write_text(json.dumps({'packages':package_count,'source_files':len(rows),'syso_inputs':syso,'distinct_notices':len(groups),'notices_sha256':sha(a.notices.read_bytes()),'sources':rows},indent=2,sort_keys=True)+'\n',encoding='utf-8')
print(package_count,'packages',len(rows),'sources',len(groups),'notices',a.notices.stat().st_size,'bytes')
