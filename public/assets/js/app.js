(() => {
  'use strict';

  const SVQ = {lat: 37.4179, lon: -5.8931};
  const RWY = {
    west:{lat:37.41788,lon:-5.912075,label:'09'},
    east:{lat:37.418008,lon:-5.874097,label:'27'}
  };
  const state = {
    flights: [], loading: false, autoScrolledDate: null,
    scenePositions: new Map(), sceneRemovalTimers: new Map(), alertDate: null,
    map: null, mapLayer: null, aircraftLayer: null, mapHasFitted: false,
    canaryAlertQueue: [], activeCanaryAlert: null, canaryAlertTimer: null,
    canaryWatchEnabled: false, pushLastError: '', pushLastAt: null, pushCheckNote: '', pushPending: 0, pushWatches: 0, wakeLock: null,
    watchedFlightIds: [], watchedFlightsBrief: [], watchTickAt: 0, queuedReload: false,
    canaryWeek: null, canaryOrigin: localStorage.getItem('matrix.canaryOrigin') || '',
    canaryNight: localStorage.getItem('matrix.canaryNight') === '1',
    canaryWeekScope: localStorage.getItem('matrix.canaryWeekScope') === '1',
    canaryScrollDate: '',
    nightWeek: null, nightContext: [], nightSort: localStorage.getItem('matrix.nightSort') || 'best',
    nightOrigin: localStorage.getItem('matrix.nightOrigin') || '',
    nightDate: '',
    pendingFlightId: 0, pendingFlightDate: '',
    detailFlightId: null, flightAwarePollTimer: null,
    focusStatus: null, focusNotice: '', redListOpen: false, pinnedFlightId: Number(localStorage.getItem('matrix.focusFlightId') || 0)
  };
  const appMain = document.querySelector('main');
  const CANARY_ALERT_DEFAULT_MS = 60_000;
  const CANARY_ALERT_QUEUED_MS = 30_000;
  const deepLink = new URLSearchParams(window.location.search);
  const els = {
    date: document.querySelector('#flightDate'), hall: document.querySelector('#hallFilter'),
    search: document.querySelector('#searchInput'), canary: document.querySelector('#canaryOnly'),
    secondary: document.querySelector('#secondaryOnly'), refresh: document.querySelector('#refreshButton'),
    now: document.querySelector('#nowButton'), fitMap: document.querySelector('#fitMapButton'),
    body: document.querySelector('#flightsBody'), table: document.querySelector('#tableWrap'),
    loading: document.querySelector('#loadingState'), empty: document.querySelector('#emptyState'),
    error: document.querySelector('#errorAlert'), updated: document.querySelector('#lastUpdated'), dailyBeltAlert:document.querySelector('#dailyBeltAlert'),
    connection: document.querySelector('#connectionBadge'), canaryWatch: document.querySelector('#canaryWatch'),
    enableNotifications: document.querySelector('#enableNotifications'), printCanaryWeek: document.querySelector('#printCanaryWeek'), canaryAlertStack: document.querySelector('#canaryAlertStack'),
    canaryPrintSheet: document.querySelector('#canaryPrintSheet'),
    upcomingStrip: document.querySelector('#upcomingStrip'),
    mapStatus: document.querySelector('#mapStatus'), airportFlow: document.querySelector('#airportFlow'),
    airportScene: document.querySelector('#airportScene'), sceneAircraft: document.querySelector('#sceneAircraft'),
    sceneTrails: document.querySelector('#sceneTrails'), sceneClock: document.querySelector('#sceneClock'),
    sceneMovementCount: document.querySelector('#sceneMovementCount'), sceneBelts: document.querySelector('#sceneBelts'),
    beltChangesSection: document.querySelector('#beltChangesSection'), beltChangesStrip: document.querySelector('#beltChangesStrip'),
    beltChangesCount: document.querySelector('#beltChangesCount'),
    radarToggleLabel: document.querySelector('#radarToggleLabel'), radarCollapse: document.querySelector('#radarCollapse'),
    arrivalsToggleLabel: document.querySelector('#arrivalsToggleLabel'), arrivalsCollapse: document.querySelector('#arrivalsCollapse'),
    metricFlights: document.querySelector('#metricFlights'), metricOrange: document.querySelector('#metricOrange'),
    metricRed: document.querySelector('#metricRed'), metricHall: document.querySelector('#metricHall'),
    predictionSummary: document.querySelector('#predictionSummary'), predictionAccuracy: document.querySelector('#predictionAccuracy'),
    predictionExactAccuracy: document.querySelector('#predictionExactAccuracy'),
    predictionCanaryAccuracy: document.querySelector('#predictionCanaryAccuracy'), predictionEvaluated: document.querySelector('#predictionEvaluated'),
    trackedFocusPanel: document.querySelector('#trackedFocusPanel'), trackedFocusBody: document.querySelector('#trackedFocusBody'),
    trackedFocusTabs: document.querySelector('#trackedFocusTabs'),
    mobileBeltWatchLabel: document.querySelector('#mobileBeltWatchLabel'),
    trackedFocusTitle: document.querySelector('#trackedFocusTitle'), trackedFocusActions: document.querySelector('#trackedFocusActions'),
    trackedFocusNav: document.querySelector('#trackedFocusNav'), focusPrev: document.querySelector('#focusPrev'),
    focusNext: document.querySelector('#focusNext'), focusCounter: document.querySelector('#focusCounter'),
    navDate: document.querySelector('#navDate'), dateLabel: document.querySelector('#dateLabel'),
    dateRelative: document.querySelector('#dateRelative'), datePrev: document.querySelector('#datePrev'),
    dateNext: document.querySelector('#dateNext'), dateToday: document.querySelector('#dateToday'),
    canaryWeek: document.querySelector('#canaryWeek'), canaryOrigins: document.querySelector('#canaryOrigins'),
    pushStatusLine: document.querySelector('#pushStatusLine'),
    testPushNotification: document.querySelector('#testPushNotification'),
    drillPushNotification: document.querySelector('#drillPushNotification'),
    dailyBeltModalBody: document.querySelector('#dailyBeltModalBody'),
    detailTitle: document.querySelector('#flightDetailLabel'), detailBody: document.querySelector('#detailBody'),
    greenWindowInput: document.querySelector('#greenWindowInput'), redWindowInput: document.querySelector('#redWindowInput'),
    greenWindowRange: document.querySelector('#greenWindowRange'), redWindowRange: document.querySelector('#redWindowRange'),
    spanWindowInput: document.querySelector('#spanWindowInput'), spanWindowRange: document.querySelector('#spanWindowRange'),
    resetCoincidenceWindows: document.querySelector('#resetCoincidenceWindows'),
    nightFromInput: document.querySelector('#nightFromInput'), nightFromRange: document.querySelector('#nightFromRange'),
    nightDays: document.querySelector('#nightDays'), nightOrigins: document.querySelector('#nightOrigins'),
    nightWeekStrip: document.querySelector('#nightWeekStrip'), nightWeekToggleLabel: document.querySelector('#nightWeekToggleLabel'),
    mobileNightLabel: document.querySelector('#mobileNightLabel'), nightWeekCollapse: document.querySelector('#nightWeekCollapse')
  };
  if (/^\d{4}-\d{2}-\d{2}$/.test(deepLink.get('date') || '')) els.date.value = deepLink.get('date');

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const parseDate = value => value instanceof Date ? value : value ? new Date(String(value).replace(' ', 'T')) : null;
  const time = value => value ? new Intl.DateTimeFormat('es-ES',{hour:'2-digit',minute:'2-digit'}).format(parseDate(value)) : '—';
  const dateTime = value => value ? new Intl.DateTimeFormat('es-ES',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}).format(parseDate(value)) : '—';
  const isIsoDate = value => /^\d{4}-\d{2}-\d{2}$/.test(String(value || ''));
  const shiftDate = (value, days) => {
    const date = new Date(`${value}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
  };
  const dayDifference = (from, to) => Math.round((Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) / 86_400_000);
  const operationalDateOf = value => {
    const match = String(value || '').match(/^(\d{4}-\d{2}-\d{2})[T\s](\d{2})/);
    if (!match) return null;
    return Number(match[2]) < 2 ? shiftDate(match[1], -1) : match[1];
  };
  const dayName = (value, options = {weekday:'long',day:'numeric',month:'short'}) =>
    new Intl.DateTimeFormat('es-ES', {timeZone:'UTC', ...options}).format(new Date(`${value}T12:00:00Z`)).replace('.', '');
  const relativeDayLabel = value => {
    const diff = dayDifference(madridNow().operationalDate, value);
    if (diff === 0) return 'Hoy';
    if (diff === 1) return 'Mañana';
    if (diff === -1) return 'Ayer';
    return diff > 0 ? `Dentro de ${diff} días` : `Hace ${-diff} días`;
  };
  const updateDateLabels = () => {
    const value = els.date?.value;
    if (!isIsoDate(value)) return;
    const today = madridNow().operationalDate;
    if (els.dateLabel) els.dateLabel.textContent = dayName(value);
    if (els.dateRelative) els.dateRelative.textContent = relativeDayLabel(value);
    els.navDate?.classList.toggle('is-other-day', value !== today);
    els.navDate?.classList.toggle('is-future-day', value > today);
    els.dateToday?.classList.toggle('d-none', value === today);
    document.title = `${value === today ? 'Hoy' : dayName(value, {weekday:'short',day:'numeric',month:'short'})} · Matriz de Cintas · Sevilla`;
  };
  function setBoardDate(value) {
    if (!isIsoDate(value)) return;
    if (els.date.value !== value) els.date.value = value;
    const url = new URL(window.location.href);
    url.searchParams.set('date', value);
    url.searchParams.delete('flight');
    window.history.replaceState(null, '', url);
    updateDateLabels();
    state.autoScrolledDate = null;
    state.mapHasFitted = false;
    renderCanaryWeek();
    loadBoard(true);
  }
  const signed = value => Number(value) > 0 ? `+${value} min` : `${value} min`;
  const timingMarkup = (flight, className = 'flight-timing') => {
    const deviation = Number(flight.deviation_minutes || 0);
    const effective = effectiveArrival(flight);
    const actual = Boolean(flight.actual_arrival);
    const deviationClass = deviation > 0 ? 'late' : deviation < 0 ? 'early' : 'ontime';
    return '<div class="' + esc(className) + '">' +
      '<span><small>Previsto</small><b>' + time(flight.scheduled_arrival) + '</b></span>' +
      '<span><small>' + (actual ? 'Llegó' : 'Llegará') + '</small><b>' + time(effective) + '</b>' +
      '<em class="' + deviationClass + '">' + signed(deviation) + '</em></span></div>';
  };
  const pressureText = p => ({baja:'Sin presión',media:'Presión media',alta:'Presión alta',muy_alta:'Presión muy alta'}[p] || p);
  const hallLoadText = p => ({baja:'Baja',media:'Media',alta:'Alta'}[p] || p);
  const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
  const leadText = value => {
    if (value === null || value === undefined || value === '') return 'sin histórico';
    const minutes = Math.round(Number(value));
    const sign = minutes < 0 ? '−' : '';
    const absolute = Math.abs(minutes);
    const hours = Math.floor(absolute / 60);
    const remainder = absolute % 60;
    if (!hours) return `${sign}${remainder} min`;
    return `${sign}${absolute} min · ${sign}${hours} h${remainder ? ` ${remainder} min` : ''}`;
  };
  const effectiveArrival = f => f.actual_arrival || f.eta || f.effective_arrival || f.scheduled_arrival;
  const codeshareCodes = flight => [...new Set(String(flight?.codes || '')
    .split(/\s*\/\s*|\s*,\s*/)
    .map(code => code.trim().toUpperCase())
    .filter(code => code && code !== String(flight?.physical_flight || '').trim().toUpperCase()))];
  const minuteOfDay = value => {
    const match = String(value || '').match(/(?:T|\s)(\d{2}):(\d{2})/);
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
  };
  const madridNow = () => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
      timeZone:'Europe/Madrid', year:'numeric', month:'2-digit', day:'2-digit',
      hour:'2-digit', minute:'2-digit', hourCycle:'h23'
    }).formatToParts(new Date()).filter(p => p.type !== 'literal').map(p => [p.type, p.value]));
    const date = `${parts.year}-${parts.month}-${parts.day}`;
    const minutes = Number(parts.hour) * 60 + Number(parts.minute);
    const pivot = new Date(`${date}T12:00:00Z`);
    if (minutes < 120) pivot.setUTCDate(pivot.getUTCDate() - 1);
    return {date, operationalDate:pivot.toISOString().slice(0,10), minutes, nowMs:Date.now()};
  };
  const telemetryAgeMinutes = f => {
    const observed = parseDate(f.telemetry_observed_at);
    return observed ? Math.max(0, Math.round((Date.now() - observed.getTime()) / 60000)) : null;
  };
  const telemetrySource = f => String(f.telemetry_source || 'opensky').toLowerCase() === 'airlabs' ? 'AirLabs' : 'OpenSky';
  const isFiniteNumber = value => value !== null && value !== '' && Number.isFinite(Number(value));
  const speedKmh = f => isFiniteNumber(f.ground_speed_ms) ? Math.round(Number(f.ground_speed_ms) * 3.6) : null;
  const speedKt = f => isFiniteNumber(f.ground_speed_ms) ? Math.round(Number(f.ground_speed_ms) * 1.94384) : null;
  const altitudeFt = f => isFiniteNumber(f.altitude_m) ? Math.round(Number(f.altitude_m) * 3.28084 / 100) * 100 : null;
  const verticalTrend = f => {
    if (!isFiniteNumber(f.vertical_rate_ms)) return {label:'Tendencia desconocida',className:'unknown'};
    const rate = Number(f.vertical_rate_ms);
    if (rate > .75) return {label:`Ascendiendo · +${rate.toLocaleString('es-ES',{maximumFractionDigits:1})} m/s`,className:'climbing'};
    if (rate < -.75) return {label:`Descendiendo · ${rate.toLocaleString('es-ES',{maximumFractionDigits:1})} m/s`,className:'descending'};
    return {label:'Nivelado',className:'level'};
  };
  const beltPosition = item => {
    if (item?.belt) return item.hall ? `${esc(item.hall)}/${esc(item.belt)}` : `cinta ${esc(item.belt)} · sala no informada`;
    return item?.hall ? `${esc(item.hall)}/cinta pendiente` : 'sin cinta';
  };
  const secondaryBeltMarkup = flight => {
    if (!flight.secondary_belt || !flight.secondary_belt_state) return '';
    const source = esc((flight.secondary_belt_source || 'secundaria').toUpperCase());
    const position = beltPosition({hall:flight.secondary_hall,belt:flight.secondary_belt});
    const labels = {
      candidate: `propuesta posterior · ${dateTime(flight.secondary_belt_at)}`,
      confirmed: 'coincide con Aena',
      not_confirmed: 'Aena actualizó después sin confirmarla'
    };
    return `<span class="secondary-belt secondary-belt-${esc(flight.secondary_belt_state)}" title="${esc(labels[flight.secondary_belt_state] || '')}">
      <span class="secondary-dot" aria-hidden="true"></span>${source}: ${position}
    </span>`;
  };
  const aircraftSilhouette = flight => `<svg class="aircraft-silhouette" viewBox="0 0 180 90" role="img" aria-label="Silueta de ${esc(flight.aircraft_type || 'aeronave')}">
    <defs><linearGradient id="planeGradient" x1="0" x2="1"><stop stop-color="#52a8ff"/><stop offset="1" stop-color="#2fd38a"/></linearGradient></defs>
    <path fill="url(#planeGradient)" d="M89 7c5 0 8 4 8 9v20l62 25v9L97 57v16l17 10v6L90 84 66 89v-6l17-10V57L21 70v-9l62-25V16c0-5 2-9 6-9z"/>
  </svg>`;

  const timelineKey = item => [item.source,item.status,item.eta,item.actual_departure,item.actual_arrival,item.hall,item.belt,item.gate,item.stand,item.baggage_state]
    .map(value => value ?? '').join('|');
  const compactHistory = history => {
    const groups = [];
    const lastGroupBySource = new Map();
    history.forEach(item => {
      const source = String(item.source || 'unknown').toLowerCase();
      const key = timelineKey(item);
      const previousIndex = lastGroupBySource.get(source);
      const previous = previousIndex === undefined ? null : groups[previousIndex];
      if (previous && previous._timelineKey === key) {
        previous.repeat_count += 1;
        previous.last_observed_at = item.observed_at;
        return;
      }
      groups.push({...item, repeat_count:1, last_observed_at:item.observed_at, _timelineKey:key});
      lastGroupBySource.set(source, groups.length - 1);
    });
    return groups;
  };
  const timelinePeriod = item => item.repeat_count > 1
    ? `${dateTime(item.observed_at)}–${item.observed_at?.slice(0,10)===item.last_observed_at?.slice(0,10)?time(item.last_observed_at):dateTime(item.last_observed_at)}`
    : dateTime(item.observed_at);
  const statusIsActive = flight => {
    const value = `${flight.status || ''} ${flight.baggage_state || ''}`.toLowerCase();
    return /vuelo|route|airborne|aproxim|landed|tierra|entrega|delivery/.test(value) && !/final|cancel/.test(value);
  };
  const flightIsFinished = flight => /final|cancel/.test(`${flight.status || ''} ${flight.baggage_state || ''}`.toLowerCase());
  const baggageDeliveryIsActive = flight => {
    const baggage = String(flight.baggage_state || '').trim().toLowerCase();
    if (['entrega','delivery','en_entrega','in_delivery'].includes(baggage)) return true;
    const status = String(flight.status || '').trim().toLowerCase();
    if (/pendiente|final|cancel/.test(status)) return false;
    return /(?:entrega|recogida)(?:\s+de)?\s+(?:equip|malet)|baggage\s+delivery/.test(status);
  };
  const beltAverageMarkup = flight => `<span class="belt-average" title="Media calculada con primeras publicaciones oficiales Aena de días anteriores">
    μ vuelo ${leadText(flight.belt_lead_flight_average_minutes)} · n=${Number(flight.belt_lead_flight_samples || 0)}<br>
    μ origen ${leadText(flight.belt_lead_origin_average_minutes)} · n=${Number(flight.belt_lead_origin_samples || 0)}
  </span>`;
  const coincidenceRatings = {
    perfecto:{label:'★ Vuelo perfecto', short:'★ Perfecto'},
    muy_bueno:{label:'Muy buena coincidencia', short:'Muy bueno'},
    parcial:{label:'Coincidencia parcial', short:'Parcial'},
    bajo:{label:'Poca coincidencia', short:'Baja'},
  };
  const arrivalBasisText = flight => {
    const minutes = Number(flight?.expected_deviation_minutes);
    const samples = Number(flight?.expected_deviation_samples || 0);
    const shift = Number.isFinite(minutes) ? `${minutes > 0 ? '+' : minutes < 0 ? '−' : '±'}${Math.abs(minutes)} min` : '';
    switch (flight?.expected_arrival_basis) {
      case 'real': return 'llegada real';
      case 'eta': return 'ETA publicada';
      case 'media_vuelo': return `media de este vuelo ${shift} (${samples} días)`;
      case 'media_origen': return `media del origen ${shift} (${samples} vuelos)`;
      case 'media_franja': return `media de la franja ${shift} (${samples} vuelos)`;
      case 'media_general': return `media general ${shift}`;
      default: return 'hora programada';
    }
  };
  const coincidenceScore10 = (greenCount, redCount) => {
    const redPart = Number(redCount) >= 2 ? 0.70 : Number(redCount) === 1 ? 0.35 : 0;
    const greenPart = 0.30 * (Math.min(Math.max(Number(greenCount) || 0, 0), 3) / 3);
    return Math.max(1, Math.min(10, Math.round(10 * (redPart + greenPart))));
  };
  const flightScore10 = flight => {
    const model = flight?.belt_coincidence;
    if (!model) return 1;
    if (model.score_10 != null) return Math.max(1, Math.min(10, Number(model.score_10)));
    return coincidenceScore10(model.green_count, model.red_count);
  };
  const coincidenceBadge = flight => {
    const model = flight?.belt_coincidence;
    if (!model) return '';
    const rating = coincidenceRatings[model.rating] || coincidenceRatings.bajo;
    const note = flightScore10(flight);
    return `<span class="coincidence-badge ${esc(model.rating)} score-${note}" title="${esc(rating.label)} · ${Number(model.green_count)} verdes · ${Number(model.red_count)} rojas · nota ${note}/10"><b>${note}</b><span>${esc(rating.short)} · ${Number(model.green_count)}V ${Number(model.red_count)}R</span></span>`;
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
  const occupancyCanaryAroundMarkup = entries => {
    const canary = rankAroundEntries(entries).filter(isCanaryEntry);
    if (!canary.length) return '';
    return `<p class="occupancy-canary-around">Canarias: ${canary.map(item =>
      `${esc(item.flight)} ${esc(item.origin)} ${esc(relativeMinuteCompact(item.offset_minutes))}′${item.belt ? ` C${item.belt}` : ''}`
    ).join(' · ')}</p>`;
  };

  const beltOccupancyMarkup = (flight, detailed = false) => {
    const model = flight?.belt_coincidence;
    if (!model) return '';
    const green = new Set((model.green_belts || []).map(Number));
    const red = new Set((model.red_belts || []).map(Number));
    const entries = Array.isArray(model.flights) ? model.flights : [];
    const rating = coincidenceRatings[model.rating] || coincidenceRatings.bajo;
    const beltCells = Array.from({length:8}, (_,index) => {
      const belt = index + 1;
      const flights = rankAroundEntries(entries.filter(item => Number(item.belt) === belt));
      const counting = flights.filter(item => item.counts);
      const nearby = flights.filter(item => !item.counts);
      const active = green.has(belt) || red.has(belt) || counting.length > 0;
      const official = counting.length > 0 && counting.every(item => item.assignment_source === 'oficial');
      const title = flights.length
        ? flights.map(item => `${item.flight} · ${item.origin} · ${time(item.arrival)} (${relativeMinuteCompact(item.offset_minutes)}) · ${item.counts?'cuenta':'nube'} · ${item.assignment_source}${isCanaryEntry(item)?' · Canarias':''}`).join(' | ')
        : `Cinta ${belt} sin llegadas en ±${Number(model.span_window_minutes || 30)} min`;
      const shown = preferOccupancyShown(counting, nearby);
      const offset = shown ? relativeMinuteCompact(shown.offset_minutes) : '';
      return `<span class="occupancy-belt ${active?'occupied':''} ${!active && nearby.length?'nearby':''} ${belt >= 7?'red':''} ${active && !official?'estimated':''} ${shown && isCanaryEntry(shown)?'is-canary':''}" title="${esc(title)}"><b>${belt}</b><small>${active ? `${offset}′` : nearby.length ? 'cerca' : 'libre'}</small></span>`;
    }).join('');
    const redStatus = [7,8].map(belt => `C${belt} ${red.has(belt)?'coincide':'libre'}`).join(' · ');
    const confidence = ({alta:'alta',media:'media',baja:'baja'}[model.confidence] || 'pendiente');
    const unknown = Number(model.unknown_belt_flights || 0);
    return `<section class="canary-occupancy coincidence-${esc(model.rating)} ${red.size?'has-red':''} ${detailed?'detailed':''}">
      <header><span>${esc(rating.label)}</span><strong>${green.size}V · ${red.size}R</strong><b>${time(model.target_time)}</b></header>
      <div class="occupancy-belts" aria-label="Cintas que coinciden con la llegada">${beltCells}</div>
      ${occupancyCanaryAroundMarkup(entries)}
      <div class="occupancy-red"><span>Rojas ±${Number(model.red_window_minutes)} min</span><strong>${red.size}/2</strong><small>${redStatus}</small></div>
      <footer><span>Verdes ±${Number(model.green_window_minutes)} min: <b>${green.size ? [...green].map(b => `C${b}`).join(' ') : 'ninguna'}</b></span><span>Llegada prevista ${time(model.target_time)} · ${esc(arrivalBasisText(flight))}${unknown?` · ${unknown} sin cinta`:''} · confianza ${confidence}</span></footer>
      ${detailed?`<p>La nube muestra ±${Number(model.span_window_minutes || 30)} min. Solo cuentan las verdes a ±${Number(model.green_window_minutes)} min y las rojas a ±${Number(model.red_window_minutes)} min. Vuelo perfecto: ${Number(model.perfect_min_green)} o más verdes y las dos rojas.</p>`:''}
    </section>`;
  };

  const relativeMinuteLabel = value => {
    const minutes = Number(value || 0);
    if (minutes === 0) return 'misma hora';
    return minutes < 0 ? `${Math.abs(minutes)} min antes` : `${minutes} min después`;
  };
  const relativeMinuteCompact = value => {
    const minutes = Math.round(Number(value || 0));
    return minutes > 0 ? `+${minutes}` : String(minutes);
  };

  function focusWindowMarkup(flight) {
    const model = flight?.belt_coincidence;
    const entries = Array.isArray(model?.flights) ? model.flights : [];
    if (!model) return '<div class="alert alert-secondary">Todavía no hay datos suficientes para calcular el entorno de cintas.</div>';
    const activeBelts = new Set([...(model.green_belts || []), ...(model.red_belts || [])].map(Number));
    const rating = coincidenceRatings[model.rating] || coincidenceRatings.bajo;
    const byBelt = new Map();
    entries.forEach(item => {
      const belt = Number(item.belt);
      if (!Number.isInteger(belt) || belt < 1 || belt > 8) return;
      if (!byBelt.has(belt)) byBelt.set(belt, []);
      byBelt.get(belt).push(item);
    });
    const sourceLabel = value => value === 'oficial' ? 'Aena' : value === 'prediccion' ? 'predicción' : 'provisional';
    const ownEstimate = model.target_belt === undefined ? redBeltEstimate(flight) : null;
    const ownBelt = ownEstimate ? (ownEstimate.source === 'historico' ? null : ownEstimate.belt) : (model.target_belt === null ? null : Number(model.target_belt));
    const ownSource = ownEstimate ? ownEstimate.source : model.target_belt_source;
    const tiles = Array.from({length:8}, (_,index) => {
      const belt = index + 1;
      const flights = byBelt.get(belt) || [];
      const counting = flights.filter(item => item.counts);
      const nearby = flights.filter(item => !item.counts);
      const active = activeBelts.has(belt) || counting.length > 0;
      const own = ownBelt === belt;
      const span = Number(model.span_window_minutes || 30);
      const ownRow = own ? `<li class="selected">
          <span><b>Tu vuelo · ${esc(flight.origin_name)}</b><small>${time(model.target_time)} · ${esc(flight.physical_flight)} · ${esc(sourceLabel(ownSource))}</small></span>
        </li>` : '';
      const rows = flights.map(item => `<li class="${item.counts?'active':'nearby'}">
          <span><b>${esc(item.origin)}</b><small>${time(item.arrival)} · ${relativeMinuteCompact(item.offset_minutes)} min · ${esc(item.flight)} · ${item.counts?'cuenta':'nube'} · ${esc(sourceLabel(item.assignment_source))}</small></span>
        </li>`).join('');
      const status = own && active ? 'TU CINTA · COINCIDE' : own ? 'TU CINTA' : active ? 'COINCIDE' : nearby.length ? `en nube ±${span}′` : `libre ±${span}′`;
      return `<article class="focus-belt ${belt >= 7?'red':''} ${active?'occupied':''} ${!active && nearby.length?'nearby':''} ${own?'own':''}">
        <header><span>Cinta ${belt}</span><strong>${status}</strong></header>
        <ul>${ownRow}${rows || (own ? '' : `<li class="empty">Sin llegadas en ±${span} min</li>`)}</ul>
      </article>`;
    }).join('');
    const unknown = entries.filter(item => item.belt === null || item.belt === undefined);
    const unknownMarkup = unknown.length ? `<aside class="focus-unknown"><strong>${unknown.length} vuelo${unknown.length===1?'':'s'} sin cinta conocida</strong>${unknown.map(item=>`<span>${esc(item.origin)} · ${esc(item.flight)} · ${relativeMinuteLabel(item.offset_minutes)}</span>`).join('')}</aside>` : '';
    return `<section class="focus-window coincidence-${esc(model.rating)}">
      <header><div><small>Coincidencia de cintas</small><h3>${esc(rating.label)} · llegada prevista ${time(model.target_time)}</h3></div><span>${Number(model.green_count)} verdes · ${Number(model.red_count)} rojas</span></header>
      <p class="coincidence-basis">Hora usada: ${esc(arrivalBasisText(flight))}. Nube ±${Number(model.span_window_minutes || 30)} min. Cuentan verdes ±${Number(model.green_window_minutes)} y rojas ±${Number(model.red_window_minutes)}. Perfecto: ${Number(model.perfect_min_green)}+ verdes y las dos rojas.</p>
      <div class="focus-belt-grid">${tiles}</div>${unknownMarkup}
    </section>`;
  }

  const beltDigit = value => {
    const match = String(value ?? '').trim().match(/(?:^|\D)([1-8])$/);
    return match ? Number(match[1]) : null;
  };

  const COINCIDENCE_DEFAULTS = {green:15, red:20, span:30};
  const NIGHT_FROM_DEFAULT = 22;
  const clampNightHour = value => Math.max(18, Math.min(23, Math.round(Number(value) || NIGHT_FROM_DEFAULT)));
  const readNightFromHour = () => clampNightHour(localStorage.getItem('matrix.nightFromHour') || NIGHT_FROM_DEFAULT);
  const saveNightFromHour = hour => localStorage.setItem('matrix.nightFromHour', String(clampNightHour(hour)));
  const nightHourLabel = hour => `${String(clampNightHour(hour)).padStart(2, '0')}:00`;
  const nightRangeText = () => `${nightHourLabel(readNightFromHour())}–01:59`;
  const clampWindow = (value, min = 5, max = 90) => Math.max(min, Math.min(max, Math.round(Number(value) || 0)));
  const readCoincidenceWindows = () => ({
    green: clampWindow(localStorage.getItem('matrix.greenWindow') || COINCIDENCE_DEFAULTS.green, 5, 60),
    red: clampWindow(localStorage.getItem('matrix.redWindow') || COINCIDENCE_DEFAULTS.red, 5, 60),
    span: clampWindow(localStorage.getItem('matrix.spanWindow') || COINCIDENCE_DEFAULTS.span, 10, 90)
  });
  const saveCoincidenceWindows = (green, red, span) => {
    localStorage.setItem('matrix.greenWindow', String(clampWindow(green, 5, 60)));
    localStorage.setItem('matrix.redWindow', String(clampWindow(red, 5, 60)));
    localStorage.setItem('matrix.spanWindow', String(clampWindow(span, 10, 90)));
  };
  const resolvedCoincidenceBelt = flight => {
    if (flight.source === 'aena') {
      const official = beltDigit(flight.belt);
      if (official !== null) return {belt:official, hall:flight.hall || (official >= 7 ? 'B' : 'A'), source:'oficial'};
    }
    const provisional = beltDigit(flight.belt) ?? beltDigit(flight.secondary_belt);
    if (provisional !== null) {
      return {belt:provisional, hall:flight.hall || flight.secondary_hall || (provisional >= 7 ? 'B' : 'A'), source:'provisional'};
    }
    const predicted = beltDigit(flight.predicted_belt);
    if (predicted !== null) {
      return {belt:predicted, hall:flight.predicted_hall || (predicted >= 7 ? 'B' : 'A'), source:'prediccion'};
    }
    return {belt:null, hall:null, source:null};
  };

  function applyBeltCoincidence(flights, windows = readCoincidenceWindows()) {
    const greenMin = clampWindow(windows.green, 5, 60);
    const redMin = clampWindow(windows.red, 5, 60);
    const spanMin = clampWindow(windows.span ?? COINCIDENCE_DEFAULTS.span, 10, 90);
    const greenMs = greenMin * 60_000;
    const redMs = redMin * 60_000;
    const spanMs = spanMin * 60_000;
    const scan = Math.max(greenMs, redMs, spanMs);
    const resolved = flights.map(flight => {
      const arrivalText = flight.expected_arrival || flight.effective_arrival;
      const arrival = parseDate(arrivalText);
      if (!arrival) return null;
      const assignment = resolvedCoincidenceBelt(flight);
      return {
        id: Number(flight.id),
        flight: flight.physical_flight,
        origin: flight.origin_name || flight.origin_iata,
        arrival: arrival.getTime(),
        arrivalText,
        belt: assignment.belt,
        assignment_source: assignment.source,
        basis: flight.expected_arrival_basis || 'programada',
        is_canary: Number(flight.is_canary) === 1 ? 1 : 0
      };
    }).filter(Boolean);

    flights.forEach(flight => {
      const targetText = flight.expected_arrival || flight.effective_arrival;
      const target = parseDate(targetText);
      if (!target) {
        flight.belt_coincidence = null;
        return;
      }
      const targetMs = target.getTime();
      const greenBelts = {};
      const redBelts = {};
      const entries = [];
      let unknown = 0;
      const sources = [];
      resolved.forEach(candidate => {
        if (candidate.id === Number(flight.id)) return;
        const delta = candidate.arrival - targetMs;
        if (Math.abs(delta) > scan) return;
        const belt = candidate.belt;
        const isRed = belt === 7 || belt === 8;
        const inside = belt !== null && Math.abs(delta) <= (isRed ? redMs : greenMs);
        if (belt === null) {
          if (Math.abs(delta) <= greenMs) unknown += 1;
        } else if (inside) {
          if (isRed) redBelts[belt] = true;
          else greenBelts[belt] = true;
          if (candidate.assignment_source) sources.push(candidate.assignment_source);
        }
        if (!inside && Math.abs(delta) > spanMs) return;
        entries.push({
          id: candidate.id,
          flight: candidate.flight,
          origin: candidate.origin,
          arrival: candidate.arrivalText,
          offset_minutes: Math.round(delta / 60_000),
          belt,
          kind: belt === null ? 'unknown' : (isRed ? 'red' : 'green'),
          counts: inside,
          assignment_source: candidate.assignment_source,
          arrival_basis: candidate.basis,
          is_canary: Number(candidate.is_canary) === 1 ? 1 : 0
        });
      });
      entries.sort((a, b) =>
        (isCanaryEntry(b) ? 1 : 0) - (isCanaryEntry(a) ? 1 : 0)
        || Math.abs(Number(a.offset_minutes)) - Math.abs(Number(b.offset_minutes))
        || Number(a.offset_minutes) - Number(b.offset_minutes)
        || Number(a.id) - Number(b.id)
      );
      const greenKeys = Object.keys(greenBelts).map(Number).sort((a, b) => a - b);
      const redKeys = Object.keys(redBelts).map(Number).sort((a, b) => a - b);
      const greenCount = greenKeys.length;
      const redCount = redKeys.length;
      const greenOk = greenCount >= 2;
      const score10 = coincidenceScore10(greenCount, redCount);
      const uniqueSources = [...new Set(sources)];
      const own = resolvedCoincidenceBelt(flight);
      flight.belt_coincidence = {
        model: 'belt_coincidence_v1',
        target_time: targetText,
        target_belt: own.belt,
        target_hall: own.hall,
        target_belt_source: own.source,
        arrival_basis: flight.expected_arrival_basis || 'programada',
        green_window_minutes: greenMin,
        red_window_minutes: redMin,
        span_window_minutes: spanMin,
        perfect_min_green: 2,
        green_belts: greenKeys,
        red_belts: redKeys,
        green_count: greenCount,
        red_count: redCount,
        score: score10,
        score_10: score10,
        rating: greenOk && redCount >= 2 ? 'perfecto' : greenOk && redCount === 1 ? 'muy_bueno' : greenOk || redCount >= 1 ? 'parcial' : 'bajo',
        unknown_belt_flights: unknown,
        confidence: unknown > 0 ? 'baja' : (uniqueSources.every(source => source === 'oficial') ? 'alta' : 'media'),
        flights: entries
      };
    });
  }

  function syncCoincidenceSettings() {
    const windows = readCoincidenceWindows();
    if (els.greenWindowInput) els.greenWindowInput.value = String(windows.green);
    if (els.redWindowInput) els.redWindowInput.value = String(windows.red);
    if (els.spanWindowInput) els.spanWindowInput.value = String(windows.span);
    if (els.greenWindowRange) els.greenWindowRange.value = String(windows.green);
    if (els.redWindowRange) els.redWindowRange.value = String(windows.red);
    if (els.spanWindowRange) els.spanWindowRange.value = String(windows.span);
    const nightFrom = readNightFromHour();
    if (els.nightFromInput) els.nightFromInput.value = String(nightFrom);
    if (els.nightFromRange) els.nightFromRange.value = String(nightFrom);
  }

  function updateNightFromHour(hour) {
    saveNightFromHour(hour);
    syncCoincidenceSettings();
    renderNightWeek();
    renderCanaryWatch();
  }

  function updateCoincidenceWindows(green, red, span) {
    saveCoincidenceWindows(green, red, span);
    syncCoincidenceSettings();
    applyBeltCoincidence(state.flights);
    applyBeltCoincidence(state.nightContext);
    render();
    renderNightWeek();
  }

  // Cinta más fiable conocida para un vuelo y probabilidad de que acabe en 7/8.
  function redBeltEstimate(flight) {
    const official = flight.source === 'aena' ? beltDigit(flight.belt) : null;
    if (official !== null) return {belt:official, source:'oficial', probability:official >= 7 ? 1 : 0};
    const provisional = beltDigit(flight.belt) ?? beltDigit(flight.secondary_belt);
    if (provisional !== null) return {belt:provisional, source:'provisional', probability:provisional >= 7 ? 1 : 0};
    const predicted = beltDigit(flight.predicted_belt);
    if (predicted !== null) return {belt:predicted, source:'prediccion', probability:predicted >= 7 ? 1 : 0};
    const profile = [flight.belt_distribution_flight, flight.belt_distribution_origin]
      .find(item => Number(item?.samples || 0) >= 3);
    if (!profile) return {belt:null, source:null, probability:0};
    const counts = Object.fromEntries((profile.belts || []).map(item => [Number(item.belt), Number(item.count || 0)]));
    return {
      belt:(counts[8] || 0) > (counts[7] || 0) ? 8 : 7,
      source:'historico',
      probability:clamp(Number(profile.red_percentage || 0) / 100, 0, 1)
    };
  }

  function dailyRedUsageMarkup(flight) {
    const now = madridNow();
    const selectedDate = String(els.date.value);
    const isPastDay = selectedDate < now.operationalDate;
    const isToday = selectedDate === now.operationalDate;
    const rows = state.flights
      .filter(item => !/cancel/i.test(`${item.status || ''}`))
      .map(item => {
        const arrival = parseDate(effectiveArrival(item))?.getTime() ?? null;
        return {flight:item, arrival, elapsed:isPastDay || (isToday && arrival !== null && arrival <= now.nowMs), ...redBeltEstimate(item)};
      });
    const used = rows.filter(row => row.elapsed && row.source === 'oficial' && row.belt >= 7);
    const pending = rows.filter(row => !row.elapsed && row.probability > 0);
    const assigned = pending.filter(row => row.source === 'oficial');
    const likely = pending.filter(row => ['provisional','prediccion'].includes(row.source));
    const historical = pending.filter(row => row.source === 'historico');
    const historicalExpected = historical.reduce((sum,row) => sum + row.probability, 0);
    const total = used.length + assigned.length + likely.length + Math.round(historicalExpected);
    const countBelt = belt => [...used, ...assigned, ...likely, ...historical.filter(row => row.probability >= .5)]
      .filter(row => row.belt === belt).length;
    const decimal = value => value.toLocaleString('es-ES', {maximumFractionDigits:1});
    const parts = [];
    if (!isPastDay && !isToday) parts.push('previsión');
    if (used.length) parts.push(`${used.length} ya usada${used.length===1?'':'s'}`);
    if (assigned.length) parts.push(`${assigned.length} asignada${assigned.length===1?'':'s'} por Aena`);
    if (likely.length) parts.push(`${likely.length} por predicción`);
    if (historicalExpected >= .05) parts.push(`≈${decimal(historicalExpected)} por histórico`);
    const title = isPastDay
      ? `${used.length} vuelo${used.length===1?'':'s'} en C7/C8`
      : `${historicalExpected >= .5 && total ? '≈ ' : ''}${total} vuelo${total===1?'':'s'} en C7/C8`;
    const label = isPastDay ? 'en toda la jornada' : isToday ? 'hoy, usadas + previstas' : `previstas para ${relativeDayLabel(selectedDate).toLowerCase()}`;
    const sourceText = {oficial:'Aena', provisional:'provisional', prediccion:'predicción', historico:'histórico'};
    const listed = [...(isPastDay ? used : [...used, ...assigned, ...likely, ...historical.filter(row => row.probability >= .5)])]
      .sort((a,b) => (a.arrival || 0) - (b.arrival || 0));
    const open = state.redListOpen;
    const list = listed.length ? `<button class="focus-red-toggle" type="button" data-red-list-toggle="${listed.length}" aria-expanded="${open}">${open ? 'Ocultar vuelos' : `Ver vuelos (${listed.length})`}</button>
      <ul class="focus-red-list"${open ? '' : ' hidden'}>${listed.map(row => `<li class="${Number(row.flight.id)===Number(flight?.id)?'selected':''} ${row.elapsed?'done':''}">
        <b>${time(effectiveArrival(row.flight))}</b><span>${esc(row.flight.origin_name)} · ${esc(row.flight.physical_flight)}</span>
        <em>C${row.belt} · ${esc(sourceText[row.source] || '')}${row.source==='historico'?` ${Math.round(row.probability*100)}%`:''}</em>
      </li>`).join('')}</ul>` : '';
    return `<section class="focus-red-day ${total?'has-red':''}">
      <div><small>Cintas rojas ${esc(label)}</small><strong>${esc(title)}</strong><span>${esc(parts.join(' · ') || 'sin vuelos previstos en 7/8')}</span></div>
      <div class="focus-red-split"><span><b>C7</b>${countBelt(7)}</span><span><b>C8</b>${countBelt(8)}</span></div>
      ${list}
    </section>`;
  }

  const latestFlightUpdateAt = flight => [
    flight.authoritative_seen_at,
    flight.observed_at,
    flight.telemetry_observed_at,
    flight.secondary_belt_at,
    flight.flightaware?.observed_at,
    flight.flightstats?.observed_at,
  ].map(parseDate).filter(Boolean).sort((a,b) => b.getTime() - a.getTime())[0] || null;

  function watchUpdateScheduleMarkup(flight) {
    const last = latestFlightUpdateAt(flight);
    const arrival = parseDate(effectiveArrival(flight));
    const now = Date.now();
    let nextLabel = 'pendiente';
    if (flightIsFinished(flight)) {
      nextLabel = 'seguimiento resuelto';
    } else if (arrival) {
      const minutesToArrival = Math.round((arrival.getTime() - now) / 60000);
      const next = minutesToArrival > 60
        ? new Date(arrival.getTime() - 60 * 60000)
        : new Date((last ? last.getTime() : now) + (minutesToArrival <= 20 ? 5 : 15) * 60000);
      nextLabel = next.getTime() <= now ? 'ahora' : time(next);
    }
    return `<small class="focus-update-schedule"><span>Últimos datos <b>${esc(last ? time(last) : 'sin datos')}</b></span><span>Próxima revisión <b>${esc(nextLabel)}</b></span></small>`;
  }

  function focusHeroMarkup(flight, focus = null, allowExpand = false) {
    const admin = appMain?.dataset.focusAdmin === '1';
    const deviceAuthorized = Boolean(localStorage.getItem('matrix.pushDevice'));
    const authorized = admin || deviceAuthorized;
    const receivedAt = Number(focus?.cooldown_started_at || 0);
    const cooldown = Math.max(
      0,
      Number(focus?.retry_after_seconds || 0)
        - (receivedAt ? Math.floor((Date.now() - receivedAt) / 1000) : 0),
    );
    const trend = verticalTrend(flight);
    const official = flight.source === 'aena' && flight.belt ? beltPosition(flight) : 'pendiente de Aena';
    const predicted = flight.predicted_belt ? `${flight.predicted_hall || (Number(flight.predicted_belt)>=7?'B':'A')}/${flight.predicted_belt}` : 'sin predicción';
    const migrationMissing = focus && focus.ready === false;
    const disabled = !authorized || migrationMissing || cooldown > 0;
    const needsAircraft = !flight.aircraft_registration || !flight.aircraft_icao24;
    const buttonLabel = migrationMissing ? 'Aplica la actualización MySQL 010'
      : !authorized ? 'Activa avisos o inicia sesión'
        : cooldown > 0 ? `Nueva consulta en ${Math.ceil(cooldown / 60)} min`
          : needsAircraft ? 'Buscar matrícula y telemetría' : 'Actualizar telemetría y estado';
    return `<section class="flight-focus-hero ${Number(flight.is_canary)===1?'canary':''}" data-focus-surface>
      <div class="focus-flight-title"><span>${Number(flight.is_canary)===1?'🌴':'✈️'}</span><div><small>Vuelo bajo foco</small><h3>${esc(flight.physical_flight)} · ${esc(flight.origin_name)}</h3><p>${esc(flight.status || 'Estado pendiente')} · ${trend.label}</p></div></div>
      <div class="focus-primary-values">
        <span><small>Programada</small><b>${time(flight.scheduled_arrival)}</b></span>
        <span><small>ETA / real</small><b>${time(effectiveArrival(flight))}</b><em>${signed(flight.deviation_minutes)}</em></span>
        <span><small>Cinta confirmada</small><b>${esc(official)}</b></span>
        <span><small>Predicción</small><b>${esc(predicted)}</b></span>
      </div>
      ${flightStatsWatchMarkup(flight)}${flight.secondary_eta && flight.secondary_eta !== flight.eta && flight.secondary_eta !== (flight.flightstats && flight.flightstats.eta) ? `<p class="flightstats-watch-line"><span>${esc((flight.secondary_eta_source || 'API').toUpperCase())}</span><strong>ETA ${time(flight.secondary_eta)}${flight.secondary_actual_departure ? ' · salió ' + time(flight.secondary_actual_departure) : ''}${flight.secondary_actual_arrival ? ' · aterrizó ' + time(flight.secondary_actual_arrival) : ''}</strong><small>secundario · no sustituye a Aena</small></p>` : ''}
      ${laterNightMarkup(flight)}
      <div class="focus-hero-actions">
        <button class="btn btn-info w-100" type="button" data-focus-refresh="${Number(flight.id)}" ${disabled?'disabled':''}>↻ ${esc(buttonLabel)}</button>
        ${allowExpand ? `<button class="btn btn-outline-light w-100" type="button" data-open-flight="${Number(flight.id)}">Ampliar vuelo</button>` : ''}
      </div>
      ${watchUpdateScheduleMarkup(flight)}
      <small class="focus-refresh-status" data-focus-refresh-status></small>
    </section>`;
  }
  const predictionMarkup = flight => {
    if (!flight.predicted_belt) return '';
    const evaluated = flight.prediction_correct === null || flight.prediction_correct === undefined
      ? 'pendiente de Aena'
      : Number(flight.prediction_exact) === 1 ? 'acierto exacto'
      : Number(flight.prediction_correct) === 1 ? `acierto de bloque; cinta oficial ${beltPosition(flight)}`
      : `fallo de bloque; cinta oficial ${beltPosition(flight)}`;
    const danger = ['7','8'].includes(String(flight.predicted_belt));
    return `<span class="prediction-belt ${danger?'prediction-red':''}" title="Modelo histórico · ${esc(evaluated)}">
      ◎ predicción ${esc(flight.predicted_hall || '?')}/${esc(flight.predicted_belt)} · ${Number(flight.prediction_score || 0)}% · ${esc(flight.prediction_confidence || 'baja')}
    </span>`;
  };
  const flightAwareUrl = flight => {
    const code = String(flight?.physical_flight || '').replace(/\s+/g, '').toUpperCase();
    return code ? `https://www.flightaware.com/live/flight/${encodeURIComponent(code)}` : 'https://www.flightaware.com/live/';
  };
  const flightAwareLink = (flight, compact = false) => `<a class="flightaware-link ${compact?'flightaware-link-compact':''}"
    data-flightaware-link href="${flightAwareUrl(flight)}" target="_blank" rel="noopener noreferrer"
    title="Abrir mapa y ficha pública de ${esc(flight.physical_flight || 'este vuelo')} en FlightAware">🛰️ ${compact?'Mapa':'Mapa y ficha en FlightAware'} ↗</a>`;
  function flightStatsWatchMarkup(flight) {
    const snapshot = flight?.flightstats;
    if (!snapshot) return '';
    const delay = Number(snapshot.delay_minutes);
    const delayText = Number.isFinite(delay) && delay !== 0 ? `${delay > 0 ? '+' : ''}${delay} min` : '';
    const eta = snapshot.eta || snapshot.actual_arrival;
    const bits = [
      snapshot.status_text || 'FlightStats',
      delayText,
      eta ? `ETA ${time(eta)}` : '',
      snapshot.actual_departure ? `salió ${time(snapshot.actual_departure)}` : '',
    ].filter(Boolean);
    return `<p class="flightstats-watch-line"><span>FlightStats</span><strong>${esc(bits.join(' · '))}</strong><small>secundario · no sustituye a Aena</small></p>`;
  }
  const canarySnapshotKey = flight => `${flight.flight_date || els.date.value}|${flight.physical_flight}|${flight.origin_iata}`;
  const alertValue = value => value === null || value === undefined || value === '' ? 'pendiente' : String(value);

  function canarySnapshot(flight) {
    return {
      id:Number(flight.id), physical_flight:flight.physical_flight, origin_name:flight.origin_name,
      origin_iata:flight.origin_iata, position:flight.belt ? `${flight.hall || '?'}/${flight.belt}` : 'sin cinta',
      scheduled_arrival:flight.scheduled_arrival, eta:flight.eta, status:flight.status, actual_arrival:flight.actual_arrival,
      baggage_state:flight.baggage_state, gate:flight.gate, stand:flight.stand,
      aircraft_registration:flight.aircraft_registration, aircraft_type:flight.aircraft_type,
      secondary_position:flight.secondary_belt ? `${flight.secondary_hall || '?'}/${flight.secondary_belt}` : null,
      secondary_belt_state:flight.secondary_belt_state,
      predicted_position:flight.predicted_belt ? `${flight.predicted_hall || '?'}/${flight.predicted_belt}` : null
    };
  }

  function updateCanaryAlertCounter() {
    const counter = state.activeCanaryAlert?.toast?.querySelector('[data-alert-counter]');
    if (!counter) return;
    counter.textContent = state.canaryAlertQueue.length
      ? `${state.canaryAlertQueue.length} en cola`
      : '60 s';
  }

  function scheduleCanaryAlertHide(targetDuration) {
    if (!state.activeCanaryAlert) return;
    clearTimeout(state.canaryAlertTimer);
    const elapsed = Date.now() - state.activeCanaryAlert.startedAt;
    const remaining = Math.max(750, targetDuration - elapsed);
    state.canaryAlertTimer = window.setTimeout(() => {
      state.activeCanaryAlert?.instance?.hide();
    }, remaining);
    updateCanaryAlertCounter();
  }

  function renderActiveCanaryChanges() {
    const container = state.activeCanaryAlert?.toast?.querySelector('[data-alert-changes]');
    if (container) container.innerHTML = state.activeCanaryAlert.changes.map(change => `<span>${esc(change)}</span>`).join('');
  }

  function showNextCanaryAlert() {
    if (!els.canaryAlertStack || state.activeCanaryAlert || !state.canaryAlertQueue.length) return;
    const alert = state.canaryAlertQueue.shift();
    const {flight, changes, isNew} = alert;
    const actualBelt = String(flight.position || '').split('/').pop();
    const predictedBelt = String(flight.predicted_position || '').split('/').pop();
    const danger = ['7','8'].includes(actualBelt) || ['7','8'].includes(predictedBelt);
    const toast = document.createElement('article');
    toast.className = `toast canary-alert-toast ${danger?'canary-alert-danger':''}`;
    toast.setAttribute('role','alert');
    toast.setAttribute('aria-live','assertive');
    toast.setAttribute('aria-atomic','true');
    toast.innerHTML = `<div class="toast-header">
      <strong class="me-auto">🌴 ${esc(flight.origin_name)} · ${esc(flight.physical_flight)}</strong>
      <small data-alert-counter>ahora</small><button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast" aria-label="Cerrar"></button>
    </div><div class="toast-body">
      <b>${isNew?'Nuevo vuelo canario detectado':'Actualización prioritaria de Canarias'}</b>
      <div data-alert-changes>${changes.map(change=>`<span>${esc(change)}</span>`).join('')}</div>
      <button type="button" class="btn btn-sm btn-warning mt-2" data-flight-id="${Number(flight.id)}">Abrir vuelo</button>
    </div>`;
    els.canaryAlertStack.replaceChildren(toast);
    toast.querySelector('[data-flight-id]')?.addEventListener('click', event => openDetail(event.currentTarget.dataset.flightId));
    const instance = bootstrap.Toast.getOrCreateInstance(toast, {autohide:false});
    state.activeCanaryAlert = {...alert, toast, instance, startedAt:Date.now()};
    toast.addEventListener('hidden.bs.toast', () => {
      clearTimeout(state.canaryAlertTimer);
      state.canaryAlertTimer = null;
      toast.remove();
      state.activeCanaryAlert = null;
      showNextCanaryAlert();
    }, {once:true});
    instance.show();
    const duration = state.canaryAlertQueue.length ? CANARY_ALERT_QUEUED_MS : CANARY_ALERT_DEFAULT_MS;
    scheduleCanaryAlertHide(duration);
  }

  function showCanaryAlert(flight, changes, isNew = false) {
    if (!els.canaryAlertStack) return;
    const key = canarySnapshotKey(flight);
    const existing = state.activeCanaryAlert?.key === key
      ? state.activeCanaryAlert
      : state.canaryAlertQueue.find(alert => alert.key === key);
    if (existing) {
      existing.flight = flight;
      existing.isNew = existing.isNew || isNew;
      existing.changes = [...new Set([...existing.changes, ...changes])];
      if (existing === state.activeCanaryAlert) renderActiveCanaryChanges();
    } else {
      state.canaryAlertQueue.push({key, flight, changes:[...new Set(changes)], isNew});
    }
    if (state.activeCanaryAlert && state.canaryAlertQueue.length) {
      scheduleCanaryAlertHide(CANARY_ALERT_QUEUED_MS);
    } else {
      showNextCanaryAlert();
    }
  }

  function clearCanaryAlerts() {
    clearTimeout(state.canaryAlertTimer);
    state.canaryAlertTimer = null;
    state.canaryAlertQueue = [];
    if (state.activeCanaryAlert?.instance) {
      state.activeCanaryAlert.instance.hide();
    } else {
      state.activeCanaryAlert = null;
      els.canaryAlertStack?.replaceChildren();
    }
  }

  function processCanaryAlerts(incoming, date) {
    const storageKey = `svq-canary-snapshot:${date}`;
    let previous = {};
    try { previous = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (_) { previous = {}; }
    const current = {};
    incoming.filter(f => Number(f.is_canary) === 1).forEach(f => {
      const snapshot = canarySnapshot(f);
      const key = canarySnapshotKey(f);
      current[key] = snapshot;
      const before = previous[key];
      if (!before) {
        if (state.canaryWatchEnabled && Object.keys(previous).length) {
          showCanaryAlert(snapshot, [`Llegada ${time(effectiveArrival(f))} · ${snapshot.position}`], true);
        }
        return;
      }
      const changes = [];
      const fields = [
        ['position','Cinta/sala'], ['scheduled_arrival','Hora programada'], ['eta','ETA'], ['status','Estado'], ['actual_arrival','Llegada real'],
        ['baggage_state','Equipaje'], ['gate','Puerta'], ['stand','Posición'],
        ['aircraft_registration','Matrícula'], ['aircraft_type','Aeronave'],
        ['secondary_position','Propuesta secundaria'], ['secondary_belt_state','Estado de propuesta']
        ,['predicted_position','Predicción histórica']
      ];
      fields.forEach(([field,label]) => {
        if (alertValue(before[field]) !== alertValue(snapshot[field])) {
          const formatter = ['scheduled_arrival','eta','actual_arrival'].includes(field) ? time : alertValue;
          changes.push(`${label}: ${formatter(before[field])} → ${formatter(snapshot[field])}`);
        }
      });
      if (state.canaryWatchEnabled && changes.length) showCanaryAlert(snapshot, changes);
    });
    try { localStorage.setItem(storageKey, JSON.stringify(current)); } catch (_) {}
    state.alertDate = date;
  }

  function watchSnapshot(flight) {
    const stats = flight.flightstats || {};
    return {
      status: flight.status || '',
      eta: flight.eta || '',
      actual_arrival: flight.actual_arrival || '',
      belt: flight.belt ? `${flight.hall || '?'}/${flight.belt}` : '',
      baggage: flight.baggage_state || '',
      secondary_eta: flight.secondary_eta || stats.eta || '',
      secondary_arrival: flight.secondary_actual_arrival || stats.actual_arrival || '',
      secondary_departure: flight.secondary_actual_departure || stats.actual_departure || '',
      secondary_source: flight.secondary_eta_source || '',
      fs_delay: stats.delay_minutes == null || stats.delay_minutes === '' ? '' : String(stats.delay_minutes)
    };
  }

  async function notifyWatchSequenceStart(flight) {
    if (!flight) return;
    const title = `${flight.origin_name} · ${flight.physical_flight}`;
    const body = `Vigilancia activa · ${flight.physical_flight}. Cada minuto te mando el itinerario para comprobar que llega.`;
    try {
      const registration = await ensureServiceWorker(localStorage.getItem('matrix.pushDevice') || '');
      await registration.showNotification(title, {
        body,
        tag: 'matrix-watch-' + flight.id + '-on',
        renotify: true,
        data: {url: `index.php?date=${encodeURIComponent(els.date.value)}&flight=${flight.id}`}
      });
    } catch (_) {}
    state.pushCheckNote = body + ' Baja el centro de notificaciones o bloquea el teléfono.';
  }

  async function notifyWatchedChange(flight, title, body, tag) {
    const notice = {
      title,
      body,
      tag: tag || ('matrix-watch-' + flight.id),
      target_url: `index.php?date=${encodeURIComponent(els.date.value)}&flight=${flight.id}`
    };
    try {
      const registration = await ensureServiceWorker(localStorage.getItem('matrix.pushDevice') || '');
      const worker = registration.active || navigator.serviceWorker.controller;
      if (worker) {
        worker.postMessage({type:'matrix-show-notice', notice});
        return;
      }
      await registration.showNotification(notice.title, {
        body: notice.body,
        tag: notice.tag,
        renotify: true,
        data: {url: notice.target_url}
      });
    } catch (_) {}
  }

  function watchedItineraryBody(flight) {
    const snap = watchSnapshot(flight);
    const dep = snap.secondary_departure ? time(snap.secondary_departure) : time(flight.scheduled_departure);
    const arr = flight.actual_arrival ? time(flight.actual_arrival) : time(effectiveArrival(flight));
    const belt = snap.belt || 'pendiente';
    const clock = new Intl.DateTimeFormat('es-ES', {hour:'2-digit', minute:'2-digit'}).format(new Date());
    return `${clock} · Itinerario · Despegue ${dep} · Llegada ${arr} · Cinta ${belt}`;
  }

  async function maybeTickWatchedFlights() {
    if (!state.watchedFlightIds.length) return;
    if (Date.now() - (state.watchTickAt || 0) < 55_000) return;
    state.watchTickAt = Date.now();
    await tickWatchedFlights();
  }

  async function holdWatchWakeLock() {
    if (!state.watchedFlightIds.length || document.hidden || !('wakeLock' in navigator)) {
      try { await state.wakeLock?.release(); } catch (_) {}
      state.wakeLock = null;
      return;
    }
    try {
      state.wakeLock = await navigator.wakeLock.request('screen');
      state.wakeLock.addEventListener('release', () => { state.wakeLock = null; });
    } catch (_) {}
  }

  async function tickWatchedFlights() {
    const fromBoard = state.flights.filter(flight => flightIsWatched(flight.id));
    const seen = new Set(fromBoard.map(flight => Number(flight.id)));
    const extra = (state.watchedFlightsBrief || []).filter(item => !seen.has(Number(item.id)));
    const list = [...fromBoard, ...extra];
    if (!document.hidden) {
      for (const flight of list) {
        const title = `${flight.origin_name || ''} · ${flight.physical_flight}`;
        await notifyWatchedChange(
          flight,
          title,
          watchedItineraryBody(flight),
          'matrix-watch-' + flight.id + '-min'
        );
      }
    }
    holdWatchWakeLock();
    const token = localStorage.getItem('matrix.pushDevice');
    if (!token) return;
    try {
      const response = await fetch('api/push.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'watch_tick', device_token:token})
      });
      const data = await response.json();
      if (!response.ok) return;
      if (Number(data.sent) > 0) {
        state.pushLastError = '';
        state.pushLastAt = new Date().toISOString();
      } else if (Number(data.failed) > 0) {
        state.pushLastError = 'El aviso cada minuto no salió por Apple';
      }
      if (els.pushStatusLine && list.length) {
        els.pushStatusLine.className = Number(data.failed) > 0
          ? 'push-status-line push-status-error'
          : 'push-status-line push-status-ready';
        els.pushStatusLine.textContent = Number(data.sent) > 0
          ? `Aviso de itinerario enviado (${data.sent}). Baja el centro de notificaciones o bloquea el teléfono.`
          : (data.loop
            ? 'El servidor sigue avisando cada minuto aunque duerma la pantalla. Baja el centro de notificaciones.'
            : 'Comprobación local cada minuto. Baja el centro de notificaciones: el globo no sale con la app abierta.');
      }
    } catch (_) {}
  }

  function processWatchAlerts(incoming) {
    if (!state.watchedFlightIds.length) return;
    const storageKey = 'matrix.watchSnapshots';
    let previous = {};
    try { previous = JSON.parse(localStorage.getItem(storageKey) || '{}') || {}; } catch (_) { previous = {}; }
    const current = {...previous};
    incoming.filter(flight => flightIsWatched(flight.id)).forEach(flight => {
      const snap = watchSnapshot(flight);
      const before = previous[String(flight.id)];
      current[String(flight.id)] = snap;
      if (!before) return;
      const title = `${flight.origin_name} · ${flight.physical_flight}`;
      if (before.actual_arrival !== snap.actual_arrival && snap.actual_arrival) {
        notifyWatchedChange(flight, title, '🛬 Aterriza · ' + time(snap.actual_arrival), 'matrix-watch-' + flight.id + '-arr');
      } else if (before.secondary_arrival !== snap.secondary_arrival && snap.secondary_arrival && !snap.actual_arrival) {
        notifyWatchedChange(flight, title, '🛬 Aterrizaje (AirLabs/FlightStats) · ' + time(snap.secondary_arrival), 'matrix-watch-' + flight.id + '-arr2');
      }
      if (before.eta !== snap.eta && snap.eta) {
        const scheduled = parseDate(flight.scheduled_arrival);
        const etaAt = parseDate(snap.eta);
        const delay = scheduled && etaAt ? Math.round((etaAt.getTime() - scheduled.getTime()) / 60000) : null;
        if (delay !== null && Math.abs(delay) >= 5) {
          notifyWatchedChange(flight, title, `⏱️ ETA ${time(snap.eta)} · ${delay > 0 ? '+' : ''}${delay} min sobre programada`, 'matrix-watch-' + flight.id + '-eta');
        }
      }
      if (before.secondary_eta !== snap.secondary_eta && snap.secondary_eta && snap.secondary_eta !== snap.eta) {
        const scheduled = parseDate(flight.scheduled_arrival);
        const etaAt = parseDate(snap.secondary_eta);
        const delay = scheduled && etaAt ? Math.round((etaAt.getTime() - scheduled.getTime()) / 60000) : null;
        if (delay !== null && Math.abs(delay) >= 5) {
          const source = (snap.secondary_source || 'API').toUpperCase();
          notifyWatchedChange(flight, title, `⏱️ Retraso ${source} · ETA ${time(snap.secondary_eta)} · ${delay > 0 ? '+' : ''}${delay} min`, 'matrix-watch-' + flight.id + '-eta2');
        }
      }
      if (before.fs_delay !== snap.fs_delay && snap.fs_delay !== '' && Math.abs(Number(snap.fs_delay)) >= 5) {
        notifyWatchedChange(flight, title, `⏱️ FlightStats ${Number(snap.fs_delay) > 0 ? '+' : ''}${snap.fs_delay} min`, 'matrix-watch-' + flight.id + '-fs');
      }
      if (before.belt !== snap.belt && snap.belt) {
        notifyWatchedChange(flight, title, (before.belt ? '⚠️ Cambio de cinta · ' + before.belt + ' → ' : '🧳 Asignación de cinta · ') + snap.belt, 'matrix-watch-' + flight.id + '-belt');
      }
      if (before.baggage !== snap.baggage && snap.baggage && snap.baggage !== 'pendiente') {
        const bag = snap.baggage === 'finalizado' || snap.baggage === 'final'
          ? '✅ Entrega de equipaje finalizada'
          : ('🧳 En la cinta' + (snap.belt ? ' · ' + snap.belt : ''));
        notifyWatchedChange(flight, title, bag, 'matrix-watch-' + flight.id + '-bag');
      }
      if (before.status !== snap.status && snap.status) {
        notifyWatchedChange(flight, title, snap.status, 'matrix-watch-' + flight.id + '-st');
      }
      if (before.secondary_departure !== snap.secondary_departure && snap.secondary_departure) {
        notifyWatchedChange(flight, title, '✈️ Despega · ' + time(snap.secondary_departure), 'matrix-watch-' + flight.id + '-dep');
      }
    });
    Object.keys(current).forEach(id => { if (!flightIsWatched(id)) delete current[id]; });
    try { localStorage.setItem(storageKey, JSON.stringify(current)); } catch (_) {}
  }

  function updateNotificationButton() {
    if (!els.enableNotifications) return;
    const capabilityError = pushCapabilityError();
    const pushReady = !capabilityError && 'Notification' in window && Notification.permission === 'granted' && !!localStorage.getItem('matrix.pushDevice');
    const needsRepair = pushReady && Boolean(state.pushLastError);
    els.enableNotifications.classList.remove('d-none');
    els.enableNotifications.className = state.canaryWatchEnabled
      ? 'btn btn-sm btn-warning'
      : 'btn btn-sm btn-outline-warning';
    els.enableNotifications.textContent = state.canaryWatchEnabled
      ? '🔔 Avisos Canarias activos · desactivar'
      : needsRepair ? '🔧 Reparar y activar avisos Canarias' : '🔔 Activar avisos Canarias';
    els.enableNotifications.title = state.pushLastError
      ? `El último envío falló: ${state.pushLastError}. Pulsa para reparar la suscripción.`
      : '';
    els.enableNotifications.disabled = false;
    if (els.testPushNotification) {
      els.testPushNotification.classList.remove('d-none');
      els.testPushNotification.disabled = false;
      els.testPushNotification.textContent = 'Probar aviso real';
    }
    if (els.drillPushNotification) {
      els.drillPushNotification.classList.remove('d-none');
      els.drillPushNotification.disabled = false;
      els.drillPushNotification.textContent = 'Simulacro de un vuelo';
    }
    if (els.pushStatusLine) {
      if (capabilityError) {
        els.pushStatusLine.className = 'push-status-line push-status-warning';
        els.pushStatusLine.textContent = capabilityError;
      } else {
        els.pushStatusLine.className = `push-status-line ${state.pushLastError?'push-status-error':(state.pushCheckNote||pushReady)?'push-status-ready':''}`;
        els.pushStatusLine.textContent = state.pushLastError
          ? `El servidor intentó avisar, pero falló: ${state.pushLastError}`
          : state.pushCheckNote
            ? state.pushCheckNote
          : pushReady
            ? `Web Push preparado · ${state.pushWatches} vuelo${state.pushWatches===1?'':'s'} vigilado${state.pushWatches===1?'':'s'} · ${state.pushPending} aviso${state.pushPending===1?'':'s'} pendiente${state.pushPending===1?'':'s'}${state.pushLastAt ? ` · último envío ${dateTime(state.pushLastAt)}` : ' · todavía sin envíos registrados'}. Con la app abierta iOS no enseña globo: baja el centro de notificaciones o bloquea el teléfono.`
            : 'Los globos de la web no son avisos de iOS. Activa Web Push o pulsa Simulacro desde la app instalada en la pantalla de inicio.';
      }
    }
  }

  async function refreshNotificationStatus() {
    let token = localStorage.getItem('matrix.pushDevice');
    if ('Notification' in window && Notification.permission === 'granted' && !pushCapabilityError()) {
      try {
        token = await enablePushNotifications();
      } catch (error) {
        state.pushLastError = error.message || 'No se pudo renovar Web Push';
      }
    }
    if (!token) {
      state.canaryWatchEnabled = false;
      state.watchedFlightIds = [];
      updateNotificationButton();
      return;
    }
    try {
      const response = await fetch('api/push.php?action=status&device_token=' + encodeURIComponent(token), {cache:'no-store'});
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo comprobar la suscripción.');
      if (!data.subscribed) {
        state.canaryWatchEnabled = false;
        state.pushLastError = data.last_error || 'La suscripción anterior ha caducado';
        clearCanaryAlerts();
        updateNotificationButton();
        return;
      }
      state.canaryWatchEnabled = Boolean(data.watch_canary_all);
      state.pushLastError = data.last_error || '';
      state.pushLastAt = data.last_push_at || null;
      state.pushPending = Number(data.pending_notifications || 0);
      state.pushWatches = Number(data.individual_watches || 0);
      state.watchedFlightIds = Array.isArray(data.watched_flight_ids)
        ? data.watched_flight_ids.map(Number).filter(Number.isFinite)
        : [];
      if (Array.isArray(data.watched_flights)) state.watchedFlightsBrief = data.watched_flights;
      if (Notification.permission === 'granted') await ensureServiceWorker(token);
      maybeTickWatchedFlights();
    } catch (error) {
      state.pushLastError = error.message || 'No se pudo comprobar Web Push';
    }
    updateNotificationButton();
  }

  async function toggleCanaryNotifications() {
    let token = localStorage.getItem('matrix.pushDevice');
    const enabled = !state.canaryWatchEnabled;
    if (enabled) token = await enablePushNotifications();
    if (!token) throw new Error('No existe una suscripción de avisos válida en este dispositivo.');
    els.enableNotifications.disabled = true;
    try {
      const response = await fetch('api/push.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'watch_canary_all',device_token:token,enabled})
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo cambiar la vigilancia de Canarias.');
      state.canaryWatchEnabled = Boolean(data.watch_canary_all);
      state.pushLastError = '';
      if (!state.canaryWatchEnabled) clearCanaryAlerts();
      if (enabled) await showPushConfirmation();
      updateNotificationButton();
    } finally {
      els.enableNotifications.disabled = false;
    }
  }

  const applePushLabel = data => {
    if (!data?.apple) return 'HTTP ' + (data?.http_status || '');
    const version = data.http_version;
    const http2 = (typeof version === 'string' && /^(2|3|h2)/i.test(String(version).trim()))
      || Number(version) >= 3;
    return http2 ? 'Apple HTTP/2' : 'Apple HTTP/1.1';
  };

  async function sendPushDrill() {
    const token = await enablePushNotifications();
    if (!token) throw new Error('No existe una suscripción Web Push válida en este dispositivo.');
    const registration = await ensureServiceWorker(token);
    const button = els.drillPushNotification;
    if (button) {
      button.disabled = true;
      button.textContent = 'Mostrando avisos…';
    }
    const samples = [
      ['Simulacro · Gran Canaria · VLGTEST', '1/6 · Despega · 20:15'],
      ['Simulacro · Gran Canaria · VLGTEST', '2/6 · Nueva ETA 22:32 · -8 min sobre programada'],
      ['Simulacro · Gran Canaria · VLGTEST', '3/6 · Asignación de cinta · B/7'],
      ['Simulacro · Gran Canaria · VLGTEST', '4/6 · Aviso 40 min · llegada prevista 22:32'],
      ['Simulacro · Gran Canaria · VLGTEST', '5/6 · Aterriza · 22:33'],
      ['Simulacro · Gran Canaria · VLGTEST', '6/6 · En la cinta · B/7']
    ];
    try {
      for (const [index, sample] of samples.entries()) {
        await registration.showNotification(sample[0], {
          body: sample[1],
          tag: 'matrix-drill-local-' + (index + 1),
          renotify: true,
          data: {url:'index.php'}
        });
        await new Promise(resolve => window.setTimeout(resolve, 280));
      }
      let serverNote = 'Los 6 avisos locales están en el centro de notificaciones del iPhone (baja el panel o bloquea la pantalla: con la app abierta iOS no enseña banner).';
      try {
        const response = await fetch('api/push.php', {
          method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
          body:JSON.stringify({action:'drill',device_token:token})
        });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || 'El aviso del servidor falló.');
        state.pushLastError = '';
        state.pushLastAt = data.sent_at || new Date().toISOString();
        const via = applePushLabel(data);
        serverNote += ` El 7º es del servidor (${via}): bloquea el iPhone ahora. Si no sale en 10 s, el canal Apple sigue fallando.`;
      } catch (serverError) {
        state.pushLastError = serverError.message || 'El aviso del servidor falló.';
        serverNote += ' El del servidor no salió: ' + state.pushLastError;
      }
      state.pushCheckNote = serverNote;
      if (els.pushStatusLine) {
        els.pushStatusLine.className = 'push-status-line push-status-ready';
        els.pushStatusLine.textContent = serverNote;
      }
      if (button) button.textContent = 'Simulacro enviado ✓';
      window.setTimeout(() => updateNotificationButton(), 12000);
    } catch (error) {
      state.pushLastError = error.message || 'No se pudo enviar el simulacro.';
      updateNotificationButton();
      throw error;
    } finally {
      if (button) button.disabled = false;
    }
  }

  async function sendForceCheck(flightId, button) {
    const token = await enablePushNotifications();
    if (!token) throw new Error('No existe una suscripción Web Push válida en este dispositivo.');
    if (button) {
      button.disabled = true;
      button.textContent = 'Comprobando…';
    }
    try {
      const response = await fetch('api/push.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'force_check',device_token:token,flight_id:Number(flightId)})
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'No se pudo forzar la comprobación.');
      state.pushLastError = '';
      state.pushLastAt = data.sent_at || new Date().toISOString();
      if (data.flightstats) {
        const id = Number(flightId);
        state.flights = state.flights.map(item => Number(item.id) === id ? {...item, flightstats: data.flightstats} : item);
        state.watchedFlightsBrief = state.watchedFlightsBrief.map(item => Number(item.id) === id ? {...item, flightstats: data.flightstats} : item);
        renderTrackedFocus();
      }
      state.pushCheckNote = (data.body || 'Comprobación enviada.') + ' Con la app abierta no hay globo: baja el centro de notificaciones o bloquea el teléfono.';
      if (els.pushStatusLine) {
        els.pushStatusLine.className = 'push-status-line push-status-ready';
        els.pushStatusLine.textContent = state.pushCheckNote;
      }
      if (button) button.textContent = 'Aviso enviado ✓';
      window.setTimeout(() => {
        if (button) {
          button.disabled = false;
          button.textContent = 'Comprobar ahora';
        }
      }, 8000);
    } catch (error) {
      if (button) {
        button.disabled = false;
        button.textContent = 'Comprobar ahora';
      }
      state.pushLastError = error.message || 'No se pudo forzar la comprobación.';
      updateNotificationButton();
      throw error;
    }
  }

  async function sendPushTest() {
    let token = localStorage.getItem('matrix.pushDevice');
    if (!token) token = await enablePushNotifications();
    if (!token) throw new Error('No existe una suscripción Web Push válida en este dispositivo.');
    // Rebind and persist the token before asking the server to wake the device.
    // This is especially important after iOS has restarted the installed PWA.
    await ensureServiceWorker(token);
    const button = els.testPushNotification;
    if (button) {
      button.disabled = true;
      button.textContent = 'Enviando…';
    }
    try {
      const registration = await ensureServiceWorker(token);
      await registration.showNotification('Matriz Sevilla · prueba local', {
        body: 'Este aviso lo ha mostrado el teléfono. Si no ves globo, baja el centro de notificaciones desde arriba.',
        tag: 'matrix-test-local',
        renotify: true,
        data: {url:'index.php'}
      });
      const response = await fetch('api/push.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'test',device_token:token})
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'El servidor no pudo enviar la prueba.');
      state.pushLastError = '';
      state.pushLastAt = data.sent_at || new Date().toISOString();
      state.pushCheckNote = `Prueba local enviada. Servidor: ${applePushLabel(data)}. Con la app abierta iOS no enseña globo: baja el centro de notificaciones o bloquea el teléfono. Si al bloquear no llega el del servidor, el canal Apple sigue fallando.`;
      if (els.pushStatusLine) {
        els.pushStatusLine.className = 'push-status-line push-status-ready';
        els.pushStatusLine.textContent = state.pushCheckNote;
      }
      if (button) button.textContent = 'Prueba enviada ✓';
      window.setTimeout(() => updateNotificationButton(), 8000);
    } catch (error) {
      state.pushLastError = error.message || 'No se pudo enviar la prueba Web Push.';
      updateNotificationButton();
      throw error;
    } finally {
      if (button) button.disabled = false;
    }
  }

  const urlBase64ToUint8Array = value => {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map(char => char.charCodeAt(0)));
  };

  const arrayBufferToBase64Url = value => {
    if (!value) return '';
    const bytes = new Uint8Array(value);
    let binary = '';
    bytes.forEach(byte => { binary += String.fromCharCode(byte); });
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  };

  const PUSH_CONFIG_CACHE = 'matrix-push-config-v1';
  const PUSH_TOKEN_CACHE_KEY = '/matrix-device-token';

  async function persistPushDeviceToken(token, registration = null) {
    if (!token) return;
    // Cache Storage is shared by the page and its service worker. Awaiting this
    // write prevents a real push from reaching a freshly restarted iPhone before
    // the asynchronous postMessage handler has persisted the device token.
    try {
      if ('caches' in window) {
        const cache = await caches.open(PUSH_CONFIG_CACHE);
        await cache.put(PUSH_TOKEN_CACHE_KEY, new Response(String(token), {
          headers:{'Content-Type':'text/plain; charset=utf-8'}
        }));
      }
    } catch (_) {
      // postMessage remains as a fallback for browsers that restrict Cache API.
    }
    const worker = registration?.active || navigator.serviceWorker.controller;
    worker?.postMessage({type:'matrix-device-token',token});
  }

  async function ensureServiceWorker(token = '') {
    let registration;
    try {
      registration = await navigator.serviceWorker.register('sw.js', {scope:'./', updateViaCache:'none'});
    } catch (_) {
      registration = await navigator.serviceWorker.register('sw.js', {scope:'./'});
    }
    try { await registration.update(); } catch (_) {}
    const ready = await navigator.serviceWorker.ready;
    if (token) await persistPushDeviceToken(token, ready);
    return ready;
  }

  function pushCapabilityError() {
    const appleMobile = /iPhone|iPad|iPod/i.test(navigator.userAgent);
    const installed = window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (!window.isSecureContext) {
      return 'La página no está en un contexto seguro. Ábrela directamente con https://www.ojito.top/public/ (no desde una vista previa ni un navegador interno).';
    }
    if (appleMobile && !installed) {
      return 'En iPhone/iPad los avisos solo funcionan desde la app instalada: abre esta página en Safari → Compartir → Añadir a pantalla de inicio; después abre Matriz SVQ desde su icono y pulsa de nuevo «Vigilar este vuelo».';
    }
    if (!('Notification' in window)) {
      return 'Este navegador no ofrece notificaciones web. Abre la página en Safari, Chrome, Edge o Firefox actualizado.';
    }
    if (!('serviceWorker' in navigator)) {
      return 'Este navegador o modo privado no permite el trabajador necesario para los avisos. Prueba una ventana normal del navegador.';
    }
    if (!('PushManager' in window)) {
      return 'Este navegador no admite Web Push. Si estás dentro de WhatsApp, Gmail u otra app, abre el enlace en Chrome/Edge/Firefox.';
    }
    return '';
  }

  async function enablePushNotifications() {
    const capabilityError = pushCapabilityError();
    if (capabilityError) throw new Error(capabilityError);
    if (Notification.permission === 'denied') {
      throw new Error('Los avisos están bloqueados para ojito.top. Actívalos en los ajustes del navegador o del sitio y vuelve a intentarlo.');
    }
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw new Error('No se concedió permiso para mostrar avisos.');
    const configResponse = await fetch('api/push.php?action=config', {cache:'no-store'});
    const config = await configResponse.json();
    if (!configResponse.ok || !config.public_key) throw new Error(config.error || 'No se pudo preparar Web Push. Aplica primero la actualización MySQL pendiente.');
    const previousDeviceToken = localStorage.getItem('matrix.pushDevice') || '';
    const registration = await ensureServiceWorker(previousDeviceToken);
    let subscription = await registration.pushManager.getSubscription();
    const currentApplicationKey = arrayBufferToBase64Url(subscription?.options?.applicationServerKey);
    if (subscription && currentApplicationKey && currentApplicationKey !== config.public_key) {
      await subscription.unsubscribe();
      subscription = null;
    }
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly:true,
        applicationServerKey:urlBase64ToUint8Array(config.public_key)
      });
    }
    const response = await fetch('api/push.php', {
      method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
      body:JSON.stringify({
        action:'subscribe',
        subscription:subscription.toJSON(),
        previous_device_token:previousDeviceToken
      })
    });
    const data = await response.json();
    if (!response.ok || !data.device_token) throw new Error(data.error || 'No se pudo registrar este móvil.');
    localStorage.setItem('matrix.notifications', 'on');
    localStorage.setItem('matrix.pushDevice', data.device_token);
    state.canaryWatchEnabled = Boolean(data.watch_canary_all);
    state.pushLastError = '';
    await persistPushDeviceToken(data.device_token, registration);
    return data.device_token;
  }

  async function showPushConfirmation() {
    try {
      const registration = await ensureServiceWorker(localStorage.getItem('matrix.pushDevice') || '');
      await registration.showNotification('Matriz Sevilla · avisos activados', {
        body:'Este aviso confirma que iOS permite notificaciones de la aplicación. Los siguientes llegarán aunque la web esté cerrada.',
        tag:'matrix-push-ready',
        data:{url:'index.php'}
      });
    } catch (error) {
      state.pushLastError = `iOS no mostró la prueba local: ${error.message || 'permiso no disponible'}`;
    }
  }

  const flightIsWatched = flightId => state.watchedFlightIds.some(id => Number(id) === Number(flightId));

  function setWatchButtonState(button, enabled) {
    if (!button) return;
    const compact = button.dataset.watchCompact === '1';
    button.dataset.watching = enabled ? '1' : '0';
    if (compact) {
      button.className = `canary-quick-watch ${enabled ? 'active' : ''}`;
      button.innerHTML = enabled ? '<span aria-hidden="true">🔔</span> Vigilando' : '<span aria-hidden="true">🔕</span> Vigilar';
      button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
      return;
    }
    button.className = enabled ? 'btn btn-warning w-100' : 'btn btn-outline-warning w-100';
    button.textContent = enabled ? '🔔 Vuelo vigilado · desactivar' : '🔔 Vigilar este vuelo';
  }

  function bindWatchButtons(root = document) {
    root.querySelectorAll('[data-watch-flight]').forEach(button => {
      if (button.dataset.watchBound === '1') return;
      button.dataset.watchBound = '1';
      button.addEventListener('click', event => {
        event.preventDefault();
        event.stopPropagation();
        watchFlight(button.dataset.watchFlight, button).catch(error => window.alert(error.message));
      });
      button.addEventListener('keydown', event => event.stopPropagation());
    });
  }

  async function watchFlight(flightId, button) {
    let token = localStorage.getItem('matrix.pushDevice');
    const enabled = button.dataset.watching !== '1';
    if (enabled) token = await enablePushNotifications();
    if (!token) throw new Error('No existe una suscripción de avisos válida en este dispositivo.');
    button.disabled = true;
    try {
      const response = await fetch('api/push.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'watch',device_token:token,flight_id:Number(flightId),enabled})
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo cambiar la vigilancia.');
      setWatchButtonState(button, enabled);
      state.pushWatches = Math.max(0, state.pushWatches + (enabled ? 1 : -1));
      if (enabled) {
        state.watchedFlightIds = [Number(flightId), ...state.watchedFlightIds.filter(id => Number(id) !== Number(flightId))];
        const flight = state.flights.find(item => Number(item.id) === Number(flightId));
        if (flight && !state.watchedFlightsBrief.some(item => Number(item.id) === Number(flightId))) {
          state.watchedFlightsBrief = [...state.watchedFlightsBrief, {
            id:Number(flight.id), flight_date:flight.flight_date, physical_flight:flight.physical_flight,
            origin_iata:flight.origin_iata, origin_name:flight.origin_name,
            scheduled_arrival:flight.scheduled_arrival, is_canary:Number(flight.is_canary),
            flightstats: flight.flightstats || null
          }];
        }
        pinFlight(flightId);
        await notifyWatchSequenceStart(flight);
        if (data.notified) {
          state.pushLastError = '';
          state.pushLastAt = data.sent_at || new Date().toISOString();
          if (els.pushStatusLine) {
            els.pushStatusLine.className = 'push-status-line push-status-ready';
            els.pushStatusLine.textContent = 'Vigilancia activa. Baja el centro de notificaciones o bloquea: ahora el de vigilancia, en unos minutos el itinerario.';
          }
        } else {
          await showPushConfirmation();
          if (data.push_error) {
            state.pushLastError = data.push_error;
            if (els.pushStatusLine) {
              els.pushStatusLine.className = 'push-status-line push-status-error';
              els.pushStatusLine.textContent = `Vigilancia guardada, pero el aviso del servidor falló: ${data.push_error}`;
            }
          }
        }
      } else {
        state.watchedFlightIds = state.watchedFlightIds.filter(id => Number(id) !== Number(flightId));
        state.watchedFlightsBrief = state.watchedFlightsBrief.filter(item => Number(item.id) !== Number(flightId));
        if (Number(state.pinnedFlightId) === Number(flightId)) pinFlight(0);
      }
      document.querySelectorAll(`[data-watch-flight="${Number(flightId)}"]`).forEach(other => {
        if (other !== button) setWatchButtonState(other, enabled);
      });
      renderTrackedFocus();
      renderCanaryWatch();
      updateNotificationButton();
    } finally { button.disabled = false; }
  }

  async function renderWatchButton(flightId) {
    const button = els.detailBody.querySelector('[data-watch-flight]');
    if (!button) return;
    const token = localStorage.getItem('matrix.pushDevice');
    if (!token) return;
    try {
      const response = await fetch(`api/push.php?action=status&device_token=${encodeURIComponent(token)}&flight_id=${encodeURIComponent(flightId)}`, {cache:'no-store'});
      const data = await response.json();
      if (response.ok) {
        state.pushWatches = Number(data.individual_watches || state.pushWatches || 0);
        state.pushPending = Number(data.pending_notifications || state.pushPending || 0);
        state.watchedFlightIds = Array.isArray(data.watched_flight_ids)
          ? data.watched_flight_ids.map(Number).filter(Number.isFinite)
          : state.watchedFlightIds;
        setWatchButtonState(button, Boolean(data.watching));
      }
      if (response.ok && Array.isArray(data.watched_flights)) state.watchedFlightsBrief = data.watched_flights;
      if (response.ok) renderTrackedFocus();
    } catch (_) {}
  }

  async function loadBoard(showSpinner = false) {
    if (state.loading) {
      state.queuedReload = true;
      return;
    }
    state.loading = true;
    if (showSpinner) setLoading(true);
    els.refresh.disabled = true;
    const requestedDate = els.date.value;
    try {
      const response = await fetch(`api/board.php?date=${encodeURIComponent(requestedDate)}`, {headers:{Accept:'application/json'}, cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No se pudo actualizar el tablero.');
      if (requestedDate !== els.date.value) {
        state.queuedReload = true;
        return;
      }
      const incoming = payload.flights || [];
      processCanaryAlerts(incoming, els.date.value);
      processWatchAlerts(incoming);
      state.flights = incoming;
      maybeTickWatchedFlights();
      applyBeltCoincidence(state.flights);
      const important = incoming.filter(f => Boolean(f.belt_attention));
      const daily = payload.belt_analysis_daily;
      if (els.mobileBeltWatchLabel) {
        const total = important.length + Number(daily?.changes_found || 0);
        els.mobileBeltWatchLabel.textContent = total
          ? `${total} aviso${total===1?'':'s'}`
          : 'Sin avisos';
        els.mobileBeltWatchLabel.closest('.mobile-command')?.classList.toggle('has-alert', total > 0);
      }
      if (els.dailyBeltAlert && (important.length || Number(daily?.changes_found || 0) > 0)) {
        const parts=[];
        if (important.length) parts.push(`${important.length} vuelo${important.length===1?'':'s'} requiere${important.length===1?'':'n'} atención de cinta ahora`);
        if (Number(daily?.changes_found || 0)>0) parts.push(`${Number(daily.changes_found)} cambio${Number(daily.changes_found)===1?'':'s'} de ayer analizado${Number(daily.changes_found)===1?'':'s'}`);
        els.dailyBeltAlert.className='belt-daily-alert';
        els.dailyBeltAlert.innerHTML=`<button type="button" data-bs-toggle="modal" data-bs-target="#beltDailyModal"><span><strong>⚠ Vigilancia de cintas</strong><small>${esc(parts.join(' · '))}</small></span><b>Ver</b></button>`;
        if (els.dailyBeltModalBody) {
          const current = important.length
            ? important.map(f => `<article class="belt-daily-flight"><div><strong>${esc(f.physical_flight)} · ${esc(f.origin_name)}</strong><span>${time(effectiveArrival(f))} · ${beltPosition(f)}</span></div><p>${esc(f.belt_attention_reason || 'Requiere revisión de cinta.')}</p><button class="btn btn-sm btn-outline-warning" type="button" data-open-flight="${Number(f.id)}" data-bs-dismiss="modal">Abrir vuelo</button></article>`).join('')
            : '<p class="text-secondary mb-0">No hay vuelos con atención de cinta activa en este momento.</p>';
          els.dailyBeltModalBody.innerHTML=`<p class="belt-daily-summary">${esc(parts.join(' · '))}</p><div class="belt-daily-flight-list">${current}</div>`;
        }
      } else if (els.dailyBeltAlert) {
        els.dailyBeltAlert.classList.add('d-none');
        if (els.dailyBeltModalBody) els.dailyBeltModalBody.innerHTML='<p class="text-secondary mb-0">No hay vuelos con atención de cinta ni cambios pendientes de revisión.</p>';
      }
      renderPredictionSummary(payload.prediction_summary || null);
      els.updated.textContent = `Actualizado ${time(payload.generated_at)} · ${payload.authority_note}`;
      els.connection.className = 'badge text-bg-success';
      els.connection.textContent = 'En directo';
      els.error.classList.add('d-none');
      render();
      if (state.detailFlightId) {
        refreshFocusLiveSections(state.flights.find(f => Number(f.id) === Number(state.detailFlightId)));
      }
      const linkedFlight = Number(deepLink.get('flight') || 0);
      if (linkedFlight && !state.deepLinkOpened && state.flights.some(f => Number(f.id) === linkedFlight)) {
        state.deepLinkOpened = true;
        openDetail(linkedFlight);
      }
      if (state.pendingFlightId && state.flights.some(f => Number(f.id) === Number(state.pendingFlightId))) {
        const pending = state.pendingFlightId;
        state.pendingFlightId = 0;
        state.pendingFlightDate = '';
        openDetail(pending);
      }
    } catch (error) {
      els.connection.className = 'badge text-bg-danger';
      els.connection.textContent = 'Sin conexión';
      els.error.textContent = error.message;
      els.error.classList.remove('d-none');
    } finally {
      state.loading = false;
      els.refresh.disabled = false;
      setLoading(false);
      if (state.queuedReload) {
        state.queuedReload = false;
        loadBoard(requestedDate !== els.date.value);
      }
    }
  }

  function renderPredictionSummary(summary) {
    if (!els.predictionSummary) return;
    const evaluated = Number(summary?.evaluated || 0);
    const canaryEvaluated = Number(summary?.canary_evaluated || 0);
    els.predictionSummary.classList.toggle('d-none', evaluated === 0 && !state.flights.some(f => f.predicted_belt));
    els.predictionAccuracy.textContent = evaluated ? `${Number(summary.accuracy_percentage || 0).toLocaleString('es-ES')}%` : 'Sin muestra';
    els.predictionCanaryAccuracy.textContent = canaryEvaluated ? `${Number(summary.canary_accuracy_percentage || 0).toLocaleString('es-ES')}%` : 'Sin muestra';
    els.predictionExactAccuracy.textContent = evaluated ? `${Number(summary.exact_accuracy_percentage || 0).toLocaleString('es-ES')}%` : 'Sin muestra';
    els.predictionEvaluated.textContent = evaluated.toLocaleString('es-ES');
  }

  function filtered() {
    const query = els.search.value.trim().toLowerCase();
    return state.flights.filter(f => (!els.hall.value || f.hall === els.hall.value)
      && (!els.canary.checked || Number(f.is_canary) === 1)
      && (!els.secondary.checked || Boolean(f.secondary_belt_state))
      && (!query || `${f.origin_name} ${f.origin_iata} ${f.physical_flight} ${f.codes}`.toLowerCase().includes(query)));
  }

  function dayGroups(flights) {
    const now = madridNow();
    const ordered = [...flights].sort((a,b) => (parseDate(effectiveArrival(a))?.getTime() || 0) - (parseDate(effectiveArrival(b))?.getTime() || 0));
    const isPast = flight => {
      if (els.date.value < now.operationalDate) return true;
      if (els.date.value > now.operationalDate) return false;
      const arrival = parseDate(effectiveArrival(flight))?.getTime();
      if (arrival === undefined || arrival >= now.nowMs) return false;
      if (!flightIsFinished(flight) && ['enroute','approach','ground','baggage'].includes(flowStage(flight))) return false;
      return true;
    };
    const past = ordered.filter(isPast);
    const remaining = ordered.filter(flight => !isPast(flight));
    const current = remaining.filter(flight => {
      const arrival = parseDate(effectiveArrival(flight))?.getTime();
      return arrival !== undefined && Math.abs(arrival - now.nowMs) <= 60 * 60 * 1000;
    });
    return {past, remaining, current};
  }

  function flightRowMarkup(f, currentIds, pastIds) {
    const beltClass = !f.belt ? 'standby' : (['7','8'].includes(String(f.belt)) ? 'red' : '');
    const interval = [f.previous_same_belt_minutes, f.next_same_belt_minutes].filter(v => v !== null).sort((a,b)=>a-b)[0];
    return `<tr class="flight-row ${Number(f.is_canary)===1?'canary':''} ${f.belt_attention?'belt-attention-row':''} ${currentIds.has(Number(f.id))?'current-flight':''} ${pastIds.has(Number(f.id))?'past-flight':''}" data-flight-id="${Number(f.id)}" data-effective-time="${parseDate(effectiveArrival(f))?.getTime() || ''}" tabindex="0">
      <td><span class="indicator" title="${esc(pressureText(f.pressure))}">${esc(f.indicator)}</span></td>
      <td><span class="time-primary">${time(f.scheduled_arrival)}</span></td>
      <td><span class="belt ${beltClass}">${esc(f.belt_label)}</span>${f.belt_attention?`<span class="belt-important" title="${esc(f.belt_attention_reason)}">IMPORTANTE · ${esc(f.belt_attention_reason)}</span>`:''}${secondaryBeltMarkup(f)}${predictionMarkup(f)}${beltAverageMarkup(f)}</td>
      <td><span class="origin-name">${esc(f.origin_name)}</span><span class="origin-code d-block">${esc(f.origin_iata)} · ${esc(f.traffic_class)}</span></td>
      <td><strong>${esc(f.physical_flight)}</strong><span class="meta d-block">${esc(f.codes || '')}</span>${laterNightRowHint(f)}</td>
      <td><strong>${time(effectiveArrival(f))}</strong><span class="meta d-block">${signed(f.deviation_minutes)}</span></td>
      <td><span class="pill pill-${esc(f.pressure.replace('_','-'))}">${interval===undefined?'Sin adyacente':`${interval} min`}</span></td>
      <td><strong>${hallLoadText(f.hall_load)}</strong><span class="meta d-block">${f.hall_flights_30m} vuelos · ${Number(f.hall_capacity_30m).toLocaleString('es-ES')} plazas</span></td>
      <td><span class="${f.source==='aena'?'source-aena':'source-other'}">${esc((f.source||'sin dato').toUpperCase())}</span><span class="meta d-block">${time(f.observed_at)}</span></td>
    </tr>`;
  }

  function groupHeader(label, count) {
    return `<tr class="flight-group-row"><td colspan="9"><span>${label}</span><b>${count}</b></td></tr>`;
  }

  function render() {
    const flights = filtered();
    const groups = dayGroups(flights);
    const currentIds = new Set(groups.current.map(f => Number(f.id)));
    const pastIds = new Set(groups.past.map(f => Number(f.id)));
    els.body.innerHTML = [
      groupHeader('Ya pasados', groups.past.length),
      ...groups.past.map(f => flightRowMarkup(f,currentIds,pastIds)),
      groupHeader('Activos y próximos', groups.remaining.length),
      ...groups.remaining.map(f => flightRowMarkup(f,currentIds,pastIds))
    ].join('');
    if (els.arrivalsToggleLabel) {
      const visibility = els.arrivalsCollapse?.classList.contains('show') ? 'visible' : 'plegado';
      els.arrivalsToggleLabel.textContent = `${flights.length} vuelos · ${groups.past.length} pasados · ${visibility}`;
    }
    els.table.classList.toggle('d-none', flights.length === 0);
    els.empty.classList.toggle('d-none', flights.length !== 0);
    metrics(flights);
    renderTrackedFocus();
    renderCanaryWatch();
    renderUpcomingLine();
    renderMap();
    renderAirportFlow();
    renderBeltChanges();
    bindFlightOpeners();
    maybeScrollToNow();
  }

  function bindFlightOpeners() {
    document.querySelectorAll('[data-flightaware-link]').forEach(link => {
      if (link.dataset.flightawareBound === '1') return;
      link.dataset.flightawareBound = '1';
      link.addEventListener('click', event => event.stopPropagation());
      link.addEventListener('keydown', event => event.stopPropagation());
    });
    document.querySelectorAll('.flight-row, .canary-card, .night-card, .upcoming-flight, .flow-flight, .scene-plane, .scene-belt.has-flight, .belt-change-card').forEach(item => {
      if (item.dataset.flightOpenerBound === '1') return;
      item.dataset.flightOpenerBound = '1';
      const open = () => {
        const date = item.dataset.gotoDate;
        if (date && date !== els.date.value) openNightFlight(item.dataset.flightId, date);
        else openDetail(item.dataset.flightId);
      };
      item.addEventListener('click', open);
      item.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          open();
        }
      });
    });
  }

  function pinFlight(flightId) {
    state.pinnedFlightId = Number(flightId) || 0;
    if (state.pinnedFlightId) localStorage.setItem('matrix.focusFlightId', String(state.pinnedFlightId));
    else localStorage.removeItem('matrix.focusFlightId');
  }

  // Vuelo fijado + vigilados, ordenados por hora de llegada. Los vigilados de
  // otros días se muestran con sus datos básicos y un acceso a su día.
  function trackedEntries() {
    const ids = [...new Set([state.pinnedFlightId, ...state.watchedFlightIds].map(Number).filter(Boolean))];
    return ids.map(id => {
      const flight = state.flights.find(item => Number(item.id) === id) || null;
      const brief = state.watchedFlightsBrief.find(item => Number(item.id) === id) || null;
      if (!flight && !brief) return null;
      const source = flight || brief;
      return {
        id, flight, brief,
        watched: flightIsWatched(id),
        date: flight ? els.date.value : (operationalDateOf(brief.scheduled_arrival) || brief.flight_date),
        arrival: parseDate(flight ? effectiveArrival(flight) : brief.scheduled_arrival)?.getTime() || 0,
        label: source.physical_flight, origin: source.origin_name
      };
    }).filter(Boolean).sort((a,b) => a.arrival - b.arrival);
  }

  function defaultTrackedEntry(entries) {
    const now = Date.now();
    const sameDay = entries.filter(entry => entry.flight);
    return sameDay.find(entry => entry.arrival >= now - 45 * 60_000) || sameDay[0]
      || entries.find(entry => entry.arrival >= now - 45 * 60_000) || entries[0];
  }

  function stepTrackedFocus(direction) {
    const entries = trackedEntries();
    if (entries.length < 2) return;
    const index = Math.max(0, entries.findIndex(entry => entry.id === Number(state.pinnedFlightId)));
    pinFlight(entries[(index + direction + entries.length) % entries.length].id);
    renderTrackedFocus();
  }

  function renderTrackedFocus() {
    if (!els.trackedFocusPanel || !els.trackedFocusBody) return;
    const entries = trackedEntries();
    if (!entries.length) {
      els.trackedFocusPanel.classList.add('d-none');
      els.trackedFocusBody.innerHTML = '';
      if (els.trackedFocusTabs) {
        els.trackedFocusTabs.classList.add('d-none');
        els.trackedFocusTabs.innerHTML = '';
      }
      return;
    }
    let current = entries.find(entry => entry.id === Number(state.pinnedFlightId));
    if (!current) {
      current = defaultTrackedEntry(entries);
      state.pinnedFlightId = current.id;
    }
    const index = entries.indexOf(current);
    const multiple = entries.length > 1;
    if (els.trackedFocusTitle) {
      els.trackedFocusTitle.textContent = multiple ? `Vuelos en seguimiento · ${entries.length}` : 'Vuelo en seguimiento';
    }
    els.trackedFocusNav?.classList.toggle('d-none', !multiple);
    if (els.focusCounter) els.focusCounter.textContent = `${index + 1} de ${entries.length}`;
    if (els.trackedFocusActions) {
      els.trackedFocusActions.innerHTML = current.watched
        ? `<button class="btn btn-sm btn-outline-info" type="button" data-force-check="${current.id}">Comprobar ahora</button>
           <button class="btn btn-sm btn-warning" type="button" data-watch-flight="${current.id}" data-watching="1" data-watch-compact="1">🔔 Vigilando</button>`
        : `<button class="btn btn-sm btn-outline-light" type="button" data-focus-unpin="${current.id}">Quitar</button>`;
      bindWatchButtons(els.trackedFocusActions);
    }
    if (els.trackedFocusTabs) {
      els.trackedFocusTabs.classList.toggle('d-none', !multiple);
      els.trackedFocusTabs.innerHTML = entries.map(entry => {
        const active = entry.id === current.id;
        const otherDay = entry.date !== els.date.value;
        const day = otherDay ? `${relativeDayLabel(entry.date)} · ` : '';
        return `<button type="button" class="tracked-focus-tab ${active?'active':''} ${otherDay?'other-day':''}" data-focus-tab="${entry.id}" aria-pressed="${active?'true':'false'}">
          <strong>${entry.watched?'🔔 ':''}${esc(entry.label)}</strong><span>${esc(day)}${time(entry.arrival ? new Date(entry.arrival) : null)} · ${esc(entry.origin)}</span>
        </button>`;
      }).join('');
      const activeTab = els.trackedFocusTabs.querySelector('.tracked-focus-tab.active');
      if (activeTab) {
        els.trackedFocusTabs.scrollLeft = activeTab.offsetLeft - (els.trackedFocusTabs.clientWidth - activeTab.offsetWidth) / 2;
      }
    }
    els.trackedFocusPanel.classList.remove('d-none');
    if (!current.flight) {
      els.trackedFocusBody.innerHTML = `<div class="tracked-other-day">
        <div><small>${esc(relativeDayLabel(current.date))} · ${esc(dayName(current.date))}</small>
        <strong>${esc(current.label)} · ${esc(current.origin)}</strong>
        <span>Programado a las ${time(current.brief.scheduled_arrival)}. Estás viendo ${esc(dayName(els.date.value))}.</span>
        ${flightStatsWatchMarkup(current.brief)}</div>
        <button class="btn btn-info" type="button" data-goto-date="${esc(current.date)}">Ver ${esc(relativeDayLabel(current.date).toLowerCase())}</button>
      </div>`;
      return;
    }
    const flight = current.flight;
    els.trackedFocusBody.innerHTML = `<div class="tracked-focus-layout">
      <aside class="tracked-focus-summary">
        ${focusHeroMarkup(flight, Number(state.detailFlightId) === Number(flight.id) ? state.focusStatus : null, true)}
        ${dailyRedUsageMarkup(flight)}
      </aside>
      <div class="tracked-focus-belts">${focusWindowMarkup(flight)}</div>
      </div>`;
    bindFocusRefreshButtons(els.trackedFocusBody);
  }

  async function loadCanaryWeek() {
    try {
      const today = madridNow().operationalDate;
      const response = await fetch(`api/canary-week.php?from=${encodeURIComponent(today)}&days=7`, {headers:{Accept:'application/json'}, cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'Sin semana');
      state.canaryWeek = payload;
    } catch (_) {
      // La franja diaria sigue funcionando con los datos del tablero.
    }
    renderCanaryWatch();
  }

  const isNightFlight = flight => {
    const arrival = parseDate(flight.expected_arrival || effectiveArrival(flight) || flight.scheduled_arrival);
    if (!arrival) return false;
    const hour = arrival.getHours();
    return hour >= readNightFromHour() || hour < 2;
  };
  const canaryOriginMatches = flight => !state.canaryOrigin || String(flight.origin_iata) === state.canaryOrigin;
  const canaryMatches = flight => canaryOriginMatches(flight) && (!state.canaryNight || isNightFlight(flight));
  const canaryWeekFlights = () => Array.isArray(state.canaryWeek?.flights) ? state.canaryWeek.flights : [];
  const canaryOriginName = iata => state.canaryWeek?.origins?.find(item => item.iata === iata)?.name
    || state.flights.find(f => f.origin_iata === iata)?.origin_name
    || iata;
  const PAST_23_MINUTES = 23 * 60;
  const arrivalSource = flight => effectiveArrival(flight) || flight.expected_arrival || flight.scheduled_arrival;
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
  const flightsForOperationalDay = date => {
    const byId = new Map();
    const add = flight => {
      if (!flight) return;
      const day = flight.operational_date || operationalDateOf(arrivalSource(flight) || flight.scheduled_arrival);
      if (day !== date) return;
      byId.set(Number(flight.id), flight);
    };
    (state.flights || []).forEach(add);
    (state.nightContext || []).forEach(add);
    canaryWeekFlights().forEach(add);
    return [...byId.values()];
  };
  const laterNightFlights = flight => {
    const date = flight.operational_date || operationalDateOf(arrivalSource(flight) || flight.scheduled_arrival);
    const key = arrivalOrderKey(flight);
    if (!date || !key || !isPast23Flight(flight)) return [];
    return flightsForOperationalDay(date)
      .filter(item => Number(item.id) !== Number(flight.id) && arrivalOrderKey(item) > key)
      .sort((a, b) => arrivalOrderKey(a).localeCompare(arrivalOrderKey(b)));
  };
  const laterNightBelt = flight => {
    if (flight.source === 'aena' && flight.belt) return beltPosition(flight);
    if (flight.predicted_belt) {
      return `${flight.predicted_hall || (Number(flight.predicted_belt) >= 7 ? 'B' : 'A')}/${flight.predicted_belt}`;
    }
    return '';
  };
  function laterNightMarkup(flight) {
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
  }
  function laterNightRowHint(flight) {
    if (!isPast23Flight(flight)) return '';
    const later = laterNightFlights(flight);
    if (!later.length) return '<span class="later-night-hint last">Último de la noche</span>';
    return `<span class="later-night-hint">${later.length} después: ${later.map(item => `${time(arrivalSource(item))} ${esc(item.physical_flight || '')}`).join(' · ')}</span>`;
  }

  function hydrateCanaryFlight(item) {
    const id = Number(item.id);
    const board = state.flights.find(f => Number(f.id) === id);
    if (board) {
      return {...board, operational_date: item.operational_date || operationalDateOf(board.scheduled_arrival) || els.date.value};
    }
    const night = (state.nightContext || []).find(f => Number(f.id) === id);
    if (night) return night;
    const official = item.official_belt ?? item.belt ?? null;
    return {
      ...item,
      expected_arrival: item.effective_arrival || item.scheduled_arrival,
      belt: official,
      hall: official == null ? null : (Number(official) >= 7 ? 'B' : 'A'),
      source: official ? 'aena' : null,
      predicted_hall: item.predicted_hall || (Number(item.predicted_belt) >= 7 ? 'B' : 'A'),
      is_canary: 1,
      indicator: item.indicator || '',
      canary_thin: true
    };
  }

  function canaryFlightsForScope() {
    if (state.canaryWeekScope) {
      const byId = new Map();
      canaryWeekFlights().map(hydrateCanaryFlight).filter(canaryMatches).forEach(flight => byId.set(Number(flight.id), flight));
      return [...byId.values()].sort((a, b) =>
        String(a.operational_date || '').localeCompare(String(b.operational_date || ''))
        || String(a.expected_arrival || a.scheduled_arrival).localeCompare(String(b.expected_arrival || b.scheduled_arrival)));
    }
    return state.flights.filter(f => Number(f.is_canary) === 1 && canaryMatches(f));
  }

  function renderCanaryWeek() {
    if (!els.canaryWeek || !els.canaryOrigins) return;
    const today = madridNow().operationalDate;
    const week = state.canaryWeek;
    const days = Array.isArray(week?.days) && week.days.length
      ? week.days
      : Array.from({length:7}, (_,index) => ({date:shiftDate(today, index)}));
    const weekFlights = canaryWeekFlights();
    const inScope = item => (state.canaryWeekScope || item.operational_date === els.date.value)
      && (!state.canaryNight || isNightFlight(item));
    els.canaryWeek.innerHTML = days.map(day => {
      const flights = weekFlights.filter(item => item.operational_date === day.date && canaryMatches(item));
      const red = flights.filter(item => Number(item.official_belt) >= 7 || (!item.official_belt && Number(item.predicted_belt) >= 7)).length;
      const diff = dayDifference(today, day.date);
      const top = diff === 0 ? 'Hoy' : diff === 1 ? 'Mañana' : dayName(day.date, {weekday:'short'});
      return `<button type="button" class="canary-day ${day.date===els.date.value?'active':''} ${flights.length?'':'empty'}" data-canary-date="${esc(day.date)}" aria-pressed="${day.date===els.date.value?'true':'false'}">
        <small>${esc(top)}</small><strong>${esc(dayName(day.date, {day:'numeric'}))}</strong>
        <span>${week ? `${flights.length} vuelo${flights.length===1?'':'s'}` : '—'}</span>${red ? `<i title="Vuelos en cinta 7/8 confirmados o predichos">${red} en 7/8</i>` : ''}
      </button>`;
    }).join('');
    const origins = new Map();
    [...weekFlights, ...state.flights.filter(f => Number(f.is_canary) === 1)].forEach(item => {
      if (item.origin_iata && !origins.has(item.origin_iata)) origins.set(item.origin_iata, item.origin_name || item.origin_iata);
    });
    if (state.canaryOrigin && !origins.has(state.canaryOrigin)) origins.set(state.canaryOrigin, canaryOriginName(state.canaryOrigin));
    const countFor = predicate => weekFlights.filter(item => inScope(item) && predicate(item)).length;
    const nightCount = countFor(item => isNightFlight(item) && canaryOriginMatches(item));
    const weekCount = weekFlights.filter(item => canaryMatches(item)).length;
    const modeChips = [
      `<button type="button" class="canary-origin mode night ${state.canaryNight?'active':''}" data-canary-filter="night" aria-pressed="${state.canaryNight?'true':'false'}">Noche${week ? ` <b>${nightCount}</b>` : ''}</button>`,
      `<button type="button" class="canary-origin mode week ${state.canaryWeekScope?'active':''}" data-canary-filter="week" aria-pressed="${state.canaryWeekScope?'true':'false'}">Semana${week ? ` <b>${weekCount}</b>` : ''}</button>`
    ];
    const originChips = [['', 'Todas'], ...[...origins.entries()].sort((a,b) => a[1].localeCompare(b[1], 'es'))].map(([iata, name]) => {
      const count = countFor(item => !iata || item.origin_iata === iata);
      return `<button type="button" class="canary-origin ${state.canaryOrigin===iata?'active':''}" data-canary-origin="${esc(iata)}" aria-pressed="${state.canaryOrigin===iata?'true':'false'}">${esc(name)}${week ? ` <b>${count}</b>` : ''}</button>`;
    });
    els.canaryOrigins.innerHTML = [...originChips.slice(0, 1), ...modeChips, ...originChips.slice(1)].join('');
  }

  function canaryCardMarkup(f, now) {
    const onBoard = state.flights.some(item => Number(item.id) === Number(f.id));
    const date = f.operational_date || operationalDateOf(f.scheduled_arrival) || els.date.value;
    const otherDay = date && date !== els.date.value;
    const arrival = parseDate(effectiveArrival(f) || f.expected_arrival || f.scheduled_arrival);
    const past = !otherDay && date === now.operationalDate && arrival && arrival.getTime() < now.nowMs - 45 * 60 * 1000;
    const confirmedBelt = f.source === 'aena' && f.belt ? beltPosition(f)
      : (f.official_belt ? `${Number(f.official_belt) >= 7 ? 'B' : 'A'}/${f.official_belt}` : null);
    const predictedBelt = f.predicted_belt ? `${f.predicted_hall || (Number(f.predicted_belt) >= 7 ? 'B' : 'A')}/${f.predicted_belt}` : null;
    const beltClass = ['7','8'].includes(String(f.belt || f.official_belt)) || ['7','8'].includes(String(f.predicted_belt)) || f.belt_attention ? 'danger' : '';
    const sharedCodes = onBoard ? codeshareCodes(f) : [];
    const watched = flightIsWatched(f.id);
    const night = isNightFlight(f);
    const goto = `data-goto-date="${esc(date || '')}"`;
    if (!onBoard) {
      return `<article class="canary-card ${past?'past':''} ${beltClass} ${night?'is-night':''}" data-flight-id="${Number(f.id)}" ${goto} tabindex="0">
        <div class="d-flex justify-content-between align-items-start gap-2"><strong>${esc(f.origin_name)}</strong><span>${night ? '☾ ' : ''}${time(arrival)}</span></div>
        ${coincidenceBadge(f)}
        <div class="canary-flight-code">${esc(f.physical_flight)}</div>
        <div class="canary-belts">
          <div class="canary-belt-line canary-belt-predicted"><span>Predicción</span><b>${esc(predictedBelt || 'sin predicción')}</b></div>
          <div class="canary-belt-line canary-belt-confirmed ${confirmedBelt?'':'pending'}"><span>Aena</span><b>${esc(confirmedBelt || 'pendiente')}</b></div>
        </div>
        ${beltOccupancyMarkup(f)}
        ${laterNightMarkup(f)}
        <div class="canary-card-actions">
          <button class="canary-quick-watch ${watched?'active':''}" type="button" data-watch-flight="${Number(f.id)}" data-watch-compact="1" data-watching="${watched?'1':'0'}" aria-pressed="${watched?'true':'false'}"><span aria-hidden="true">${watched?'🔔':'🔕'}</span> ${watched?'Vigilando':'Vigilar'}</button>
          ${otherDay ? `<button class="btn btn-sm btn-outline-info" type="button" data-goto-date="${esc(date)}">Ver ${esc(relativeDayLabel(date).toLowerCase())}</button>` : (flightAwareLink(f, true) || '<span></span>')}
        </div>
      </article>`;
    }
    return `<article class="canary-card ${past?'past':''} ${beltClass} ${night?'is-night':''}" data-flight-id="${Number(f.id)}" ${goto} tabindex="0">
      <div class="d-flex justify-content-between align-items-start gap-2"><strong>${esc(f.origin_name)}</strong><span>${night ? '☾ ' : ''}${esc(f.indicator)}</span></div>
      ${coincidenceBadge(f)}
      ${timingMarkup(f, 'canary-card-timing')}
      <div class="canary-flight-code">${esc(f.physical_flight)}</div>
      ${sharedCodes.length ? `<div class="canary-codeshare"><span>Compartido:</span> <strong>${sharedCodes.map(esc).join(' · ')}</strong></div>` : ''}
      <div class="canary-belts">
        <div class="canary-belt-line canary-belt-predicted"><span>Predicción matriz</span><b>${esc(predictedBelt || 'sin predicción')}</b><small>${predictedBelt ? `${Number(f.prediction_score || 0)}% · ${esc(f.prediction_confidence || 'baja')}` : 'histórico insuficiente'}</small></div>
        <div class="canary-belt-line canary-belt-confirmed ${confirmedBelt?'':'pending'}"><span>Confirmada Aena</span><b>${esc(confirmedBelt || 'pendiente')}</b><small>${confirmedBelt ? 'dato oficial prevalente' : 'todavía no publicada'}</small></div>
      </div>
      ${beltOccupancyMarkup(f)}
      ${f.belt_attention ? `<div class="canary-important"><b>IMPORTANTE</b><span>${esc(f.belt_attention_reason)}</span></div>` : ''}
      ${f.secondary_belt_state === 'candidate' ? `<small class="canary-proposal">● ${esc((f.secondary_belt_source||'API').toUpperCase())}: ${beltPosition({hall:f.secondary_hall,belt:f.secondary_belt})}</small>` : ''}
      ${laterNightMarkup(f)}
      <div class="canary-card-actions">
        <button class="canary-quick-watch ${watched?'active':''}" type="button" data-watch-flight="${Number(f.id)}" data-watch-compact="1" data-watching="${watched?'1':'0'}" aria-pressed="${watched?'true':'false'}"><span aria-hidden="true">${watched?'🔔':'🔕'}</span> ${watched?'Vigilando':'Vigilar'}</button>
        ${otherDay ? `<button class="btn btn-sm btn-outline-info" type="button" data-goto-date="${esc(date)}">Ver ${esc(relativeDayLabel(date).toLowerCase())}</button>` : flightAwareLink(f, true)}
      </div>
    </article>`;
  }

  function renderCanaryWatch() {
    if (!els.canaryWatch) return;
    const flights = canaryFlightsForScope();
    const now = madridNow();
    els.canaryWatch.classList.toggle('week-scope', state.canaryWeekScope);
    if (!flights.length) {
      const originName = canaryOriginName(state.canaryOrigin);
      const nightHint = state.canaryNight ? ` nocturnos (${nightRangeText()})` : '';
      const scopeHint = state.canaryWeekScope ? 'en la semana' : relativeDayLabel(els.date.value).toLowerCase();
      els.canaryWatch.innerHTML = `<div class="canary-empty">${state.canaryOrigin || state.canaryNight
        ? `No hay vuelos${nightHint}${state.canaryOrigin ? ` desde ${esc(originName)}` : ''} ${esc(scopeHint)}. Prueba otro filtro.`
        : `No hay vuelos canarios ${esc(scopeHint)}.`}</div>`;
      renderCanaryWeek();
      buildCanaryPrintSheet([]);
      return;
    }
    if (state.canaryWeekScope) {
      const days = [];
      flights.forEach(flight => {
        const date = flight.operational_date || operationalDateOf(flight.scheduled_arrival);
        if (!date) return;
        let group = days.find(item => item.date === date);
        if (!group) {
          group = {date, flights: []};
          days.push(group);
        }
        group.flights.push(flight);
      });
      days.sort((a, b) => a.date.localeCompare(b.date));
      els.canaryWatch.innerHTML = days.map(group => {
        const diff = dayDifference(now.operationalDate, group.date);
        const label = diff === 0 ? 'Hoy' : diff === 1 ? 'Mañana' : dayName(group.date, {weekday:'long', day:'numeric', month:'short'});
        return `<section class="night-day" id="canary-day-${esc(group.date)}">
          <header class="night-day-header">
            <div><small>${esc(label)}</small><strong>${esc(dayName(group.date, {weekday:'short', day:'numeric'}))}</strong></div>
            <span>${group.flights.length} vuelo${group.flights.length===1?'':'s'}</span>
          </header>
          <div class="night-day-strip">${group.flights.map(flight => canaryCardMarkup(flight, now)).join('')}</div>
        </section>`;
      }).join('');
    } else {
      els.canaryWatch.innerHTML = flights.map(flight => canaryCardMarkup(flight, now)).join('');
    }
    bindWatchButtons(els.canaryWatch);
    bindFlightOpeners();
    renderCanaryWeek();
    buildCanaryPrintSheet(flights);
    if (state.canaryScrollDate) {
      const node = document.getElementById(`canary-day-${state.canaryScrollDate}`);
      state.canaryScrollDate = '';
      node?.scrollIntoView({behavior:'smooth', block:'nearest'});
    }
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

  function canaryPrintFilterLabel() {
    const parts = [];
    if (state.canaryWeekScope) {
      const days = Array.isArray(state.canaryWeek?.days) ? state.canaryWeek.days : [];
      const first = days[0]?.date;
      const last = days[days.length - 1]?.date;
      parts.push(first && last
        ? `Semana ${dayName(first, {day:'numeric', month:'short'})} – ${dayName(last, {day:'numeric', month:'short', year:'numeric'})}`
        : 'Semana');
    } else {
      parts.push(dayName(els.date.value, {weekday:'long', day:'numeric', month:'long'}));
    }
    parts.push(state.canaryNight ? `Solo noche ${nightRangeText()}` : 'Todo el día');
    parts.push(state.canaryOrigin ? canaryOriginName(state.canaryOrigin) : 'Todos los orígenes');
    return parts.join(' · ');
  }

  function enrichCanaryPrintFlights(flights) {
    flights.forEach(flight => {
      const board = state.flights.find(item => Number(item.id) === Number(flight.id));
      if (board?.belt_coincidence) flight.belt_coincidence = board.belt_coincidence;
    });
  }

  function canaryPrintOccupancyMarkup(flight) {
    const model = flight.belt_coincidence;
    if (!model) return '<p class="canary-print-around-empty">Todavía no hay ocupación calculada para este vuelo.</p>';
    const green = new Set((model.green_belts || []).map(Number));
    const red = new Set((model.red_belts || []).map(Number));
    const entries = Array.isArray(model.flights) ? model.flights : [];
    const cells = Array.from({length:8}, (_, index) => {
      const belt = index + 1;
      const flights = rankAroundEntries(entries.filter(item => Number(item.belt) === belt));
      const counting = flights.filter(item => item.counts);
      const nearby = flights.filter(item => !item.counts);
      const active = green.has(belt) || red.has(belt) || counting.length > 0;
      const shown = preferOccupancyShown(counting, nearby);
      const label = active ? `${relativeMinuteCompact(shown?.offset_minutes)}′` : nearby.length ? 'cerca' : 'libre';
      return `<span class="print-belt ${active ? 'occupied' : ''} ${!active && nearby.length ? 'nearby' : ''} ${belt >= 7 ? 'red' : ''} ${shown && isCanaryEntry(shown) ? 'is-canary' : ''}"><b>${belt}</b><small>${esc(label)}</small></span>`;
    }).join('');
    const rating = coincidenceRatings[model.rating] || coincidenceRatings.bajo;
    return `<div class="canary-print-occupancy coincidence-${esc(model.rating)} ${red.size ? 'has-red' : ''}">
      <header><span>${esc(rating.label)}</span><strong>${green.size}V · ${red.size}R</strong><b>${esc(time(model.target_time))}</b></header>
      <div class="print-belts">${cells}</div>
      <p>Verdes ±${Number(model.green_window_minutes)} min · Rojas ±${Number(model.red_window_minutes)} min · Nube ±${Number(model.span_window_minutes || 30)} min</p>
    </div>`;
  }

  function canaryPrintAroundMarkup(flight) {
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
      return `<li class="${item.counts ? 'counts' : 'near'} ${item.kind === 'red' ? 'red' : ''} ${canary ? 'is-canary' : ''}">
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

  function canaryPrintCardMarkup(flight) {
    const predicted = canaryPrintBelt(flight, 'predicted');
    const official = canaryPrintBelt(flight, 'aena');
    const red = canaryPrintIsRed(flight);
    const night = isNightFlight(flight);
    const model = flight.belt_coincidence;
    const rating = model ? (coincidenceRatings[model.rating] || coincidenceRatings.bajo) : null;
    const note = model ? flightScore10(flight) : null;
    return `<article class="canary-print-card ${red ? 'is-red' : ''} ${night ? 'is-night' : ''} ${model ? `coincidence-${esc(model.rating)}` : ''}">
      <header>
        <div class="canary-print-id">
          <strong>${esc(time(effectiveArrival(flight) || flight.expected_arrival || flight.scheduled_arrival))}</strong>
          <b>${esc(flight.origin_name || flight.origin_iata || '')}</b>
          <span>${esc(flight.physical_flight || '')}</span>
          ${night ? '<em>Noche</em>' : ''}
        </div>
        <div class="canary-print-assign">
          <span>Predicción <b>${esc(predicted)}</b></span>
          <span>Aena <b>${esc(official)}</b></span>
          ${note != null ? `<span class="score">${note}/10 · ${esc(rating.short)} · ${Number(model.green_count)}V ${Number(model.red_count)}R</span>` : ''}
        </div>
      </header>
      ${flight.scheduled_arrival ? `<p class="canary-print-timing">Previsto ${esc(time(flight.scheduled_arrival))} · Llegada ${esc(time(effectiveArrival(flight) || flight.expected_arrival || flight.scheduled_arrival))}</p>` : ''}
      ${canaryPrintOccupancyMarkup(flight)}
      ${canaryPrintAroundMarkup(flight)}
      ${flight.belt_attention ? `<p class="canary-print-alert">${esc(flight.belt_attention_reason || 'Atención de cinta')}</p>` : ''}
      ${laterNightMarkup(flight)}
    </article>`;
  }

  function buildCanaryPrintSheet(flights = canaryFlightsForScope()) {
    if (!els.canaryPrintSheet) return;
    const printedAt = new Intl.DateTimeFormat('es-ES', {
      timeZone: 'Europe/Madrid', weekday:'long', day:'numeric', month:'long',
      hour:'2-digit', minute:'2-digit'
    }).format(new Date());
    if (!flights.length) {
      els.canaryPrintSheet.innerHTML = `<div class="canary-print">
        <header>
          <p class="canary-print-kicker">Matriz operativa de cintas · Sevilla</p>
          <h1>Canarias · cintas</h1>
          <p class="canary-print-meta">${esc(canaryPrintFilterLabel())}</p>
        </header>
        <p class="canary-print-empty">No hay vuelos canarios con los filtros actuales.</p>
      </div>`;
      return;
    }
    const groups = [];
    flights.forEach(flight => {
      const date = flight.operational_date || operationalDateOf(flight.scheduled_arrival) || els.date.value;
      let group = groups.find(item => item.date === date);
      if (!group) {
        group = {date, flights: []};
        groups.push(group);
      }
      group.flights.push(flight);
    });
    groups.sort((a, b) => String(a.date).localeCompare(String(b.date)));
    const sections = groups.map(group => {
      const label = dayName(group.date, {weekday:'long', day:'numeric', month:'short'});
      return `<section class="canary-print-day">
        <h2>${esc(label)} <span>${group.flights.length} vuelo${group.flights.length === 1 ? '' : 's'}</span></h2>
        ${group.flights.map(flight => canaryPrintCardMarkup(flight)).join('')}
      </section>`;
    }).join('');
    els.canaryPrintSheet.innerHTML = `<div class="canary-print">
      <header>
        <p class="canary-print-kicker">Matriz operativa de cintas · Sevilla</p>
        <h1>Canarias · cintas</h1>
        <p class="canary-print-meta">${esc(canaryPrintFilterLabel())} · ${flights.length} vuelo${flights.length === 1 ? '' : 's'}</p>
        <p class="canary-print-when">Impreso el ${esc(printedAt)}</p>
      </header>
      ${sections}
      <footer>Ocupación y vuelos de alrededor como en las tarjetas. Aena prevalece. Cintas 7 y 8 marcadas. Día operativo 02:00–01:59.</footer>
    </div>`;
  }

  async function printCanaryWeek() {
    const flights = canaryFlightsForScope();
    if (!flights.length) {
      buildCanaryPrintSheet([]);
      window.alert('No hay vuelos canarios con los filtros actuales.');
      return;
    }
    const button = els.printCanaryWeek;
    const previousLabel = button?.textContent;
    if (button) {
      button.disabled = true;
      button.textContent = 'Preparando folio…';
    }
    try {
      if (flights.some(flight => !flight.belt_coincidence)) await loadCanaryWeek();
      const ready = canaryFlightsForScope();
      enrichCanaryPrintFlights(ready);
      buildCanaryPrintSheet(ready);
      const previous = document.title;
      document.title = 'Canarias · cintas · Sevilla';
      const restore = () => { document.title = previous; };
      window.addEventListener('afterprint', restore, {once: true});
      window.print();
    } catch (error) {
      window.alert(error.message || 'No se pudo preparar el folio.');
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = previousLabel || 'Imprimir';
      }
    }
  }
  const nightRankValue = flight => flightScore10(flight);

  async function loadNightWeek() {
    try {
      const today = madridNow().operationalDate;
      const nightFrom = readNightFromHour();
      const response = await fetch(`api/night-week.php?from=${encodeURIComponent(today)}&days=7&night_from=${nightFrom}`, {headers:{Accept:'application/json'}, cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'Sin nocturnos');
      state.nightWeek = payload;
      state.nightContext = Array.isArray(payload.context) ? payload.context : (payload.flights || []);
      applyBeltCoincidence(state.nightContext);
    } catch (_) {
      if (!state.nightWeek) state.nightWeek = {flights:[], days:[]};
    }
    renderNightWeek();
    if (state.canaryWeekScope || state.canaryNight) renderCanaryWatch();
  }

  const nightOriginMatches = flight => !state.nightOrigin || String(flight.origin_iata) === state.nightOrigin;
  const nightCanaryFlights = () => {
    const context = state.nightContext.length ? state.nightContext : (state.nightWeek?.flights || []);
    return context.filter(f => isNightFlight(f) && Number(f.is_canary) === 1);
  };

  function nightCardMarkup(f, today, bestOfDay) {
    const arrival = parseDate(f.expected_arrival || effectiveArrival(f));
    const confirmedBelt = f.source === 'aena' && f.belt ? beltPosition(f) : null;
    const predictedBelt = f.predicted_belt ? `${f.predicted_hall || (Number(f.predicted_belt) >= 7 ? 'B' : 'A')}/${f.predicted_belt}` : null;
    const note = flightScore10(f);
    const greens = Number(f.belt_coincidence?.green_count || 0);
    const reds = Number(f.belt_coincidence?.red_count || 0);
    const top = note === bestOfDay && bestOfDay >= 7;
    const otherDay = f.operational_date && f.operational_date !== today;
    return `<article class="canary-card night-card canary score-${note} ${top?'best':''}" data-flight-id="${Number(f.id)}" data-goto-date="${esc(f.operational_date || '')}" tabindex="0">
      <div class="night-card-kicker"><small>${esc(f.origin_iata || '')}</small>${top ? '<b>Mejor de la noche</b>' : ''}</div>
      <div class="night-score" aria-label="Nota ${note} de 10 · ${greens} verdes · ${reds} rojas">
        <div class="night-score-note"><strong>${note}</strong><small>/10</small></div>
        <span class="night-score-belts"><i class="green">${greens} verde${greens===1?'':'s'}</i><i class="red">${reds} roja${reds===1?'':'s'}</i></span>
      </div>
      <div class="d-flex justify-content-between align-items-start gap-2"><strong>${esc(f.origin_name)}</strong><span>${time(arrival)}</span></div>
      ${coincidenceBadge(f)}
      <div class="canary-flight-code">${esc(f.physical_flight)}</div>
      <div class="canary-belts">
        <div class="canary-belt-line canary-belt-predicted"><span>Predicción</span><b>${esc(predictedBelt || 'sin predicción')}</b></div>
        <div class="canary-belt-line canary-belt-confirmed ${confirmedBelt?'':'pending'}"><span>Aena</span><b>${esc(confirmedBelt || 'pendiente')}</b></div>
      </div>
      ${beltOccupancyMarkup(f)}
      ${laterNightMarkup(f)}
      ${otherDay ? `<button class="btn btn-sm btn-outline-info w-100 mt-2" type="button" data-goto-date="${esc(f.operational_date)}">Ver ${esc(relativeDayLabel(f.operational_date).toLowerCase())}</button>` : ''}
    </article>`;
  }

  function renderNightWeek() {
    if (!els.nightWeekStrip) return;
    const today = madridNow().operationalDate;
    const all = nightCanaryFlights();
    const weekDates = Array.isArray(state.nightWeek?.days) && state.nightWeek.days.length
      ? state.nightWeek.days.map(day => day.date)
      : Array.from({length:7}, (_, index) => shiftDate(today, index));
    const days = weekDates.map(date => {
      const flights = all.filter(flight => (flight.operational_date || operationalDateOf(flight.scheduled_arrival)) === date && nightOriginMatches(flight));
      flights.sort((a, b) => state.nightSort === 'time'
        ? String(a.expected_arrival || a.scheduled_arrival).localeCompare(String(b.expected_arrival || b.scheduled_arrival))
        : flightScore10(b) - flightScore10(a) || String(a.expected_arrival || '').localeCompare(String(b.expected_arrival || '')));
      return {
        date,
        flights,
        best: flights.reduce((max, flight) => Math.max(max, flightScore10(flight)), 0)
      };
    });
    if (!state.nightDate || !days.some(day => day.date === state.nightDate)) {
      const todayGroup = days.find(day => day.date === today);
      const withFlights = days.find(day => day.flights.length);
      state.nightDate = (todayGroup?.flights.length ? today : withFlights?.date) || today;
    }
    const selected = days.find(day => day.date === state.nightDate) || days[0];
    const visible = selected?.flights || [];
    const best = all.filter(nightOriginMatches).reduce((max, flight) => Math.max(max, flightScore10(flight)), 0);
    if (els.nightWeekToggleLabel) {
      const open = els.nightWeekCollapse?.classList.contains('show');
      els.nightWeekToggleLabel.textContent = `${open ? 'visible' : 'plegado'} · ${nightRangeText()} · ${all.length} vuelo${all.length===1?'':'s'}${best ? ` · mejor ${best}/10` : ''}`;
    }
    if (els.mobileNightLabel) {
      els.mobileNightLabel.textContent = all.length
        ? `${all.length} Canarias${best ? ` · ${best}/10` : ''}`
        : `Canarias ${nightHourLabel(readNightFromHour())}`;
    }
    document.querySelectorAll('[data-night-sort]').forEach(button => {
      const on = button.dataset.nightSort === state.nightSort;
      button.classList.toggle('active', on);
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    if (els.nightDays) {
      els.nightDays.innerHTML = days.map(day => {
        const diff = dayDifference(today, day.date);
        const top = diff === 0 ? 'Hoy' : diff === 1 ? 'Mañana' : dayName(day.date, {weekday:'short'});
        return `<button type="button" class="canary-day ${day.date===state.nightDate?'active':''} ${day.flights.length?'':'empty'}" data-night-date="${esc(day.date)}" aria-pressed="${day.date===state.nightDate?'true':'false'}">
          <small>${esc(top)}</small><strong>${esc(dayName(day.date, {day:'numeric'}))}</strong>
          <span>${day.flights.length} vuelo${day.flights.length===1?'':'s'}</span>${day.best ? `<i>mejor ${day.best}/10</i>` : ''}
        </button>`;
      }).join('');
    }
    if (els.nightOrigins) {
      const origins = new Map();
      all.forEach(item => {
        if (item.origin_iata && !origins.has(item.origin_iata)) origins.set(item.origin_iata, item.origin_name || item.origin_iata);
      });
      if (state.nightOrigin && !origins.has(state.nightOrigin)) origins.set(state.nightOrigin, state.nightOrigin);
      const chips = [['', 'Todas'], ...[...origins.entries()].sort((a, b) => a[1].localeCompare(b[1], 'es'))];
      els.nightOrigins.innerHTML = chips.map(([iata, name]) => {
        const count = all.filter(item => (!iata || item.origin_iata === iata) && (item.operational_date || operationalDateOf(item.scheduled_arrival)) === state.nightDate).length;
        return `<button type="button" class="canary-origin ${state.nightOrigin===iata?'active':''}" data-night-origin="${esc(iata)}" aria-pressed="${state.nightOrigin===iata?'true':'false'}">${esc(name)} <b>${count}</b></button>`;
      }).join('');
    }
    if (!all.length) {
      els.nightWeekStrip.innerHTML = `<div class="canary-empty">No hay vuelos canarios de ${esc(nightRangeText())} en los próximos 7 días.</div>`;
      return;
    }
    if (!visible.length) {
      const originName = all.find(item => item.origin_iata === state.nightOrigin)?.origin_name || state.nightOrigin;
      els.nightWeekStrip.innerHTML = `<div class="canary-empty">${state.nightOrigin
        ? `No hay vuelos desde ${esc(originName)} ${esc(relativeDayLabel(state.nightDate).toLowerCase())}. Elige otro día u origen.`
        : `No hay vuelos canarios ${esc(relativeDayLabel(state.nightDate).toLowerCase())} de ${esc(nightRangeText())}.`}</div>`;
      return;
    }
    els.nightWeekStrip.innerHTML = visible.map(flight => nightCardMarkup(flight, today, selected.best)).join('');
    els.nightWeekStrip.scrollLeft = 0;
    bindFlightOpeners();
  }

  function openNightFlight(id, date) {
    if (date && date !== els.date.value) {
      state.pendingFlightId = Number(id);
      state.pendingFlightDate = date;
      setBoardDate(date);
      return;
    }
    openDetail(id);
  }

  function renderUpcomingLine() {
    const now = madridNow();
    const isToday = els.date.value === now.operationalDate;
    const flights = state.flights.filter(f => {
      if (flightIsFinished(f) || !['waiting','enroute','approach'].includes(flowStage(f))) return false;
      const arrival = parseDate(effectiveArrival(f))?.getTime();
      return !isToday || arrival === undefined || arrival >= now.nowMs - 10 * 60 * 1000;
    }).sort((a,b) => (parseDate(effectiveArrival(a))?.getTime() || 0) - (parseDate(effectiveArrival(b))?.getTime() || 0)).slice(0, 5);
    if (!flights.length) {
      els.upcomingStrip.innerHTML = '<div class="upcoming-empty">No quedan llegadas activas para esta fecha.</div>';
      return;
    }
    els.upcomingStrip.innerHTML = flights.map(f => {
      const arrival = parseDate(effectiveArrival(f));
      const deviation = Number(f.deviation_minutes || 0);
      const deviationClass = deviation >= 5 ? 'late' : deviation <= -5 ? 'early' : 'ontime';
      const deviationText = Math.abs(deviation) < 5 ? 'en hora' : signed(deviation);
      const official = f.source === 'aena' && f.belt;
      const predicted = !official && f.predicted_belt;
      const beltText = official ? `Cinta ${beltPosition(f)}` : predicted ? `Prev. cinta ${esc(f.predicted_belt)}` : 'Cinta —';
      const red = ['7','8'].includes(String(official ? f.belt : f.predicted_belt));
      return `<article class="upcoming-flight ${Number(f.is_canary)===1?'canary':''}" data-flight-id="${Number(f.id)}" tabindex="0">
        <div class="upcoming-clock"><b>${time(arrival)}</b><small>${esc(arrivalCountdown(arrival, isToday))}</small></div>
        <div class="upcoming-main"><strong>${esc(f.origin_name)}</strong><span>${esc(f.physical_flight)} · prevista ${time(f.scheduled_arrival)}</span></div>
        <div class="upcoming-side"><em class="${deviationClass}">${esc(deviationText)}</em><span class="upcoming-belt ${official?'official':predicted?'predicted':'pending'} ${red?'red':''}">${beltText}</span>${coincidenceBadge(f)}</div>
        ${laterNightMarkup(f)}
      </article>`;
    }).join('');
  }

  function arrivalCountdown(arrival, isToday) {
    if (!arrival) return '';
    if (!isToday) return dayName(els.date.value, {weekday:'short', day:'numeric'});
    const minutes = Math.round((arrival.getTime() - Date.now()) / 60_000);
    if (minutes > 90) return `en ${Math.floor(minutes / 60)} h${minutes % 60 ? ` ${minutes % 60} min` : ''}`;
    if (minutes > 0) return `en ${minutes} min`;
    if (minutes === 0) return 'ahora';
    return `hace ${-minutes} min`;
  }

  function initMap() {
    if (state.map || !document.querySelector('#flightMap')) return;
    if (typeof window.L === 'undefined') {
      els.mapStatus.textContent = 'El mapa no pudo cargar la librería cartográfica.';
      return;
    }
    state.map = L.map('flightMap', {zoomControl:true, preferCanvas:true}).setView([SVQ.lat, SVQ.lon], 8);
    state.mapLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom:18, attribution:'&copy; OpenStreetMap'
    }).addTo(state.map);
    state.aircraftLayer = L.layerGroup().addTo(state.map);
    L.circleMarker([SVQ.lat, SVQ.lon], {radius:7, color:'#2fd38a', weight:3, fillColor:'#07111f', fillOpacity:1})
      .bindTooltip('Aeropuerto de Sevilla · SVQ').addTo(state.map);
    setTimeout(() => state.map.invalidateSize(), 0);
  }

  function renderMap() {
    initMap();
    if (!state.map || !state.aircraftLayer) return;
    state.aircraftLayer.clearLayers();
    const tracked = state.flights.filter(f => isFiniteNumber(f.latitude) && isFiniteNumber(f.longitude));
    const bounds = [[SVQ.lat, SVQ.lon]];
    tracked.forEach(f => {
      const lat = Number(f.latitude), lon = Number(f.longitude);
      const heading = isFiniteNumber(f.track_deg) ? Number(f.track_deg) : 0;
      const age = telemetryAgeMinutes(f);
      const stale = age !== null && age > 10;
      const icon = L.divIcon({
        className:'aircraft-marker-wrap',
        html:`<div class="aircraft-marker ${stale?'stale':''}" style="transform:rotate(${heading}deg)">✈</div><span>${esc(f.physical_flight)}</span>`,
        iconSize:[74,44], iconAnchor:[22,22]
      });
      const marker = L.marker([lat, lon], {icon, title:`${f.physical_flight} · ${f.origin_name}`}).addTo(state.aircraftLayer);
      const aircraft = [f.aircraft_type, f.aircraft_registration].filter(Boolean).map(esc).join(' · ') || 'Aeronave no verificada';
      marker.bindPopup(`<div class="map-popup">
        <div class="map-popup-visual">${aircraftSilhouette(f)}</div>
        <strong>${esc(f.physical_flight)} · ${esc(f.origin_name)}</strong>
        <span>${aircraft}</span>
        <dl><dt>Altura</dt><dd>${altitudeFt(f) !== null ? `${altitudeFt(f).toLocaleString('es-ES')} ft · ${Math.round(Number(f.altitude_m)).toLocaleString('es-ES')} m` : 'sin dato'}</dd>
        <dt>Velocidad</dt><dd>${speedKt(f) !== null ? `${speedKt(f)} kt · ${speedKmh(f)} km/h` : 'sin dato'}</dd>
        <dt>Rumbo</dt><dd>${isFiniteNumber(f.track_deg) ? `${Math.round(Number(f.track_deg))}°` : 'sin dato'}</dd>
        <dt>Destino</dt><dd>${beltPosition(f)}</dd></dl>
        <small>${telemetrySource(f)} · ${age === null ? 'hora desconocida' : age === 0 ? 'ahora' : `hace ${age} min`}${stale?' · señal antigua':''}</small>
        <button type="button" class="btn btn-sm btn-success w-100 mt-2" data-open-flight="${Number(f.id)}">Ver ficha completa</button>
      </div>`, {maxWidth:300});
      bounds.push([lat, lon]);
    });
    els.mapStatus.textContent = tracked.length
      ? `${tracked.length} aeronave${tracked.length===1?'':'s'} con posición · OpenSky/AirLabs son telemetría secundaria`
      : 'Sin posiciones ADS‑B disponibles para los vuelos cargados.';
    if (els.radarToggleLabel) {
      const radarState = els.radarCollapse?.classList.contains('show') ? 'visible' : 'plegado';
      els.radarToggleLabel.textContent = `${tracked.length} aeronave${tracked.length===1?'':'s'} con posición · ${radarState}`;
    }
    if (tracked.length && !state.mapHasFitted) {
      state.map.fitBounds(bounds, {padding:[36,36], maxZoom:9});
      state.mapHasFitted = true;
    }
  }

  function distanceToSvq(f) {
    if (!isFiniteNumber(f.latitude) || !isFiniteNumber(f.longitude)) return null;
    const toRad = degrees => degrees * Math.PI / 180;
    const lat1 = toRad(SVQ.lat), lat2 = toRad(Number(f.latitude));
    const dLat = lat2 - lat1, dLon = toRad(Number(f.longitude) - SVQ.lon);
    const a = Math.sin(dLat/2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon/2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  }

  function flowStage(f) {
    const status = `${f.status || ''} ${f.telemetry_status || ''}`.toLowerCase();
    if (flightIsFinished(f)) return 'finished';
    // Aena manda el estado operativo y el estado de equipaje por separado.
    // Cualquiera de los dos puede anunciar primero el inicio de la entrega.
    if (baggageDeliveryIsActive(f)) return 'baggage';
    if (f.actual_arrival || status.includes('landed') || status.includes('tierra')) return 'ground';
    if (isFiniteNumber(f.latitude) && isFiniteNumber(f.longitude)) {
      const distance = distanceToSvq(f);
      const descending = !isFiniteNumber(f.vertical_rate_ms) || Number(f.vertical_rate_ms) <= 0;
      if (distance !== null && distance <= 28 && (!isFiniteNumber(f.altitude_m) || Number(f.altitude_m) <= 3500) && descending) return 'approach';
      return 'enroute';
    }
    if (status.includes('vuelo') || status.includes('route') || status.includes('airborne')) return 'enroute';
    return 'waiting';
  }

  function oneFlightPerBelt(flights, newestFirst = false) {
    const ordered = [...flights].sort((a,b) => {
      const first = parseDate(effectiveArrival(a))?.getTime() || 0;
      const second = parseDate(effectiveArrival(b))?.getTime() || 0;
      return newestFirst ? second-first : first-second;
    });
    const seen = new Set();
    return ordered.filter(f => {
      const belt = Number(f.belt);
      if (!Number.isInteger(belt) || belt < 1 || belt > 8 || seen.has(belt)) return false;
      seen.add(belt);
      return true;
    });
  }

  function currentBeltFlights(flights) {
    return oneFlightPerBelt(flights.filter(f => f.source === 'aena' && f.belt && flowStage(f) === 'baggage'));
  }

  function waitingBaggageFlights(flights, perBeltLimit = 2) {
    const beltsWithActiveDelivery = new Set(currentBeltFlights(flights).map(f => Number(f.belt)));
    const ordered = flights
      .filter(f => f.source === 'aena' && f.belt && flowStage(f) === 'ground')
      .sort((a,b) => (parseDate(effectiveArrival(a))?.getTime() || 0) - (parseDate(effectiveArrival(b))?.getTime() || 0));
    const beltCounts = new Map();
    return ordered.filter(f => {
      const belt = Number(f.belt);
      if (!Number.isInteger(belt) || belt < 1 || belt > 8) return false;
      const count = beltCounts.get(belt) || 0;
      const limit = beltsWithActiveDelivery.has(belt) ? 1 : perBeltLimit;
      if (count >= limit) return false;
      beltCounts.set(belt,count + 1);
      return true;
    });
  }

  function renderAirportFlow() {
    const now = madridNow();
    const windowFlights = state.flights.filter(f => {
      const arrival = parseDate(effectiveArrival(f))?.getTime();
      if (els.date.value !== now.operationalDate) return !flightIsFinished(f);
      return arrival !== undefined
        && arrival >= now.nowMs - 45 * 60 * 1000
        && arrival <= now.nowMs + 150 * 60 * 1000
        && !flightIsFinished(f);
    });
    const airborne = windowFlights.filter(f => ['enroute','approach'].includes(flowStage(f))).sort((a,b) => {
      const freshness = Number(telemetryIsFresh(b)) - Number(telemetryIsFresh(a));
      if (freshness !== 0) return freshness;
      const aDistance = distanceToSvq(a), bDistance = distanceToSvq(b);
      if (aDistance !== null || bDistance !== null) return (aDistance ?? 99999) - (bDistance ?? 99999);
      return (minuteOfDay(effectiveArrival(a)) ?? 99999) - (minuteOfDay(effectiveArrival(b)) ?? 99999);
    });
    const surface = [...currentBeltFlights(windowFlights),...waitingBaggageFlights(windowFlights)];
    const waiting = windowFlights.filter(f => flowStage(f) === 'waiting')
      .sort((a,b) => (parseDate(effectiveArrival(a))?.getTime() || 0) - (parseDate(effectiveArrival(b))?.getTime() || 0));
    const skyQueue = [...airborne,...waiting].slice(0,5);
    const active = [...new Map([...skyQueue,...surface].map(f => [Number(f.id),f])).values()];
    active.forEach(f => { f._sceneRank = skyQueue.findIndex(item => Number(item.id) === Number(f.id)) + 1 || null; });
    renderAirportScene(active);
    const stages = [
      ['waiting','Esperando / prevista'], ['enroute','En ruta'], ['approach','Aterrizando'],
      ['ground','En tierra'], ['baggage','En cintas']
    ];
    if (!active.length) {
      els.airportFlow.innerHTML = '<div class="flow-empty">No hay movimientos activos en la ventana de ±2 horas.</div>';
      return;
    }
    els.airportFlow.innerHTML = stages.map(([key,label]) => {
      const flights = active.filter(f => flowStage(f) === key);
      return `<section class="flow-lane flow-${key}">
        <header><span>${label}</span><b>${flights.length}</b></header>
        <div class="flow-lane-cards">${flights.length ? flights.map(f => {
          const telemetry = key === 'approach' || key === 'enroute'
            ? `<small>${altitudeFt(f)!==null?`${altitudeFt(f).toLocaleString('es-ES')} ft`:'altura —'} · ${speedKt(f)!==null?`${speedKt(f)} kt`:'vel. —'}</small>`
            : `<small>${f.gate?`puerta ${esc(f.gate)}`:f.stand?`posición ${esc(f.stand)}`:'posición pendiente'}</small>`;
          return `<article class="flow-flight" data-flight-id="${Number(f.id)}" tabindex="0">
            <div><strong>${esc(f.physical_flight)}</strong><span>${time(effectiveArrival(f))}</span></div>
            <p>${esc(f.origin_name)}</p>
            <div class="flow-destination"><b>${beltPosition(f)}</b>${telemetry}</div>
          </article>`;
        }).join('') : '<span class="flow-none">Ninguno</span>'}</div>
      </section>`;
    }).join('');
  }

  function approachSide(flight) {
    if (isFiniteNumber(flight.track_deg)) {
      const track = (Number(flight.track_deg) + 360) % 360;
      if (track >= 45 && track <= 135) return 'left';
      if (track >= 225 && track <= 315) return 'right';
    }
    if (isFiniteNumber(flight.longitude)) return Number(flight.longitude) < SVQ.lon ? 'left' : 'right';
    return 'unknown';
  }

  function telemetryIsFresh(flight, maximumMinutes = 15) {
    const age = telemetryAgeMinutes(flight);
    return isFiniteNumber(flight.latitude) && isFiniteNumber(flight.longitude) && age !== null && age <= maximumMinutes;
  }

  function distanceToPoint(latitude, longitude) {
    if (!isFiniteNumber(latitude) || !isFiniteNumber(longitude)) return null;
    const toRad = degrees => degrees * Math.PI / 180;
    const lat1 = toRad(SVQ.lat), lat2 = toRad(Number(latitude));
    const dLat = lat2 - lat1, dLon = toRad(Number(longitude) - SVQ.lon);
    const a = Math.sin(dLat/2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLon/2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
  }

  function projectTelemetry(latitude, longitude) {
    if (!isFiniteNumber(latitude) || !isFiniteNumber(longitude)) return null;
    const lat = Number(latitude), lon = Number(longitude);
    const distance = distanceToPoint(lat,lon);
    if (distance !== null && distance <= 6) {
      const runwaySpan = RWY.east.lon - RWY.west.lon;
      return {
        left:clamp(5 + ((lon - RWY.west.lon) / runwaySpan) * 90, 3, 97),
        top:clamp(28 + (RWY.west.lat - lat) * 6000, 24, 55)
      };
    }
    const side = lon < SVQ.lon ? 'left' : 'right';
    const ratio = clamp((distance ?? 280) / 280,0,1);
    const nearThreshold = 1 - ratio;
    return {
      left:side === 'left' ? 4 + nearThreshold * 9 : 96 - nearThreshold * 9,
      top:clamp(8 + nearThreshold * 25,7,33)
    };
  }

  function scenePosition(stage, index, flight) {
    const gps = telemetryIsFresh(flight) ? projectTelemetry(flight.latitude, flight.longitude) : null;
    if (gps && ['enroute','approach'].includes(stage)) return {...gps,gps:true};
    const side = approachSide(flight);
    if (stage === 'approach') {
      return side === 'right'
        ? {left:88, top:28 + (index % 3) * 7, gps:false}
        : {left:12, top:28 + (index % 3) * 7, gps:false};
    }
    if (stage === 'enroute') {
      const rank = Math.max(0, Number(flight._sceneRank || index + 1) - 1);
      return {left:10 + (rank % 5) * 20, top:8 + Math.floor(rank / 5) * 9, gps:false};
    }
    if (stage === 'ground') {
      const belt = Number(flight.belt);
      const queueRank = Number(flight._beltQueueRank) || 0;
      const beltColumn = 8 - belt;
      return Number.isInteger(belt) && belt >= 1 && belt <= 8
        ? {left:(beltColumn+.5)*12.5,top:74.5 - Math.min(queueRank,1) * 11 + (beltColumn % 2 ? -2.2 : 2.2),gps:false,flow:true}
        : {left:50 + (index % 3) * 5,top:67,gps:false,flow:true};
    }
    if (stage === 'baggage') return {left:29 + (index % 6) * 9,top:58 + (index % 2) * 7,gps:false};
    return {left:32 + (index % 5) * 9, top:7 + Math.floor(index / 5) * 7, gps:false};
  }

  function sceneHeading(stage, flight) {
    if (isFiniteNumber(flight.track_deg)) return Number(flight.track_deg);
    const side = approachSide(flight);
    if (side === 'right') return 270;
    if (side === 'left') return 90;
    return stage === 'ground' || stage === 'baggage' ? 90 : 0;
  }

  function renderSceneTrails(active) {
    if (!els.sceneTrails) return;
    const gpsTrails = active.map(f => {
      const points = (Array.isArray(f.telemetry_trail) ? f.telemetry_trail : [])
        .map(point => projectTelemetry(point.latitude, point.longitude)).filter(Boolean);
      if (points.length < 2) return '';
      return `<polyline class="scene-gps-trail ${Number(f.is_canary)===1?'canary':''}" points="${points.map(point=>`${point.left},${point.top}`).join(' ')}"/>`;
    }).join('');
    const baggageLinks = waitingBaggageFlights(active).map(f => {
      const beltNumber = Number(f.belt);
      if (!Number.isInteger(beltNumber) || beltNumber < 1 || beltNumber > 8) return '';
      const beltX = (8 - beltNumber + .5) * 12.5;
      const start = state.scenePositions.get(Number(f.id)) || {left:50,top:67};
      return `<path class="scene-baggage-link" d="M${start.left} ${Math.min(start.top,75)} Q${(start.left+beltX)/2} 77 ${beltX} 79"/>`;
    }).join('');
    els.sceneTrails.innerHTML = gpsTrails + baggageLinks;
  }

  function renderAirportScene(active) {
    if (!els.sceneAircraft) return;
    const counters = {waiting:0,enroute:0,approach:0,ground:0,baggage:0};
    const stageLabels = {waiting:'previsto',enroute:'en ruta',approach:'aterrizando',ground:'en tierra',baggage:'equipaje'};
    const surfaceWaiting = waitingBaggageFlights(active);
    const beltQueueCounts = new Map();
    surfaceWaiting.forEach(f => {
      const belt = Number(f.belt);
      const rank = beltQueueCounts.get(belt) || 0;
      f._beltQueueRank = rank;
      beltQueueCounts.set(belt,rank + 1);
    });
    const sceneFlights = [
      ...active.filter(f => ['waiting','enroute','approach'].includes(flowStage(f))),
      ...surfaceWaiting
    ];
    const activeById = new Map(active.map(f => [Number(f.id),f]));
    const boardById = new Map(state.flights.map(f => [Number(f.id),f]));
    const activeIds = new Set(sceneFlights.map(f => Number(f.id)));
    const nextPositions = new Map();
    sceneFlights.forEach(f => {
      const stage = flowStage(f);
      const position = scenePosition(stage, counters[stage]++, f);
      const id = Number(f.id);
      nextPositions.set(id, position);
      const destination = f.belt ? beltPosition(f) : 'cinta pendiente';
      const canary = Number(f.is_canary) === 1;
      let node = els.sceneAircraft.querySelector(`.scene-plane[data-flight-id="${id}"]`);
      let isNew = false;
      if (!node) {
        isNew = true;
        node = document.createElement('button');
        node.type = 'button';
        node.className = 'scene-plane';
        node.dataset.flightId = id;
        const entry = position.flow ? {left:50,top:66} : position;
        node.style.left = `${entry.left}%`;
        node.style.top = `${entry.top}%`;
        node.addEventListener('click', () => openDetail(id));
        els.sceneAircraft.appendChild(node);
      }
      const removalTimer = state.sceneRemovalTimers.get(id);
      if (removalTimer) {
        clearTimeout(removalTimer.fade);
        clearTimeout(removalTimer.remove);
        node.classList.remove('scene-plane-leaving');
        state.sceneRemovalTimers.delete(id);
      }
      const side = position.gps ? approachSide(f) : 'unknown';
      const distance = distanceToSvq(f);
      const direction = side === 'left' ? 'entrada oeste/09' : side === 'right' ? 'entrada este/27' : 'dirección pendiente';
      const positionText = f.stand ? `posición ${esc(f.stand)}` : f.gate ? `puerta ${esc(f.gate)} · puesto sin verificar` : 'posición pendiente';
      const detail = position.gps
        ? `${distance!==null?`${Math.round(distance)} km · `:''}${direction}`
        : `${destination} · sin GPS`;
      node.className = `scene-plane scene-plane-${stage} scene-plane-side-${side} ${canary?'scene-plane-canary':''} ${position.gps?'scene-plane-live':'scene-plane-planned'}`;
      if (isNew) node.classList.add('scene-plane-entering');
      node.dataset.targetLeft = position.left;
      node.dataset.targetTop = position.top;
      node.style.setProperty('--scene-heading', `${sceneHeading(stage,f)}deg`);
      node.title = `${f.physical_flight} · ${f.origin_name} · ${stageLabels[stage]} · ${destination}`;
      node.innerHTML = `<span class="scene-plane-icon" aria-hidden="true">${stage==='ground'?'🧳':'✈'}</span>
        <span class="scene-plane-data"><span class="scene-plane-time"><b>${time(effectiveArrival(f))}</b>${stage==='ground'?'':`<i>${esc(arrivalCountdown(parseDate(effectiveArrival(f)), els.date.value === madridNow().operationalDate))}</i>`}</span><strong>${esc(f.physical_flight)}${f._sceneRank?` · #${f._sceneRank}`:''}</strong><em>${esc(f.origin_name)}</em><small>${stage==='ground'?`${destination} · espera entrega`:detail}</small></span>`;
      requestAnimationFrame(() => {
        node.classList.remove('scene-plane-entering');
        node.style.left = `${position.left}%`;
        node.style.top = `${position.top}%`;
      });
    });
    els.sceneAircraft.querySelectorAll('.scene-plane').forEach(node => {
      const id = Number(node.dataset.flightId);
      if (activeIds.has(id) || state.sceneRemovalTimers.has(id)) return;
      const flight = activeById.get(id);
      const boardFlight = boardById.get(id);
      if (boardFlight && flightIsFinished(boardFlight)) {
        node.classList.add('scene-plane-leaving');
        const timers = {fade:null,remove:setTimeout(() => {
          node.remove();
          state.sceneRemovalTimers.delete(id);
        },1200)};
        state.sceneRemovalTimers.set(id,timers);
        return;
      }
      if (flight && flowStage(flight) === 'baggage') {
        node.classList.add('scene-plane-leaving');
        const timers = {fade:null,remove:setTimeout(() => {
          node.remove();
          state.sceneRemovalTimers.delete(id);
        },1200)};
        state.sceneRemovalTimers.set(id,timers);
        return;
      }
      const timers = {fade:null,remove:null};
      timers.fade = setTimeout(() => {
        node.classList.add('scene-plane-leaving');
        timers.remove = setTimeout(() => {
          node.remove();
          state.sceneRemovalTimers.delete(id);
        },12000);
      },20000);
      state.sceneRemovalTimers.set(id,timers);
    });
    state.scenePositions = nextPositions;
    els.sceneMovementCount.textContent = active.length;
    renderSceneTrails(active);
    renderSceneBelts(active);
  }

  function renderSceneBelts(active) {
    if (!els.sceneBelts) return;
    const currentByBelt = new Map(currentBeltFlights(active).map(f => [Number(f.belt),f]));
    const waitingByBelt = new Map();
    waitingBaggageFlights(active).forEach(f => {
      const belt = Number(f.belt);
      if (!waitingByBelt.has(belt)) waitingByBelt.set(belt,[]);
      waitingByBelt.get(belt).push(f);
    });
    els.sceneBelts.innerHTML = [8,7,6,5,4,3,2,1].map(number => {
      const current = currentByBelt.get(number) || null;
      const waiting = waitingByBelt.get(number) || [];
      const queued = waiting[0] || null;
      const next = waiting[1] || null;
      const selected = current || queued;
      const danger = number >= 7 ? 'danger' : '';
      const mobileNext = current && queued
        ? queued
        : next;
      return `<button type="button" class="scene-belt ${danger} ${current?'has-flight':queued?'reserved':''}" ${selected?`data-flight-id="${Number(selected.id)}"`:''} title="${current?esc(`Cinta ${number}: ${current.physical_flight} recogiendo maletas`):queued?`Cinta ${number}: ${queued.physical_flight} asignado, entrega pendiente`:`Cinta ${number} sin vuelo activo`}">
        <span>${number}</span>${current
          ? `<strong class="scene-belt-origin">${esc(current.origin_name)}</strong><small>${esc(current.physical_flight)} · ${time(effectiveArrival(current))}</small><em><i aria-hidden="true">🧳</i> RECOGIENDO MALETAS</em>`
          : queued
            ? `<strong class="scene-belt-origin free">ASIGNADA · EN ESPERA</strong><small>${esc(queued.physical_flight)} · aún no entrega</small>`
            : '<strong class="scene-belt-origin free">LIBRE</strong><small>sin vuelo activo</small>'}
        ${mobileNext ? `<span class="scene-belt-next"><i>🧳 SIGUIENTE</i><b>${esc(mobileNext.origin_name)}</b><small>${esc(mobileNext.physical_flight)} · entrega pendiente</small></span>` : ''}
      </button>`;
    }).join('');
  }

  function officialBeltChanges(flight) {
    return (Array.isArray(flight.belt_events) ? flight.belt_events : [])
      .filter(event => event.source === 'aena' && event.event_type === 'belt_changed');
  }

  function renderBeltChanges() {
    if (!els.beltChangesSection || !els.beltChangesStrip) return;
    const changed = state.flights.filter(f => officialBeltChanges(f).length > 0);
    els.beltChangesSection.classList.toggle('d-none', changed.length === 0);
    els.beltChangesCount.textContent = changed.length;
    els.beltChangesStrip.innerHTML = changed.map(f => {
      const changes = officialBeltChanges(f);
      const last = changes[changes.length - 1];
      const first = f.first_belt ? `${f.first_hall ? `${f.first_hall}/` : ''}${f.first_belt}` : (last.before_value || '—');
      const current = f.belt ? `${f.hall ? `${f.hall}/` : ''}${f.belt}` : (last.after_value || 'retirada');
      return `<button type="button" class="belt-change-card" data-flight-id="${Number(f.id)}">
        <span><b>${esc(f.origin_name)}</b><small>${esc(f.physical_flight)} · ${time(effectiveArrival(f))}</small></span>
        <strong>${esc(first)} <i>→</i> ${esc(current)}</strong>
        <em>${changes.length} cambio${changes.length===1?'':'s'} · ${dateTime(last.detected_at)}</em>
      </button>`;
    }).join('');
  }

  function updateSceneClock() {
    if (!els.sceneClock) return;
    els.sceneClock.textContent = new Intl.DateTimeFormat('es-ES', {
      timeZone:'Europe/Madrid', hour:'2-digit', minute:'2-digit', second:'2-digit'
    }).format(new Date());
  }

  function scrollToCurrent(force = false) {
    const now = madridNow();
    if (els.date.value !== now.operationalDate) return false;
    const rows = [...els.body.querySelectorAll('.flight-row')];
    if (!rows.length) return false;
    const target = rows.find(row => Number(row.dataset.effectiveTime) >= now.nowMs - 5 * 60 * 1000) || rows[rows.length - 1];
    if (!target) return false;
    target.scrollIntoView({behavior:force?'smooth':'auto', block:'start'});
    target.classList.add('current-flight');
    return true;
  }

  function maybeScrollToNow() {
    const now = madridNow();
    if (state.autoScrolledDate === els.date.value || els.date.value !== now.operationalDate) return;
    if (els.search.value || els.hall.value || els.canary.checked || els.secondary.checked) return;
    if (els.arrivalsCollapse && !els.arrivalsCollapse.classList.contains('show')) return;
    state.autoScrolledDate = els.date.value;
    requestAnimationFrame(() => scrollToCurrent(false));
  }

  function metrics(flights) {
    els.metricFlights.textContent = flights.length;
    els.metricOrange.textContent = flights.filter(f => String(f.indicator).includes('🟠') || f.secondary_belt_state === 'candidate').length;
    els.metricRed.textContent = flights.filter(f => ['7','8'].includes(String(f.belt))).length;
    const max = flights.reduce((best,f) => Number(f.hall_capacity_30m)>Number(best.hall_capacity_30m||0)?f:best,{});
    els.metricHall.textContent = max.hall_capacity_30m ? `${Number(max.hall_capacity_30m).toLocaleString('es-ES')} · ${max.hall||'A'}` : '—';
  }

  function telemetrySummary(flight, compact = false) {
    const compactClass = compact ? ' telemetry-compact' : '';
    if (!flight.telemetry_observed_at) {
      const aircraftState = flight.aircraft_registration
        ? `${esc(flight.aircraft_registration)} · falta posición ADS‑B utilizable`
        : 'Matrícula todavía no identificada';
      return `<section class="telemetry-card mb-4 telemetry-empty${compactClass}" data-focus-surface>
        <div class="telemetry-visual">${aircraftSilhouette(flight)}<span>Sin telemetría reciente</span></div>
        <small class="telemetry-note">${aircraftState}. La consulta dirigida buscará primero la aeronave y después su señal.</small>
        <button class="btn btn-sm btn-outline-info telemetry-refresh" type="button" data-focus-refresh="${Number(flight.id)}">↻ ${flight.aircraft_icao24?'Actualizar telemetría':'Buscar matrícula y telemetría'}</button>
        <small class="focus-refresh-status" data-focus-refresh-status></small>
      </section>`;
    }
    const trend = verticalTrend(flight);
    const coordinates = isFiniteNumber(flight.latitude) && isFiniteNumber(flight.longitude)
      ? `${Number(flight.latitude).toFixed(3)}, ${Number(flight.longitude).toFixed(3)}` : '—';
    if (compact) {
      return `<section class="telemetry-card mb-4 telemetry-compact">
        <header><strong>Telemetría</strong><small>${telemetryAgeMinutes(flight)===0?'ahora':`hace ${telemetryAgeMinutes(flight)} min`}</small></header>
        <div class="telemetry-compact-values"><span><small>Altura</small><b>${altitudeFt(flight)!==null?`${altitudeFt(flight).toLocaleString('es-ES')} ft`:'—'}</b></span><span><small>Velocidad</small><b>${speedKt(flight)!==null?`${speedKt(flight)} kt`:'—'}</b></span><span><small>Fase</small><b class="telemetry-trend ${trend.className}">${esc(trend.label)}</b></span></div>
      </section>`;
    }
    return `<section class="telemetry-card mb-4">
      <div class="telemetry-visual">${aircraftSilhouette(flight)}<span>${esc(flight.aircraft_type || 'tipo no verificado')}</span></div>
      <div class="telemetry-values">
        <div><small>Altura</small><strong>${altitudeFt(flight)!==null?`${altitudeFt(flight).toLocaleString('es-ES')} ft`:'—'}</strong></div>
        <div><small>Velocidad</small><strong>${speedKt(flight)!==null?`${speedKt(flight)} kt`:'—'}</strong></div>
        <div><small>Rumbo</small><strong>${isFiniteNumber(flight.track_deg)?`${Math.round(Number(flight.track_deg))}°`:'—'}</strong></div>
        <div><small>Fase vertical</small><strong class="telemetry-trend ${trend.className}">${esc(trend.label)}</strong></div>
        <div class="telemetry-position"><small>Posición ADS‑B</small><strong>${coordinates}</strong></div>
        <div><small>Estado de señal</small><strong>${telemetryAgeMinutes(flight)===0?'ahora':`hace ${telemetryAgeMinutes(flight)} min`}</strong></div>
      </div>
      <small class="telemetry-note">${telemetrySource(flight)} · ${dateTime(flight.telemetry_observed_at)}. La silueta representa el tipo; no es una fotografía de la matrícula.</small>
    </section>`;
  }

  function flightAwareSnapshotMarkup(snapshot) {
    if (!snapshot) return '';
    const value = (number, suffix) => isFiniteNumber(number)
      ? `${Number(number).toLocaleString('es-ES')} ${suffix}` : '—';
    return `<section class="telemetry-card flightaware-snapshot mb-4">
      <header><div><strong>FlightAware web</strong><span class="badge text-bg-warning">secundario</span></div><time>${dateTime(snapshot.observed_at)}</time></header>
      <p>${esc(snapshot.status_text || 'Estado público sin descripción')}</p>
      <div class="telemetry-values">
        <div><small>Matrícula</small><strong>${esc(snapshot.aircraft_registration || 'Todavía no publicada')}</strong></div>
        <div><small>Aeronave</small><strong>${esc(snapshot.aircraft_type || '—')}</strong></div>
        <div><small>Altitud visible</small><strong>${value(snapshot.altitude_ft,'ft')}</strong></div>
        <div><small>Velocidad visible</small><strong>${value(snapshot.speed_mph,'mph')}</strong></div>
        <div><small>Distancia</small><strong>${value(snapshot.distance_mi,'mi')}</strong></div>
        <div><small>Duración</small><strong>${esc(snapshot.duration_text || '—')}</strong></div>
        <div><small>Últimos hitos</small><strong>${esc(snapshot.arrival_text || snapshot.departure_text || '—')}</strong></div>
      </div>
      <small class="telemetry-note">Lectura limitada de la ficha pública. Puede faltar o retrasarse y nunca sustituye a Aena para hora, sala o cinta.</small>
    </section>`;
  }

  function flightStatsSnapshotMarkup(snapshot) {
    if (!snapshot) return '';
    const delay = Number(snapshot.delay_minutes);
    const delayLabel = Number.isFinite(delay) ? `${delay > 0 ? '+' : ''}${delay} min` : '—';
    return `<section class="telemetry-card flightstats-snapshot mb-4">
      <header><div><strong>FlightStats web</strong><span class="badge text-bg-info">secundario</span></div><time>${dateTime(snapshot.observed_at)}</time></header>
      <p>${esc(snapshot.status_text || 'Estado público sin descripción')}</p>
      <div class="telemetry-values">
        <div><small>Retraso</small><strong>${esc(delayLabel)}</strong></div>
        <div><small>ETA</small><strong>${time(snapshot.eta)}</strong></div>
        <div><small>Salida real</small><strong>${time(snapshot.actual_departure)}</strong></div>
        <div><small>Llegada real</small><strong>${time(snapshot.actual_arrival)}</strong></div>
        <div><small>Fase</small><strong>${esc(snapshot.phase || '—')}</strong></div>
        <div><small>Hitos</small><strong>${esc(snapshot.arrival_text || snapshot.departure_text || '—')}</strong></div>
      </div>
      <small class="telemetry-note">Lectura pública de retraso y ETA. Aena sigue siendo la autoridad de cinta, sala y equipaje.</small>
    </section>`;
  }

  function stopFlightAwarePolling() {
    if (state.flightAwarePollTimer) window.clearTimeout(state.flightAwarePollTimer);
    state.flightAwarePollTimer = null;
  }

  async function pollFlightAwareSnapshot(flightId, baselineObservedAt, attempt = 1) {
    if (Number(state.detailFlightId) !== Number(flightId)) return;
    try {
      const response = await fetch(`api/history.php?flight_id=${encodeURIComponent(flightId)}`, {cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No se pudo comprobar la lectura.');
      if (Number(state.detailFlightId) !== Number(flightId)) return;
      const snapshot = payload.flightaware || null;
      const aircraft = payload.aircraft || null;
      const flight = state.flights.find(item => Number(item.id) === Number(flightId));
      if (flight && aircraft) {
        flight.aircraft_registration = aircraft.aircraft_registration || flight.aircraft_registration;
        flight.aircraft_icao24 = aircraft.aircraft_icao24 || flight.aircraft_icao24;
        flight.aircraft_type = aircraft.aircraft_type || flight.aircraft_type;
      }
      if (snapshot && aircraft?.aircraft_registration) {
        snapshot.aircraft_registration = aircraft.aircraft_registration;
      }
      const observedAt = String(snapshot?.observed_at || '');
      const requestFinished = payload.flightaware_request?.status === 'processed';
      if (requestFinished && aircraft?.aircraft_registration) {
        const target = els.detailBody.querySelector('[data-flightaware-snapshot]');
        const status = els.detailBody.querySelector('[data-flightaware-request-status]');
        const hero = els.detailBody.querySelector('[data-focus-hero]');
        const telemetry = els.detailBody.querySelector('[data-focus-telemetry]');
        if (target) target.innerHTML = flightAwareSnapshotMarkup(snapshot || {
          aircraft_registration:aircraft.aircraft_registration,
          aircraft_type:aircraft.aircraft_type,
        });
        if (hero && flight) hero.innerHTML = focusHeroMarkup(flight, state.focusStatus);
        if (telemetry && flight) telemetry.innerHTML = telemetrySummary(flight, true);
        bindFocusRefreshButtons(els.detailBody);
        if (status) {
          status.className = 'd-block mt-2 text-success';
          status.textContent = `Matrícula ${aircraft.aircraft_registration} localizada en la ficha pública de FlightAware.`;
        }
        stopFlightAwarePolling();
        return;
      }
      if (snapshot && observedAt && observedAt !== String(baselineObservedAt || '')) {
        const target = els.detailBody.querySelector('[data-flightaware-snapshot]');
        const status = els.detailBody.querySelector('[data-flightaware-request-status]');
        if (target) target.innerHTML = flightAwareSnapshotMarkup(snapshot);
        if (status) {
          status.className = requestFinished && !aircraft?.aircraft_registration
            ? 'd-block mt-2 text-warning'
            : 'd-block mt-2 text-success';
          status.textContent = requestFinished && !aircraft?.aircraft_registration
            ? `FlightAware consultado · todavía no publica la matrícula (${snapshot.aircraft_type || 'tipo pendiente'}).`
            : `Datos del avión cargados automáticamente · ${dateTime(snapshot.observed_at)}.`;
        }
        if (!requestFinished || aircraft?.aircraft_registration) {
          stopFlightAwarePolling();
          return;
        }
      }
      if (requestFinished && !aircraft?.aircraft_registration) {
        const status = els.detailBody.querySelector('[data-flightaware-request-status]');
        if (status) {
          status.className = 'd-block mt-2 text-warning';
          status.textContent = `FlightAware consultado · todavía no publica la matrícula (${snapshot?.aircraft_type || aircraft?.aircraft_type || 'tipo pendiente'}). Se volverá a comprobar al solicitar otra lectura.`;
        }
      }
      if (attempt >= 24) {
        const status = els.detailBody.querySelector('[data-flightaware-request-status]');
        if (status) {
          status.className = 'd-block mt-2 text-secondary';
          status.textContent = requestFinished && !aircraft?.aircraft_registration
            ? `FlightAware terminó la consulta sin publicar matrícula (${snapshot?.aircraft_type || aircraft?.aircraft_type || 'tipo pendiente'}).`
            : snapshot
            ? 'La lectura terminó sin publicar datos diferentes; se conserva la última ficha disponible.'
            : 'FlightAware no publicó datos utilizables en esta ventana. Puedes mantener el panel abierto o intentarlo más tarde.';
        }
        stopFlightAwarePolling();
        return;
      }
    } catch (error) {
      if (attempt >= 24) {
        const status = els.detailBody.querySelector('[data-flightaware-request-status]');
        if (status) {
          status.className = 'd-block mt-2 text-danger';
          status.textContent = error.message || 'No se pudo actualizar la ficha del avión.';
        }
        stopFlightAwarePolling();
        return;
      }
    }
    state.flightAwarePollTimer = window.setTimeout(
      () => pollFlightAwareSnapshot(flightId, baselineObservedAt, attempt + 1),
      30_000
    );
  }

  async function requestFlightAwareSnapshot(flightId, baselineObservedAt = null) {
    const status = els.detailBody.querySelector('[data-flightaware-request-status]');
    stopFlightAwarePolling();
    try {
      const response = await fetch('api/flightaware.php', {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'request',flight_id:Number(flightId)})
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo solicitar la lectura.');
      if (status) {
        status.className = 'd-block mt-2 text-warning';
        status.innerHTML = `<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>${data.queued
          ? 'Consultando los datos del avión. Esta ficha se completará automáticamente cuando lleguen.'
          : 'La consulta ya estaba en marcha. Esta ficha se completará automáticamente.'}`;
      }
      state.flightAwarePollTimer = window.setTimeout(
        () => pollFlightAwareSnapshot(flightId, baselineObservedAt),
        15_000
      );
    } catch (error) {
      if (status) {
        status.className = 'd-block mt-2 text-danger';
        status.textContent = error.message || 'No se pudo solicitar la lectura FlightAware.';
      }
    }
  }

  function propensityLabel(profile) {
    const labels = {
      historico_insuficiente:'Histórico insuficiente (<5)',
      caliente_confirmado:'Caliente confirmado (>50% en 7/8)',
      caliente_fuerte:'Caliente fuerte (≥70% en 7/8)',
      sin_propension_roja:'Sin propensión roja confirmada'
    };
    return labels[profile?.classification] || 'Sin histórico';
  }

  function beltDistributionMarkup(profile, title, currentBelt = null) {
    const safeProfile = profile && Array.isArray(profile.belts) ? profile : {samples:0,belts:[],red_percentage:0,classification:'historico_insuficiente'};
    const belts = safeProfile.belts.length ? safeProfile.belts : Array.from({length:8},(_,index)=>({belt:index+1,count:0,percentage:0}));
    return `<section class="belt-probability-panel">
      <header><div><strong>${esc(title)}</strong><small>Frecuencia final oficial observada · ${Number(safeProfile.samples || 0)} vuelos</small></div><span>${esc(propensityLabel(safeProfile))}</span></header>
      <div class="belt-probability-grid">${belts.map(item => `<article class="belt-probability ${Number(item.belt)>=7?'red':''} ${String(item.belt)===String(currentBelt)?'current':''}">
        <div><strong>Cinta ${Number(item.belt)}</strong><b>${Number(item.percentage || 0).toLocaleString('es-ES',{maximumFractionDigits:1})}%</b></div>
        <span><i style="width:${clamp(Number(item.percentage || 0),0,100)}%"></i></span>
        <small>${Number(item.count || 0)} de ${Number(safeProfile.samples || 0)} llegadas</small>
      </article>`).join('')}</div>
      <footer>Cintas 7/8: <strong>${Number(safeProfile.red_percentage || 0).toLocaleString('es-ES',{maximumFractionDigits:1})}%</strong>. Es frecuencia histórica, no una garantía de la asignación futura.</footer>
    </section>`;
  }

  function beltIntelligence(flight) {
    const events = (Array.isArray(flight.belt_events) ? flight.belt_events : []);
    const changes = events.filter(event => event.event_type === 'belt_changed' || event.event_type === 'belt_removed');
    const intervals = [flight.previous_same_belt_minutes, flight.next_same_belt_minutes].filter(value => value !== null);
    const minimum = intervals.length ? Math.min(...intervals.map(Number)) : null;
    const context = [
      `${pressureText(flight.pressure)}${minimum !== null ? ` · intervalo mínimo ${minimum} min` : ''}`,
      `Afluencia ${hallLoadText(flight.hall_load).toLowerCase()} · ${Number(flight.hall_flights_30m || 0)} vuelos / ${Number(flight.hall_capacity_30m || 0).toLocaleString('es-ES')} plazas teóricas en ±30 min`,
      Math.abs(Number(flight.deviation_minutes || 0)) >= 15 ? `Desviación ${signed(flight.deviation_minutes)}` : 'Sin desviación ≥15 min'
    ];
    const eventMarkup = changes.length ? changes.map(event => {
      const publishedReason = event.reason_code && !['belt_change','belt_removed','source_changed'].includes(event.reason_code);
      const analyzed = Boolean(event.analysis_reason_label);
      const reason = analyzed ? event.analysis_reason_detail : publishedReason ? event.reason_detail : 'La fuente no publicó una causa operativa verificable.';
      const reasonLabel = analyzed
        ? `${Number(event.analysis_official_reason)===1 ? 'Causa publicada' : 'Motivo inferido por la matriz'} · ${event.analysis_reason_label}`
        : null;
      return `<article class="belt-event-item">
        <header><strong>${esc(event.before_value || 'sin cinta')} → ${esc(event.after_value || 'retirada')}</strong><time>${dateTime(event.detected_at)}</time></header>
        <div><span class="badge ${event.source==='aena'?'text-bg-success':'text-bg-warning'}">${esc((event.source||'').toUpperCase())}</span> <span class="badge ${event.analysis_confidence==='confirmado'?'text-bg-success':event.analysis_confidence==='probable'?'text-bg-warning':'text-bg-secondary'}">${esc(event.analysis_confidence || event.confidence || 'provisional')}</span></div>
        ${reasonLabel ? `<strong class="belt-event-reason">${esc(reasonLabel)}</strong>` : ''}
        <p>${esc(reason)}</p>
      </article>`;
    }).join('') : '<p class="text-secondary mb-0">No se han registrado cambios de cinta.</p>';
    return `<section class="belt-intelligence mb-4">
      <h3 class="h6">Inteligencia de cinta</h3>
      <div class="detail-grid mb-3">
        <div class="detail-stat"><small>Media del mismo vuelo</small><strong>${leadText(flight.belt_lead_flight_average_minutes)}</strong><span class="meta">${Number(flight.belt_lead_flight_samples || 0)} observaciones oficiales</span></div>
        <div class="detail-stat"><small>Media del origen ${esc(flight.origin_iata || '')}</small><strong>${leadText(flight.belt_lead_origin_average_minutes)}</strong><span class="meta">${Number(flight.belt_lead_origin_samples || 0)} observaciones oficiales</span></div>
      </div>
      ${flight.predicted_belt ? `<aside class="prediction-detail ${['7','8'].includes(String(flight.predicted_belt))?'prediction-detail-red':''}">
        <div><strong>Predicción previa ${esc(flight.predicted_hall || '?')}/${esc(flight.predicted_belt)}</strong><span>${Number(flight.prediction_score || 0)}% · confianza ${esc(flight.prediction_confidence || 'baja')}</span></div>
        <p>${flight.prediction_correct === null || flight.prediction_correct === undefined
          ? 'Pendiente de contrastar con Aena.'
          : Number(flight.prediction_exact)===1
            ? '✓ Acierto exacto: coincidieron el bloque y la cinta oficial.'
            : Number(flight.prediction_correct)===1
              ? `✓ Acierto operativo de bloque; la cinta concreta fue distinta dentro del mismo bloque y Aena publicó ${beltPosition(flight)}.`
              : `✕ Fallo crítico de bloque; la predicción quedó en el bloque ${Number(flight.predicted_belt)<=6?'1–6':'7–8'} y Aena publicó ${beltPosition(flight)}.`}</p>
      </aside>` : ''}
      ${beltDistributionMarkup(flight.belt_distribution_flight, `Mismo vuelo ${flight.physical_flight}`, flight.belt)}
      ${Number(flight.is_canary)===1 ? beltDistributionMarkup(flight.belt_distribution_origin, `Origen canario ${flight.origin_name}`, flight.belt) : ''}
      <div class="belt-event-list">${eventMarkup}</div>
      ${changes.length ? `<aside class="observed-context"><strong>Contexto observado — no demuestra causalidad</strong>${context.map(item=>`<span>${esc(item)}</span>`).join('')}</aside>` : ''}
    </section>`;
  }

  function bindFocusRefreshButtons(root = document) {
    root.querySelectorAll('[data-focus-refresh]').forEach(button => {
      if (button.dataset.bound === '1') return;
      button.dataset.bound = '1';
      button.addEventListener('click', () => refreshFocusedFlight(button.dataset.focusRefresh, button));
    });
  }

  function refreshFocusLiveSections(flight) {
    if (!flight || Number(state.detailFlightId) !== Number(flight.id)) return;
    const hero = els.detailBody.querySelector('[data-focus-hero]');
    const red = els.detailBody.querySelector('[data-focus-red-day]');
    const windowPanel = els.detailBody.querySelector('[data-focus-window]');
    const telemetry = els.detailBody.querySelector('[data-focus-telemetry]');
    if (hero) hero.innerHTML = focusHeroMarkup(flight, state.focusStatus);
    if (red) red.innerHTML = dailyRedUsageMarkup(flight);
    if (windowPanel) windowPanel.innerHTML = focusWindowMarkup(flight);
    if (telemetry) telemetry.innerHTML = telemetrySummary(flight, true);
    bindFocusRefreshButtons(els.detailBody);
  }

  async function refreshFocusedFlight(id, button) {
    const csrf = appMain?.dataset.focusCsrf || '';
    const deviceToken = localStorage.getItem('matrix.pushDevice') || '';
    const surface = button.closest('[data-focus-surface]') || els.detailBody;
    const status = surface.querySelector('[data-focus-refresh-status]');
    const openedFromDetail = Boolean(button.closest('#detailBody'));
    if (!csrf && !deviceToken) {
      if (status) status.textContent = 'Activa las notificaciones de este dispositivo o inicia sesión en Aena / Admin.';
      return;
    }
    button.disabled = true;
    if (status) status.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Consultando telemetría y activando prioridad Aena…';
    try {
      const response = await fetch('api/flight-focus.php', {
        method:'POST',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({flight_id:Number(id),csrf,device_token:deviceToken})
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.error || 'No se pudo actualizar el vuelo.');
      if (data.flight_data) {
        state.flights = state.flights.map(f => Number(f.id) === Number(id) ? data.flight_data : f);
      }
      state.focusStatus = data.focus || null;
      if (state.focusStatus && Number(data.opensky?.retry_after_seconds || 0) > Number(state.focusStatus.retry_after_seconds || 0)) {
        state.focusStatus.retry_after_seconds = Number(data.opensky.retry_after_seconds);
      }
      if (state.focusStatus) state.focusStatus.cooldown_started_at = Date.now();
      const aircraftMessage = data.aircraft?.message || '';
      const flightAwareMessage = data.flightaware?.message || '';
      const openSkyMessage = data.opensky?.message || 'Telemetría sin cambios.';
      state.focusNotice = `${aircraftMessage} ${flightAwareMessage} ${openSkyMessage} ${data.aena_message || ''}`.trim();
      if (openedFromDetail) {
        await openDetail(id);
      } else {
        renderTrackedFocus();
      }
    } catch (error) {
      state.focusNotice = error.message || 'No se pudo actualizar el vuelo.';
      if (status) {
        status.className = 'focus-refresh-status text-danger';
        status.textContent = state.focusNotice;
      }
      button.disabled = false;
    }
  }

  async function openDetail(id) {
    const flight = state.flights.find(f => Number(f.id) === Number(id));
    if (!flight) return;
    state.pinnedFlightId = Number(id);
    localStorage.setItem('matrix.focusFlightId', String(id));
    renderTrackedFocus();
    if (Number(state.detailFlightId) !== Number(id)) {
      state.focusNotice = '';
      state.focusStatus = null;
    }
    stopFlightAwarePolling();
    state.detailFlightId = Number(id);
    els.detailTitle.textContent = `${flight.physical_flight || 'Vuelo'} · ${flight.origin_name || ''}`;
    els.detailBody.innerHTML = '<div class="loading-state"><div class="spinner-border spinner-border-sm"></div></div>';
    bootstrap.Offcanvas.getOrCreateInstance('#flightDetail').show();
    try {
      const response = await fetch(`api/history.php?flight_id=${encodeURIComponent(id)}`, {cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No se pudo cargar el histórico.');
      if (Number(state.detailFlightId) !== Number(id)) return;
      state.focusStatus = payload.focus || state.focusStatus;
      if (state.focusStatus) state.focusStatus.cooldown_started_at = Date.now();
      const summary = `<div class="detail-grid mb-4">
        <div class="detail-stat"><small>Programada</small><strong>${time(flight.scheduled_arrival)}</strong></div>
        <div class="detail-stat"><small>ETA / real</small><strong>${time(effectiveArrival(flight))}</strong></div>
        <div class="detail-stat"><small>${flight.source==='aena'?'Primera cinta oficial':'Primera cinta disponible'}</small><strong>${flight.first_belt_at?`${beltPosition({hall:flight.first_hall,belt:flight.first_belt})} · ${leadText(flight.first_belt_lead_minutes)} antes`:'Pendiente'}</strong></div>
        <div class="detail-stat"><small>Cambios de la fuente prevalente</small><strong>${Number(flight.belt_changes||0)}</strong></div>
        <div class="detail-stat"><small>Aeronave</small><strong>${esc(flight.aircraft_registration||'No verificada')}</strong><span class="meta">${esc(flight.aircraft_type||'')}</span></div>
        <div class="detail-stat"><small>Ocupación</small><strong>${esc(flight.occupancy_level||'no verificable')}</strong></div>
      </div>`;
      const history = compactHistory(payload.history || []);
      const timeline = history.length ? history.map(item => {
        const isAena = item.source === 'aena';
        const officialPosition = beltPosition({hall:flight.hall,belt:flight.belt});
        const conflictsWithAena = !isAena && flight.source === 'aena' && item.belt
          && (String(item.belt) !== String(flight.belt) || (item.hall && String(item.hall) !== String(flight.hall)));
        const authority = isAena
          ? '<span class="badge text-bg-success ms-2">oficial</span>'
          : conflictsWithAena
            ? `<span class="badge text-bg-warning ms-2">provisional · Aena mantiene ${officialPosition}</span>`
            : '<span class="badge text-bg-secondary ms-2">secundario</span>';
        const repeats = item.repeat_count > 1
          ? `<span class="badge text-bg-info ms-2" title="${item.repeat_count} lecturas iguales agrupadas">+${item.repeat_count - 1}</span>`
          : '';
        return `<article class="timeline-item ${isAena?'aena':''}">
          <div class="d-flex justify-content-between gap-2"><strong>${esc((item.source||'').toUpperCase())}${authority}${repeats}</strong><time class="meta text-end">${timelinePeriod(item)}</time></div>
          <div>${esc(item.status||'Observación')} · ${beltPosition(item)}</div>
          <div class="meta">ETA ${time(item.eta)}${item.baggage_state?` · Equipaje: ${esc(item.baggage_state)}`:''}</div>
        </article>`;
      }).join('') : '<p class="text-secondary">Todavía no hay observaciones.</p>';
      const watched = flightIsWatched(id);
      const watch = `<div class="detail-watch-quick"><button class="btn ${watched?'btn-warning':'btn-outline-warning'} w-100" type="button" data-watch-flight="${Number(id)}" data-watching="${watched?'1':'0'}">${watched?'🔔 Vuelo vigilado · desactivar':'🔔 Vigilar este vuelo'}</button></div>`;
      const watchSupport = `<p class="watch-explanation mb-3">1) Vigilancia activa. 2) En unos minutos, itinerario (despegue, llegada y cinta). 3) Aviso a ~40 min. Después, cualquier detección de Aena, AirLabs, FlightStats u OpenSky. Con la app abierta iOS no enseña globo.</p>
        <div class="flightaware-detail mb-4">${flightAwareLink(flight)}<small>Abre el mapa, tipo de avión, velocidad, altitud, distancia y fotografías cuando FlightAware los publique.</small><small data-flightaware-request-status class="d-block mt-2 text-secondary">La lectura pública se solicita al abrir la ficha o al buscar matrícula y telemetría.</small></div>`;
      els.detailBody.innerHTML = watch + `<div class="tracked-focus-layout detail-focus-layout">
        <aside class="tracked-focus-summary">
          <div data-focus-hero>${focusHeroMarkup(flight, state.focusStatus)}</div>
          <div data-focus-red-day>${dailyRedUsageMarkup(flight)}</div>
          <div data-focus-telemetry>${telemetrySummary(flight, true)}</div>
        </aside>
        <div class="tracked-focus-belts" data-focus-window>${focusWindowMarkup(flight)}</div>
      </div>`
        + summary + watchSupport + beltIntelligence(flight)
        + `<div data-flightaware-snapshot>${flightAwareSnapshotMarkup(payload.flightaware)}</div>`
        + `<div data-flightstats-snapshot>${flightStatsSnapshotMarkup(payload.flightstats || flight.flightstats)}</div>`
        + `<h3 class="h6 mb-3">Cronología</h3><div class="timeline">${timeline}</div>`;
      bindFocusRefreshButtons(els.detailBody);
      bindWatchButtons(els.detailBody);
      renderWatchButton(id);
      requestFlightAwareSnapshot(id, payload.flightaware?.observed_at || null);
    } catch (error) {
      els.detailBody.innerHTML = `<div class="alert alert-danger">${esc(error.message)}</div>`;
    }
  }

  function fitTrackedAircraft() {
    if (!state.map) return;
    const tracked = state.flights.filter(f => isFiniteNumber(f.latitude) && isFiniteNumber(f.longitude));
    if (!tracked.length) {
      state.map.setView([SVQ.lat, SVQ.lon], 8);
      return;
    }
    state.map.fitBounds([[SVQ.lat,SVQ.lon], ...tracked.map(f => [Number(f.latitude),Number(f.longitude)])], {padding:[36,36], maxZoom:9});
  }

  function setLoading(show) {
    els.loading.classList.toggle('d-none', !show);
    if (show) { els.table.classList.add('d-none'); els.empty.classList.add('d-none'); }
  }

  document.addEventListener('click', event => {
    const focusTab = event.target.closest('[data-focus-tab]');
    if (focusTab) {
      pinFlight(focusTab.dataset.focusTab);
      renderTrackedFocus();
      return;
    }
    const unpin = event.target.closest('[data-focus-unpin]');
    if (unpin) {
      pinFlight(0);
      renderTrackedFocus();
      return;
    }
    const redToggle = event.target.closest('[data-red-list-toggle]');
    if (redToggle) {
      state.redListOpen = !state.redListOpen;
      document.querySelectorAll('[data-red-list-toggle]').forEach(btn => {
        btn.setAttribute('aria-expanded', String(state.redListOpen));
        btn.textContent = state.redListOpen ? 'Ocultar vuelos' : `Ver vuelos (${btn.dataset.redListToggle})`;
        const list = btn.nextElementSibling;
        if (list?.classList.contains('focus-red-list')) list.hidden = !state.redListOpen;
      });
      return;
    }
    const nightSort = event.target.closest('[data-night-sort]');
    if (nightSort) {
      state.nightSort = nightSort.dataset.nightSort || 'best';
      localStorage.setItem('matrix.nightSort', state.nightSort);
      renderNightWeek();
      return;
    }
    const nightDay = event.target.closest('[data-night-date]');
    if (nightDay) {
      state.nightDate = nightDay.dataset.nightDate || '';
      renderNightWeek();
      return;
    }
    const nightOrigin = event.target.closest('[data-night-origin]');
    if (nightOrigin) {
      state.nightOrigin = nightOrigin.dataset.nightOrigin || '';
      if (state.nightOrigin) localStorage.setItem('matrix.nightOrigin', state.nightOrigin);
      else localStorage.removeItem('matrix.nightOrigin');
      renderNightWeek();
      return;
    }
    const gotoDate = event.target.closest('[data-goto-date]');
    if (gotoDate) {
      if (gotoDate.closest('.night-card, .canary-card')) return;
      setBoardDate(gotoDate.dataset.gotoDate);
      return;
    }
    const canaryDay = event.target.closest('[data-canary-date]');
    if (canaryDay) {
      const date = canaryDay.dataset.canaryDate;
      if (state.canaryWeekScope) state.canaryScrollDate = date;
      if (date !== els.date.value) setBoardDate(date);
      else renderCanaryWatch();
      return;
    }
    const canaryFilter = event.target.closest('[data-canary-filter]');
    if (canaryFilter) {
      const kind = canaryFilter.dataset.canaryFilter;
      if (kind === 'night') {
        state.canaryNight = !state.canaryNight;
        if (state.canaryNight) localStorage.setItem('matrix.canaryNight', '1');
        else localStorage.removeItem('matrix.canaryNight');
      } else if (kind === 'week') {
        state.canaryWeekScope = !state.canaryWeekScope;
        if (state.canaryWeekScope) localStorage.setItem('matrix.canaryWeekScope', '1');
        else localStorage.removeItem('matrix.canaryWeekScope');
      }
      renderCanaryWatch();
      return;
    }
    const canaryOrigin = event.target.closest('[data-canary-origin]');
    if (canaryOrigin) {
      state.canaryOrigin = canaryOrigin.dataset.canaryOrigin || '';
      if (state.canaryOrigin) localStorage.setItem('matrix.canaryOrigin', state.canaryOrigin);
      else localStorage.removeItem('matrix.canaryOrigin');
      renderCanaryWatch();
      return;
    }
    const button = event.target.closest('[data-open-flight]');
    if (button) openDetail(button.dataset.openFlight);
  });
  els.focusPrev?.addEventListener('click', () => stepTrackedFocus(-1));
  els.focusNext?.addEventListener('click', () => stepTrackedFocus(1));
  if (els.trackedFocusBody) {
    let touchStart = null;
    els.trackedFocusBody.addEventListener('touchstart', event => {
      const touch = event.touches[0];
      touchStart = touch ? {x:touch.clientX, y:touch.clientY} : null;
    }, {passive:true});
    els.trackedFocusBody.addEventListener('touchend', event => {
      const touch = event.changedTouches[0];
      if (!touchStart || !touch) return;
      const dx = touch.clientX - touchStart.x;
      const dy = touch.clientY - touchStart.y;
      touchStart = null;
      if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) stepTrackedFocus(dx < 0 ? 1 : -1);
    }, {passive:true});
  }
  els.datePrev?.addEventListener('click', () => setBoardDate(shiftDate(els.date.value, -1)));
  els.dateNext?.addEventListener('click', () => setBoardDate(shiftDate(els.date.value, 1)));
  els.dateToday?.addEventListener('click', () => setBoardDate(madridNow().operationalDate));
  document.querySelector('#flightDetail')?.addEventListener('hidden.bs.offcanvas', () => {
    state.detailFlightId = null;
    state.focusStatus = null;
    state.focusNotice = '';
    stopFlightAwarePolling();
  });
  [els.hall,els.canary,els.secondary].forEach(el => el.addEventListener('change', () => {
    render();
  }));
  els.search.addEventListener('input', render);
  els.date.addEventListener('change', () => setBoardDate(els.date.value));
  els.refresh.addEventListener('click', () => loadBoard(true));
  els.now.addEventListener('click', () => {
    if (els.arrivalsCollapse && !els.arrivalsCollapse.classList.contains('show')) {
      bootstrap.Collapse.getOrCreateInstance(els.arrivalsCollapse).show();
      return;
    }
    scrollToCurrent(true);
  });
  els.fitMap.addEventListener('click', fitTrackedAircraft);
  const bindWindowPair = (input, range, side) => {
    const apply = value => {
      const windows = readCoincidenceWindows();
      updateCoincidenceWindows(
        side === 'green' ? value : windows.green,
        side === 'red' ? value : windows.red,
        side === 'span' ? value : windows.span
      );
    };
    input?.addEventListener('change', () => apply(input.value));
    range?.addEventListener('input', () => {
      if (input) input.value = range.value;
    });
    range?.addEventListener('change', () => apply(range.value));
  };
  bindWindowPair(els.greenWindowInput, els.greenWindowRange, 'green');
  bindWindowPair(els.redWindowInput, els.redWindowRange, 'red');
  bindWindowPair(els.spanWindowInput, els.spanWindowRange, 'span');
  els.resetCoincidenceWindows?.addEventListener('click', () => {
    updateCoincidenceWindows(COINCIDENCE_DEFAULTS.green, COINCIDENCE_DEFAULTS.red, COINCIDENCE_DEFAULTS.span);
    updateNightFromHour(NIGHT_FROM_DEFAULT);
  });
  const applyNightFrom = value => updateNightFromHour(value);
  els.nightFromInput?.addEventListener('change', () => applyNightFrom(els.nightFromInput.value));
  els.nightFromRange?.addEventListener('input', () => {
    if (els.nightFromInput) els.nightFromInput.value = els.nightFromRange.value;
  });
  els.nightFromRange?.addEventListener('change', () => applyNightFrom(els.nightFromRange.value));
  syncCoincidenceSettings();
  els.printCanaryWeek?.addEventListener('click', printCanaryWeek);
  if (els.enableNotifications) els.enableNotifications.addEventListener('click', async () => {
    try {
      await toggleCanaryNotifications();
    } catch (error) { window.alert(error.message); }
    updateNotificationButton();
  });
  if (els.testPushNotification) els.testPushNotification.addEventListener('click', async () => {
    try {
      await sendPushTest();
    } catch (error) { window.alert(error.message); }
  });
  if (els.drillPushNotification) els.drillPushNotification.addEventListener('click', async () => {
    try {
      await sendPushDrill();
    } catch (error) { window.alert(error.message); }
  });
  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-force-check]');
    if (!button) return;
    try {
      await sendForceCheck(button.dataset.forceCheck, button);
    } catch (error) { window.alert(error.message); }
  });
  if (els.radarCollapse) els.radarCollapse.addEventListener('shown.bs.collapse', () => {
    if (state.map) {
      state.map.invalidateSize();
      fitTrackedAircraft();
    }
    if (els.radarToggleLabel) els.radarToggleLabel.textContent = els.radarToggleLabel.textContent.replace('plegado','visible');
  });
  if (els.radarCollapse) els.radarCollapse.addEventListener('hidden.bs.collapse', () => {
    if (els.radarToggleLabel) els.radarToggleLabel.textContent = els.radarToggleLabel.textContent.replace('visible','plegado');
  });
  if (els.arrivalsCollapse) els.arrivalsCollapse.addEventListener('shown.bs.collapse', () => {
    if (els.arrivalsToggleLabel) els.arrivalsToggleLabel.textContent = els.arrivalsToggleLabel.textContent.replace('plegado','visible');
    state.autoScrolledDate = null;
    requestAnimationFrame(() => maybeScrollToNow());
  });
  if (els.arrivalsCollapse) els.arrivalsCollapse.addEventListener('hidden.bs.collapse', () => {
    if (els.arrivalsToggleLabel) els.arrivalsToggleLabel.textContent = els.arrivalsToggleLabel.textContent.replace('visible','plegado');
  });
  els.nightWeekCollapse?.addEventListener('shown.bs.collapse', () => renderNightWeek());
  els.nightWeekCollapse?.addEventListener('hidden.bs.collapse', () => renderNightWeek());

  updateDateLabels();
  updateNotificationButton();
  updateSceneClock();
  setInterval(updateSceneClock, 1000);
  renderCanaryWeek();
  loadCanaryWeek();
  loadNightWeek();
  setInterval(loadCanaryWeek, 120_000);
  setInterval(loadNightWeek, 180_000);
  setInterval(updateDateLabels, 60_000);
  Promise.race([
    refreshNotificationStatus(),
    new Promise(resolve => setTimeout(resolve, 1500))
  ]).finally(() => loadBoard(true));
  setInterval(() => loadBoard(false), Number(document.querySelector('main').dataset.pollSeconds || 15) * 1000);
  setInterval(() => { maybeTickWatchedFlights(); }, 60_000);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    state.watchTickAt = 0;
    holdWatchWakeLock();
    maybeTickWatchedFlights();
  });
})();
