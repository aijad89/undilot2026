// Menjana docs/diagram/aliran-sistem.svg (+ .html untuk render PNG).
// Jalankan: node docs/diagram/aliran-sistem.js
// PNG: chrome --headless --screenshot=docs/aliran-sistem.png --window-size=1900,1010 docs/diagram/aliran-sistem.html
const fs = require('fs');
const path = require('path');

const W = 2180, H = 950;
const LANE_X = 170, TOP = 96, LANE_H = 165;
const lanes = [
  { name: 'Super / HQ', sub: 'admin pusat', fill: '#eef2ff', ink: '#3730a3' },
  { name: 'Admin negeri', sub: 'ikut zon', fill: '#ecfdf5', ink: '#065f46' },
  { name: 'Sistem', sub: 'penjadual + peraturan', fill: '#fff7ed', ink: '#9a3412' },
  { name: 'Peserta', sub: 'usahawan, di telefon', fill: '#fdf2f8', ink: '#9d174d' },
];
const laneY = i => TOP + i * LANE_H;
const colX = c => LANE_X + 30 + c * 205;
const BW = 168, BH = 104;

const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
let out = [];

function box(col, lane, n, title, lines, opts = {}) {
  const x = colX(col) + (opts.dx || 0), y = laneY(lane) + (LANE_H - BH) / 2 + (opts.dy || 0);
  const l = lanes[lane];
  const shape = opts.diamond
    ? `<polygon points="${x + BW / 2},${y - 6} ${x + BW + 6},${y + BH / 2} ${x + BW / 2},${y + BH + 6} ${x - 6},${y + BH / 2}" fill="#fff" stroke="${l.ink}" stroke-width="2"/>`
    : `<rect x="${x}" y="${y}" width="${BW}" height="${BH}" rx="12" fill="#fff" stroke="${l.ink}" stroke-width="2"/>`;
  out.push(shape);
  let ty = y + (opts.diamond ? 38 : 26);
  if (n) {
    out.push(`<circle cx="${x + 4}" cy="${y + 4}" r="14" fill="${l.ink}"/><text x="${x + 4}" y="${y + 9}" class="num">${n}</text>`);
  }
  out.push(`<text x="${x + BW / 2}" y="${ty}" class="t" fill="${l.ink}">${esc(title)}</text>`);
  lines.forEach((s, i) => out.push(`<text x="${x + BW / 2}" y="${ty + 20 + i * 17}" class="s">${esc(s)}</text>`));
  return { x, y, cx: x + BW / 2, cy: y + BH / 2, r: x + BW, b: y + BH };
}

function arrow(d, label, lx, ly, opts = {}) {
  out.push(`<path d="${d}" fill="none" stroke="${opts.color || '#475569'}" stroke-width="2" ${opts.dash ? 'stroke-dasharray="6 5"' : ''} marker-end="url(#ah${opts.color ? 'r' : ''})"/>`);
  if (label) out.push(`<text x="${lx}" y="${ly}" class="lbl" fill="${opts.color || '#334155'}">${esc(label)}</text>`);
}

// Header
out.push(`<text x="40" y="50" class="h1">Aliran Sistem undilot2026</text>`);
out.push(`<text x="40" y="78" class="h2">Cabutan lot acara kraf Kraftangan Malaysia — dari persediaan hingga slip lot peserta</text>`);

// Lanes
lanes.forEach((l, i) => {
  const y = laneY(i);
  out.push(`<rect x="20" y="${y}" width="${W - 40}" height="${LANE_H - 6}" rx="14" fill="${l.fill}"/>`);
  out.push(`<text x="40" y="${y + LANE_H / 2 - 4}" class="lane" fill="${l.ink}">${l.name}</text>`);
  out.push(`<text x="40" y="${y + LANE_H / 2 + 16}" class="lanesub">${l.sub}</text>`);
});

