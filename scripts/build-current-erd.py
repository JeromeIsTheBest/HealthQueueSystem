import json,html,collections,math
from pathlib import Path
out=Path('docs/diagrams');data=json.loads((out/'healthqueue-current-schema.json').read_text(encoding='utf-8'))
cols=collections.defaultdict(list)
for c in data['columns']: cols[c['TABLE_NAME']].append(c)
fks=collections.defaultdict(list)
for f in data['foreign_keys']:fks[(f['TABLE_NAME'],f['CONSTRAINT_NAME'])].append(f)
edges=list(fks.values());assert len(edges)==len(fks)
fkby=collections.defaultdict(list)
for edge in edges:
 for f in edge:fkby[(f['TABLE_NAME'],f['COLUMN_NAME'])].append(f)
indexes=collections.defaultdict(lambda:collections.defaultdict(list))
for i in data['indexes']:
 if not i['NON_UNIQUE']: indexes[i['TABLE_NAME']][i['INDEX_NAME']].append(i['COLUMN_NAME'])
groups=[('Accounts & access',['roles','users','patientprofiledetails','passwordresets','emailverificationtokens','signupemailverifications','requestratelimits']),('Clinics & schedules',['clinic','physicianaccountowners','physicianavailability','physiciandateavailability','clinicphoto','clinicfeerequests','clinicregistrationinquiry']),('Appointments & queue',['appointments','queue','appointmentreschedulerequests','visitcompletionreviews','visitcompletionnotifications']),('Consultation & AI',['consultations','consultationversions','aitranscriptversions','aitranscriptevaluations','feedback']),('Patient record sharing',['medicalrecords','medicalrecordaccessrequests','medicalrecordaccessfiles','medicalrecordaccessnotifications']),('Demo finances',['appointmentpayments','wallettransactions','demosettlements','demosettlementitems','physicianwithdrawals','platformdemobanktransfers']),('Messages & audit',['announcements','announcementdismissals','notifications','patientnotifications','physiciannotifications','smsoutbox','queueemailoutbox','auditlogs'])]
assert sorted(t for _,ts in groups for t in ts)==sorted(data['tables'])
E=lambda x:html.escape(str(x),quote=True)
def text(x,y,t,size=15,fill='#23475c',weight='400',anchor='start'):
 return f'<text x="{x}" y="{y}" font-size="{size}" fill="{fill}" font-weight="{weight}" text-anchor="{anchor}">{E(t)}</text>'
def keys(t,c):
 k=[]
 if c['COLUMN_KEY']=='PRI':k.append('PK')
 if (t,c['COLUMN_NAME']) in fkby:k.append('FK')
 if any(v==[c['COLUMN_NAME']] for name,v in indexes[t].items() if name!='PRIMARY'):k.append('UQ')
 return '/'.join(k)
