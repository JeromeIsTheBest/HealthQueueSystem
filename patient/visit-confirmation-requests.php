<?php
require_once __DIR__.'/../includes/auth.php';require_once __DIR__.'/../includes/visit-completion.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$user=currentUser();if(!$user||$user['RoleName']!=='Patient'){http_response_code(403);echo json_encode(['error'=>'Patient access required.']);exit;}
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
try{echo json_encode(['visits'=>pendingPatientVisitConfirmations(getDbConnection(),(int)$user['UserID'])],JSON_THROW_ON_ERROR);}catch(Throwable $e){error_log('Visit confirmation reminders: '.$e->getMessage());http_response_code(503);echo json_encode(['error'=>'Visit confirmation is temporarily unavailable.']);}
