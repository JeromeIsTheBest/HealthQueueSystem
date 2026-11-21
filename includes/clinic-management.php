<?php
require_once __DIR__.'/physician-accounts.php';
require_once __DIR__.'/clinic-photos.php';
require_once __DIR__.'/input-validation.php';
require_once __DIR__.'/clinic-inquiry.php';
require_once __DIR__.'/clinic-fees.php';
require_once __DIR__.'/audit.php';

/** Admins manage clinic registration details; operational/public profile fields belong to the owner. */
function saveAdminClinicBasics(PDO $pdo,int $adminId,array $input,array $files=[]):int
{
    $admin=$pdo->prepare("SELECT 1 FROM Users u JOIN Roles r ON r.RoleID=u.RoleID WHERE u.UserID=? AND r.RoleName='Admin' AND u.Status='Active' AND u.DeletedAt IS NULL");$admin->execute([$adminId]);
    if(!$admin->fetchColumn())throw new RuntimeException('Administrator access required.');
    foreach(['open_days','open_time','close_time','description','remove_photos','photo_order','photo_url'] as $field)if(array_key_exists($field,$input))throw new RuntimeException('Clinic hours, photos and description are managed by the main physician.');
    if($files)throw new RuntimeException('Clinic photos are managed by the main physician.');
    $id=(int)($input['clinic_id']??0);$name=trim((string)($input['clinic_name']??''));$address=trim((string)($input['address']??''));$phone=trim((string)($input['contact_number']??''));$fee=trim((string)($input['base_consultation_fee']??''));
    $specialties=implode(', ',array_unique(array_filter(array_map(static fn($x)=>ucfirst(trim($x)),explode(',',(string)($input['specialties']??''))))));
    if($name===''||mb_strlen($name)>150||$address===''||mb_strlen($address)>255)throw new RuntimeException('Enter a clinic name up to 150 characters and address up to 255 characters.');
    if($phone===''||!hqValidMobile($phone))throw new RuntimeException('Enter a valid mobile number');
    if(!validClinicInquiryPrice($fee))throw new RuntimeException('Enter a valid consultation fee with up to two decimal places.');
    if(mb_strlen($specialties)>255)throw new RuntimeException('Specialties must be 255 characters or fewer.');
    $lat=trim((string)($input['latitude']??''));$lng=trim((string)($input['longitude']??''));
    if(($lat==='')!==($lng==='')||($lat!==''&&(!is_numeric($lat)||!is_numeric($lng)||abs((float)$lat)>90||abs((float)$lng)>180)))throw new RuntimeException('Enter valid latitude and longitude coordinates.');
    $pdo->beginTransaction();
    try{
        $params=[$name,$address,$phone,$fee,$specialties?:null,$lat===''?null:$lat,$lng===''?null:$lng];
        if($id){$s=$pdo->prepare('SELECT ClinicID FROM Clinic WHERE ClinicID=? FOR UPDATE');$s->execute([$id]);if(!$s->fetchColumn())throw new RuntimeException('Clinic not found.');$owner=$pdo->prepare('SELECT MainPhysicianID FROM PhysicianAccountOwners WHERE ClinicID=?');$owner->execute([$id]);$hasOwner=(bool)$owner->fetchColumn();if($hasOwner&&(int)round((float)$fee*100)!==(int)round((float)currentClinicFee($pdo,$id)*100))throw new RuntimeException('Review a main physician fee-change request to change this clinic fee.');if($hasOwner){$original=$pdo->prepare('SELECT BaseConsultationFee FROM Clinic WHERE ClinicID=?');$original->execute([$id]);$params[3]=$original->fetchColumn();}$pdo->prepare('UPDATE Clinic SET ClinicName=?,Address=?,ContactNumber=?,BaseConsultationFee=?,Specialties=?,Latitude=?,Longitude=? WHERE ClinicID=?')->execute([...$params,$id]);}
        else{$pdo->prepare("INSERT INTO Clinic(ClinicName,Address,ContactNumber,BaseConsultationFee,Specialties,Latitude,Longitude,OpenDays,OpenTime,CloseTime) VALUES(?,?,?,?,?,?,?,'','00:00','00:00')")->execute($params);$id=(int)$pdo->lastInsertId();}
        logActivity($pdo,$adminId,$id,'Saved clinic registration details',$name);$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

/** Main physician edits only their own clinic's hours, description and photo gallery. */
function saveManagedClinic(PDO $pdo,int $physicianId,array $input,array $files=[]):void
{
    $owner=requireMainPhysician($pdo,$physicianId);$clinicId=(int)$owner['ClinicID'];
    if(isset($input['clinic_id'])&&(int)$input['clinic_id']!==$clinicId)throw new RuntimeException('You can only manage your own clinic.');
    foreach((array)($input['open_days']??[]) as $day)if(!is_scalar($day)||!preg_match('/\A[1-7]\z/',(string)$day))throw new RuntimeException('Choose valid opening days.');
    $days=array_values(array_unique(array_map('intval',(array)($input['open_days']??[]))));sort($days);
    foreach($days as $day)if($day<1||$day>7)throw new RuntimeException('Choose valid opening days.');
    $open=(string)($input['open_time']??'');$close=(string)($input['close_time']??'');$description=trim((string)($input['description']??''));
    if(!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/',$open)||!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/',$close)||$open>=$close)throw new RuntimeException('Enter valid opening and closing times; closing must be later than opening.');
    if(mb_strlen($description)>5000)throw new RuntimeException('Clinic description must be 5,000 characters or fewer.');
    $photos=$files['photos']??[];$count=is_array($photos['name']??null)?count($photos['name']):0;
    if($count>10)throw new RuntimeException('Select at most 10 photos per upload.');
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];$uploads=[];
    for($i=0;$i<$count;$i++){
        $error=$photos['error'][$i]??UPLOAD_ERR_NO_FILE;if($error===UPLOAD_ERR_NO_FILE)continue;$tmp=$photos['tmp_name'][$i]??'';
        if($error!==UPLOAD_ERR_OK||!is_uploaded_file($tmp))throw new RuntimeException('A photo could not be uploaded. Please try again.');
        if(($photos['size'][$i]??0)<=0||$photos['size'][$i]>4*1024*1024)throw new RuntimeException('Each clinic photo must be 4 MB or smaller.');
        $mime=mime_content_type($tmp);if(!isset($allowed[$mime])||!getimagesize($tmp))throw new RuntimeException('Clinic photos must be valid JPG, PNG, or WEBP images.');
        $uploads[]=['tmp'=>$tmp,'name'=>'clinic_'.bin2hex(random_bytes(16)).'.'.$allowed[$mime]];
    }
    $saved=[];$removed=[];$committed=false;$uploadDir=__DIR__.'/../assets/uploads/clinics';
    $pdo->beginTransaction();
    try{
        $s=$pdo->prepare("SELECT * FROM Clinic WHERE ClinicID=? AND archived=0 AND Status='Active' FOR UPDATE");$s->execute([$clinicId]);$clinic=$s->fetch();if(!$clinic)throw new RuntimeException('Your clinic is currently unavailable.');
        $pdo->prepare('SELECT UserID FROM Users WHERE UserID=? FOR UPDATE')->execute([$physicianId]);requireMainPhysician($pdo,$physicianId);
        $scheduleChanged=implode(',',$days)!==$clinic['OpenDays']||$open!==substr($clinic['OpenTime'],0,5)||$close!==substr($clinic['CloseTime'],0,5);
        $s=$pdo->prepare("SELECT AppointmentDate,AppointmentTime FROM Appointments WHERE ClinicID=? AND AppointmentDate>=CURDATE() AND (Status='Confirmed' OR (Status='Pending' AND BookingFeePaid=1))");$s->execute([$clinicId]);
        foreach($s->fetchAll() as $visit){
            if(!$scheduleChanged)break;
            if(!in_array((int)date('N',strtotime($visit['AppointmentDate'])),$days,true))throw new RuntimeException('These opening days would exclude an existing paid or confirmed appointment. Have staff resolve the affected visit first.');
            $time=$visit['AppointmentTime'];if($time&&$time!=='00:00:00'&&($time<$open.':00'||strtotime($time)+900>strtotime($close)))throw new RuntimeException('These hours would exclude an existing scheduled appointment. Have staff reschedule it first.');
        }
        $requested=array_filter((array)($input['remove_photos']??[]),'is_string');$removed=array_values(array_intersect(clinicPhotos($pdo,$clinic),$requested));
        foreach($removed as $photo)$pdo->prepare('DELETE FROM ClinicPhoto WHERE ClinicID=? AND Filename=?')->execute([$clinicId,$photo]);
        if(in_array($clinic['PhotoUrl'],$removed,true))$pdo->prepare('UPDATE Clinic SET PhotoUrl=NULL WHERE ClinicID=?')->execute([$clinicId]);
        $pdo->prepare('UPDATE Clinic SET OpenDays=?,OpenTime=?,CloseTime=?,Description=? WHERE ClinicID=?')->execute([implode(',',$days),$open,$close,$description?:null,$clinicId]);
        if($uploads&&!is_dir($uploadDir)&&!mkdir($uploadDir,0755,true))throw new RuntimeException('Clinic photo storage is unavailable.');
        foreach($uploads as $upload){$path=$uploadDir.'/'.$upload['name'];if(!move_uploaded_file($upload['tmp'],$path))throw new RuntimeException('Could not save clinic photo.');$saved[]=$path;$pdo->prepare('INSERT INTO ClinicPhoto(ClinicID,Filename) VALUES(?,?)')->execute([$clinicId,$upload['name']]);}
        saveClinicPhotoOrder($pdo,$clinicId,(array)($input['photo_order']??[]));
        logActivity($pdo,$physicianId,$clinicId,'Updated own clinic settings','Opening schedule, clinic description and photos.');$pdo->commit();$committed=true;
        foreach($removed as $photo){$s=$pdo->prepare('SELECT (SELECT COUNT(*) FROM Clinic WHERE PhotoUrl=?)+(SELECT COUNT(*) FROM ClinicPhoto WHERE Filename=?)');$s->execute([$photo,$photo]);$path=$uploadDir.'/'.basename($photo);if(!(int)$s->fetchColumn()&&is_file($path))unlink($path);}
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if(!$committed)foreach($saved as $path)if(is_file($path))unlink($path);throw $e;}
}
