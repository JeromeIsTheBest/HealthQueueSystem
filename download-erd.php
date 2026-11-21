<?php
// Public, schema-only documentation. No arbitrary paths or application data.
$format=(string)($_GET['format']??'pdf');
$files=[
 'dbdiagram-dbml'=>['HealthQueue-dbdiagram.dbml','text/plain; charset=utf-8'],

 'dbdiagram-sql'=>['HealthQueue-dbdiagram.sql','text/plain; charset=utf-8'],
 'exact-schema-sql'=>['HealthQueue-Exact-Schema.sql','text/plain; charset=utf-8'],

 'letter-word'=>['HealthQueue-ERD-Letter-Landscape.docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],

 'larger-letter-pdf'=>['healthqueue-erd-arranged-letter-large.pdf','application/pdf'],
 'larger-letter-image'=>['healthqueue-erd-arranged-letter-large.png','image/png'],
 'larger-letter-svg'=>['healthqueue-erd-arranged-letter-large.svg','image/svg+xml'],

 'arranged-letter-pdf'=>['healthqueue-erd-arranged-letter.pdf','application/pdf'],
 'arranged-letter-image'=>['healthqueue-erd-arranged-letter.png','image/png'],
 'arranged-letter-svg'=>['healthqueue-erd-arranged-letter.svg','image/svg+xml'],

 'letter-pdf'=>['healthqueue-erd-letter-landscape.pdf','application/pdf'],
 'letter-image'=>['healthqueue-erd-letter-landscape.png','image/png'],
 'letter-svg'=>['healthqueue-erd-letter-landscape.svg','image/svg+xml'],

 'one-page-pdf'=>['healthqueue-erd-one-page.pdf','application/pdf'],
 'one-page-image'=>['healthqueue-erd-one-page.png','image/png'],
 'one-page-svg'=>['healthqueue-erd-one-page.svg','image/svg+xml'],

 'manuscript-word'=>['manuscript/HealthQueue-Manuscript-ERD.docx','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
 'manuscript-pdf'=>['manuscript/HealthQueue-Manuscript-ERD.pdf','application/pdf'],
 'manuscript-images'=>['manuscript/HealthQueue-Manuscript-ERD-Images.zip','application/zip'],
 'manuscript-overview'=>['manuscript/overview.png','image/png'],

 'clear-pdf'=>['healthqueue-erd-clear.pdf','application/pdf'],
 'clear-full'=>['healthqueue-erd-clear-full.png','image/png'],
 'clear-core'=>['healthqueue-erd-clear-core.png','image/png'],
 'clear-svg'=>['healthqueue-erd-clear-full.svg','image/svg+xml'],
 'clear-package'=>['healthqueue-erd-clear-package.zip','application/zip'],
 'clear-records'=>['healthqueue-erd-clear-section-5.png','image/png'],

 'pdf'=>['healthqueue-erd-current.pdf','application/pdf'],
 'overview'=>['healthqueue-erd-current-overview.png','image/png'],
 'full'=>['healthqueue-erd-current-full.png','image/png'],
 'overview-svg'=>['healthqueue-erd-current-overview.svg','image/svg+xml'],
 'full-svg'=>['healthqueue-erd-current-full.svg','image/svg+xml'],
 'mermaid'=>['healthqueue-erd-current.mmd','text/plain; charset=utf-8'],
];
if(!isset($files[$format])){http_response_code(404);exit('Diagram unavailable.');}
[$name,$mime]=$files[$format];$path=__DIR__.'/docs/diagrams/'.$name;
if(!is_file($path)){http_response_code(404);exit('Diagram unavailable.');}
header('Cache-Control: no-store');header('Content-Type: '.$mime);header('Content-Disposition: attachment; filename="'.basename($name).'"');header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');readfile($path);
