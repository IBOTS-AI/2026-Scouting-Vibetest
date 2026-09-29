(() => {
  const colors = ['#cf1836', '#0069b4', '#008b69', '#a140b3', '#de6512', '#3149a8', '#9a7132', '#d2338c', '#087f95', '#657d00', '#7047c7', '#bd4935'];
  const colorFor = index => colors[index] || `hsl(${Math.round(index * 137.508) % 360} 78% 38%)`;
  const parse = (raw) => { try { const value = JSON.parse(raw || '[]'); return Array.isArray(value) ? value : []; } catch { return []; } };
  function draw(canvas, paths) {
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    paths.forEach(({ strokes, color }) => {
      ctx.strokeStyle = color;
      ctx.fillStyle = color;
      ctx.lineWidth = 4;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      for (const stroke of strokes) {
        if (!Array.isArray(stroke) || !stroke.length) continue;
        ctx.beginPath();
        let started = false;
        for (const point of stroke) {
          if (!Array.isArray(point) || point.length !== 2 || !point.every(n => typeof n === 'number' && Number.isFinite(n))) continue;
          const x = point[0] * canvas.width, y = point[1] * canvas.height;
          if (!started) { ctx.moveTo(x, y); started = true; }
          else ctx.lineTo(x, y);
        }
        if (!started) continue;
        ctx.stroke();
        const first = stroke[0], last = stroke[stroke.length - 1];
        for (const point of [first, last]) {
          ctx.beginPath(); ctx.arc(point[0] * canvas.width, point[1] * canvas.height, 5, 0, Math.PI * 2); ctx.fill();
        }
      }
    });
  }
  const canvas = document.getElementById('autoPathCanvas');
  if (canvas) {
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
    const input = document.getElementById('autoPathInput');
    const strokes = parse(canvas.dataset.strokes);
    const redraw = () => draw(canvas, [{ strokes, color: '#182d4e' }]);
    const save = () => { if (input) input.value = JSON.stringify(strokes); redraw(); };
    redraw();
    if (input) {
      let active = null;
      const point = (event) => {
        const box = canvas.getBoundingClientRect();
        return [Number(Math.max(0, Math.min(1, (event.clientX - box.left) / box.width)).toFixed(4)), Number(Math.max(0, Math.min(1, (event.clientY - box.top) / box.height)).toFixed(4))];
      };
      canvas.addEventListener('pointerdown', event => {
        if (strokes.length >= 30) return;
        event.preventDefault();
        active = [point(event)];
        strokes.push(active);
        canvas.setPointerCapture(event.pointerId);
        save();
      });
      canvas.addEventListener('pointermove', event => {
        if (!active || active.length >= 1000) return;
        event.preventDefault();
        const next = point(event), previous = active[active.length - 1];
        if (Math.abs(next[0] - previous[0]) + Math.abs(next[1] - previous[1]) >= 0.004) { active.push(next); save(); }
      });
      const stop = () => { active = null; save(); };
      canvas.addEventListener('pointerup', stop);
      canvas.addEventListener('pointercancel', stop);
      document.getElementById('pathUndo')?.addEventListener('click', () => { strokes.pop(); save(); });
      document.getElementById('pathClear')?.addEventListener('click', () => { strokes.length = 0; save(); });
    }
  }
  for (const slider of document.querySelectorAll('.rating-label input[type="range"]')) {
    const output = document.getElementById(slider.id + 'Value');
    slider.addEventListener('input', () => { if (output) output.value = Number(slider.value).toFixed(1); });
  }
  const combined = document.getElementById('teamPathsCanvas');
  if (combined) {
    const paths = parse(combined.dataset.paths);
    draw(combined, paths.map((path, index) => ({ strokes: path.strokes, color: colorFor(index) })));
    const legend = document.getElementById('pathLegend');
    paths.forEach((path, index) => {
      const item = document.createElement('span');
      const swatch = document.createElement('i');
      swatch.style.backgroundColor = colorFor(index);
      item.append(swatch, document.createTextNode(path.label));
      legend.append(item);
    });
  }
})();
