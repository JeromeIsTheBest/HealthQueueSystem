<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../includes/medical-record-access.php';
define('HQ_BASE_URL','..');
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
$user=currentUser();if(!$user||!in_array($user['RoleName'],['Patient','Physician'],true)){http_response_code(403);echo json_encode(['error'=>'Please sign in to your account.']);exit;}
try{
 $pdo=getDbConnection();if(!$pdo)throw new RuntimeException('Record sharing is temporarily unavailable.');
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!verifyCsrfToken($_POST['csrf_token']??null)){http_response_code(419);echo json_encode(['error'=>'Your session expired. Refresh and try again.']);exit;}
  if($user['RoleName']==='Physician'){
   if(($_POST['action']??'')!=='request')throw new RuntimeException('Invalid action.');
   requestMedicalRecordAccess($pdo,(int)$user['UserID'],(int)($_POST['appointment_id']??0));
  }else respondMedicalRecordAccess($pdo,(int)$user['UserID'],(int)($_POST['request_id']??0),(string)($_POST['action']??''),is_array($_POST['records']??null)?$_POST['records']:[],isset($_POST['grant_version'])?(string)$_POST['grant_version']:null);
 }elseif($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);exit;}
 $data=$user['RoleName']==='Patient'?patientRecordAccessRequests($pdo,(int)$user['UserID']):physicianRecordAccessState($pdo,(int)$user['UserID'],(int)($_POST['appointment_id']??$_GET['appointment_id']??0));echo json_encode($data,JSON_THROW_ON_ERROR);
}catch(RuntimeException $e){http_response_code(409);echo json_encode(['error'=>$e->getMessage()]);}catch(Throwable $e){error_log('Medical record access: '.$e->getMessage());http_response_code(503);echo json_encode(['error'=>'Record sharing is temporarily unavailable. Please try again.']);}
