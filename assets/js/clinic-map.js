(() => {
  const status = document.getElementById('clinicMapStatus');
  if (!status) return;
  if (!window.L) { status.textContent = 'Map unavailable. Clinic addresses are listed below.'; return; }
  const clinics = JSON.parse(document.getElementById('registeredClinicLocations').textContent);
  const map = L.map('registeredClinicMap', { scrollWheelZoom:false }).setView([10.3157, 123.8854], 12);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom:19,
    attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
  }).addTo(map);
  const bounds = [];
  for (const clinic of clinics) {
    if (clinic.latitude === null || clinic.longitude === null) continue;
    const lat = Number(clinic.latitude), lng = Number(clinic.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) continue;
    const popup = document.createElement('div');
    const heading = document.createElement('strong');
    heading.textContent = clinic.name;
    const address = document.createElement('p');
    address.textContent = clinic.address;
    const link = document.createElement('a');
    link.href = `clinic-profile.php?clinic_id=${clinic.id}`;
    link.textContent = 'View clinic';
    popup.append(heading, address, link);
    L.marker([lat, lng]).addTo(map).bindPopup(popup);
    bounds.push([lat, lng]);
  }
  if (bounds.length) map.fitBounds(bounds, {padding:[30,30], maxZoom:15});
  const missing = clinics.length - bounds.length;
  status.textContent = bounds.length
    ? `${bounds.length} registered clinic location${bounds.length === 1 ? '' : 's'}${missing ? ` · ${missing} awaiting map coordinates` : ''}`
    : 'No clinic pins yet. Registered clinics need map coordinates added by an admin.';
})();
