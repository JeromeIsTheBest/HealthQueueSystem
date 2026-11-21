from pathlib import Path
import json,zipfile,html,re,xml.etree.ElementTree as ET
out=Path('docs/diagrams/manuscript');jobs=json.loads((out/'render-jobs.json').read_text(encoding='utf-8'));schema=json.loads(Path('docs/diagrams/healthqueue-current-schema.json').read_text(encoding='utf-8'));ns={'s':'http://www.w3.org/2000/svg'};focuses=[];edgeids=[]
for j in jobs[1:]:
 root=ET.parse(out/(j['name']+'.svg'));nodes=root.findall('.//s:g[@class="node"]',ns);look={n.find('s:title',ns).text:n for n in nodes}
 for t in j['focus']:
  texts=[n.text for n in look[t].findall('s:text',ns)]
  for c in schema['columns']:
   if c['TABLE_NAME']==t:assert c['COLUMN_NAME'] in texts,(j['name'],t,c['COLUMN_NAME'])
 focuses+=j['focus'];edgeids+=j['edges']
assert sorted(focuses)==sorted(schema['tables']);assert sorted(edgeids)==list(range(86));print('Coverage verified: all 42 entities, 362 fields, and 86 relationships.')
print('PDF pages:',len(re.findall(rb'/Type\s*/Page\b',(out/'HealthQueue-Manuscript-ERD.pdf').read_bytes())))
esc=lambda s:html.escape(str(s),quote=True)
def para(text,bold=False,size=20):return '<w:p><w:pPr><w:spacing w:before="0" w:after="80"/></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/><w:sz w:val="'+str(size)+'"/>'+('<w:b/>' if bold else '')+'</w:rPr><w:t xml:space="preserve">'+esc(text)+'</w:t></w:r></w:p>'
body=[];rels=['<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'];media=[]
for i,j in enumerate(jobs):
 if i:body.append('<w:p><w:r><w:br w:type="page"/></w:r></w:p>')
 body.append(para('Database Design - Entity-Relationship Diagram'+(' (continued)' if i else ''),True,24))
 scale=min(720/j['width'],480/j['height']);cx=round(j['width']*scale*12700);cy=round(j['height']*scale*12700);rid='rId'+str(i+1);name=j['name']+'.png';rels.append('<Relationship Id="'+rid+'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'+name+'"/>');media.append((name,(out/name).read_bytes()))
 body.append(f'''<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="0" w:after="0"/></w:pPr><w:r><w:rPr><w:sz w:val="2"/></w:rPr><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="{cx}" cy="{cy}"/><wp:docPr id="{i+1}" name="{esc(j['caption'])}" descr="HealthQueue database schema ERD"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="{i+1}" name="{name}"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="{rid}"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="{cx}" cy="{cy}"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>''')
 caption='Entity index. All fields and declared relationships are shown on the continuation pages.' if i==0 else 'ERD detail '+str(i)+': '+j['caption']+'. Referenced entities repeat for context.'
 body.append(para(caption,False,18))
body.append('<w:sectPr><w:pgSz w:w="15840" w:h="12240" w:orient="landscape"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="360" w:footer="360"/></w:sectPr>')
document='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'+''.join(body)+'</w:body></w:document>'
ET.fromstring(document);rels.append('</Relationships>')
with zipfile.ZipFile(out/'HealthQueue-Manuscript-ERD.docx','w',zipfile.ZIP_DEFLATED) as z:
 z.writestr('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
 z.writestr('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>')
 z.writestr('word/document.xml',document);z.writestr('word/_rels/document.xml.rels',''.join(rels))
 for name,data in media:z.writestr('word/media/'+name,data)
with zipfile.ZipFile(out/'HealthQueue-Manuscript-ERD-Images.zip','w',zipfile.ZIP_DEFLATED) as z:
 for j in jobs:
  for ext in ['.png','.svg']:z.write(out/(j['name']+ext),j['name']+ext)
 z.write(Path('docs/diagrams/healthqueue-erd-clear-relationships.tsv'),'Relationship-Index.tsv')
print('Word document and high-resolution image package created.')
