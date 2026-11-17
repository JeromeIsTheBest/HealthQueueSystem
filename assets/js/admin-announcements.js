document.addEventListener('DOMContentLoaded', function () {
  var input = document.getElementById('aa-photo');
  var name = document.getElementById('aa-photo-name');
  if (input && name) input.addEventListener('change', function () {
    name.textContent = input.files.length ? input.files[0].name : '';
  });
});
