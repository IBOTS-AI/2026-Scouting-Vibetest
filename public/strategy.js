(() => {
  const canvas = document.getElementById('strategyCanvas');
  if (!canvas) return;
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
  if (input) {
    const point = event => {
      const box = canvas.getBoundingClientRect();
      return [Number(Math.max(0, Math.min(1, (event.clientX - box.left) / box.width)).toFixed(4)), Number(Math.max(0, Math.min(1, (event.clientY - box.top) / box.height)).toFixed(4))];
    };
    canvas.addEventListener('pointerdown', event => {
      if (!teams[active]?.value || paths[active].length >= 30) return;
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
