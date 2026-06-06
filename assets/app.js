'use strict';

const $ = (sel) => document.querySelector(sel);

// Studio background is always the same rendering, so the mask fill is fixed to the
// measured background color (matches ffmpeg's decode of the footage -> seamless patch).
const FILL_COLOR = '#316CA4';

// Escape untrusted strings (upstream API fields) before injecting into innerHTML.
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
const state = {
  records: [],          // from list.php
  current: null,        // {filename, view_url}
  boxes: [],            // normalized {x,y,w,h}
  drawing: null,        // in-progress box (display px)
  frameApproved: false,
  videoApproved: false,
  jobs: [],             // last queue snapshot (for date filtering)
  bust: {},             // filename -> cache-bust token for thumbnails
};

function msg(text) { $('#status-msg').textContent = text; }

// ---- Listing ----
async function loadDate() {
  const date = $('#date').value.trim();
  if (!/^\d{8}$/.test(date)) { msg('Date must be YYYYMMDD'); return; }
  msg('Loading…');
  const res = await fetch(`api/list.php?date=${date}`);
  const data = await res.json();
  if (data.error) { msg('Error: ' + data.error); return; }
  state.records = data.records;
  renderList();
  renderBatch();
  msg(`${data.records.length} takes`);
}

function camFilter() { return $('#camera').value; }

// Thumbnails live next to the video: same URL with .mp4 -> .jpg.
// A per-file cache-bust token forces a reload after the file changes (fix/restore).
function thumbUrl(f) {
  const base = (f.view_url || '').replace(/\.mp4$/i, '.jpg');
  const t = state.bust[f.filename];
  return t ? base + '?t=' + t : base;
}

// Shared card pieces ------------------------------------------------
function cardThumb(rec, f, pstatFile) {
  return `<div class="card-thumb">
      <span class="cam-badge">${esc(f.camera)}</span>
      ${f.already_fixed ? '<span class="fixed-badge">fixed</span>' : ''}
      <span class="noimg-fallback">no thumbnail</span>
      <img class="thumb" src="${esc(thumbUrl(f))}" loading="lazy" alt="" onerror="this.classList.add('noimg')">
      ${pstatFile ? `<span class="pstat" data-file="${esc(pstatFile)}"></span>` : ''}
    </div>`;
}
function cardBody(rec, f) {
  const gloss = rec.glos || rec.m_transcription || '';
  return `<div class="card-body">
      <span class="card-id">#${esc(rec.id)}</span>
      ${gloss ? `<span class="card-gloss">${esc(gloss)}</span>` : ''}
      <span class="card-file">${esc(f.filename)}</span>
    </div>`;
}

function renderList() {
  const cam = camFilter();
  const wrap = $('#list');
  wrap.innerHTML = '';
  let n = 0;
  for (const rec of state.records) {
    for (const f of (rec.files || [])) {
      if (!f.local || (cam && f.camera !== cam)) continue;
      const el = document.createElement('div');
      el.className = 'card';
      el.style.animationDelay = Math.min(n * 18, 400) + 'ms';
      el.innerHTML = cardThumb(rec, f) + `<span class="card-cta">Edit ✎</span>` + cardBody(rec, f);
      el.onclick = () => openEditor(f);
      if (f.already_fixed) {
        const rb = document.createElement('button');
        rb.className = 'btn btn-ghost btn-sm restore-btn';
        rb.textContent = '⟲ Restore original';
        rb.onclick = (e) => { e.stopPropagation(); restoreFiles([f.filename], rb); };
        el.querySelector('.card-body').appendChild(rb);
      }
      wrap.appendChild(el);
      n++;
    }
  }
  $('#list-count').textContent = n ? `${n} clips` : '';
  if (!n) wrap.innerHTML = '<p class="muted">No local clips for this date / camera.</p>';
}

// ---- Editor ----
function openEditor(f) {
  state.current = f;
  state.boxes = [];
  state.frameApproved = false;
  state.videoApproved = false;
  $('#edit-pane').hidden = false;
  $('#edit-name').textContent = f.filename;
  $('#preview-out').innerHTML = '';
  const v = $('#video');
  v.src = f.view_url;
  v.onloadedmetadata = sizeCanvas;
  $('#edit-pane').scrollIntoView({ behavior: 'smooth' });
  updateBoxCount();
}