// Boxes
const b1 = box(0, 0, 1, 'Tetapan', ['kod kraf, hierarki,', 'zon, gaya pendedahan,', 'akaun admin']);
const b2 = box(1, 1, 2, 'Cipta syarikat', ['+ akaun peserta', '(tiada daftar sendiri)']);
const b3 = box(1, 0, 3, 'Cipta acara', ['tarikh, tetingkap', 'pendaftaran, banner']);
const b4 = box(2, 1, 4, 'Tambah ke cabutan', ['kod kraf, saiz gerai,', 'nota bayaran', '→ menunggu HQ']);
const b5 = box(3, 0, 5, 'HQ semak', ['sahkan (boleh betulkan', 'kod / saiz) atau', 'pulangkan + nota']);
const b5n = box(3, 2, null, 'Pengesahan pertama', ['hantar AddedToDraw', '(berbaris)']);
const b5p = box(3, 3, null, 'Notifikasi', ['"Anda dalam', 'cabutan ini"']);
const b6 = box(4, 0, 6, 'Pelan lot', ['lokasi, saiz gerai,', 'jana plot, cat kod,', 'set aside (preset)']);
const b7 = box(5, 2, 7, 'PollingGuard', ['7 semakan sebelum', 'cabutan dibuka'], { diamond: true });
const b7b = box(5, 0, null, 'Start Polling', ['butang HQ, atau auto', 'pada polling_start_date']);
const b8 = box(6, 3, 8, 'Sahkan lot', ['log masuk → halaman lot', '→ tanda setuju →', 'Confirm (sekali)']);
const b8s = box(6, 2, null, 'LotAllocator', ['pilih lot ikut kod', 'kraf + saiz gerai', '(dikunci baris)']);
const b9 = box(7, 3, 9, 'Pendedahan', ['split-flap / sampul', '→ butiran lot, QR', 'daftar masuk, slip PDF']);
const b9r = box(7, 2, null, 'Peringatan', ['~30 min sebelum tutup', 'kepada yang belum', 'undi (sekali)']);
const b10 = box(8, 2, 10, 'Tutup cabutan', ['manual, atau auto', 'pada polling_deadline']);
const b11 = box(8, 0, 11, 'Agih baki lot', ['untuk yang belum undi;', 'tak muat → set aside', 'manual, ulang']);

// Ending box at far right
const bEnd = { x: colX(8) + 0, y: 0 };
// Arrows
arrow(`M${b1.r},${b1.cy} L${b3.x - 4},${b3.cy}`);
arrow(`M${b1.cx},${b1.b} C${b1.cx},${b2.cy} ${b1.cx},${b2.cy} ${b2.x - 4},${b2.cy}`, 'akaun admin negeri', b1.cx + 10, b1.b + 34);
arrow(`M${b2.r},${b2.cy} L${b4.x - 4},${b4.cy}`);
arrow(`M${b3.r},${b3.cy - 20} C${b3.r + 20},${b3.cy - 20} ${b4.cx},${b3.cy} ${b4.cx},${b4.y - 4}`);
arrow(`M${b4.r},${b4.cy - 18} C${b4.r + 12},${b4.cy - 18} ${b5.cx - 30},${b5.b + 40} ${b5.cx - 30},${b5.b + 4}`, 'hantar ke HQ', b4.r + 2, b4.cy - 26);
arrow(`M${b5.cx + 30},${b5.b} C${b5.cx + 30},${b5.b + 60} ${b4.r + 60},${b4.cy + 20} ${b4.r + 4},${b4.cy + 20}`, 'pulang + nota', b5.cx - 60, b5.b + 70, { color: '#b91c1c', dash: true });
arrow(`M${b5.cx + 50},${b5.b} L${b5n.cx + 50},${b5n.y - 4}`, '', 0, 0);
arrow(`M${b5n.cx},${b5n.b} L${b5p.cx},${b5p.y - 4}`);
arrow(`M${b5.r},${b5.cy} L${b6.x - 4},${b6.cy}`, 'disahkan', b5.r + 2, b5.cy - 8);
arrow(`M${b6.r},${b6.cy} L${b7b.x - 4},${b7b.cy}`);
arrow(`M${b7b.cx},${b7b.b} L${b7.cx},${b7.y - 10}`);
arrow(`M${b7.x - 8},${b7.cy} C${b7.x - 60},${b7.cy} ${b7.x - 60},${b6.b + 40} ${b6.cx},${b6.b + 4}`, 'tidak lulus → betulkan', b7.x - 182, b7.cy + 28, { color: '#b91c1c', dash: true });
arrow(`M${b7.r + 8},${b7.cy} L${b8s.x - 4},${b8s.cy}`, 'lulus', b7.r + 8, b7.cy - 8);
arrow(`M${b8.cx},${b8.y} L${b8s.cx},${b8s.b + 4}`);
arrow(`M${b8s.r},${b8s.b - 14} C${b8s.r + 10},${b9.y} ${b9.x - 10},${b9.y + 20} ${b9.x - 4},${b9.y + 20}`);
arrow(`M${b9r.x + 20},${b9r.b} L${b8.r - 16},${b8.y - 4}`, "", 0, 0, { dash: true });
arrow(`M${b9r.r},${b9r.cy} L${b10.x - 4},${b10.cy}`);
arrow(`M${b10.cx},${b10.y} L${b11.cx},${b11.b + 4}`);

