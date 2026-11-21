from pathlib import Path
import json,re
# Reuse the schema-aware node/relationship builder, without running its output stage.
source=Path('scripts/build-clear-erd.py').read_text(encoding='utf-8-sig');env={};exec(source[:source.index("core=['roles'")],env)
out=Path('docs/diagrams/manuscript');out.mkdir(exist_ok=True);rels=env['rels'];base_node=env['node'];cols=env['cols'];jobs=[]
for group_i,(title,color,children) in enumerate(env['groups'],1):
 for chunk_i,start in enumerate(range(0,len(children),2),1):
  focus=children[start:start+2];edge_ids=[i for i,e in enumerate(rels) if e[0]['TABLE_NAME'] in focus];parents={rels[i][0]['REFERENCED_TABLE_NAME'] for i in edge_ids};tables=focus+sorted(parents-set(focus))
  def node(t,fields=None):
   fields=None if t in focus else {e['REFERENCED_COLUMN_NAME'] for i in edge_ids for e in rels[i] if e['REFERENCED_TABLE_NAME']==t}
   value=base_node(t,fields).replace('CELLPADDING="5"','CELLPADDING="2"').replace('POINT-SIZE="8"','POINT-SIZE="10"').replace('POINT-SIZE="9"','POINT-SIZE="11"')
   value=re.sub(r'(enum|set)\([^<]*\)',lambda m:m.group(1).upper(),value,flags=re.I)
   return value
  env['node']=node
  name=f'group-{group_i}-{chunk_i}';caption=title+' - '+', '.join(focus)
  dot=env['diagram'](caption,tables,edge_ids,False).replace('fontsize=10','fontsize=12').replace('fontsize=18','fontsize=14').replace('nodesep=0.65','nodesep=0.25').replace('ranksep=1.5','ranksep=0.45')
  short_label='HealthQueue - '+title+'\nPK primary key | FK foreign key | UQ unique | ? nullable\nR-numbers match the relationship index. Referenced tables repeat for context.'
  dot=re.sub(r'labelloc=t, label="(?:\\.|[^"\\])*"',lambda m:'labelloc=t, label='+json.dumps(short_label),dot,count=1)
  # Keep column names on the entities; short relationship IDs avoid labels forcing wide pages.
  dot=re.sub(r'label="(R\d+): [^"]*"',r'label="\1"',dot)
  (out/(name+'.dot')).write_text(dot,encoding='utf-8');jobs.append({'name':name,'caption':caption,'focus':focus,'tables':tables,'edges':edge_ids})
(out/'jobs.json').write_text(json.dumps(jobs,indent=2),encoding='utf-8');print('Prepared',len(jobs),'manuscript detail sheets.')
