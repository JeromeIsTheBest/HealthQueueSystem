import json,collections,html
from pathlib import Path
out=Path('docs/diagrams');s=json.loads((out/'healthqueue-current-schema.json').read_text(encoding='utf-8'));cols=collections.defaultdict(list)
for c in s['columns']:cols[c['TABLE_NAME']].append(c)
relations=collections.defaultdict(list)
for f in s['foreign_keys']:relations[(f['TABLE_NAME'],f['CONSTRAINT_NAME'])].append(f)
rels=list(relations.values());unique=collections.defaultdict(lambda:collections.defaultdict(list));fkcols={(f['TABLE_NAME'],f['COLUMN_NAME']) for f in s['foreign_keys']}
for i in s['indexes']:
 if not i['NON_UNIQUE']:unique[i['TABLE_NAME']][i['INDEX_NAME']].append(i['COLUMN_NAME'])
groups=[('Accounts and access','#d9edff',['roles','users','patientprofiledetails','passwordresets','emailverificationtokens','signupemailverifications','requestratelimits']),('Clinics and schedules','#fff0c5',['clinic','physicianaccountowners','physicianavailability','physiciandateavailability','clinicphoto','clinicfeerequests','clinicregistrationinquiry']),('Appointments and queue','#d8f1da',['appointments','queue','appointmentreschedulerequests','visitcompletionreviews','visitcompletionnotifications']),('Consultations and AI','#e8ddfa',['consultations','consultationversions','aitranscriptversions','aitranscriptevaluations','feedback']),('Medical record sharing','#f9dcf0',['medicalrecords','medicalrecordaccessrequests','medicalrecordaccessfiles','medicalrecordaccessnotifications']),('Demo earnings and payouts','#ffead4',['appointmentpayments','wallettransactions','demosettlements','demosettlementitems','physicianwithdrawals','platformdemobanktransfers']),('Notifications and audit','#d4f0ef',['announcements','announcementdismissals','notifications','patientnotifications','physiciannotifications','smsoutbox','queueemailoutbox','auditlogs'])]
color={t:c for _,c,ts in groups for t in ts};q=lambda v:json.dumps(str(v));esc=lambda v:html.escape(str(v),quote=True)
def node(t,fields=None):
 rows=[]
 for c in cols[t]:
  if fields and c['COLUMN_NAME'] not in fields:continue
  key=[]
  if c['COLUMN_KEY']=='PRI':key.append('PK')
  if (t,c['COLUMN_NAME']) in fkcols:key.append('FK')
  if any(v==[c['COLUMN_NAME']] for n,v in unique[t].items() if n!='PRIMARY'):key.append('UQ')
  bg='#ffffff' if len(rows)%2==0 else '#f4f6f8'
  rows.append('<TR><TD ALIGN="LEFT" PORT="'+'left_'+esc(c['COLUMN_NAME'])+'" BGCOLOR="'+bg+'">'+esc(c['COLUMN_NAME'])+'</TD><TD BGCOLOR="'+bg+'"><FONT POINT-SIZE="8">'+(esc(', '.join(key)) or '-')+'</FONT></TD><TD ALIGN="LEFT" PORT="right_'+esc(c['COLUMN_NAME'])+'" BGCOLOR="'+bg+'"><FONT COLOR="#506277" POINT-SIZE="9">'+esc(c['COLUMN_TYPE'])+(' ?' if c['IS_NULLABLE']=='YES' else '')+'</FONT></TD></TR>')
 return q(t)+' [label=<<TABLE BORDER="1" CELLBORDER="0" CELLSPACING="0" CELLPADDING="5" COLOR="#b5c4cf"><TR><TD COLSPAN="3" BGCOLOR="'+color[t]+'"><B>'+t.upper()+'</B></TD></TR>'+''.join(rows)+'</TABLE>>];'
