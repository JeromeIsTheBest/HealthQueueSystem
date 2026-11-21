from pathlib import Path
import xml.etree.ElementTree as ET,json
out=Path('docs/diagrams');src=Path('scripts/build-clear-erd.py').read_text(encoding='utf-8');env={};exec(src[:src.index("core=['roles'")],env);schema=env['s'];rels=env['rels'];q=env['q'];root=ET.parse(out/'healthqueue-erd-clear-full.svg');ns={'s':'http://www.w3.org/2000/svg'};sizes={}
for node in root.findall('.//s:g[@class="node"]',ns):
 t=node.find('s:title',ns).text;pts=[tuple(map(float,p.split(','))) for poly in node.findall('s:polygon',ns) for p in poly.attrib['points'].split()];sizes[t]=(max(x for x,y in pts)-min(x for x,y in pts),max(y for x,y in pts)-min(y for x,y in pts))
rows=[
 [['roles'],[],['physicianaccountowners'],[],[]],
 [['consultations'],['users'],['physicianavailability','physiciandateavailability'],['clinic'],['appointments']],
 [['auditlogs'],['medicalrecords'],['queue'],['feedback'],['wallettransactions']],
 [['notifications'],['consultationversions'],['aitranscriptversions'],['visitcompletionreviews'],['appointmentpayments']]
]
core=[t for row in rows for cell in row for t in cell];remaining=[t for t in schema['tables'] if t not in core]
aux=[[],[],[]];auxheights=[0,0,0]
for t in sorted(remaining,key=lambda t:sizes[t][1],reverse=True):
 ci=min(range(3),key=lambda c:auxheights[c]);aux[ci].append(t);auxheights[ci]+=sizes[t][1]+50
assert sorted(core+[t for cell in aux for t in cell])==sorted(schema['tables'])
widths=[max((sizes[t][0] for row in rows for t in row[c]),default=0) for c in range(5)]
heights=[max((sum(sizes[t][1] for t in cell)+32*max(0,len(cell)-1) for cell in row),default=0) for row in rows];xs=[];x=0
for w in widths:xs.append(x+w/2);x+=w+110
coreh=sum(heights)+80*(len(rows)-1);totalh=max(coreh,max(auxheights));positions={};top=totalh
for ri,row in enumerate(rows):
 for ci,cell in enumerate(row):
  y=top
  for t in cell:positions[t]={'x':xs[ci],'y':y-sizes[t][1]/2,'row':ri,'col':ci};y-=sizes[t][1]+32
 top-=heights[ri]+80
x+=80
for ci,cell in enumerate(aux,5):
 w=max(sizes[t][0] for t in cell);y=totalh
 for ri,t in enumerate(cell):positions[t]={'x':x+w/2,'y':y-sizes[t][1]/2,'row':ri,'col':ci};y-=sizes[t][1]+50
 x+=w+70
lines=['digraph HealthQueue {','graph [layout=neato, overlap=true, splines=ortho, outputorder=edgesfirst, pad=0.35, bgcolor="white", notranslate=false, fontname="Arial",fontsize=18,labelloc=t,label="Database Design - HealthQueue\\nEntity-Relationship Diagram\\n42 tables | 365 fields | 91 declared foreign keys\\nPK primary key | FK foreign key | UQ unique | ? nullable | Circle zero, bar one, fork many"];','node [shape=plain,fontname="Arial",fontsize=10,pin=true];','edge [fontname="Arial",fontsize=8,fontcolor="#334a5c",color="#718497",arrowsize=.65,penwidth=.9];']
for t in schema['tables']:
 n=env['node'](t);p=positions[t];n=n[:-2]+',pos='+q(f'{p["x"]/72},{p["y"]/72}!')+'];';lines.append(n)
for i,e in enumerate(rels):
 f=e[0];parent=f['REFERENCED_TABLE_NAME'];child=f['TABLE_NAME'];nullable=any(c['IS_NULLABLE']=='YES' for c in env['cols'][child] if c['COLUMN_NAME'] in [x['COLUMN_NAME'] for x in e]);one=any(set(v)=={x['COLUMN_NAME'] for x in e} for v in env['unique'][child].values());identifying=all(next(c for c in env['cols'][child] if c['COLUMN_NAME']==x['COLUMN_NAME'])['COLUMN_KEY']=='PRI' for x in e)
 if positions[parent]['col']<=positions[child]['col']:pa='right_'+f['REFERENCED_COLUMN_NAME']+':e';ch='left_'+f['COLUMN_NAME']+':w'
 else:pa='left_'+f['REFERENCED_COLUMN_NAME']+':w';ch='right_'+f['COLUMN_NAME']+':e'
 pport,pcomp=pa.rsplit(':',1);cport,ccomp=ch.rsplit(':',1)
 lines.append(q(parent)+':'+q(pport)+':'+pcomp+' -> '+q(child)+':'+q(cport)+':'+ccomp+' [dir=both,arrowtail='+q('odottee' if nullable else 'teetee')+',arrowhead='+q('odottee' if one else 'crowodot')+',style='+q('solid' if identifying else 'dashed')+',xlabel='+q('R'+str(i+1).zfill(2)+': '+','.join(x['COLUMN_NAME'] for x in e))+'];')
lines.append('}');(out/'healthqueue-erd-arranged.dot').write_text('\n'.join(lines),encoding='utf-8');(out/'healthqueue-erd-arranged-layout.json').write_text(json.dumps(positions,indent=2),encoding='utf-8');print('Prepared reference-style positions for',len(positions),'actual tables.')
