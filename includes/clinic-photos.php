<?php
function clinicCoverPhoto(PDO $pdo, array $clinic): ?string
{
    $cover = basename((string) ($clinic['PhotoUrl'] ?? ''));
    if ($cover !== '' && is_file(__DIR__ . '/../assets/uploads/clinics/' . $cover)) return $cover;
    return clinicPhotos($pdo, $clinic)[0] ?? null;
}

/** Include the legacy cover, then additional uploaded photos in upload order. */
function clinicPhotos(PDO $pdo, array $clinic): array
{
    $names = empty($clinic['PhotoUrl']) ? [] : [basename($clinic['PhotoUrl'])];
    try {
        $stmt = $pdo->prepare('SELECT Filename FROM ClinicPhoto WHERE ClinicID = ? ORDER BY SortOrder, PhotoID');
        $stmt->execute([$clinic['ClinicID']]);
        $names = array_merge($names, $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        error_log('Clinic gallery unavailable: ' . $e->getMessage());
    }
    return array_values(array_filter(array_unique($names), static function ($name) {
        return $name === basename($name) && is_file(__DIR__ . '/../assets/uploads/clinics/' . $name);
    }));
}

/** Persist only filenames already belonging to this clinic. Caller owns transaction. */
function saveClinicPhotoOrder(PDO $pdo, int $clinicId, array $requested): void
{
    $stmt = $pdo->prepare('SELECT ClinicID, PhotoUrl FROM Clinic WHERE ClinicID = ?');
    $stmt->execute([$clinicId]);
    $clinic = $stmt->fetch();
    if (!$clinic) throw new RuntimeException('Clinic not found.');
    $existing = clinicPhotos($pdo, $clinic);
    $requested = array_filter($requested, 'is_string');
    $ordered = array_values(array_unique(array_merge(array_intersect($requested, $existing), $existing)));
    foreach ($ordered as $position => $filename) {
        $find = $pdo->prepare('SELECT PhotoID FROM ClinicPhoto WHERE ClinicID = ? AND Filename = ?');
        $find->execute([$clinicId, $filename]);
        if (!$find->fetchColumn()) {
            $pdo->prepare('INSERT INTO ClinicPhoto (ClinicID, Filename, SortOrder) VALUES (?, ?, ?)')->execute([$clinicId, $filename, $position]);
        } else {
            $pdo->prepare('UPDATE ClinicPhoto SET SortOrder = ? WHERE ClinicID = ? AND Filename = ?')->execute([$position, $clinicId, $filename]);
        }
    }
    $pdo->prepare('UPDATE Clinic SET PhotoUrl = ? WHERE ClinicID = ?')->execute([$ordered[0] ?? null, $clinicId]);
}
