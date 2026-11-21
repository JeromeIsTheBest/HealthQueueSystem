<?php
// Allowlisted public project documents only; private document paths are never accepted.
$role=(string)($_GET['role']??'');
$files=[
 'explainer'=>'HealthQueue-Explainer-Video-Script.docx',
 'acceptance-staff'=>'HealthQueue-Staff-Acceptance-Testing.docx',
 'acceptance-physician'=>'HealthQueue-Physician-Acceptance-Testing.docx',
 'acceptance-patient'=>'HealthQueue-Patient-Acceptance-Testing.docx',
 'acceptance'=>'HealthQueue-Acceptance-Testing.docx',
 ''=>'HealthQueue-Feedback-Questionnaire.docx',
 'objectives'=>'HealthQueue-Revised-Objectives-of-the-Study.docx',
 'ux'=>'HealthQueue-User-Experience-Test-Survey.docx',
 'ux-patient'=>'HealthQueue-Patient-User-Experience-Test-Survey.docx',
 'ux-staff'=>'HealthQueue-Staff-User-Experience-Test-Survey.docx',
 'ux-physician'=>'HealthQueue-Physician-User-Experience-Test-Survey.docx',
 'patient'=>'HealthQueue-Patient-Feedback-Questionnaire.docx',
 'staff'=>'HealthQueue-Staff-Feedback-Questionnaire.docx',
 'physician'=>'HealthQueue-Physician-Feedback-Questionnaire.docx',
];
if(!isset($files[$role])){http_response_code(404);exit('Questionnaire is unavailable.');}
$name=$files[$role];$file=__DIR__.'/docs/feedback/'.$name;
if(!is_file($file)){http_response_code(404);exit('Questionnaire is unavailable.');}
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="'.$name.'"');
header('Content-Length: '.filesize($file));
header('X-Content-Type-Options: nosniff');
readfile($file);