def diagram(title,tables,edges,core=False):
 label=title+'\nHealthQueue actual schema | '+s['generated_at'][:10]+'\nPK primary key | FK foreign key | UQ unique | ? nullable | Crow-foot notation: circle = zero, bar = one, fork = many\nSolid: identifying FK (part of child PK). Dashed: non-identifying FK. R-number matches the relationship index.'
 lines=['digraph HealthQueue {','graph [rankdir=LR, bgcolor="white", splines=polyline, nodesep=0.65, ranksep=1.5, pad=0.5, outputorder=edgesfirst, forcelabels=true, fontname="Arial", fontsize=18, labelloc=t, label='+q(label)+'];','node [shape=plain, fontname="Arial", fontsize=10];','edge [fontname="Arial", fontsize=9, color="#738697", fontcolor="#334b5d", arrowsize=0.7, penwidth=1.1];']
 for t in tables:
  fields=None
  if core:fields={c['COLUMN_NAME'] for c in cols[t] if c['COLUMN_KEY']=='PRI' or (t,c['COLUMN_NAME']) in fkcols}
  lines.append(node(t,fields))
 for idx in edges:
  e=rels[idx];f=e[0];child=f['TABLE_NAME'];parent=f['REFERENCED_TABLE_NAME'];nullable=any(c['IS_NULLABLE']=='YES' for c in cols[child] if c['COLUMN_NAME'] in [x['COLUMN_NAME'] for x in e]);one=any(set(v)=={x['COLUMN_NAME'] for x in e} for v in unique[child].values());identifying=all(next(c for c in cols[child] if c['COLUMN_NAME']==x['COLUMN_NAME'])['COLUMN_KEY']=='PRI' for x in e)
  lines.append(q(parent)+':'+q('right_'+f['REFERENCED_COLUMN_NAME'])+':e -> '+q(child)+':'+q('left_'+f['COLUMN_NAME'])+':w [dir=both, arrowtail='+q('odottee' if nullable else 'teetee')+', arrowhead='+q('odottee' if one else 'crowodot')+', style='+q('solid' if identifying else 'dashed')+', label='+q('R'+str(idx+1).zfill(2)+': '+','.join(x['COLUMN_NAME'] for x in e))+'];')
 lines.append('}');return '\n'.join(lines)
core=['roles','users','clinic','physicianaccountowners','appointments','queue','consultations','consultationversions','aitranscriptversions','medicalrecords','medicalrecordaccessrequests','medicalrecordaccessfiles','visitcompletionreviews','appointmentpayments','wallettransactions','feedback']
jobs=[{'name':'full','title':'HealthQueue - Complete ERD','tables':s['tables'],'edges':list(range(len(rels))),'core':False},{'name':'core','title':'HealthQueue - Core ERD (key fields only)','tables':core,'edges':[i for i,e in enumerate(rels) if e[0]['TABLE_NAME'] in core and e[0]['REFERENCED_TABLE_NAME'] in core],'core':True}]
for i,(title,c,ts) in enumerate(groups,1):
 # Every FK is assigned once to its CHILD module. Referenced tables are repeated only for context.
 edges=[j for j,e in enumerate(rels) if e[0]['TABLE_NAME'] in ts];parents={e[0]['REFERENCED_TABLE_NAME'] for j,e in enumerate(rels) if j in edges};tables=list(ts)+sorted(parents-set(ts));jobs.append({'name':'section-'+str(i),'title':'HealthQueue - '+title,'tables':tables,'edges':edges,'core':False})
for job in jobs:(out/('healthqueue-erd-clear-'+job['name']+'.dot')).write_text(diagram(job['title'],job['tables'],job['edges'],job['core']),encoding='utf-8')
index=['ID\tChild table\tForeign-key column(s)\tParent table\tReferenced column(s)\tOn delete']
for i,e in enumerate(rels):f=e[0];index.append('\t'.join(['R'+str(i+1).zfill(2),f['TABLE_NAME'],','.join(x['COLUMN_NAME'] for x in e),f['REFERENCED_TABLE_NAME'],','.join(x['REFERENCED_COLUMN_NAME'] for x in e),f['DELETE_RULE']]))
(out/'healthqueue-erd-clear-relationships.tsv').write_text('\n'.join(index)+'\n',encoding='utf-8');(out/'healthqueue-erd-clear-jobs.json').write_text(json.dumps(jobs,indent=2),encoding='utf-8');print('Prepared',len(jobs),'diagrams; all',len(rels),'relationships assigned to a detailed section.')