function sizeCanvas() {
  const v = $('#video'), c = $('#canvas');
  c.width = v.clientWidth;
  c.height = v.clientHeight;
  redraw();
}
window.addEventListener('resize', () => { if (!$('#edit-pane').hidden) sizeCanvas(); });

function redraw() {
  const c = $('#canvas'), ctx = c.getContext('2d');
  ctx.clearRect(0, 0, c.width, c.height);
  const color = FILL_COLOR;
  const drawBox = (b) => {
    ctx.fillStyle = color + 'cc';
    ctx.strokeStyle = '#fff';
    ctx.fillRect(b.x * c.width, b.y * c.height, b.w * c.width, b.h * c.height);
    ctx.strokeRect(b.x * c.width, b.y * c.height, b.w * c.width, b.h * c.height);
  };
  state.boxes.forEach(drawBox);
  if (state.drawing) {
    const d = state.drawing;
    ctx.fillStyle = FILL_COLOR + '66';
    ctx.fillRect(d.x, d.y, d.w, d.h);
  }
}

function updateBoxCount() { $('#box-count').textContent = `${state.boxes.length} boxes`; }

// Canvas mouse drawing (display px -> normalized on mouseup)
(function bindCanvas() {
  const c = $('#canvas');
  let start = null;
  // Map a pointer event to canvas coords, clamped to the canvas bounds so dragging
  // past the video edge snaps the box exactly to 0 / width / height (corners are reachable).
  const pos = (e) => {
    const r = c.getBoundingClientRect();
    return {
      x: Math.max(0, Math.min(e.clientX - r.left, c.width)),
      y: Math.max(0, Math.min(e.clientY - r.top, c.height)),
    };
  };
  const onMove = (e) => {
    if (!start) return;
    const p = pos(e);
    state.drawing = { x: Math.min(start.x, p.x), y: Math.min(start.y, p.y),
                      w: Math.abs(p.x - start.x), h: Math.abs(p.y - start.y) };
    redraw();
  };
  const onUp = () => {
    if (!start) return;
    if (state.drawing && state.drawing.w > 4 && state.drawing.h > 4) {
      const d = state.drawing;
      state.boxes.push({ x: d.x / c.width, y: d.y / c.height,
                         w: d.w / c.width, h: d.h / c.height });
      // New geometry invalidates prior approvals.
      state.frameApproved = false; state.videoApproved = false;
    }
    state.drawing = null; start = null;
    updateBoxCount(); redraw();
  };
  c.addEventListener('mousedown', (e) => { start = pos(e); e.preventDefault(); });
  // Listen on the window so the drag keeps tracking (and finishes) even when the
  // cursor leaves the canvas/video area.
  window.addEventListener('mousemove', onMove);
  window.addEventListener('mouseup', onUp);
})();

function reqBody() {
  return JSON.stringify({
    filename: state.current.filename,
    color: FILL_COLOR,
    boxes: state.boxes,
  });
}

async function previewFrame() {
  if (state.boxes.length === 0) { msg('Draw at least one box'); return; }
  msg('Rendering frame…');
  const res = await fetch('api/preview_frame.php',
    { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: reqBody() });
  const data = await res.json();
  if (data.error) { msg('Frame error: ' + (data.detail || data.error)); return; }
  msg('Frame ready — approve to continue');
  $('#preview-out').innerHTML =
    `<p>Preview frame — does this look right?</p><img src="${data.url}?t=${Date.now()}">
     <div class="row-actions"><button id="approve-frame">Approve frame</button></div>`;
  $('#approve-frame').onclick = () => { state.frameApproved = true; msg('Frame approved — now preview the video'); };
}

async function previewVideo() {
  if (!state.frameApproved) { msg('Approve the frame first'); return; }
  msg('Rendering video (may take a few seconds)…');
  const res = await fetch('api/preview_video.php',
    { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: reqBody() });
  const data = await res.json();
  if (data.error) { msg('Video error: ' + (data.detail || data.error)); return; }
  msg('Video ready — approve to enable batch');
  $('#preview-out').innerHTML =
    `<p>Preview video — does this look right?</p>
     <video src="${data.url}?t=${Date.now()}" controls autoplay></video>
     <div class="row-actions"><button id="approve-video">Approve video</button></div>`;
  $('#approve-video').onclick = () => {
    state.videoApproved = true;
    $('#batch-pane').hidden = false;
    renderBatch();
    msg('Approved — select files and process the batch');
    $('#batch-pane').scrollIntoView({ behavior: 'smooth' });
  };
}

