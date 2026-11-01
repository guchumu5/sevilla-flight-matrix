(() => {
  'use strict';

  const digit = value => {
    const match = String(value || '').match(/(\d+)/);
    const number = match ? Number(match[1]) : NaN;
    return Number.isInteger(number) && number >= 1 && number <= 8 ? number : null;
  };
  const isRed = value => {
    const number = typeof value === 'number' ? value : digit(value);
    return number === 7 || number === 8;
  };
  const hall = value => {
    const match = String(value || '').match(/\b([AB])\b/i);
    if (match) return match[1].toUpperCase();
    const number = digit(value);
    if (number == null) return null;
    return number >= 7 ? 'B' : 'A';
  };
  const format = value => {
    const number = digit(value);
    if (number == null) return String(value || '').trim() || '—';
    return `${hall(value) || (number >= 7 ? 'B' : 'A')}/${number}`;
  };
  const isBlockChange = (from, to) => {
    const left = digit(from);
    const right = digit(to);
    if (left != null && right != null) return isRed(left) !== isRed(right);
    const hallFrom = hall(from);
    const hallTo = hall(to);
    return Boolean(hallFrom && hallTo && hallFrom !== hallTo);
  };

  let overlay = null;
  let audio = null;
  let vibrateTimer = null;
  let pulseTimer = null;

  const ensureStyle = () => {
    if (document.getElementById('matrix-belt-alarm-style')) return;
    const style = document.createElement('style');
    style.id = 'matrix-belt-alarm-style';
    style.textContent = `
      .belt-crisis-alarm{position:fixed;inset:0;z-index:20000;display:grid;place-items:center;padding:1.2rem;background:rgba(90,0,12,.92);color:#fff;text-align:center;animation:beltCrisisFlash .7s ease-in-out infinite alternate;}
      .belt-crisis-alarm strong{display:block;font-size:clamp(1.1rem,4vw,1.5rem);letter-spacing:.12em;text-transform:uppercase;}
      .belt-crisis-alarm b{display:block;margin:.45rem 0 .2rem;font-size:clamp(2.4rem,12vw,5.2rem);line-height:1;font-variant-numeric:tabular-nums;}
      .belt-crisis-alarm p{margin:.35rem 0 1rem;font-size:clamp(1rem,3.4vw,1.35rem);font-weight:800;}
      .belt-crisis-alarm button{min-width:min(320px,100%);padding:.85rem 1.1rem;border:0;border-radius:12px;background:#fff;color:#7a0014;font-weight:900;font-size:1.05rem;}
      @keyframes beltCrisisFlash{from{background:rgba(118,0,16,.94)}to{background:rgba(176,0,22,.97)}}
    `;
    document.head.appendChild(style);
  };

  const stopSiren = () => {
    if (pulseTimer) window.clearInterval(pulseTimer);
    pulseTimer = null;
    if (audio) {
      try { audio.oscillator.stop(); } catch (_) {}
      try { audio.ctx.close(); } catch (_) {}
      audio = null;
    }
    if (vibrateTimer) window.clearInterval(vibrateTimer);
    vibrateTimer = null;
    try { navigator.vibrate?.(0); } catch (_) {}
  };

  const startSiren = () => {
    stopSiren();
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      const oscillator = ctx.createOscillator();
      const gain = ctx.createGain();
      oscillator.type = 'square';
      oscillator.frequency.value = 880;
      gain.gain.value = 0.18;
      oscillator.connect(gain);
      gain.connect(ctx.destination);
      oscillator.start();
      ctx.resume?.();
      let high = true;
      pulseTimer = window.setInterval(() => {
        high = !high;
        oscillator.frequency.setValueAtTime(high ? 880 : 494, ctx.currentTime);
      }, 280);
      audio = {ctx, oscillator};
    } catch (_) {}
    const burst = () => {
      try { navigator.vibrate?.([500, 120, 500, 120, 900]); } catch (_) {}
    };
    burst();
    vibrateTimer = window.setInterval(burst, 1600);
  };

  const hide = () => {
    stopSiren();
    overlay?.remove();
    overlay = null;
  };

  const show = ({flight = '', origin = '', from = '', to = '', onOpen = null} = {}) => {
    ensureStyle();
    const fromLabel = format(from);
    const toLabel = format(to);
    if (!overlay) {
      overlay = document.createElement('aside');
      overlay.className = 'belt-crisis-alarm';
      overlay.setAttribute('role', 'alertdialog');
      overlay.setAttribute('aria-live', 'assertive');
      overlay.setAttribute('aria-modal', 'true');
      document.body.appendChild(overlay);
    }
    overlay.innerHTML = `<strong>Alarma de cinta</strong>
      <b>${fromLabel} → ${toLabel}</b>
      <p>${origin ? `${origin} · ` : ''}${flight || 'Vuelo'} · cambio de bloque. Hay que moverse YA.</p>
      <button type="button" data-belt-alarm-ack>Entendido · ir al vuelo</button>`;
    overlay.querySelector('[data-belt-alarm-ack]')?.addEventListener('click', () => {
      const open = onOpen;
      hide();
      if (typeof open === 'function') open();
    }, {once: true});
    startSiren();
  };

  window.MatrixBeltAlarm = {digit, format, isBlockChange, isRed, show, hide};
})();
