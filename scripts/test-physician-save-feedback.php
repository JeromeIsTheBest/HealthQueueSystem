<?php
if (PHP_SAPI !== 'cli') exit;
require __DIR__.'/../includes/physician-save-feedback.php';
function feedbackCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
$_SESSION=[];$_SERVER['REQUEST_METHOD']='POST';$flash='Availability saved.';
feedbackCheck(physicianSaveFeedback($flash,[],'../physician/availability.php?month=2026-12')==='../physician/availability.php?month=2026-12','Successful save redirects and preserves calendar month');
$_SERVER['REQUEST_METHOD']='GET';$flash='';feedbackCheck(physicianSaveFeedback($flash,[],'../physician/availability.php?month=2026-12')===null && $flash==='Availability saved.','First clean page shows success once');
$flash='';physicianSaveFeedback($flash,[],'../physician/availability.php?month=2026-12');feedbackCheck($flash==='','Refreshing does not repeat success');
$_SERVER['REQUEST_METHOD']='POST';$flash='';feedbackCheck(physicianSaveFeedback($flash,['Invalid input'],'../physician/dashboard.php')===null,'Validation errors remain visible');
$flash='Partial result';feedbackCheck(physicianSaveFeedback($flash,['Not saved'],'../physician/profile.php')===null && !isset($_SESSION['physician_save_feedback']['profile.php']),'Failed save is never flashed as success');
$flash='Profile saved';physicianSaveFeedback($flash,[],'../physician/profile.php');$_SERVER['REQUEST_METHOD']='GET';$flash='';physicianSaveFeedback($flash,[],'../physician/dashboard.php');feedbackCheck($flash==='','Feedback cannot leak to a different physician page');
physicianSaveFeedback($flash,[],'../physician/profile.php');feedbackCheck($flash==='Profile saved','Profile success is retained for its own page');
echo "Session fixtures only; no records, email, SMS or AI requests.\n";
