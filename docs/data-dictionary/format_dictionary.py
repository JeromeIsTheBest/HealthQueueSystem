from pathlib import Path
from zipfile import ZipFile,ZIP_DEFLATED
from xml.sax.saxutils import escape
import runpy,re
R=Path(__file__).parent
G=runpy.run_path(str(R/'build_dictionary.py'))
D,TABLES,INDEX,FK,DESC,STATUS=[G[k] for k in ['D','TABLES','INDEX','FK','DESC','STATUS']]
order=['roles','users','clinicregistrationinquiry','clinic']+[t for t in TABLES if t not in ['roles','users','clinicregistrationinquiry','clinic']]
schema=(R.parent.parent/'database/schema.sql').read_text(encoding='utf-8')
names={m.lower():m for m in re.findall(r'CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)',schema)}
NS='xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
def x(s):return escape(str(s))
def p(text,bold=False,center=False,keep=False):
 return '<w:p><w:pPr>'+('<w:jc w:val="center"/>' if center else '')+('<w:keepNext/>' if keep else '')+'<w:spacing w:after="100" w:line="300" w:lineRule="auto"/></w:pPr><w:r><w:rPr>'+('<w:b/>' if bold else '')+'</w:rPr><w:t xml:space="preserve">'+x(text)+'</w:t></w:r></w:p>'
widths=[2340,2010,2640,2370]
def cell(text,width):
 return f'<w:tc><w:tcPr><w:tcW w:w="{width}" w:type="dxa"/><w:vAlign w:val="top"/></w:tcPr>'+p(text)+'</w:tc>'
def table(rows):
 a='<w:tbl><w:tblPr><w:tblW w:w="9360" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>'+''.join(f'<w:{n} w:val="single" w:sz="6" w:color="000000"/>' for n in ['top','left','bottom','right','insideH','insideV'])+'</w:tblBorders><w:tblCellMar><w:top w:w="85" w:type="dxa"/><w:left w:w="110" w:type="dxa"/><w:bottom w:w="85" w:type="dxa"/><w:right w:w="110" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'+''.join(f'<w:gridCol w:w="{w}"/>' for w in widths)+'</w:tblGrid>'
 for i,row in enumerate([['Column Name','Data Type','Constraints','Description']]+rows):
  a+='<w:tr><w:trPr><w:cantSplit/>'+('<w:tblHeader/>' if i==0 else '')+'</w:trPr>'+''.join(cell(t,w) for t,w in zip(row,widths))+'</w:tr>'
 return a+'</w:tbl>'
body=[]
for num,t in enumerate(order,1):
 if num>1:body.append('<w:p><w:r><w:br w:type="page"/></w:r></w:p>')
 if num==1:body.append(p('Data Dictionary',True,keep=True))
 body.extend([p(f'Table {num}',center=True,keep=True),p('DATA DICTIONARY: '+names.get(t,t),center=True,keep=True)])
 rows=[]
 for c in TABLES[t]:
  name=c['COLUMN_NAME'];constraints=[]
  for (it,ix),items in INDEX.items():
   if it!=t or name not in [v['COLUMN_NAME'] for v in items]:continue
   if ix=='PRIMARY':constraints.append('PK' if len(items)==1 else 'PK (composite)')
   elif items[0]['NON_UNIQUE']==0:constraints.append('UNIQUE' if len(items)==1 else 'UNIQUE (composite)')
  if 'auto_increment' in c['EXTRA']:constraints.append('AUTO_INCREMENT')
  rel=FK.get((t,name))
  if rel:constraints.append('FK: '+names.get(rel['REFERENCED_TABLE_NAME'],rel['REFERENCED_TABLE_NAME'])+'.'+rel['REFERENCED_COLUMN_NAME'])
  constraints.append('NULL' if c['IS_NULLABLE']=='YES' else 'NOT NULL')
  if c['COLUMN_DEFAULT'] is not None:constraints.append('DEFAULT '+str(c['COLUMN_DEFAULT']))
  if 'on update' in c['EXTRA'].lower():constraints.append(c['EXTRA'].upper())
  description=STATUS.get(t,'Lifecycle or review state of this record.') if name=='Status' else DESC.get(name,c['COLUMN_COMMENT'] or 'Value stored for this record.')
  if t=='smsoutbox' and name in ['PatientID','AppointmentID']:description+=' Application reference; no database FK is declared.'
  typ=re.sub(r'(?i)\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)',lambda m:m.group(1),c['COLUMN_TYPE']).upper()
  rows.append([name,typ,', '.join(dict.fromkeys(constraints)),description])
 body.append(table(rows))
section='<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="720" w:right="1440" w:bottom="720" w:left="1440"/></w:sectPr>'
doc=f'<?xml version="1.0" encoding="UTF-8"?><w:document {NS}><w:body>'+''.join(body)+section+'</w:body></w:document>'
styles=f"""<?xml version="1.0" encoding="UTF-8"?><w:styles {NS}><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/><w:sz w:val="22"/><w:color w:val="000000"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:pPr><w:spacing w:line="300" w:lineRule="auto"/></w:pPr></w:style></w:styles>"""
ct='<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>'
rels='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>'
dr='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>'
output=R/'HealthQueue-Data-Dictionary-Formatted.docx'
with ZipFile(output,'w',ZIP_DEFLATED) as z:
 for name,text in {'[Content_Types].xml':ct,'_rels/.rels':rels,'word/document.xml':doc,'word/styles.xml':styles,'word/_rels/document.xml.rels':dr}.items():z.writestr(name,text.encode('utf-8'))
print('Created sample-format Word document with 28 tables and 245 fields.')