// "Tamat" pill
const tx = b11.r + 18, ty = b11.cy;
out.push(`<rect x="${tx}" y="${ty - 22}" width="${W - 40 - tx - 10}" height="44" rx="22" fill="#1e3a8a"/>`);
out.push(`<text x="${tx + (W - 40 - tx - 10) / 2}" y="${ty + 6}" class="pill">Tamat</text>`);
arrow(`M${b11.r},${b11.cy} L${tx - 3},${ty}`);

// Status strip
const sy = laneY(4) + 10;
out.push(`<text x="40" y="${sy + 22}" class="lane" fill="#0f172a">polling_status</text>`);
out.push(`<text x="40" y="${sy + 42}" class="lanesub">kitaran cabutan</text>`);
const st = [
  ['draft', 'Draf', '#64748b', 'senarai + pelan disediakan'],
  ['polling', 'Open', '#16a34a', 'peserta sahkan lot'],
  ['closed', 'Closed', '#ea580c', 'agih baki lot'],
  ['completed', 'End', '#2563eb', 'semua ada lot — muktamad'],
];
st.forEach(([k, lab, c, note], i) => {
  const x = LANE_X + 40 + i * 330;
  out.push(`<rect x="${x}" y="${sy}" width="220" height="40" rx="20" fill="${c}"/>`);
  out.push(`<text x="${x + 110}" y="${sy + 26}" class="pill">${lab} · ${k}</text>`);
  out.push(`<text x="${x + 110}" y="${sy + 62}" class="s">${note}</text>`);
  if (i < st.length - 1) arrow(`M${x + 224},${sy + 20} L${x + 326},${sy + 20}`);
});
const cx = LANE_X + 40 + 4 * 330;
out.push(`<rect x="${cx}" y="${sy}" width="220" height="40" rx="20" fill="#dc2626"/>`);
out.push(`<text x="${cx + 110}" y="${sy + 26}" class="pill">Cancelled</text>`);
out.push(`<text x="${cx + 110}" y="${sy + 62}" class="s">boleh kembali ke Draf</text>`);
const cl = LANE_X + 40 + 2 * 330, op = LANE_X + 40 + 330;
arrow(`M${cl + 22},${sy + 38} C${cl + 10},${sy + 105} ${op + 210},${sy + 105} ${op + 200},${sy + 44}`, '', 0, 0, { dash: true });
out.push(`<text x="${op + 275}" y="${sy + 100}" class="lbl" fill="#334155">buka semula</text>`);

// Legend
out.push(`<text x="${W - 40}" y="${H - 14}" class="foot">Sumber: kod undilot2026 @ a152c14 · docs/aliran-sistem.md · 2026-11-16</text>`);

const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}" font-family="Segoe UI, Arial, sans-serif">
<defs>
<marker id="ah" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#475569"/></marker>
<marker id="ahr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="#b91c1c"/></marker>
<style>
.h1{font-size:30px;font-weight:700;fill:#0f172a}
.h2{font-size:16px;fill:#475569}
.lane{font-size:19px;font-weight:700}
.lanesub{font-size:13px;fill:#64748b}
.t{font-size:15px;font-weight:700;text-anchor:middle}
.s{font-size:12.5px;fill:#334155;text-anchor:middle}
.num{font-size:13px;font-weight:700;fill:#fff;text-anchor:middle}
.lbl{font-size:12px;font-style:italic}
.pill{font-size:15px;font-weight:700;fill:#fff;text-anchor:middle}
.foot{font-size:12px;fill:#94a3b8;text-anchor:end}
</style></defs>
<rect width="${W}" height="${H}" fill="#ffffff"/>
${out.join('\n')}
</svg>`;

fs.writeFileSync(path.join(__dirname, 'aliran-sistem.svg'), svg);
fs.writeFileSync(path.join(__dirname, 'aliran-sistem.html'),
  `<!doctype html><html><head><meta charset="utf-8"><style>html,body{margin:0;background:#fff}</style></head><body>${svg}</body></html>`);
console.log('ok');