// ---- Batch ----
function renderBatch() {
  const cam = camFilter();
  const wrap = $('#batch-list');
  wrap.innerHTML = '';
  let n = 0;
  for (const rec of state.records) {
    for (const f of (rec.files || [])) {
      if (!f.local || (cam && f.camera !== cam)) continue;
      const el = document.createElement('label');
      el.className = 'card bcard';
      el.style.animationDelay = Math.min(n * 18, 400) + 'ms';
      el.innerHTML = `<input type="checkbox" value="${esc(f.filename)}">
        <span class="check">✓</span>
        ${cardThumb(rec, f, f.filename)}
        ${cardBody(rec, f)}`;
      wrap.appendChild(el);
      n++;
    }
  }
}

function selectedFiles() {
  return [...document.querySelectorAll('#batch-list input:checked')].map(c => c.value);
}

async function runBatch() {
  if (!state.videoApproved) { msg('Approve a preview video first'); return; }
  const files = selectedFiles();
  if (files.length === 0) { msg('Select at least one file'); return; }
  if (!confirm(`Process ${files.length} file(s)? Originals are backed up.`)) return;
  msg('Submitting batch…');
  const res = await fetch('api/process.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      date: $('#date').value.trim(), color: FILL_COLOR,
      boxes: state.boxes, filenames: files,
    }),
  });
  const data = await res.json();
  if (data.error) { msg('Batch error: ' + data.error); return; }
  loadJobs();          // surface the new job in the queue immediately
  pollStatus(data.job);
}

async function pollStatus(jobId) {
  const res = await fetch(`api/status.php?job=${jobId}`);
  const data = await res.json();
  if (data.error) { msg('Status error: ' + data.error); return; }
  $('#progress').textContent =
    `Progress: ${data.finished}/${data.total} (done ${data.counts.done}, error ${data.counts.error})`;
  for (const it of data.items) {
    const el = document.querySelector(`.pstat[data-file="${it.filename}"]`);
    if (!el) continue;
    el.textContent = it.status; el.className = 'pstat ' + it.status;
    if (it.status === 'done') markDone(el.closest('.card'), it.filename);
  }
  if (!data.complete) { setTimeout(() => pollStatus(jobId), 1500); }
  else { msg(`Batch complete: ${data.counts.done} done, ${data.counts.error} error`); loadJobs(); }
}

// When a clip finishes, refresh its thumbnail to the masked result and make the
// card open the fixed video on click.
function markDone(card, filename) {
  const f = fileByName(filename);
  if (f) f.already_fixed = true;
  state.bust[filename] = Date.now();          // force thumbnail reload
  if (!card || card.dataset.done) return;
  card.dataset.done = '1';
  card.classList.add('done-card');
  const img = card.querySelector('img.thumb');
  if (img) { img.classList.remove('noimg'); img.src = thumbForFile(filename); }
  card.addEventListener('click', (ev) => { ev.preventDefault(); viewResult(filename); }, true);
}

function fileByName(filename) {
  for (const rec of state.records) for (const f of (rec.files || [])) if (f.filename === filename) return f;
  return null;
}
function thumbForFile(filename) { const f = fileByName(filename); return f ? thumbUrl(f) : ''; }

// ---- Restore originals ----
function selectedFixedFiles() {
  const sel = new Set(selectedFiles());
  const out = [];
  for (const rec of state.records) for (const f of (rec.files || []))
    if (sel.has(f.filename) && f.already_fixed) out.push(f.filename);
  return out;
}

async function restoreFiles(filenames, btnEl) {
  if (!filenames.length) { msg('No fixed files selected to restore'); return; }
  if (!confirm(`Restore ${filenames.length} file(s) to original? This removes the fix and its backup.`)) return;
  const label = btnEl ? btnEl.textContent : '';
  const reset = () => { if (btnEl) { btnEl.disabled = false; btnEl.textContent = label; } };
  if (btnEl) { btnEl.disabled = true; btnEl.textContent = 'Restoring…'; }
  msg('Restoring…');
  let data;
  try {
    const res = await fetch('api/restore.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ filenames }),
    });
    data = await res.json();
  } catch (e) { msg('Restore failed (network)'); reset(); return; }
  if (data.error) { msg('Restore error: ' + data.error); reset(); return; }

  let ok = 0, fail = 0;
  for (const n of filenames) {
    const r = data.results[n];
    if (r && r.ok) { ok++; const f = fileByName(n); if (f) f.already_fixed = false; state.bust[n] = Date.now(); }
    else fail++;
  }
  msg(`Restored ${ok}${fail ? `, ${fail} failed` : ''}`);
  renderList(); renderBatch();   // re-render with refreshed thumbs + cleared fixed state
}

