<?php
/** Clinic coordinates are public metadata. Patient coordinates never reach PHP. */
function clinicMapPoint(array $clinic):?array {
 $lat=$clinic['Latitude']??null;$lng=$clinic['Longitude']??null;
 if($lat===null||$lng===null||trim((string)$lat)===''||trim((string)$lng)===''||!is_numeric($lat)||!is_numeric($lng))return null;
 $lat=(float)$lat;$lng=(float)$lng;if(!is_finite($lat)||!is_finite($lng)||abs($lat)>90||abs($lng)>180)return null;
 return ['lat'=>$lat,'lng'=>$lng];
}
function clinicNearbyAttributes(array $clinic):string {
 $p=clinicMapPoint($clinic);return 'data-nearby-clinic data-clinic-id="'.(int)$clinic['ClinicID'].'" data-lat="'.($p?$p['lat']:'').'" data-lng="'.($p?$p['lng']:'').'"';
}
function clinicDirectionsUrl(array $clinic):string {
 $p=clinicMapPoint($clinic);$destination=$p?$p['lat'].','.$p['lng']:trim(($clinic['ClinicName']??'').' '.($clinic['Address']??''));
 return 'https://www.google.com/maps/dir/?'.http_build_query(['api'=>1,'destination'=>$destination],'','&',PHP_QUERY_RFC3986);
}
function renderClinicLocationControls():void { ?>
<section class="nearby-controls" aria-label="Find nearby clinics"><div class="nearby-intro"><span class="nearby-pin" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span><div><strong>Find nearby clinics</strong><p>Use your location to see approximate distances.</p></div></div><div class="nearby-control-actions"><button type="button" class="btn btn-primary" data-nearby-locate>Use my location</button><button type="button" class="btn btn-outline" data-nearby-clear hidden>Clear location</button></div><p class="nearby-location-status" data-nearby-status role="status" aria-live="polite">Location is optional. You can also search by city or area.</p><small>Distances are straight-line estimates, not driving distances. Your location is not saved to your account.</small></section>
<?php }
