<?php
/** Shared presentation and filtering for the admin directories. */
$isAdminDirectory = true;
$dirStatus = in_array($_GET['status'] ?? '', ['Active','Inactive'], true) ? $_GET['status'] : '';
$dirClinic = max(0, (int) ($_GET['clinic'] ?? 0));
$dirPage = max(1, (int) ($_GET['page'] ?? 1));
$dirPerPage = 10;
$dirStats = ['total'=>0,'active'=>0,'inactive'=>0,'archived'=>0,'pending'=>0];
$dirClinicOptions = [];
try {
 if ($pdo) {
  if ($directoryKind === 'clinics') {
   $row=$pdo->query("SELECT COUNT(*) AS total,COALESCE(SUM(archived=0 AND Status='Active'),0) AS active,COALESCE(SUM(archived=0 AND Status='Inactive'),0) AS inactive,COALESCE(SUM(archived=1),0) AS archived FROM Clinic")->fetch();
   foreach ($row as $key=>$value) $dirStats[$key]=(int)$value;
   $dirStats['pending']=(int)$pdo->query("SELECT COUNT(*) FROM ClinicRegistrationInquiry WHERE Status='Pending'")->fetchColumn();
  } else {
   $stmt=$pdo->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(u.Status='Active'),0) AS active,COALESCE(SUM(u.Status='Inactive'),0) AS inactive FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE r.RoleName=? AND u.DeletedAt IS NULL");
   $stmt->execute([ROLE_NAME]);foreach($stmt->fetch() as $key=>$value)$dirStats[$key]=(int)$value;
   $dirClinicOptions=$pdo->query('SELECT ClinicID,ClinicName FROM Clinic WHERE archived=0 ORDER BY ClinicName')->fetchAll();
  }
 }
} catch(PDOException $e) { $errors[]='Some directory totals could not be loaded.'; }
$dirRows = $directoryKind==='clinics' ? $clinics : $members;
$dirRows = array_values(array_filter($dirRows,static fn($row)=>($dirStatus==='' || $row['Status']===$dirStatus) && (!$dirClinic || $directoryKind==='patients' || $directoryKind==='clinics' || (int)$row['ClinicID']===$dirClinic)));
$dirTotalRows=count($dirRows);$dirPages=max(1,(int)ceil($dirTotalRows/$dirPerPage));$dirPage=min($dirPage,$dirPages);
$dirRows=array_slice($dirRows,($dirPage-1)*$dirPerPage,$dirPerPage);
if($directoryKind==='clinics')$clinics=$dirRows;else $members=$dirRows;
function dirUrl(array $changes=[]): string {
 $query=array_merge($_GET,$changes);unset($query['edit']);$query=array_filter($query,static fn($v)=>$v!==''&&$v!==null);
 return '?'.http_build_query($query);
}
function dirInitials(array $row): string {return mb_strtoupper(mb_substr($row['FirstName'],0,1).mb_substr($row['LastName'],0,1));}
function dirIcon(string $name='users'): string {
 $paths=['users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M21 21v-3a6 6 0 0 0-4-5"/>','clinic'=>'<path d="M4 21V5h16v16M2 21h20M9 21v-4h6v4M9 9h6M12 6v6M7 14h.01M17 14h.01"/>','check'=>'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>','inactive'=>'<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6m0-6-6 6"/>'];
 return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths[$name].'</svg>';
}
function renderDirectoryHeading(string $title,string $description,?string $modalId=null,?string $addLabel=null): void {
 global $directoryKind;
 echo '<p class="dr-breadcrumb">Administration <span>/</span> '.htmlspecialchars(ucfirst($directoryKind)).'</p><header class="dr-heading"><div><h1>'.htmlspecialchars($title).'</h1><p>'.htmlspecialchars($description).'</p></div><div class="dr-heading-actions">';
 if($modalId)echo '<button type="button" class="btn btn-primary" data-directory-open="'.htmlspecialchars($modalId).'">+ '.htmlspecialchars($addLabel).'</button>';
 if(in_array($directoryKind,['clinics','patients'],true))echo '<a class="btn btn-outline" href="export.php?type='.htmlspecialchars($directoryKind).'">Export CSV</a>';
 echo '</div></header>';
}
function renderDirectoryStats(): void {
 global $dirStats,$directoryKind;
 $cards=$directoryKind==='clinics' ? [['active','Active clinics','clinic'],['inactive','Inactive','inactive'],['archived','Archived','clinic'],['pending','Pending inquiries','clinic']] : [['total','Total '.$directoryKind,'users'],['active','Active','check'],['inactive','Inactive','inactive']];
 echo '<section class="dr-stats'.($directoryKind==='clinics'?' dr-stats-four':'').'" aria-label="Directory totals">';
 foreach($cards as [$key,$label,$icon])echo '<article><span class="dr-stat-icon">'.dirIcon($icon).'</span><div><h2>'.htmlspecialchars($label).'</h2><strong>'.$dirStats[$key].'</strong></div></article>';
 echo '</section>';
}
function renderDirectoryFilters(): void {
 global $search,$dirStatus,$dirClinic,$directoryKind,$dirClinicOptions,$showArchived;
 echo '<form method="get" class="dr-filters">';
 if($directoryKind==='clinics'&&!empty($showArchived))echo '<input type="hidden" name="archived" value="1">';
 $placeholder=['clinics'=>'Search clinic name or location','physicians'=>'Search physician name or email','staff'=>'Search staff name or email','patients'=>'Search patient name, ID, or email'][$directoryKind];
 echo '<label class="dr-search"><span class="sr-only">Search directory</span><input type="search" name="q" value="'.htmlspecialchars($search).'" placeholder="'.htmlspecialchars($placeholder).'"></label>';
 if(in_array($directoryKind,['physicians','staff'],true)) {
  echo '<label class="dr-filter-field"><span>Clinic</span><select name="clinic"><option value="">All clinics</option>';
  foreach($dirClinicOptions as $clinic)echo '<option value="'.(int)$clinic['ClinicID'].'"'.($dirClinic===(int)$clinic['ClinicID']?' selected':'').'>'.htmlspecialchars($clinic['ClinicName']).'</option>';
  echo '</select></label>';
 }
 echo '<label class="dr-filter-field"><span>Status</span><select name="status"><option value="">All statuses</option>';
 foreach(['Active','Inactive'] as $status)echo '<option value="'.$status.'"'.($status===$dirStatus?' selected':'').'>'.$status.'</option>';
 echo '</select></label><button class="btn btn-primary" type="submit">Apply filters</button>';
 if($search!==''||$dirStatus!==''||$dirClinic)echo '<a class="dr-reset" href="'.($directoryKind==='clinics'&&!empty($showArchived)?'?archived=1':'?').'">Reset</a>';
 echo '</form>';
}
function renderDirectoryPagination(): void {
 global $dirTotalRows,$dirPerPage,$dirPage,$dirPages,$directoryKind;
 $from=$dirTotalRows?($dirPage-1)*$dirPerPage+1:0;$to=min($dirTotalRows,$dirPage*$dirPerPage);
 echo '<footer class="dr-list-footer"><span>Showing '.$from.' to '.$to.' of '.$dirTotalRows.' '.htmlspecialchars($directoryKind).'</span><nav aria-label="Directory pages">';
 if($dirPage>1)echo '<a href="'.htmlspecialchars(dirUrl(['page'=>$dirPage-1])).'">Previous</a>';
 for($n=max(1,$dirPage-2);$n<=min($dirPages,$dirPage+2);$n++)echo '<a href="'.htmlspecialchars(dirUrl(['page'=>$n])).'"'.($n===$dirPage?' aria-current="page"':'').'>'.$n.'</a>';
 if($dirPage<$dirPages)echo '<a href="'.htmlspecialchars(dirUrl(['page'=>$dirPage+1])).'">Next</a>';
 echo '</nav></footer>';
}