def svg_diagram(title,subtitle,columns,selected=None,allfields=True,width=620):
 nodes={};gap=66;row=24;top=250;parts=[]
 for ci,(label,tables) in enumerate(columns):
  x=40+ci*(width+gap);y=top
  for t in tables:
   fields=cols[t] if allfields else [c for c in cols[t] if c['COLUMN_KEY']=='PRI' or (t,c['COLUMN_NAME']) in fkby]
   h=56+row*len(fields)+14;nodes[t]=(x,y,width,h,fields);y+=h+36
 H=max(y+h for x,y,w,h,fields in nodes.values())+80;W=80+len(columns)*width+(len(columns)-1)*gap
 parts=[f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}"><style>text{{font-family:Arial,sans-serif}}</style><defs><marker id="parent" markerWidth="7" markerHeight="7" refX="6" refY="3.5" orient="auto"><path d="M0 0 L7 3.5 L0 7Z" fill="#6999ad"/></marker></defs><rect width="100%" height="100%" fill="#f7fbfd"/>',text(40,55,title,30,'#003e52','700'),text(40,90,subtitle,17),text(40,122,'PK primary key | FK foreign key | UQ single-column unique | ? nullable | Arrows run from child FK to parent key.',15),text(40,150,'Only declared database relationships are drawn. Patient, Staff, Physician and Admin share Users through Roles.',15),text(40,178,'Snapshot: '+data['generated_at'][:10]+' | Database: '+data['database'],14,'#607d8c')]
 for ci,(label,tables) in enumerate(columns):parts.append(text(40+ci*(width+gap),226,label,20,'#007e9f','700'))
 contained=[edge for edge in edges if edge[0]['TABLE_NAME'] in nodes and edge[0]['REFERENCED_TABLE_NAME'] in nodes]
 for i,edge in enumerate(contained):
  f=edge[0];cx,cy,cw,ch,cf=nodes[f['TABLE_NAME']];px,py,pw,ph,pf=nodes[f['REFERENCED_TABLE_NAME']]
  iy=next((j for j,c in enumerate(cf) if c['COLUMN_NAME']==f['COLUMN_NAME']),0);jy=next((j for j,c in enumerate(pf) if c['COLUMN_NAME']==f['REFERENCED_COLUMN_NAME']),0)
  sy=cy+68+iy*row;ty=py+68+jy*row;sx=cx+cw;tx=px+pw
  lane=16+(i%9)*3;bus=192+(i%12)*2
  if cx==px:
   path=f'M {sx} {sy} H {sx+lane} V {ty} H {tx}'
  else:path=f'M {sx} {sy} H {sx+lane} V {bus} H {tx+lane} V {ty} H {tx}'
  parts.append(f'<path d="{path}" fill="none" stroke="#6999ad" stroke-opacity=".5" stroke-width="1.2" marker-end="url(#parent)"/>')
 for t,(x,y,w,h,fields) in nodes.items():
  parts.extend([f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="9" fill="white" stroke="#c8dce7"/>',f'<path d="M{x+9} {y}H{x+w-9}Q{x+w} {y} {x+w} {y+9}V{y+44}H{x}V{y+9}Q{x} {y} {x+9} {y}" fill="#006d8c"/>',text(x+14,y+28,t,19,'white','700')])
  for j,c in enumerate(fields):
   ry=y+49+j*row
   if j%2==0:parts.append(f'<rect x="{x+1}" y="{ry}" width="{w-2}" height="24" fill="#f0f7fa"/>')
   parts.extend([text(x+10,ry+17,keys(t,c),11,'#007b9a','700'),text(x+91,ry+17,c['COLUMN_NAME'],14),text(x+w-12,ry+17,c['COLUMN_TYPE']+(' ?' if c['IS_NULLABLE']=='YES' else ''),12,'#5e7788',anchor='end')])
 parts.append(text(40,H-30,f'{len(nodes)} entities | {len(contained)} declared relationships shown | Schema only; no patient records.',15));parts.append('</svg>');return ''.join(parts),W,H
svg,W,H=svg_diagram('HealthQueue | Complete Entity-Relationship Diagram',f'All {len(cols)} database tables, all {len(data["columns"])} columns and all {len(edges)} foreign-key relationships.',groups)
(out/'healthqueue-erd-current-full.svg').write_text(svg,encoding='utf-8')
core=[('Accounts & clinic',['roles','clinic','users','physicianaccountowners']),('Booking & consultation',['appointments','queue','consultations','consultationversions']),('Records & access',['medicalrecords','medicalrecordaccessrequests','medicalrecordaccessfiles','aitranscriptversions']),('Completion & finances',['visitcompletionreviews','appointmentpayments','wallettransactions','feedback'])]
svg,ow,oh=svg_diagram('HealthQueue | Core ERD', '16 core entities | PK and FK columns only | Use the complete ERD for all tables and attributes.',core,allfields=False)
(out/'healthqueue-erd-current-overview.svg').write_text(svg,encoding='utf-8')
# Reproducible Mermaid source, retaining every actual column and declared FK.
lines=['erDiagram']
for t in data['tables']:
 lines.append('    '+t+' {')
 for c in cols[t]:
  typ=c['COLUMN_TYPE'].split('(')[0].replace(' ','_');key=keys(t,c).replace('/',',');lines.append('        '+typ+' '+c['COLUMN_NAME']+(' '+key if key else ''))
 lines.append('    }')
for edge in edges:
 f=edge[0];child=f['TABLE_NAME'];nullable=any(c['IS_NULLABLE']=='YES' for c in cols[child] if c['COLUMN_NAME'] in [e['COLUMN_NAME'] for e in edge]);unique=any(set(v)==set(e['COLUMN_NAME'] for e in edge) for v in indexes[child].values());parent='|o' if nullable else '||';childend='o|' if unique else 'o{';lines.append('    '+f['REFERENCED_TABLE_NAME']+' '+parent+'--'+childend+' '+child+' : "'+','.join(e['COLUMN_NAME'] for e in edge)+'"')
(out/'healthqueue-erd-current.mmd').write_text('\n'.join(lines)+'\n',encoding='utf-8')
# A printable companion with all fields and explicit references, divided by module.
pages=[]
for label,tables in groups:
 cards=[]
 for t in tables:
  rows=[]
  for c in cols[t]:
   refs=fkby.get((t,c['COLUMN_NAME']),[]);ref='; '.join(f["REFERENCED_TABLE_NAME"]+'.'+f['REFERENCED_COLUMN_NAME'] for f in refs)
   rows.append('<tr><td>'+E(keys(t,c))+'</td><td>'+E(c['COLUMN_NAME'])+'</td><td>'+E(c['COLUMN_TYPE'])+(' NULL' if c['IS_NULLABLE']=='YES' else '')+'</td><td>'+E(ref)+'</td></tr>')
  cards.append('<article><h3>'+E(t)+'</h3><table><thead><tr><th>Key</th><th>Column</th><th>Type / nullability</th><th>References</th></tr></thead><tbody>'+''.join(rows)+'</tbody></table></article>')
 pages.append('<section><h2>'+E(label)+'</h2>'+''.join(cards)+'</section>')
relations=[]
for edge in edges:
 f=edge[0];relations.append('<tr><td>'+E(f['TABLE_NAME']+'.'+','.join(e['COLUMN_NAME'] for e in edge))+'</td><td>'+E(f['REFERENCED_TABLE_NAME']+'.'+','.join(e['REFERENCED_COLUMN_NAME'] for e in edge))+'</td><td>'+E(f['DELETE_RULE'])+'</td></tr>')
report='''<!doctype html><html><head><meta charset="utf-8"><style>@page{size:A3 landscape;margin:14mm}body{font-family:Arial,sans-serif;color:#183e53;font-size:10px}h1{font-size:25px;color:#003e52}h2{font-size:21px;border-bottom:2px solid #0084a1;padding-bottom:8px}h3{margin:0;background:#006d8c;color:#fff;padding:8px;font-size:13px}section{break-before:page}article{break-inside:avoid;margin:0 0 14px;border:1px solid #ccdee7}table{border-collapse:collapse;width:100%;table-layout:fixed;font-size:9px}th,td{text-align:left;border-bottom:1px solid #e0eaf0;padding:5px;overflow-wrap:anywhere}th{background:#eaf5f9}th:first-child{width:7%}th:nth-child(2){width:30%}th:nth-child(3){width:20%}thead{display:table-header-group}.overview{width:100%;height:auto;max-height:245mm;object-fit:contain}p{line-height:1.6}</style></head><body><h1>HealthQueue — Entity-Relationship Diagram</h1><p>Current database snapshot: '''+E(data['generated_at'][:10])+f''' | {len(cols)} tables | {len(data['columns'])} columns | {len(edges)} declared foreign keys.</p><p>The overview shows core PK/FK fields. The following module pages include every table and attribute, with explicit FK references. Four roles share Users. Only database-enforced relationships are represented; application-only associations are not claimed as foreign keys.</p><img class="overview" src="healthqueue-erd-current-overview.svg">'''+''.join(pages)+'<section><h2>Complete foreign-key relationship index</h2><table><thead><tr><th>Child column(s)</th><th>Parent column(s)</th><th>On delete</th></tr></thead><tbody>'+''.join(relations)+'</tbody></table></section></body></html>'
(out/'healthqueue-erd-current-reference.html').write_text(report,encoding='utf-8')
style=report[report.index('<style>'):report.index('</style>')+8]
printable='<!doctype html><html><head><meta charset="utf-8">'+style+'</head><body><h1>HealthQueue - Core Entity-Relationship Diagram</h1><p>Schema snapshot: '+E(data['generated_at'][:10])+'. Core overview only. See page 2 for all '+str(len(cols))+' tables, '+str(len(data['columns']))+' columns and '+str(len(edges))+' declared foreign-key relationships.</p><img class="overview" src="healthqueue-erd-current-overview.svg"><section><h2>Complete Entity-Relationship Diagram</h2><p>All current tables and attributes. Zoom to read individual fields. Arrows run from child foreign keys to referenced parent keys. No patient records are included.</p><img class="overview" src="healthqueue-erd-current-full.svg"></section></body></html>'
(out/'healthqueue-erd-current-print.html').write_text(printable,encoding='utf-8')
(out/'healthqueue-erd-current-render.json').write_text(json.dumps({'full':{'width':W,'height':H},'overview':{'width':ow,'height':oh},'tables':len(cols),'columns':len(data['columns']),'relationships':len(edges)}),encoding='utf-8')
print(json.dumps({'full':[W,H],'overview':[ow,oh],'tables':len(cols),'columns':len(data['columns']),'relationships':len(edges)}))
