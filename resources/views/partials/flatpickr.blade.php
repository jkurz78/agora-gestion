{{-- flatpickr (fr) et son analyseur de saisie, requis par le composant x-date-input. --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/fr.js"></script>
<script>
window.parseFlatpickrDate = function(str) {
    str = (str || '').trim();
    const y = new Date().getFullYear();
    function expandYear(yy) {
        yy = parseInt(yy, 10);
        // Pivot : si > année courante en 2 chiffres → 1900+, sinon 2000+
        return yy > (y % 100) ? 1900 + yy : 2000 + yy;
    }
    function make(d, m, yr) {
        d = parseInt(d, 10); m = parseInt(m, 10) - 1; yr = parseInt(yr, 10);
        const dt = new Date(yr, m, d);
        return (!isNaN(dt) && dt.getDate()===d && dt.getMonth()===m && dt.getFullYear()===yr) ? dt : null;
    }
    if (/^\d{8}$/.test(str)) return make(str.slice(0,2), str.slice(2,4), str.slice(4,8));
    if (/^\d{6}$/.test(str)) return make(str.slice(0,2), str.slice(2,4), expandYear(str.slice(4,6)));
    if (/^\d{4}$/.test(str))  return make(str.slice(0,2), str.slice(2,4), y);
    const p = str.split(/[\/\-\.]/);
    if (p.length === 2) return make(p[0], p[1], y);
    if (p.length === 3 && p[0].length === 4) return make(p[2], p[1], p[0]); // ISO : aaaa-mm-jj
    if (p.length === 3) return make(p[0], p[1], p[2].length <= 2 ? expandYear(p[2]) : p[2]);
    return null;
};
</script>