// ---- Result modal ----
function openModal(title, bodyHtml) {
  $('#modal-title').textContent = title;
  $('#modal-body').innerHTML = bodyHtml;
  $('#modal').hidden = false;
}
function closeModal() { $('#modal').hidden = true; $('#modal-body').innerHTML = ''; }

function viewResult(filename) {
  const f = fileByName(filename);
  if (!f || !f.view_url) return;
  const bust = f.view_url + '?t=' + Date.now();   // bypass cache -> show the fixed file
  openModal(filename + ' — fixed', `<video src="${esc(bust)}" controls autoplay loop></video>`);
}

// ---- Date dropdown ----
async function loadDates() {
  const sel = $('#date');
  try {
    const res = await fetch('api/dates.php');
    const data = await res.json();
    const dates = data.dates || [];
    if (dates.length === 0) { sel.innerHTML = '<option value="">No dates available</option>'; return; }
    sel.innerHTML = dates.map(d => `<option value="${esc(d)}">${esc(d)}</option>`).join('');
    msg(`${dates.length} dates available`);
  } catch (e) {
    sel.innerHTML = '<option value="">Failed to load dates</option>';
    msg('Could not load date list');
  }
}

// ---- Queue / history (persists across logins via jobs/*.json) ----
async function loadJobs() {
  try {
    const res = await fetch('api/jobs.php');
    const data = await res.json();
    state.jobs = data.jobs || [];
    renderJobs();
    // Keep refreshing while any job is still running.
    clearTimeout(loadJobs._t);
    if (state.jobs.some(j => !j.complete)) loadJobs._t = setTimeout(loadJobs, 1500);
  } catch (e) { /* leave previous render in place */ }
}

// Render the queue, filtered to the currently selected date.
function renderJobs() {
  const wrap = $('#queue');
  const date = $('#date').value;
  const jobs = date ? state.jobs.filter(j => j.date === date) : state.jobs;
  if (!jobs.length) {
    wrap.innerHTML = `<p class="muted">No batches${date ? ' for ' + esc(date) : ''} yet.</p>`;
    return;
  }
  wrap.innerHTML = jobs.map(j => {
    const when = String(j.created || '').replace('T', ' ').slice(0, 19);
    const cls = !j.complete ? 'job-active' : (j.counts.error ? 'job-err' : 'job-done');
    const label = !j.complete ? 'processing…'
                : (j.counts.error ? `done, ${j.counts.error} error(s)` : 'done');
    return `<div class="job ${cls}">
      <span class="job-date">${esc(j.date)}</span>
      <span class="job-when">${esc(when)}</span>
      <span class="job-prog">${j.finished}/${j.total}</span>
      <span class="job-counts">✓${j.counts.done} ✗${j.counts.error}</span>
      <span class="job-state">${esc(label)}</span>
    </div>`;
  }).join('');
}

// ---- Wire up ----
$('#load').onclick = loadDate;
$('#refresh-queue').onclick = loadJobs;
$('#date').onchange = renderJobs;   // queue follows the selected date
$('#camera').onchange = () => { renderList(); renderBatch(); };
$('#restore-batch').onclick = function () { restoreFiles(selectedFixedFiles(), this); };
$('#clear-boxes').onclick = () => { state.boxes = []; state.frameApproved = false; state.videoApproved = false; updateBoxCount(); redraw(); };
$('#preview-frame').onclick = previewFrame;
$('#preview-video').onclick = previewVideo;
$('#select-cam').onclick = () => document.querySelectorAll('#batch-list input').forEach(c => c.checked = true);
$('#run-batch').onclick = runBatch;
$('#modal-close').onclick = closeModal;
$('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal') closeModal(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

// Populate the date dropdown and queue/history on startup.
loadDates();
loadJobs();
