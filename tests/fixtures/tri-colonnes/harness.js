// Exécute le script de tri des colonnes de la page « Écritures de rattachement » (resources/views/exercices/provisions.blade.php)
// dans un DOM minimal, sans navigateur : de quoi vérifier ce qu'il trie et dans quel ordre.
//
// usage : node harness.js <fichier du script>   (exit 0 si tout est « ok »)
// Lancé par RattachementTriColonnesTest, qui en extrait le script de la page.
const fs = require('fs');
const script = fs.readFileSync(process.argv[2], 'utf8');

class El {
  constructor(tag, attrs = {}, text = '') { this.tag = tag; this.attrs = {...attrs}; this.children = []; this.parentElement = null; this.textContent = text; }
  getAttribute(n) { return n in this.attrs ? this.attrs[n] : null; }
  setAttribute(n, v) { this.attrs[n] = v; }
  removeAttribute(n) { delete this.attrs[n]; }
  append(c) { c.parentElement = this; this.children.push(c); return c; }
  appendChild(c) { if (c.parentElement) c.parentElement.children.splice(c.parentElement.children.indexOf(c), 1); return this.append(c); }
  get rows() { return this.children; }
  get cells() { return this.children; }
  get tBodies() { return this.children.filter(c => c.tag === 'tbody'); }
  closest(sel) {
    const [tag, attr] = sel.match(/^(\w+)\[([\w-]+)\]$/).slice(1);
    for (let e = this; e; e = e.parentElement) if (e.tag === tag && attr in e.attrs) return e;
    return null;
  }
}
let handler;
global.document = { addEventListener: (t, h) => { if (t === 'click') handler = h; } };
eval(script);

function tableau(colonnes, lignes) {
  const table = new El('table', { 'data-triable': '' });
  const thead = table.append(new El('thead')); const tr = thead.append(new El('tr'));
  colonnes.forEach(c => tr.append(new El('th', { 'data-tri': '' }, c)));
  const tbody = table.append(new El('tbody'));
  lignes.forEach(l => { const row = tbody.append(new El('tr')); l.forEach(([txt, sort]) => row.append(new El('td', sort === undefined ? {} : { 'data-sort': sort }, txt))); });
  return { table, tbody, ths: tr.children };
}
const ordre = (t, col) => t.tbody.children.map(r => r.children[col].textContent);
let ko = 0;
const verifie = (nom, obtenu, attendu) => { const ok = JSON.stringify(obtenu) === JSON.stringify(attendu); if (!ok) ko++; console.log((ok ? 'ok  ' : 'KO  ') + nom + (ok ? '' : '\n   obtenu  ' + JSON.stringify(obtenu) + '\n   attendu ' + JSON.stringify(attendu))); };

const t = tableau(['Date', 'Libellé', 'Montant'], [
  [['08/04/2026', '2026-04-08'], ['Stage', undefined], ['175,00 €', '175.00']],
  [['01/09/2025', '2025-09-01'], ['équitation', undefined], ['1 000,00 €', '1000.00']],
  [['31/08/2026', '2026-08-31'], ['Atelier 10', undefined], ['20,00 €', '20.00']],
  [['15/01/2026', '2026-01-15'], ['Atelier 2', undefined], ['175,00 €', '175.00']],
]);
const clic = (th) => handler({ target: th });

clic(t.ths[2]);
verifie('montant croissant : 20 < 175 = 175 < 1000 (pas l’ordre du texte), égalité dans l’ordre d’origine', ordre(t, 2), ['20,00 €', '175,00 €', '175,00 €', '1 000,00 €']);
verifie('  les deux 175 gardent leur ordre d’origine (08/04 avant 15/01)', t.tbody.children.slice(1, 3).map(r => r.children[0].textContent), ['08/04/2026', '15/01/2026']);
verifie('  aria-sort ascendant', t.ths[2].getAttribute('aria-sort'), 'ascending');
clic(t.ths[2]);
verifie('montant décroissant', ordre(t, 2), ['1 000,00 €', '175,00 €', '175,00 €', '20,00 €']);
verifie('  aria-sort descendant', t.ths[2].getAttribute('aria-sort'), 'descending');
clic(t.ths[0]);
verifie('date ISO croissante', ordre(t, 0), ['01/09/2025', '15/01/2026', '08/04/2026', '31/08/2026']);
verifie('  aria-sort de l’ancienne colonne retiré', t.ths[2].getAttribute('aria-sort'), null);
clic(t.ths[1]);
verifie('texte à la française, chiffres dans l’ordre naturel, accents et casse ignorés', ordre(t, 1), ['Atelier 2', 'Atelier 10', 'équitation', 'Stage']);
// Des nombres à décimales inégales ne se comparent pas comme du texte : 10,5 vient après 10,25.
const d = tableau(['Valeur'], [[['10,5', '10.5']], [['9', '9']], [['10,25', '10.25']], [['-3', '-3']]]);
clic(d.ths[0]);
verifie('décimales inégales et négatifs comparés comme des nombres', ordre(d, 0), ['-3', '9', '10,25', '10,5']);
clic({ target: new El('td') });
verifie('un clic hors en-tête ne change rien', ordre(t, 1), ['Atelier 2', 'Atelier 10', 'équitation', 'Stage']);
process.exit(ko ? 1 : 0);
