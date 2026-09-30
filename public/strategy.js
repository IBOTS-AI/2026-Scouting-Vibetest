(() => {
  const canvas = document.getElementById('strategyCanvas');
  if (!canvas) return;
  const stage = canvas.closest('.drawing-stage');
  const fullScreenButton = stage?.querySelector('.canvas-fullscreen');
  if (fullScreenButton) {
    const expanded = () => document.fullscreenElement === stage || stage.classList.contains('canvas-expanded');
    const sync = () => {
      fullScreenButton.textContent = expanded() ? 'Exit full screen' : 'Full screen';
      fullScreenButton.setAttribute('aria-pressed', String(expanded()));
    };
    fullScreenButton.addEventListener('click', async () => {
      if (document.fullscreenElement === stage) await document.exitFullscreen();
      else if (stage.classList.contains('canvas-expanded')) stage.classList.remove('canvas-expanded');
      else {
        try {
          if (!stage.requestFullscreen) throw new Error('Fullscreen unavailable');
          await stage.requestFullscreen();
        } catch { stage.classList.add('canvas-expanded'); }
      }
      sync();
    });
    document.addEventListener('fullscreenchange', sync);
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && stage.classList.contains('canvas-expanded')) {
        stage.classList.remove('canvas-expanded');
        sync();
      }
    });
  }
  const ctx = canvas.getContext('2d');
  if (!ctx) return;
  let plan;
  try { plan = JSON.parse(canvas.dataset.plan); } catch { return; }
  const paths = Array.from({ length: 3 }, (_, i) => Array.isArray(plan.paths?.[i]) ? plan.paths[i] : []);
  const colors = [...document.querySelectorAll('[data-color]')];
  const teams = [...document.querySelectorAll('[data-partner]')];
  const buttons = [...document.querySelectorAll('.strategy-layer')];
  const input = document.getElementById('strategyPaths');
  let active = 0, stroke = null;
  const color = i => colors[i]?.value || plan.teams?.[i]?.color || '#173e6e';
  function render() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    paths.forEach((layer, i) => {
      ctx.strokeStyle = color(i); ctx.fillStyle = color(i); ctx.lineWidth = 4;
      ctx.lineCap = 'round'; ctx.lineJoin = 'round';
      for (const points of layer) {
        if (!Array.isArray(points) || !points.length) continue;
        ctx.beginPath();
        points.forEach(([x, y], index) => index ? ctx.lineTo(x * canvas.width, y * canvas.height) : ctx.moveTo(x * canvas.width, y * canvas.height));
        ctx.stroke();
        for (const [x, y] of [points[0], points[points.length - 1]]) {
          ctx.beginPath(); ctx.arc(x * canvas.width, y * canvas.height, 5, 0, 2 * Math.PI); ctx.fill();
        }
      }
    });
    if (input) input.value = JSON.stringify(paths);
    buttons.forEach((button, i) => {
      button.classList.toggle('active', i === active);
      button.style.borderLeftColor = color(i);
      button.textContent = teams[i]?.value ? `Team ${teams[i].value}` : `Partner ${i + 1}`;
      button.setAttribute('aria-pressed', String(i === active));
    });
  }
  buttons.forEach((button, i) => button.addEventListener('click', () => { active = i; render(); }));
  colors.forEach(input => input.addEventListener('input', render));
  teams.forEach(input => input.addEventListener('change', render));
  document.getElementById('strategyLoadMatch')?.addEventListener('click', () => {
    const match = document.getElementById('strategyMatch');
    const alliance = document.getElementById('strategyAlliance');
    const status = document.getElementById('strategyMatchStatus');
    if (!match.value) { status.textContent = 'Choose a match first.'; return; }
    const option = match.selectedOptions[0];
    let slots;
    try { slots = JSON.parse(option.dataset.teams); } catch { status.textContent = 'Match teams are unavailable.'; return; }
    const numbers = teams.map((_, i) => String(slots[`${alliance.value}${i + 1}`] || 0));
    if (numbers.some(number => number === '0') || numbers.some((number, i) => ![...teams[i].options].some(option => option.value === number))) {
      status.textContent = 'This alliance has an incomplete schedule. Refresh Official Data in Admin or choose teams manually.';
      return;
    }
    const changed = numbers.some((number, i) => teams[i].value !== number);
    if (changed && paths.some(layer => layer.length) && !window.confirm('Loading different teams will clear the drawn paths. Continue?')) return;
    if (changed) { stroke = null; paths.forEach(layer => { layer.length = 0; }); }
    teams.forEach((team, i) => { team.value = numbers[i]; });
    const name = alliance.value === 'R' ? 'Red' : 'Blue';
    document.getElementById('strategyTitle').value = `${option.textContent} ${name} alliance`;
    status.textContent = `Loaded ${option.textContent} ${name} alliance. You can change any partner below.`;
    render();
  });
  if (input) {
    const point = event => {
      const box = canvas.getBoundingClientRect();
      return [Number(Math.max(0, Math.min(1, (event.clientX - box.left) / box.width)).toFixed(4)), Number(Math.max(0, Math.min(1, (event.clientY - box.top) / box.height)).toFixed(4))];
    };
    canvas.addEventListener('pointerdown', event => {
      if (!teams[active]?.value || teams[active].value === '0' || paths[active].length >= 30) return;
      event.preventDefault();stroke = [point(event)];paths[active].push(stroke);
      canvas.setPointerCapture(event.pointerId);render();
    });
    canvas.addEventListener('pointermove', event => {
      if (!stroke || stroke.length >= 1000) return;
      event.preventDefault();const next = point(event), last = stroke[stroke.length - 1];
      if (Math.abs(next[0] - last[0]) + Math.abs(next[1] - last[1]) >= 0.004) { stroke.push(next); render(); }
    });
    const stop = () => { stroke = null; render(); };
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    document.getElementById('strategyUndo')?.addEventListener('click', () => { paths[active].pop(); render(); });
    document.getElementById('strategyClear')?.addEventListener('click', () => { paths[active].length = 0; render(); });
  }
  render();
})();
