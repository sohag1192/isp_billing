// public/assets/js/live_graph.js (Dark + Smooth + Area + 1s polling)
(function(){
  const cfg = window.LIVE_GRAPH || {};
  const clientId   = cfg.clientId;
  const apiUrl     = cfg.apiUrl || '/api/client_live_status.php';
  const interval   = cfg.intervalMs || 1000;   // 1s default
  const MAX_POINTS = cfg.maxPoints || 120;     // ~2 minutes

  const els = {
    canvas:   document.getElementById('liveChart'),
    unit:     document.getElementById('chartUnit'),
    window:   document.getElementById('chartWindow'),
    rxLabel:  document.getElementById('rxLabel'),
    txLabel:  document.getElementById('txLabel'),
    btnPause: document.getElementById('btnPauseChart'),
    btnResume:document.getElementById('btnResumeChart'),
    btnReset: document.getElementById('btnResetChart')
  };

  // error badge
  let errBadge = null;
  function showError(msg){
    console.error('[LiveGraph]', msg);
    if (!errBadge){
      errBadge = document.createElement('div');
      errBadge.className = 'alert alert-danger py-2 px-3 my-2';
      errBadge.style.fontSize = '12px';
      const wrap = els.canvas?.closest('.p-3') || document.body;
      wrap.prepend(errBadge);
    }
    errBadge.textContent = 'Live graph error: ' + (msg || 'Unknown');
  }
  function clearError(){ if (errBadge) errBadge.remove(), errBadge = null; }

  if (!clientId || !els.canvas){ showError('Missing clientId or canvas'); return; }

  const chart = { ctx:null, rx:[], tx:[], paused:false };

  // ==== init
  function init(){
    try{
      chart.ctx = els.canvas.getContext('2d');
      if (!chart.ctx) { showError('Canvas 2D context not available'); return; }
    }catch(e){ showError('Canvas init failed: ' + e.message); return; }

    els.btnPause?.addEventListener('click', ()=>{ chart.paused = true; els.btnPause.disabled = true; els.btnResume.disabled = false; });
    els.btnResume?.addEventListener('click', ()=>{ chart.paused = false; els.btnResume.disabled = true; els.btnPause.disabled = false; });
    els.btnReset?.addEventListener('click', ()=>{ chart.rx = []; chart.tx = []; draw(); });

    window.addEventListener('resize', resize);
    document.addEventListener('visibilitychange', ()=> {
      if (document.hidden) stopPolling(); else { startPolling(); tick(); }
    });

    resize();
    startPolling();
    tick(); // immediate
  }

  // ==== polling
  let timer = null;
  function startPolling(){ if (!timer) timer = setInterval(tick, interval); }
  function stopPolling(){ if (timer) { clearInterval(timer); timer = null; } }

  async function tick(){
    try{
      const res = await fetch(`${apiUrl}?id=${encodeURIComponent(clientId)}`, {cache:'no-store'});
      if (!res.ok){ showError(`API HTTP ${res.status}`); return; }
      const d = await res.json();
      clearError();

      if (els.rxLabel) els.rxLabel.textContent = d.rx_rate || ((d.rx_kbps??0) + ' Kbps');
      if (els.txLabel) els.txLabel.textContent = d.tx_rate || ((d.tx_kbps??0) + ' Kbps');

      push(Number(d.rx_kbps)||0, Number(d.tx_kbps)||0);
      console.debug('[LiveGraph]', {rx_kbps:d.rx_kbps, tx_kbps:d.tx_kbps, iface:d.iface, note:d.note});
    }catch(e){
      showError('Fetch failed: ' + (e.message || e));
    }
  }

  function push(rx, tx){
    if (chart.paused) return;
    chart.rx.push(rx); chart.tx.push(tx);
    if (chart.rx.length > MAX_POINTS) chart.rx.shift();
    if (chart.tx.length > MAX_POINTS) chart.tx.shift();
    draw();
  }

  // ==== layout
  function resize(){
    const rect = els.canvas.getBoundingClientRect();
    if (rect.width === 0){ setTimeout(resize, 100); return; }
    const dpr = window.devicePixelRatio || 1;
    els.canvas.width  = Math.floor(rect.width * dpr);
    const cssH = parseInt(getComputedStyle(els.canvas).height || '320', 10) || 320;
    els.canvas.height = Math.floor(cssH * dpr);
    chart.ctx.setTransform(dpr,0,0,dpr,0,0);
    draw();
  }

  // ==== helpers
  function niceMax(x){
    const x2 = Math.max(1, x);
    const pow10 = Math.pow(10, Math.floor(Math.log10(x2)));
    const n = x2 / pow10;
    let f = 1;
    if (n > 7.5) f = 10;
    else if (n > 5) f = 7.5;
    else if (n > 3) f = 5;
    else if (n > 2) f = 3;
    else if (n > 1) f = 2;
    return f * pow10;
  }
  function autoUnit(kbps){
    if (kbps >= 1000) return { value: +(kbps/1000).toFixed(2), unit:'Mbps' };
    return { value: +kbps.toFixed(1), unit:'Kbps' };
  }

  // Catmull-Rom → Bezier smooth
  function smoothPath(ctx, points){
    if (points.length < 2){ return; }
    const p = points;
    ctx.beginPath();
    ctx.moveTo(p[0].x, p[0].y);
    for (let i=0;i<p.length-1;i++){
      const p0 = p[i-1] || p[i];
      const p1 = p[i];
      const p2 = p[i+1];
      const p3 = p[i+2] || p[i+1];
      const cp1x = p1.x + (p2.x - p0.x) / 6;
      const cp1y = p1.y + (p2.y - p0.y) / 6;
      const cp2x = p2.x - (p3.x - p1.x) / 6;
      const cp2y = p2.y - (p3.y - p1.y) / 6;
      ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, p2.x, p2.y);
    }
  }

  // ==== draw
  function draw(){
    const ctx = chart.ctx; if (!ctx) return;
    const w = els.canvas.clientWidth, h = els.canvas.clientHeight;

    // padding
    const pad = {l:56, r:16, t:16, b:28};
    const cw = w - pad.l - pad.r;
    const ch = h - pad.t - pad.b;

    // bg
    ctx.clearRect(0,0,w,h);
    ctx.fillStyle = '#0b1220'; ctx.fillRect(0,0,w,h);

    const n = Math.max(chart.rx.length, chart.tx.length);
    if (n === 0){
      ctx.fillStyle='#93a4bf'; ctx.font='12px system-ui';
      ctx.fillText('Waiting for data…', pad.l+8, pad.t+16);
      return;
    }

    const maxVal = Math.max(...chart.rx, ...chart.tx, 1);
    const pretty = niceMax(maxVal);
    const yScale = ch / pretty;

    // grid lines
    ctx.strokeStyle='#1e293b'; ctx.lineWidth=1;
    ctx.fillStyle='#94a3b8'; ctx.font='11px system-ui';
    const steps = 5;
    let unitText = 'Kbps';
    for (let i=0;i<=steps;i++){
      const v = (pretty/steps)*i;
      const y = pad.t + ch - v*yScale;
      ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l+cw, y); ctx.stroke();
      const f = autoUnit(v); unitText = f.unit;
      ctx.fillText(`${f.value} ${f.unit}`, 8, y+3);
    }
    // x-axis
    ctx.strokeStyle='#334155';
    ctx.beginPath(); ctx.moveTo(pad.l, pad.t+ch); ctx.lineTo(pad.l+cw, pad.t+ch); ctx.stroke();

    // build points
    function makePoints(arr){
      const m = arr.length;
      const pts = [];
      for (let i=0;i<m;i++){
        const x = pad.l + (m<=1 ? 0 : cw*(i/(m-1)));
        const y = pad.t + ch - (arr[i]*yScale);
        pts.push({x,y});
      }
      return pts;
    }
    const rxPts = makePoints(chart.rx);
    const txPts = makePoints(chart.tx);

    // Gradients
    const gradRx = ctx.createLinearGradient(0, pad.t, 0, pad.t+ch);
    gradRx.addColorStop(0, 'rgba(59,130,246,0.35)');
    gradRx.addColorStop(1, 'rgba(59,130,246,0.02)');
    const gradTx = ctx.createLinearGradient(0, pad.t, 0, pad.t+ch);
    gradTx.addColorStop(0, 'rgba(34,197,94,0.35)');
    gradTx.addColorStop(1, 'rgba(34,197,94,0.02)');

    // RX area
    ctx.save();
    smoothPath(ctx, rxPts);
    ctx.lineTo(pad.l + cw, pad.t+ch);
    ctx.lineTo(pad.l, pad.t+ch);
    ctx.closePath();
    ctx.fillStyle = gradRx; ctx.fill();
    ctx.restore();

    // TX area
    ctx.save();
    smoothPath(ctx, txPts);
    ctx.lineTo(pad.l + cw, pad.t+ch);
    ctx.lineTo(pad.l, pad.t+ch);
    ctx.closePath();
    ctx.fillStyle = gradTx; ctx.fill();
    ctx.restore();

    // RX line
    ctx.lineWidth = 2;
    ctx.strokeStyle = '#3b82f6';
    smoothPath(ctx, rxPts); ctx.stroke();

    // TX line
    ctx.strokeStyle = '#22c55e';
    smoothPath(ctx, txPts); ctx.stroke();

    // latest dot + glow
    function dot(pts, color){
      const p = pts[pts.length-1];
      if (!p) return;
      ctx.save();
      const g = ctx.createRadialGradient(p.x, p.y, 0, p.x, p.y, 18);
      g.addColorStop(0, color.replace('1)', '0.35)'));
      g.addColorStop(1, 'rgba(0,0,0,0)');
      ctx.fillStyle = g; ctx.beginPath(); ctx.arc(p.x, p.y, 18, 0, Math.PI*2); ctx.fill();
      ctx.fillStyle = color; ctx.beginPath(); ctx.arc(p.x, p.y, 3.5, 0, Math.PI*2); ctx.fill();
      ctx.restore();
    }
    dot(rxPts, 'rgba(59,130,246,1)');
    dot(txPts, 'rgba(34,197,94,1)');

    // footer texts
    if (els.unit) els.unit.textContent = `Unit: ${unitText}`;
    if (els.window){
      const seconds = MAX_POINTS * (interval/1000);
      els.window.textContent = seconds >= 60 ? `~${Math.round(seconds/60)} মিনিট` : `~${seconds} সেকেন্ড`;
    }

    // corner ticker (latest)
    const rxNow = chart.rx[chart.rx.length-1] || 0;
    const txNow = chart.tx[chart.tx.length-1] || 0;
    const fr = autoUnit(rxNow), ft = autoUnit(txNow);
    ctx.fillStyle='#e2e8f0'; ctx.font='12px system-ui';
    ctx.fillText(`RX: ${fr.value} ${fr.unit}   TX: ${ft.value} ${ft.unit}`, pad.l+8, pad.t+16);
  }

  document.addEventListener('DOMContentLoaded', init);
})();
