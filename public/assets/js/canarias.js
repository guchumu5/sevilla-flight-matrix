(() => {
  'use strict';

  const shell = document.querySelector('#sheetShell');
  const els = {
    title: document.querySelector('#sheetTitle'),
    meta: document.querySelector('#sheetMeta'),
    clock: document.querySelector('#sheetClock'),
    day: document.querySelector('#sheetDay'),
    error: document.querySelector('#sheetError'),
    body: document.querySelector('#sheetBody'),
    print: document.querySelector('#sheetPrint')
  };

  const coincidenceRatings = {
    perfecto: {label: '★ Vuelo perfecto', short: '★ Perfecto'},
    muy_bueno: {label: 'Muy buena coincidencia', short: 'Muy bueno'},
    parcial: {label: 'Coincidencia parcial', short: 'Parcial'},
    bajo: {label: 'Poca coincidencia', short: 'Baja'}
  };

  const state = {
    week: null,
    board: [],
    context: [],
    night: shell?.dataset.night === '1',
    weekScope: shell?.dataset.week === '1',
    origin: String(shell?.dataset.origin || ''),
    date: String(shell?.dataset.date || ''),
    nightFrom: Math.max(18, Math.min(23, Number(shell?.dataset.nightFrom || 22))),
    today: String(shell?.dataset.today || '')
  };

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  }[c]));
  const pad = value => String(value).padStart(2, '0');
  const parseDate = value => value ? new Date(String(value).replace(' ', 'T')) : null;
  const time = value => value ? new Intl.DateTimeFormat('es-ES', {hour: '2-digit', minute: '2-digit'}).format(parseDate(value)) : '—';
  const shiftDate = (value, days) => {
    const date = new Date(`${value}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
  };
  const operationalDateOf = value => {
    const match = String(value || '').match(/^(\d{4}-\d{2}-\d{2})[T\s](\d{2})/);
    if (!match) return null;
    return Number(match[2]) < 2 ? shiftDate(match[1], -1) : match[1];
  };
  const dayName = (value, options = {weekday: 'long', day: 'numeric', month: 'short'}) =>
    new Intl.DateTimeFormat('es-ES', {timeZone: 'UTC', ...options}).format(new Date(`${value}T12:00:00Z`)).replace('.', '');
  const madridNow = () => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23'
    }).formatToParts(new Date()).filter(p => p.type !== 'literal').map(p => [p.type, p.value]));
    const date = `${parts.year}-${parts.month}-${parts.day}`;
    const minutes = Number(parts.hour) * 60 + Number(parts.minute);
    const pivot = new Date(`${date}T12:00:00Z`);
    if (minutes < 120) pivot.setUTCDate(pivot.getUTCDate() - 1);
    return {date, operationalDate: pivot.toISOString().slice(0, 10), minutes, parts};
  };
  const hourOf = value => {
    const match = String(value || '').match(/[T\s](\d{2}):/);
    return match ? Number(match[1]) : null;
  };
  const isNightFlight = flight => {
    const hour = hourOf(flight.expected_arrival || flight.effective_arrival || flight.scheduled_arrival);
    return hour !== null && (hour >= state.nightFrom || hour < 2);
  };
  const nightRangeText = () => `${pad(state.nightFrom)}:00–01:59`;
  const originName = iata => state.week?.origins?.find(item => item.iata === iata)?.name
    || state.board.find(item => item.origin_iata === iata)?.origin_name
    || iata;
  const effectiveArrival = flight => flight.actual_arrival || flight.eta || flight.effective_arrival || flight.scheduled_arrival;
  const arrivalSource = flight => effectiveArrival(flight) || flight.expected_arrival || flight.scheduled_arrival;
  const minuteOfDay = value => {
    const match = String(value || '').match(/(?:T|\s)(\d{2}):(\d{2})/);
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
  };
  const PAST_23_MINUTES = 23 * 60;
  const isPast23Flight = flight => {
    const minutes = minuteOfDay(arrivalSource(flight));
    return minutes !== null && (minutes >= PAST_23_MINUTES || minutes < 120);
  };
  const arrivalOrderKey = flight => {
    const raw = arrivalSource(flight);
    const date = flight.operational_date || operationalDateOf(raw);
    const minutes = minuteOfDay(raw);
    if (!date || minutes === null) return '';
    return `${date}T${String(minutes < 120 ? minutes + 1440 : minutes).padStart(4, '0')}`;
  };
  const isCanaryEntry = item => Number(item?.is_canary) === 1;
  const rankAroundEntries = entries => [...(Array.isArray(entries) ? entries : [])].sort((a, b) =>
    (isCanaryEntry(b) ? 1 : 0) - (isCanaryEntry(a) ? 1 : 0)
    || Math.abs(Number(a.offset_minutes)) - Math.abs(Number(b.offset_minutes))
    || Number(a.offset_minutes) - Number(b.offset_minutes)
    || Number(a.id) - Number(b.id)
  );
  const preferOccupancyShown = (counting, nearby) =>
    counting.find(isCanaryEntry) || nearby.find(isCanaryEntry) || counting[0] || nearby[0];
  const relativeMinuteCompact = value => {
    const minutes = Math.round(Number(value || 0));
    return minutes > 0 ? `+${minutes}` : String(minutes);
  };
  const flightScore10 = flight => {
    const model = flight?.belt_coincidence;
    if (!model) return 1;
    if (model.score_10 != null) return Math.max(1, Math.min(10, Number(model.score_10)));
    const redPart = Number(model.red_count) >= 2 ? 0.70 : Number(model.red_count) === 1 ? 0.35 : 0;
    const greenPart = 0.30 * (Math.min(Math.max(Number(model.green_count) || 0, 0), 3) / 3);
    return Math.max(1, Math.min(10, Math.round(10 * (redPart + greenPart))));
  };

  const laterNightFlights = flight => {
    const date = flight.operational_date || operationalDateOf(arrivalSource(flight) || flight.scheduled_arrival);
    const key = arrivalOrderKey(flight);
    if (!date || !key || !isPast23Flight(flight)) return [];
    return state.context
      .filter(item => {
        const day = item.operational_date || operationalDateOf(arrivalSource(item) || item.scheduled_arrival);
        return day === date && Number(item.id) !== Number(flight.id) && arrivalOrderKey(item) > key;
      })
      .sort((a, b) => arrivalOrderKey(a).localeCompare(arrivalOrderKey(b)));
  };
  const laterNightBelt = flight => {
    if (flight.source === 'aena' && flight.belt) {
      return flight.hall ? `${flight.hall}/${flight.belt}` : `cinta ${flight.belt}`;
    }
    if (flight.predicted_belt) {
      return `${flight.predicted_hall || (Number(flight.predicted_belt) >= 7 ? 'B' : 'A')}/${flight.predicted_belt}`;
    }
    if (flight.official_belt) {
      return `${Number(flight.official_belt) >= 7 ? 'B' : 'A'}/${flight.official_belt}`;
    }
    return '';
  };
  const laterNightMarkup = flight => {
    if (!isPast23Flight(flight)) return '';
    const later = laterNightFlights(flight);
    if (!later.length) {
      return `<p class="later-night is-last"><strong>Último de la noche</strong><span>No hay más llegadas después de este.</span></p>`;
    }
    return `<div class="later-night">
      <strong>${later.length} vuelo${later.length === 1 ? '' : 's'} después</strong>
      <ul>${later.map(item => {
        const belt = laterNightBelt(item);
        return `<li><b>${time(arrivalSource(item))}</b> ${esc(item.physical_flight || '')} · ${esc(item.origin_name || item.origin_iata || '')}${belt ? ` · ${esc(belt)}` : ''}</li>`;
      }).join('')}</ul>
    </div>`;
  };

  function filterLabel() {
    const parts = [];
    if (state.weekScope) {
      const days = Array.isArray(state.week?.days) ? state.week.days : [];
      const first = days[0]?.date;
      const last = days[days.length - 1]?.date;
      parts.push(first && last
        ? `Semana ${dayName(first, {day: 'numeric', month: 'short'})} – ${dayName(last, {day: 'numeric', month: 'short', year: 'numeric'})}`
        : 'Semana');
    } else {
      parts.push(dayName(state.date, {weekday: 'long', day: 'numeric', month: 'long'}));
    }
    parts.push(state.night ? `Solo noche ${nightRangeText()}` : 'Todo el día');
    parts.push(state.origin ? originName(state.origin) : 'Todos los orígenes');
    return parts.join(' · ');
  }

  function canaryPrintBelt(flight, kind) {
    if (kind === 'aena') {
      if (flight.source === 'aena' && flight.belt) return `${flight.hall || '?'}/${flight.belt}`;
      if (flight.official_belt != null && flight.official_belt !== '') {
        return `${Number(flight.official_belt) >= 7 ? 'B' : 'A'}/${flight.official_belt}`;
      }
      return 'pendiente';
    }
    if (flight.predicted_belt) {
      return `${flight.predicted_hall || (Number(flight.predicted_belt) >= 7 ? 'B' : 'A')}/${flight.predicted_belt}`;
    }
    return '—';
  }

  function canaryPrintIsRed(flight) {
    return ['7', '8'].includes(String(flight.belt || flight.official_belt || ''))
      || ['7', '8'].includes(String(flight.predicted_belt || ''));
  }

  function occupancyMarkup(flight) {
    const model = flight.belt_coincidence;
    if (!model) return '<p class="canary-print-around-empty">Todavía no hay ocupación calculada para este vuelo.</p>';
    const green = new Set((model.green_belts || []).map(Number));
    const red = new Set((model.red_belts || []).map(Number));
    const entries = Array.isArray(model.flights) ? model.flights : [];
    const cells = Array.from({length: 8}, (_, index) => {
      const belt = index + 1;
      const flights = rankAroundEntries(entries.filter(item => Number(item.belt) === belt));
      const counting = flights.filter(item => item.counts);
      const nearby = flights.filter(item => !item.counts);
      const active = green.has(belt) || red.has(belt) || counting.length > 0;
      const shown = preferOccupancyShown(counting, nearby);
      const label = active ? `${relativeMinuteCompact(shown?.offset_minutes)}′` : nearby.length ? 'cerca' : 'libre';
      return `<span class="print-belt ${active ? 'occupied' : ''} ${!active && nearby.length ? 'nearby' : ''} ${belt >= 7 ? 'red' : ''} ${shown && isCanaryEntry(shown) ? 'is-canary' : ''}"><b>${belt}</b><small>${esc(label)}</small></span>`;
    }).join('');
    const ratingKey = model.rating || 'bajo';
    const rating = coincidenceRatings[ratingKey] || coincidenceRatings.bajo;
    return `<div class="canary-print-occupancy rating rating-${esc(ratingKey)} coincidence-${esc(ratingKey)} ${red.size ? 'has-red' : ''}">
      <header class="rating"><span>${esc(rating.label)}</span><strong>${green.size}V · ${red.size}R</strong><b>${esc(time(model.target_time))}</b></header>
      <div class="print-belts">${cells}</div>
      <p>Verdes ±${Number(model.green_window_minutes)} min · Rojas ±${Number(model.red_window_minutes)} min · Nube ±${Number(model.span_window_minutes || 30)} min</p>
    </div>`;
  }

  function aroundMarkup(flight) {
    const model = flight.belt_coincidence;
    const entries = Array.isArray(model?.flights) ? model.flights : [];
    if (!entries.length) {
      return `<p class="canary-print-around-empty">Sin vuelos alrededor en ±${Number(model?.span_window_minutes || 30)} min.</p>`;
    }
    const sourceLabel = value => value === 'oficial' ? 'Aena' : value === 'prediccion' ? 'pred.' : (value || '');
    const rows = rankAroundEntries(entries).map(item => {
      const belt = item.belt ? `${Number(item.belt) >= 7 ? 'B' : 'A'}/${item.belt}` : 'sin cinta';
      const role = item.counts ? 'cuenta' : (item.kind === 'unknown' ? 'sin cinta' : 'nube');
      const source = sourceLabel(item.assignment_source);
      const canary = isCanaryEntry(item);
      return `<li class="${item.counts ? 'counts occupied' : 'near nearby'} ${item.kind === 'red' ? 'red' : ''} ${canary ? 'is-canary' : ''}">
        <b>${esc(time(item.arrival))}</b>
        <span>${esc(item.flight)}</span>
        <span>${esc(item.origin)}</span>
        <span>${esc(belt)}</span>
        <em>${esc(relativeMinuteCompact(item.offset_minutes))}′</em>
        <small>${esc(role)}${source ? ` · ${esc(source)}` : ''}</small>
        ${canary ? '<i>Canarias</i>' : ''}
      </li>`;
    }).join('');
    return `<div class="canary-print-around-wrap"><h3>Vuelos de alrededor</h3><ul class="canary-print-around">${rows}</ul></div>`;
  }

  function cardMarkup(flight) {
    const predicted = canaryPrintBelt(flight, 'predicted');
    const official = canaryPrintBelt(flight, 'aena');
    const red = canaryPrintIsRed(flight);
    const night = isNightFlight(flight);
    const model = flight.belt_coincidence;
    const rating = model ? (coincidenceRatings[model.rating] || coincidenceRatings.bajo) : null;
    const note = model ? flightScore10(flight) : null;
    return `<article class="canary-print-card ${red ? 'is-red' : ''} ${night ? 'is-night' : ''} ${model ? `rating rating-${esc(model.rating)} coincidence-${esc(model.rating)}` : ''}">
      <header>
        <div class="canary-print-id">
          <strong>${esc(time(effectiveArrival(flight) || flight.expected_arrival || flight.scheduled_arrival))}</strong>
          <b>${esc(flight.origin_name || flight.origin_iata || '')}</b>
          <span>${esc(flight.physical_flight || '')}</span>
          ${night ? '<em class="night">Noche</em>' : ''}
          ${red ? '<em class="red">7/8</em>' : ''}
        </div>
        <div class="canary-print-assign">
          <span>Predicción <b>${esc(predicted)}</b></span>
          <span>Aena <b>${esc(official)}</b></span>
          ${note != null ? `<span class="score">${note}/10 · ${esc(rating.short)} · ${Number(model.green_count)}V ${Number(model.red_count)}R</span>` : ''}
        </div>
      </header>
      ${flight.scheduled_arrival ? `<p class="canary-print-timing">Previsto ${esc(time(flight.scheduled_arrival))} · Llegada ${esc(time(effectiveArrival(flight) || flight.expected_arrival || flight.scheduled_arrival))}</p>` : ''}
      ${occupancyMarkup(flight)}
      ${aroundMarkup(flight)}
      ${flight.belt_attention ? `<p class="canary-print-alert">${esc(flight.belt_attention_reason || 'Atención de cinta')}</p>` : ''}
      ${laterNightMarkup(flight)}
    </article>`;
  }

  function hydrate(item) {
    const board = state.board.find(flight => Number(flight.id) === Number(item.id));
    if (board) {
      return {
        ...board,
        operational_date: item.operational_date || operationalDateOf(board.scheduled_arrival) || state.date,
        belt_coincidence: board.belt_coincidence || item.belt_coincidence
      };
    }
    const official = item.official_belt ?? item.belt ?? null;
    return {
      ...item,
      expected_arrival: item.expected_arrival || item.effective_arrival || item.scheduled_arrival,
      belt: official,
      hall: item.hall || (official == null ? null : (Number(official) >= 7 ? 'B' : 'A')),
      source: item.source || (official ? 'aena' : null),
      predicted_hall: item.predicted_hall || (Number(item.predicted_belt) >= 7 ? 'B' : 'A'),
      is_canary: 1
    };
  }

  function scopedFlights() {
    const weekFlights = Array.isArray(state.week?.flights) ? state.week.flights : [];
    const matches = flight =>
      (!state.origin || String(flight.origin_iata) === state.origin)
      && (!state.night || isNightFlight(flight));
    if (state.weekScope) {
      return weekFlights.map(hydrate).filter(matches).sort((a, b) =>
        String(a.operational_date || '').localeCompare(String(b.operational_date || ''))
        || String(a.expected_arrival || a.scheduled_arrival).localeCompare(String(b.expected_arrival || b.scheduled_arrival)));
    }
    const fromWeek = weekFlights.filter(item => item.operational_date === state.date);
    const fromBoard = state.board.filter(item => Number(item.is_canary) === 1);
    const byId = new Map();
    [...fromWeek, ...fromBoard].map(hydrate).filter(matches).forEach(flight => byId.set(Number(flight.id), flight));
    return [...byId.values()].sort((a, b) =>
      String(a.expected_arrival || a.scheduled_arrival).localeCompare(String(b.expected_arrival || b.scheduled_arrival)));
  }

  function buildContext() {
    const byId = new Map();
    [...(Array.isArray(state.week?.flights) ? state.week.flights : []), ...state.board].forEach(item => {
      const flight = hydrate(item);
      const id = Number(flight.id);
      if (!id) return;
      const current = byId.get(id);
      byId.set(id, current
        ? {...current, ...flight, operational_date: current.operational_date || flight.operational_date, belt_coincidence: flight.belt_coincidence || current.belt_coincidence}
        : flight);
    });
    state.context = [...byId.values()];
  }

  function render(flights) {
    const printedAt = new Intl.DateTimeFormat('es-ES', {
      timeZone: 'Europe/Madrid', weekday: 'long', day: 'numeric', month: 'long',
      hour: '2-digit', minute: '2-digit'
    }).format(new Date());
    const label = filterLabel();
    document.title = `Canarias · ${label}`;
    if (els.title) els.title.textContent = `Canarias · ${label}`;
    if (els.meta) {
      els.meta.textContent = flights.length
        ? `${flights.length} vuelo${flights.length === 1 ? '' : 's'} · Aena prevalece`
        : 'Sin vuelos con estos filtros';
    }
    if (!flights.length) {
      els.body.innerHTML = `<div class="canary-print">
        <header>
          <p class="canary-print-kicker">Matriz operativa de cintas · Sevilla</p>
          <h1>Canarias · cintas</h1>
          <p class="canary-print-meta">${esc(label)}</p>
        </header>
        <p class="canary-print-empty">No hay vuelos canarios con los filtros actuales.</p>
      </div>`;
      return;
    }
    const groups = [];
    flights.forEach(flight => {
      const date = flight.operational_date || operationalDateOf(flight.scheduled_arrival) || state.date;
      let group = groups.find(item => item.date === date);
      if (!group) {
        group = {date, flights: []};
        groups.push(group);
      }
      group.flights.push(flight);
    });
    groups.sort((a, b) => String(a.date).localeCompare(String(b.date)));
    const sections = groups.map(group => {
      const heading = dayName(group.date, {weekday: 'long', day: 'numeric', month: 'short'});
      return `<section class="canary-print-day">
        <h2>${esc(heading)} <span>${group.flights.length} vuelo${group.flights.length === 1 ? '' : 's'}</span></h2>
        ${group.flights.map(flight => cardMarkup(flight)).join('')}
      </section>`;
    }).join('');
    els.body.innerHTML = `<div class="canary-print">
      <header>
        <p class="canary-print-kicker">Matriz operativa de cintas · Sevilla</p>
        <h1>Canarias · cintas</h1>
        <p class="canary-print-meta">${esc(label)} · ${flights.length} vuelo${flights.length === 1 ? '' : 's'}</p>
        <p class="canary-print-when">Consultado el ${esc(printedAt)}</p>
      </header>
      ${sections}
      <footer>Ocupación y vuelos de alrededor como en las tarjetas. Aena prevalece. Cintas 7 y 8 marcadas. Día operativo 02:00–01:59.</footer>
    </div>`;
  }

  function tickClock() {
    const now = madridNow();
    if (els.clock) els.clock.textContent = `${now.parts.hour}:${now.parts.minute}:${now.parts.second}`;
    if (els.day) {
      const label = dayName(now.operationalDate, {weekday: 'long', day: 'numeric', month: 'short'});
      els.day.textContent = now.operationalDate === state.today ? `Hoy · ${label}` : label;
    }
  }

  async function loadJson(url) {
    const response = await fetch(url, {headers: {Accept: 'application/json'}, cache: 'no-store'});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `Error ${response.status}`);
    return payload;
  }

  async function load() {
    if (els.error) {
      els.error.hidden = true;
      els.error.textContent = '';
    }
    try {
      const from = state.weekScope ? (state.today || madridNow().operationalDate) : state.date;
      const days = state.weekScope ? 7 : 1;
      const boardDate = state.weekScope ? (state.today || from) : state.date;
      const [weekPayload, boardPayload] = await Promise.all([
        loadJson(`api/canary-week.php?from=${encodeURIComponent(from)}&days=${days}`),
        loadJson(`api/board.php?date=${encodeURIComponent(boardDate)}`).catch(() => ({flights: []}))
      ]);
      state.week = weekPayload;
      state.board = Array.isArray(boardPayload?.flights) ? boardPayload.flights : [];
      buildContext();
      render(scopedFlights());
    } catch (error) {
      if (els.error) {
        els.error.hidden = false;
        els.error.textContent = error.message || 'No se pudo cargar Canarias.';
      }
      if (els.body) els.body.innerHTML = '<p class="canary-print-empty">No hay datos para mostrar.</p>';
    }
  }

  tickClock();
  window.setInterval(tickClock, 1000);
  els.print?.addEventListener('click', () => window.print());
  load();
})();
