(() => {
  'use strict';

  const digit = value => {
    const match = String(value || '').match(/^\s*(?:[AB]\s*\/\s*)?([1-8])\s*$/i);
    const number = match ? Number(match[1]) : NaN;
    return Number.isInteger(number) && number >= 1 && number <= 8 ? number : null;
  };
  const isRed = value => {
    const number = typeof value === 'number' ? value : digit(value);
    return number === 7 || number === 8;
  };
  const format = value => {
    const number = digit(value);
    if (number == null) return String(value || '').trim() || '—';
    return `${number >= 7 ? 'B' : 'A'}/${number}`;
  };
  const isBlockChange = (from, to) => {
    const left = digit(from);
    const right = digit(to);
    return left != null && right != null && isRed(left) !== isRed(right);
  };

  const validEvent = event => Boolean(event && /^[1-9]\d*$/.test(String(event.id))
    && /^[1-9]\d*$/.test(String(event.flight_id)) && event.source === 'aena'
    && event.event_type === 'belt_changed' && isBlockChange(event.from, event.to)
    && Number(event.expires_at) > Date.now() / 1000);
  const body = event => `${format(event.from)} → ${format(event.to)} · cambio de bloque ${isRed(event.to) ? '1–6 a 7–8' : '7–8 a 1–6'}. Hay que moverse YA.`;
  const seen = new Map();
  const queue = new Map();
  let activeEvent = null;
  const displayNext = () => {
    if (activeEvent) return;
    for (const [id, item] of queue) {
      queue.delete(id);
      const {event, flight, onOpen} = item;
      if (!validEvent(event)) continue;
      const key = `matrix.beltAlarm:${id}`;
      let last = seen.get(key) || '0';
      try { if (BigInt(localStorage.getItem(key) || '0') > BigInt(last)) last = localStorage.getItem(key); } catch (_) {}
      if (BigInt(event.id) <= BigInt(last)) continue;
      activeEvent = event;
      seen.set(key, String(event.id));
      try { localStorage.setItem(key, String(event.id)); } catch (_) {}
      show({flight:flight.physical_flight, origin:flight.origin_name, from:event.from, to:event.to,
        onOpen:() => { if (onOpen) onOpen(flight); displayNext(); }});
      return;
    }
  };
  const process = (flights, onOpen) => {
    for (const flight of flights || []) {
      const id = String(flight.id);
      const event = flight.critical_belt_event;
      const valid = validEvent(event) && String(event.flight_id) === id;
      if (activeEvent?.flight_id === id && (!valid || activeEvent.id !== event.id)) hide();
      if (!valid) { queue.delete(id); continue; }
      queue.set(id, {flight, event, onOpen});
    }
    if (activeEvent && !validEvent(activeEvent)) hide();
    displayNext();
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
    activeEvent = null;
    stopSiren();
    overlay?.remove();
    overlay = null;
  };

  const show = ({flight = '', origin = '', from = '', to = '', onOpen = null} = {}) => {
    if (!isBlockChange(from, to)) return;
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
    overlay.replaceChildren();
    for (const [tag, text] of [['strong', 'Alarma de cinta'], ['b', `${fromLabel} → ${toLabel}`],
      ['p', `${origin ? `${origin} · ` : ''}${flight || 'Vuelo'} · ${body({from, to}).split(' · ')[1]}`]]) {
      const element = document.createElement(tag);
      element.textContent = text;
      overlay.appendChild(element);
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.setAttribute('data-belt-alarm-ack', '');
    button.textContent = 'Entendido · ir al vuelo';
    overlay.appendChild(button);
    overlay.querySelector('[data-belt-alarm-ack]')?.addEventListener('click', () => {
      const open = onOpen;
      hide();
      if (typeof open === 'function') open();
    }, {once: true});
    startSiren();
  };

  globalThis.MatrixBeltAlarm = {digit, format, isBlockChange, isRed, validEvent, body, process, show, hide};
})();
