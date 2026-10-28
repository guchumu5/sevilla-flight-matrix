(() => {
  'use strict';

  const DWELL_MS = 30 * 60 * 1000;
  const MAX_OFFSET = 90;
  const BELTS = [1, 2, 3, 4, 5, 6, 7, 8];
  const SOURCE_LABEL = {oficial: 'Aena', provisional: 'provisional', prediccion: 'estimado'};

  const shell = document.querySelector('#liveShell');
  const pollMs = Math.max(5, Number(shell?.dataset.pollSeconds || 15)) * 1000;
  const els = {
    grid: document.querySelector('#liveGrid'),
    clock: document.querySelector('#liveClock'),
    dayRelative: document.querySelector('#liveDayRelative'),
    dayLabel: document.querySelector('#liveDayLabel'),
    status: document.querySelector('#liveStatus'),
    error: document.querySelector('#liveError'),
    viewTime: document.querySelector('#liveViewTime'),
    updated: document.querySelector('#liveUpdated'),
    slider: document.querySelector('#liveSlider'),
    play: document.querySelector('#livePlay'),
    fullscreen: document.querySelector('#liveFullscreen')
  };

  const state = {
    flightsByDate: new Map(),
    fetchedAt: new Map(),
    offsetMin: 0,
    anchorMs: Date.now(),
    frozen: false,
    playing: false,
    loading: false,
    error: '',
    lastOkAt: 0
  };

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[c]));

  const pad = value => String(value).padStart(2, '0');
  const parseDate = value => value ? new Date(String(value).replace(' ', 'T')) : null;

  const madridParts = (date = new Date()) => Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Europe/Madrid',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: 'h23'
    }).formatToParts(date).filter(part => part.type !== 'literal').map(part => [part.type, part.value])
  );

  const calendarDate = parts => `${parts.year}-${parts.month}-${parts.day}`;

  const operationalDateOf = (ms = Date.now()) => {
    const parts = madridParts(new Date(ms));
    const date = calendarDate(parts);
    if (Number(parts.hour) < 2) {
      const pivot = new Date(`${date}T12:00:00Z`);
      pivot.setUTCDate(pivot.getUTCDate() - 1);
      return pivot.toISOString().slice(0, 10);
    }
    return date;
  };

  const shiftDate = (value, days) => {
    const date = new Date(`${value}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
  };

  const dayName = value => new Intl.DateTimeFormat('es-ES', {
    timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'short'
  }).format(new Date(`${value}T12:00:00Z`)).replace('.', '');

  const clockText = (ms = Date.now()) => {
    const parts = madridParts(new Date(ms));
    return `${parts.hour}:${parts.minute}:${parts.second}`;
  };

  const hmText = (ms = Date.now()) => {
    const parts = madridParts(new Date(ms));
    return `${parts.hour}:${parts.minute}`;
  };

  const beltDigit = value => {
    const match = String(value ?? '').trim().match(/(?:^|\D)([1-8])$/);
    return match ? Number(match[1]) : null;
  };

  const resolvedBelt = flight => {
    if (flight.source === 'aena') {
      const official = beltDigit(flight.belt);
      if (official !== null) {
        return {belt: official, hall: flight.hall || (official >= 7 ? 'B' : 'A'), source: 'oficial'};
      }
    }
    const provisional = beltDigit(flight.belt) ?? beltDigit(flight.secondary_belt);
    if (provisional !== null) {
      return {
        belt: provisional,
        hall: flight.hall || flight.secondary_hall || (provisional >= 7 ? 'B' : 'A'),
        source: 'provisional'
      };
    }
    const predicted = beltDigit(flight.predicted_belt);
    if (predicted !== null) {
      return {
        belt: predicted,
        hall: flight.predicted_hall || (predicted >= 7 ? 'B' : 'A'),
        source: 'prediccion'
      };
    }
    return {belt: null, hall: null, source: null};
  };

  const arrivalMs = flight => {
    const parsed = parseDate(flight.expected_arrival || flight.effective_arrival || flight.eta || flight.scheduled_arrival);
    return parsed && Number.isFinite(parsed.getTime()) ? parsed.getTime() : null;
  };

  const occupancyFlights = () => {
    const seen = new Set();
    const items = [];
    for (const flights of state.flightsByDate.values()) {
      for (const flight of flights) {
        const id = Number(flight.id || 0);
        if (id && seen.has(id)) continue;
        if (id) seen.add(id);
        const start = arrivalMs(flight);
        if (start === null) continue;
        const assignment = resolvedBelt(flight);
        if (assignment.belt === null) continue;
        items.push({
          id,
          flight: flight.physical_flight || '',
          origin: flight.origin_name || flight.origin_iata || '',
          iata: flight.origin_iata || '',
          start,
          end: start + DWELL_MS,
          belt: assignment.belt,
          hall: assignment.hall,
          source: assignment.source,
          canary: Number(flight.is_canary) === 1
        });
      }
    }
    items.sort((a, b) => a.start - b.start || a.id - b.id);
    return items;
  };

  const viewMs = () => {
    if (state.offsetMin === 0) return Date.now();
    const base = state.frozen || state.playing ? state.anchorMs : Date.now();
    return base + state.offsetMin * 60_000;
  };

  const occupancyAt = (items, at) => BELTS.map(belt => {
    const occupying = items.filter(item => item.belt === belt && item.start <= at && item.end >= at);
    const upcoming = items.filter(item => item.belt === belt && item.start > at);
    const current = occupying[0] || null;
    const next = occupying[1] || upcoming[0] || null;
    return {belt, hall: belt >= 7 ? 'B' : 'A', current, next, remainingMs: current ? current.end - at : null, untilNextMs: next ? next.start - at : null};
  });

  const formatCountdown = (ms, occupied) => {
    if (occupied) return {text: 'AHORA', long: false};
    if (ms === null || !Number.isFinite(ms)) return {text: 'LIBRE', long: true};
    if (ms <= 0) return {text: 'AHORA', long: false};
    const totalSec = Math.max(0, Math.round(ms / 1000));
    const minutes = Math.floor(totalSec / 60);
    if (minutes >= 60) {
      const hours = Math.floor(minutes / 60);
      const rest = minutes % 60;
      return {text: rest ? `LIBRE ${hours} h ${rest} min` : `LIBRE ${hours} h`, long: true};
    }
    if (minutes >= 15) return {text: `LIBRE ${minutes} min`, long: true};
    return {text: `${pad(minutes)}:${pad(totalSec % 60)}`, long: false};
  };

  const sourceChip = item => {
    if (!item || item.source === 'oficial') return '';
    return `<em class="live-chip">${esc(SOURCE_LABEL[item.source] || item.source)}</em>`;
  };

  const flightBlock = (item, remain) => {
    if (!item) {
      return `<div class="live-flight is-empty"><b>LIBRE</b></div>`;
    }
    const meta = [hmText(item.start), item.iata, remain].filter(Boolean).join(' · ');
    return `<div class="live-flight">
      <b class="${item.canary ? 'live-canary' : ''}">${item.canary ? '🌴 ' : ''}${esc(item.origin)}</b>
      <span>${esc(item.flight)}</span>
      <small>${esc(meta)}</small>
      ${sourceChip(item)}
    </div>`;
  };

  const nextBlock = (snapshot, occupied) => {
    if (!snapshot.next) {
      return occupied
        ? `<div class="live-next"><small>Siguiente</small><b>Sin más vuelos</b></div>`
        : `<div class="live-next"><small>Próxima</small><b>Sin vuelos previstos</b></div>`;
    }
    const when = occupied && snapshot.untilNextMs !== null
      ? `en ${formatWait(snapshot.untilNextMs)}`
      : hmText(snapshot.next.start);
    return `<div class="live-next">
      <small>${occupied ? 'Siguiente' : 'Próxima'}${snapshot.next.canary ? ' · Canarias' : ''}</small>
      <b>${esc(snapshot.next.flight)} · ${esc(snapshot.next.origin)}</b>
      <span>${when}${snapshot.next.source === 'prediccion' ? ' · estimado' : ''}</span>
    </div>`;
  };

  const formatWait = ms => {
    const minutes = Math.max(0, Math.round(ms / 60000));
    if (minutes < 1) return 'menos de 1 min';
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return rest ? `${hours} h ${rest} min` : `${hours} h`;
  };

  const relativeDay = date => {
    const today = operationalDateOf();
    if (date === today) return 'Hoy';
    if (date === shiftDate(today, 1)) return 'Mañana';
    if (date === shiftDate(today, -1)) return 'Ayer';
    return 'Día operativo';
  };

  function render() {
    const at = viewMs();
    const live = state.offsetMin === 0;
    const snapshots = occupancyAt(occupancyFlights(), at);
    const opDate = operationalDateOf(at);

    els.clock.textContent = clockText(Date.now());
    els.dayRelative.textContent = relativeDay(opDate);
    els.dayLabel.textContent = dayName(opDate);
    els.viewTime.textContent = live ? 'Ahora' : `+${state.offsetMin} min · ${hmText(at)}`;
    els.status.textContent = state.error ? 'Sin datos' : live ? 'En vivo' : `+${state.offsetMin} min`;
    els.status.className = `live-status ${state.error ? 'is-error' : live ? 'is-live' : 'is-future'}`;
    if (state.lastOkAt) {
      els.updated.textContent = live
        ? `Actualizado ${clockText(state.lastOkAt)}`
        : `Instantánea a las ${hmText(at)}`;
    }

    els.grid.innerHTML = snapshots.map(snapshot => {
      const occupied = Boolean(snapshot.current);
      const countdown = formatCountdown(occupied ? 0 : snapshot.untilNextMs, occupied);
      const remain = occupied && snapshot.remainingMs !== null
        ? `queda ${formatWait(snapshot.remainingMs)}`
        : '';
      const canary = Boolean(snapshot.current?.canary || (!occupied && snapshot.next?.canary));
      return `<article class="live-belt ${snapshot.belt >= 7 ? 'is-red' : 'is-green'} ${occupied ? 'is-busy' : ''} ${canary ? 'is-canary' : ''}" aria-label="Cinta ${snapshot.belt}">
        <header class="live-belt-head">
          <span class="live-belt-num">${snapshot.belt}</span>
          <span class="live-belt-hall">Sala ${snapshot.hall}</span>
        </header>
        <div class="live-count ${countdown.long ? 'is-free-long' : ''}">${esc(countdown.text)}</div>
        ${occupied ? flightBlock(snapshot.current, remain) : (String(countdown.text).startsWith('LIBRE') ? '' : '<div class="live-flight is-empty"><b>LIBRE</b></div>')}
        ${nextBlock(snapshot, occupied)}
      </article>`;
    }).join('');
  }

  function setError(message) {
    state.error = message || '';
    if (!els.error) return;
    if (!message) {
      els.error.hidden = true;
      els.error.textContent = '';
      return;
    }
    els.error.hidden = false;
    els.error.textContent = message;
  }

  async function fetchBoard(date) {
    const response = await fetch(`api/board.php?date=${encodeURIComponent(date)}`, {
      headers: {Accept: 'application/json'},
      cache: 'no-store'
    });
    const payload = await response.json();
    if (!response.ok) throw new Error(payload.error || `No se pudo leer el tablero ${date}.`);
    state.flightsByDate.set(date, payload.flights || []);
    state.fetchedAt.set(date, Date.now());
    return payload;
  }

  async function load(force = false) {
    if (state.loading) return;
    const at = viewMs();
    const today = operationalDateOf();
    const dates = [...new Set([today, shiftDate(today, 1), operationalDateOf(at), operationalDateOf(at + DWELL_MS)])];
    const stale = dates.filter(date => {
      const fetched = state.fetchedAt.get(date) || 0;
      const ttl = date === operationalDateOf() ? pollMs : 180_000;
      return force || Date.now() - fetched >= ttl;
    });
    if (!stale.length) {
      render();
      return;
    }
    state.loading = true;
    try {
      await Promise.all(stale.map(fetchBoard));
      state.lastOkAt = Date.now();
      setError('');
    } catch (error) {
      if (!state.flightsByDate.size) setError(error.message || 'No se pudo actualizar.');
    } finally {
      state.loading = false;
      render();
    }
  }

  const setOffset = (value, freeze) => {
    state.offsetMin = Math.max(0, Math.min(MAX_OFFSET, Math.round(Number(value) || 0)));
    els.slider.value = String(state.offsetMin);
    if (state.offsetMin === 0) {
      state.frozen = false;
    } else if (freeze && !state.playing) {
      state.anchorMs = Date.now();
      state.frozen = true;
    } else if (!state.playing) {
      state.frozen = false;
    }
    render();
  };

  els.slider.addEventListener('input', () => setOffset(els.slider.value, false));
  els.slider.addEventListener('change', () => {
    setOffset(els.slider.value, true);
    load(false);
  });

  els.play.addEventListener('click', () => {
    state.playing = !state.playing;
    if (state.playing) {
      state.anchorMs = Date.now();
      state.frozen = state.offsetMin !== 0;
    }
    els.play.setAttribute('aria-pressed', state.playing ? 'true' : 'false');
    els.play.textContent = state.playing ? '❚❚' : '▶';
    render();
  });

  els.fullscreen.addEventListener('click', async () => {
    try {
      if (document.fullscreenElement) {
        await document.exitFullscreen();
      } else {
        await document.documentElement.requestFullscreen();
      }
    } catch (_) {}
  });

  document.addEventListener('fullscreenchange', () => {
    const on = Boolean(document.fullscreenElement);
    els.fullscreen.setAttribute('aria-pressed', on ? 'true' : 'false');
    els.fullscreen.textContent = on ? 'Salir' : 'Pantalla';
  });

  setInterval(() => {
    if (state.playing) setOffset(state.offsetMin >= MAX_OFFSET ? 0 : state.offsetMin + 1, false);
    render();
  }, 1000);

  setInterval(() => {
    if (state.offsetMin === 0) load(false);
  }, pollMs);

  load(true);
})();
