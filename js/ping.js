// Used only by check.php to prove JavaScript files load from this folder.
(function () {
  var r = document.getElementById('jsrow'), i = document.getElementById('jsicon'), t = document.getElementById('jstxt');
  if (r) { r.className = 'r ok'; i.textContent = '✅'; t.textContent = 'JavaScript files load correctly'; }
})();
