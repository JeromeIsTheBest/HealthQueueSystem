<?php
/** Clinic membership controls finances; clinical access remains assigned-physician only. */
function physicianAccount(PDO $pdo, int $userId): array
{
    $s=$pdo->prepare("SELECT u.UserID,u.ClinicID,u.FirstName,u.LastName,u.Status,u.DeletedAt,c.ClinicName,o.MainPhysicianID FROM Users u JOIN Roles r ON r.RoleID=u.RoleID LEFT JOIN Clinic c ON c.ClinicID=u.ClinicID LEFT JOIN PhysicianAccountOwners o ON o.ClinicID=u.ClinicID WHERE u.UserID=? AND r.RoleName='Physician'");
    $s->execute([$userId]);$row=$s->fetch();
    if(!$row)throw new RuntimeException('Physician account not found.');
    $row['is_main']=$row['MainPhysicianID']!==null&&(int)$row['MainPhysicianID']===$userId;
    return $row;
}
function requireMainPhysician(PDO $pdo,int $userId): array
{
    $account=physicianAccount($pdo,$userId);
    if(!$account['is_main']||$account['Status']!=='Active'||$account['DeletedAt']!==null)throw new RuntimeException('Only the main physician can manage clinic settings, earnings and sub-accounts.');
    return $account;
}
/** Caller owns transaction and locks the clinic before creating physicians. */
function registerPhysicianOwner(PDO $pdo,int $clinicId,int $userId): void
{
    $pdo->prepare('INSERT IGNORE INTO PhysicianAccountOwners (ClinicID,MainPhysicianID) VALUES (?,?)')->execute([$clinicId,$userId]);
}

/** Owner may create/edit their clinic's sub-accounts, never another owner or clinic. */
function savePhysicianSubAccount(PDO $pdo,int $ownerId,array $input): int
{
    require_once __DIR__.'/input-validation.php';
    $first=hqCapitalizeName((string)($input['first_name']??''));$last=hqCapitalizeName((string)($input['last_name']??''));
    $email=trim((string)($input['email']??''));$phone=trim((string)($input['contact_number']??''));
    $specialty=trim((string)($input['specialty']??''));$password=(string)($input['password']??'');$id=(int)($input['member_id']??0);
    if($first===''||$last===''||mb_strlen($first)>50||mb_strlen($last)>50)throw new RuntimeException('Enter a first and last name of up to 50 characters.');
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>100)throw new RuntimeException('Enter a valid email address.');
    if(!preg_match('/^09[0-9]{9}$/D',$phone))throw new RuntimeException('Enter a valid mobile number');
    if(mb_strlen($specialty)>100)throw new RuntimeException('Specialty must be 100 characters or fewer.');
    if((!$id||$password!=='')&&strlen($password)<8)throw new RuntimeException('Password must be at least 8 characters long.');
    $owner=requireMainPhysician($pdo,$ownerId);
    $pdo->beginTransaction();
    try{
        $clinicLock=$pdo->prepare("SELECT ClinicID FROM Clinic WHERE ClinicID=? AND Status='Active' AND archived=0 FOR UPDATE");$clinicLock->execute([$owner['ClinicID']]);
        if(!$clinicLock->fetchColumn())throw new RuntimeException('This clinic is unavailable.');
        $pdo->prepare('SELECT UserID FROM Users WHERE UserID=? FOR UPDATE')->execute([$ownerId]);
        requireMainPhysician($pdo,$ownerId);
        if(hqMobileInUse($pdo,$phone,$id))throw new RuntimeException('This mobile number is already registered to another account.');
        if($id){
            $s=$pdo->prepare("SELECT UserID FROM Users WHERE UserID=? AND ClinicID=? AND UserID<>? AND DeletedAt IS NULL AND RoleID=(SELECT RoleID FROM Roles WHERE RoleName='Physician') FOR UPDATE");$s->execute([$id,$owner['ClinicID'],$ownerId]);
            if(!$s->fetchColumn())throw new RuntimeException('Sub-account not found in your clinic.');
            $sql='UPDATE Users SET FirstName=?,LastName=?,Email=?,ContactNumber=?,PhysicianSpecialty=?';$args=[$first,$last,$email,$phone,$specialty];
            if($password!==''){$sql.=',PasswordHash=?';$args[]=password_hash($password,PASSWORD_DEFAULT);}
            $sql.=' WHERE UserID=?';$args[]=$id;$pdo->prepare($sql)->execute($args);
        }else{
            $pdo->prepare("INSERT INTO Users (ClinicID,RoleID,FirstName,LastName,Email,ContactNumber,PhysicianSpecialty,PasswordHash) VALUES (?,(SELECT RoleID FROM Roles WHERE RoleName='Physician'),?,?,?,?,?,?)")->execute([$owner['ClinicID'],$first,$last,$email,$phone,$specialty,password_hash($password,PASSWORD_DEFAULT)]);
            $id=(int)$pdo->lastInsertId();assignUserIdNumber($pdo,$id);
        }
        $pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function setPhysicianSubAccountStatus(PDO $pdo,int $ownerId,int $id,string $status): void
{
    if(!in_array($status,['Active','Inactive'],true))throw new RuntimeException('Choose a valid account status.');
    $owner=requireMainPhysician($pdo,$ownerId);
    $pdo->beginTransaction();
    try{
        $clinicLock=$pdo->prepare("SELECT ClinicID FROM Clinic WHERE ClinicID=? AND Status='Active' AND archived=0 FOR UPDATE");$clinicLock->execute([$owner['ClinicID']]);
        if(!$clinicLock->fetchColumn())throw new RuntimeException('This clinic is unavailable.');
        $pdo->prepare('SELECT UserID FROM Users WHERE UserID=? FOR UPDATE')->execute([$ownerId]);
        requireMainPhysician($pdo,$ownerId);
        $s=$pdo->prepare("UPDATE Users SET Status=? WHERE UserID=? AND UserID<>? AND ClinicID=? AND DeletedAt IS NULL AND RoleID=(SELECT RoleID FROM Roles WHERE RoleName='Physician')");$s->execute([$status,$id,$ownerId,$owner['ClinicID']]);
        if(!$s->rowCount())throw new RuntimeException('Sub-account not found or already has that status.');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
