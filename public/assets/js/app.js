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
    canaryWatchEnabled: false, pushLastError: '', pushLastAt: null, pushPending: 0, pushWatches: 0,
    watchedFlightIds: [],
    detailFlightId: null, flightAwarePollTimer: null,
    focusStatus: null, focusNotice: '', pinnedFlightId: Number(localStorage.getItem('matrix.focusFlightId') || 0)
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
    enableNotifications: document.querySelector('#enableNotifications'), canaryAlertStack: document.querySelector('#canaryAlertStack'),
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
    trackedFocusTabs: document.querySelector('#trackedFocusTabs'), mobileDateLabel: document.querySelector('#mobileDateLabel'),
    mobileBeltWatchLabel: document.querySelector('#mobileBeltWatchLabel'),
    clearTrackedFocus: document.querySelector('#clearTrackedFocus'), pushStatusLine: document.querySelector('#pushStatusLine'),
    testPushNotification: document.querySelector('#testPushNotification'), dailyBeltModalBody: document.querySelector('#dailyBeltModalBody'),
    detailTitle: document.querySelector('#flightDetailLabel'), detailBody: document.querySelector('#detailBody')
  };
  if (/^\d{4}-\d{2}-\d{2}$/.test(deepLink.get('date') || '')) els.date.value = deepLink.get('date');

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const parseDate = value => value ? new Date(String(value).replace(' ', 'T')) : null;
  const time = value => value ? new Intl.DateTimeFormat('es-ES',{hour:'2-digit',minute:'2-digit'}).format(parseDate(value)) : '—';
  const dateTime = value => value ? new Intl.DateTimeFormat('es-ES',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}).format(parseDate(value)) : '—';
  const updateMobileDateLabel = () => {
    if (!els.mobileDateLabel) return;
    const parsed = /^\d{4}-\d{2}-\d{2}$/.test(els.date?.value || '') ? new Date(`${els.date.value}T12:00:00`) : null;
    els.mobileDateLabel.textContent = parsed
      ? new Intl.DateTimeFormat('es-ES',{weekday:'short',day:'2-digit',month:'2-digit'}).format(parsed).replace('.', '')
      : 'Elegir día';
  };
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
  const beltOccupancyMarkup = (flight, detailed = false) => {
    const model = flight?.belt_occupancy_30m;
    const arrival = model?.at_arrival;
    const peak = model?.window_peak;
    if (!arrival || !peak) return '';
    const occupied = new Set((arrival.belts || []).map(Number));
    const beltFlights = arrival.belt_flights || {};
    const beltCells = Array.from({length:8}, (_,index) => {
      const belt = index + 1;
      const flights = Array.isArray(beltFlights[belt]) ? beltFlights[belt] : [];
      const official = flights.length > 0 && flights.every(item => item.assignment_source === 'oficial');
      const title = flights.length
        ? flights.map(item => `${item.flight} · ${item.origin} · ${time(item.arrival)} · ${item.assignment_source}`).join(' | ')
        : `Cinta ${belt} libre en la ETA`;
      return `<span class="occupancy-belt ${occupied.has(belt)?'occupied':''} ${belt >= 7?'red':''} ${occupied.has(belt) && !official?'estimated':''}" title="${esc(title)}"><b>${belt}</b><small>${occupied.has(belt)?(official?'Aena':'prev.'):'libre'}</small></span>`;
    }).join('');
    const redStatus = [7,8].map(belt => `C${belt} ${occupied.has(belt)?'ocupada':'libre'}`).join(' · ');
    const confidence = ({alta:'alta',media:'media',baja:'baja'}[model.confidence] || 'pendiente');
    const unknown = Number(model.unknown_belt_flights || 0);
    return `<section class="canary-occupancy ${Number(arrival.red_occupied_count || 0)>0?'has-red':''} ${detailed?'detailed':''}">
      <header><span>Ocupación al aterrizar</span><strong>${Number(arrival.percentage || 0).toLocaleString('es-ES',{maximumFractionDigits:1})}%</strong><b>${Number(arrival.occupied_count || 0)}/8</b></header>
      <div class="occupancy-belts" aria-label="Cintas ocupadas en la ETA">${beltCells}</div>
      <div class="occupancy-red"><span>Bloque 7–8</span><strong>${Number(arrival.red_percentage || 0).toLocaleString('es-ES',{maximumFractionDigits:1})}%</strong><small>${redStatus}</small></div>
      <footer><span>Pico ±30 min: <b>${Number(peak.percentage || 0).toLocaleString('es-ES',{maximumFractionDigits:1})}% · ${Number(peak.occupied_count || 0)}/8</b> a las ${time(peak.at)}</span><span>${Number(model.nearby_flights || 0)} vuelos coincidentes${unknown?` · ${unknown} sin cinta`:''} · confianza ${confidence}</span></footer>
      ${detailed?'<p>Modelo v2: cada llegada reserva su cinta durante 30 minutos. Aena prevalece; “prev.” identifica una cinta provisional o predicha.</p>':''}
    </section>`;
  };

  const relativeMinuteLabel = value => {
    const minutes = Number(value || 0);
    if (minutes === 0) return 'misma hora';
    return minutes < 0 ? `${Math.abs(minutes)} min antes` : `${minutes} min después`;
  };

  function focusWindowMarkup(flight) {
    const model = flight?.belt_occupancy_30m;
    const atArrival = model?.at_arrival;
    const entries = Array.isArray(model?.window_flights) ? model.window_flights : [];
    if (!model || !atArrival) return '<div class="alert alert-secondary">Todavía no hay datos suficientes para calcular el entorno de cintas.</div>';
    const byBelt = new Map();
    entries.forEach(item => {
      const belt = Number(item.belt);
      if (!Number.isInteger(belt) || belt < 1 || belt > 8) return;
      if (!byBelt.has(belt)) byBelt.set(belt, []);
      byBelt.get(belt).push(item);
    });
    const occupied = new Set((atArrival.belts || []).map(Number));
    const tiles = Array.from({length:8}, (_,index) => {
      const belt = index + 1;
      const flights = byBelt.get(belt) || [];
      const active = occupied.has(belt);
      const rows = flights.length ? flights.map(item => {
        const selected = Number(item.id) === Number(flight.id);
        const source = item.assignment_source === 'oficial' ? 'Aena' : item.assignment_source === 'prediccion' ? 'predicción' : 'provisional';
        return `<li class="${selected?'selected':''} ${item.occupies_at_target?'active':''}">
          <span><b>${esc(item.origin)}</b><small>${esc(item.flight)} · ${esc(source)}</small></span>
          <em>${relativeMinuteLabel(item.offset_minutes)}</em>
        </li>`;
      }).join('') : '<li class="empty">Sin llegadas en ±30 min</li>';
      return `<article class="focus-belt ${belt >= 7?'red':''} ${active?'occupied':''}">
        <header><span>Cinta ${belt}</span><strong>${active?'OCUPADA A TU LLEGADA':'sin ocupación activa'}</strong></header>
        <ul>${rows}</ul>
      </article>`;
    }).join('');
    const unknown = entries.filter(item => item.belt === null || item.belt === undefined);
    const unknownMarkup = unknown.length ? `<aside class="focus-unknown"><strong>${unknown.length} vuelo${unknown.length===1?'':'s'} sin cinta conocida</strong>${unknown.map(item=>`<span>${esc(item.origin)} · ${esc(item.flight)} · ${relativeMinuteLabel(item.offset_minutes)}</span>`).join('')}</aside>` : '';
    return `<section class="focus-window">
      <header><div><small>Entorno operativo</small><h3>Las ocho cintas alrededor de ${time(model.target_time)}</h3></div><span>${Number(atArrival.occupied_count || 0)}/8 · ${Number(atArrival.percentage || 0).toLocaleString('es-ES',{maximumFractionDigits:1})}% activas</span></header>
      <p>Se incluyen llegadas desde 30 minutos antes hasta 30 minutos después. Una cinta queda activa durante 30 minutos desde la llegada estimada o real.</p>
      <div class="focus-belt-grid">${tiles}</div>${unknownMarkup}
    </section>`;
  }

  function dailyRedUsageMarkup(flight) {
    const now = madridNow();
    const selectedDate = String(els.date.value);
    const red = state.flights.filter(item => {
      if (item.source !== 'aena' || !['7','8'].includes(String(item.belt))) return false;
      const arrival = parseDate(effectiveArrival(item))?.getTime();
      return selectedDate < now.operationalDate
        || (selectedDate === now.operationalDate && arrival !== undefined && arrival <= now.nowMs);
    });
    const belt7 = red.filter(item => String(item.belt) === '7').length;
    const belt8 = red.filter(item => String(item.belt) === '8').length;
    const used = Number(belt7 > 0) + Number(belt8 > 0);
    const label = selectedDate === now.operationalDate ? 'en lo que llevamos de día operativo' : selectedDate < now.operationalDate ? 'en toda la jornada' : 'hasta ahora';
    return `<section class="focus-red-day ${red.length?'has-red':''}">
      <div><small>Histórico rojo ${esc(label)}</small><strong>${red.length} uso${red.length===1?'':'s'} oficial${red.length===1?'':'es'}</strong><span>${used}/2 cintas rojas utilizadas · ${(used/2*100).toLocaleString('es-ES')}%</span></div>
      <div class="focus-red-split"><span><b>C7</b>${belt7} vuelo${belt7===1?'':'s'}</span><span><b>C8</b>${belt8} vuelo${belt8===1?'':'s'}</span></div>
    </section>`;
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
    const notice = state.focusNotice || (focus?.last_result ? String(focus.last_result) : 'La ficha se mantiene con las capturas automáticas; el botón fuerza una revisión dirigida.');
    return `<section class="flight-focus-hero ${Number(flight.is_canary)===1?'canary':''}" data-focus-surface>
      <div class="focus-flight-title"><span>${Number(flight.is_canary)===1?'🌴':'✈️'}</span><div><small>Vuelo bajo foco</small><h3>${esc(flight.physical_flight)} · ${esc(flight.origin_name)}</h3><p>${esc(flight.status || 'Estado pendiente')} · ${trend.label}</p></div></div>
      <div class="focus-primary-values">
        <span><small>Programada</small><b>${time(flight.scheduled_arrival)}</b></span>
        <span><small>ETA / real</small><b>${time(effectiveArrival(flight))}</b><em>${signed(flight.deviation_minutes)}</em></span>
        <span><small>Cinta confirmada</small><b>${esc(official)}</b></span>
        <span><small>Predicción</small><b>${esc(predicted)}</b></span>
      </div>
      <div class="focus-hero-actions">
        <button class="btn btn-info w-100" type="button" data-focus-refresh="${Number(flight.id)}" ${disabled?'disabled':''}>↻ ${esc(buttonLabel)}</button>
        ${allowExpand ? `<button class="btn btn-outline-light w-100" type="button" data-open-flight="${Number(flight.id)}">Ampliar vuelo</button>` : ''}
      </div>
      <small class="focus-refresh-status" data-focus-refresh-status>${esc(notice)}</small>
      <footer>OpenSky: máximo una consulta por vuelo cada 3 min y 90 s entre consultas globales · FlightAware: 5 min · Aena: cada 15 min en la última hora y cada 5 min desde T‑20 hasta resolver equipaje.</footer>
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

  function updateNotificationButton() {
    if (!els.enableNotifications) return;
    const capabilityError = pushCapabilityError();
    if (capabilityError) {
      els.enableNotifications.classList.add('d-none');
      els.testPushNotification?.classList.add('d-none');
      if (els.pushStatusLine) {
        els.pushStatusLine.className = 'push-status-line push-status-warning';
        els.pushStatusLine.textContent = capabilityError;
      }
      return;
    }
    const pushReady = Notification.permission === 'granted' && !!localStorage.getItem('matrix.pushDevice');
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
      els.testPushNotification.classList.toggle('d-none', !pushReady);
      els.testPushNotification.disabled = !pushReady;
      els.testPushNotification.textContent = 'Probar aviso real';
    }
    if (els.pushStatusLine) {
      els.pushStatusLine.className = `push-status-line ${state.pushLastError?'push-status-error':pushReady?'push-status-ready':''}`;
      els.pushStatusLine.textContent = state.pushLastError
        ? `El servidor intentó avisar, pero falló: ${state.pushLastError}`
        : pushReady
          ? `Web Push preparado · ${state.pushWatches} vuelo${state.pushWatches===1?'':'s'} vigilado${state.pushWatches===1?'':'s'} · ${state.pushPending} aviso${state.pushPending===1?'':'s'} pendiente${state.pushPending===1?'':'s'}${state.pushLastAt ? ` · último envío ${dateTime(state.pushLastAt)}` : ' · todavía sin envíos registrados'}.`
          : 'Los globos de la web no son avisos de iOS. Activa Web Push desde la app instalada en la pantalla de inicio.';
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
      if (!state.pinnedFlightId && state.watchedFlightIds.length) {
        state.pinnedFlightId = state.watchedFlightIds[0];
        localStorage.setItem('matrix.focusFlightId', String(state.pinnedFlightId));
      }
      if (Notification.permission === 'granted') await ensureServiceWorker(token);
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

  async function sendPushTest() {
    let token = localStorage.getItem('matrix.pushDevice');
    if (!token) token = await enablePushNotifications();
    if (!token) throw new Error('No existe una suscripción Web Push válida en este dispositivo.');
    const button = els.testPushNotification;
    if (button) {
      button.disabled = true;
      button.textContent = 'Enviando…';
    }
    try {
      const response = await fetch('api/push.php', {
        method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'},
        body:JSON.stringify({action:'test',device_token:token})
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || 'El servidor no pudo enviar la prueba.');
      state.pushLastError = '';
      state.pushLastAt = data.sent_at || new Date().toISOString();
      if (els.pushStatusLine) {
        els.pushStatusLine.className = 'push-status-line push-status-ready';
        els.pushStatusLine.textContent = 'Prueba aceptada por el servicio Push. Debe aparecer como notificación del sistema en unos segundos.';
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

  async function ensureServiceWorker(token = '') {
    let registration;
    try {
      registration = await navigator.serviceWorker.register('sw.js', {scope:'./', updateViaCache:'none'});
    } catch (_) {
      registration = await navigator.serviceWorker.register('sw.js', {scope:'./'});
    }
    try { await registration.update(); } catch (_) {}
    const ready = await navigator.serviceWorker.ready;
    if (token) {
      const worker = ready.active || registration.active || navigator.serviceWorker.controller;
      worker?.postMessage({type:'matrix-device-token',token});
    }
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
    (registration.active || navigator.serviceWorker.controller)?.postMessage({type:'matrix-device-token',token:data.device_token});
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
        state.pinnedFlightId = Number(flightId);
        localStorage.setItem('matrix.focusFlightId', String(flightId));
        await showPushConfirmation();
      } else {
        state.watchedFlightIds = state.watchedFlightIds.filter(id => Number(id) !== Number(flightId));
        if (Number(state.pinnedFlightId) === Number(flightId)) {
          state.pinnedFlightId = Number(state.watchedFlightIds[0] || 0);
          if (state.pinnedFlightId) localStorage.setItem('matrix.focusFlightId', String(state.pinnedFlightId));
          else localStorage.removeItem('matrix.focusFlightId');
        }
      }
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
      if (response.ok && data.watching) {
        state.pinnedFlightId = Number(flightId);
        localStorage.setItem('matrix.focusFlightId', String(flightId));
        renderTrackedFocus();
      }
    } catch (_) {}
  }

  async function loadBoard(showSpinner = false) {
    if (state.loading) return;
    state.loading = true;
    if (showSpinner) setLoading(true);
    els.refresh.disabled = true;
    try {
      const response = await fetch(`api/board.php?date=${encodeURIComponent(els.date.value)}`, {headers:{Accept:'application/json'}, cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No se pudo actualizar el tablero.');
      const incoming = payload.flights || [];
      processCanaryAlerts(incoming, els.date.value);
      state.flights = incoming;
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
    } catch (error) {
      els.connection.className = 'badge text-bg-danger';
      els.connection.textContent = 'Sin conexión';
      els.error.textContent = error.message;
      els.error.classList.remove('d-none');
    } finally {
      state.loading = false;
      els.refresh.disabled = false;
      setLoading(false);
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
      <td><strong>${esc(f.physical_flight)}</strong><span class="meta d-block">${esc(f.codes || '')}</span></td>
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
    document.querySelectorAll('.flight-row, .canary-card, .upcoming-flight, .flow-flight, .scene-plane, .scene-belt.has-flight, .belt-change-card').forEach(item => {
      if (item.dataset.flightOpenerBound === '1') return;
      item.dataset.flightOpenerBound = '1';
      item.addEventListener('click', () => openDetail(item.dataset.flightId));
      item.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openDetail(item.dataset.flightId);
        }
      });
    });
  }

  function renderTrackedFocus() {
    if (!els.trackedFocusPanel || !els.trackedFocusBody) return;
    const flight = state.flights.find(item => Number(item.id) === Number(state.pinnedFlightId));
    if (!flight) {
      els.trackedFocusPanel.classList.add('d-none');
      els.trackedFocusBody.innerHTML = '';
      if (els.trackedFocusTabs) {
        els.trackedFocusTabs.classList.add('d-none');
        els.trackedFocusTabs.innerHTML = '';
      }
      return;
    }
    const watchedFlights = state.watchedFlightIds
      .map(id => state.flights.find(item => Number(item.id) === Number(id)))
      .filter(Boolean);
    if (els.trackedFocusTabs) {
      els.trackedFocusTabs.classList.toggle('d-none', watchedFlights.length < 2);
      els.trackedFocusTabs.innerHTML = watchedFlights.map(item => `<button type="button" class="tracked-focus-tab ${Number(item.id)===Number(flight.id)?'active':''}" data-focus-tab="${Number(item.id)}" aria-pressed="${Number(item.id)===Number(flight.id)?'true':'false'}"><strong>${esc(item.physical_flight)}</strong><span>${esc(item.origin_name)} · ${time(effectiveArrival(item))}</span></button>`).join('');
    }
    els.trackedFocusPanel.classList.remove('d-none');
    els.trackedFocusBody.innerHTML = `<div class="tracked-focus-layout">
      <aside class="tracked-focus-summary">
        ${focusHeroMarkup(flight, Number(state.detailFlightId) === Number(flight.id) ? state.focusStatus : null, true)}
        ${dailyRedUsageMarkup(flight)}
        ${telemetrySummary(flight, true)}
      </aside>
      <div class="tracked-focus-belts">${focusWindowMarkup(flight)}</div>
      </div>`;
    bindFocusRefreshButtons(els.trackedFocusBody);
  }

  function renderCanaryWatch() {
    const flights = state.flights.filter(f => Number(f.is_canary) === 1);
    if (!flights.length) {
      els.canaryWatch.innerHTML = '<div class="canary-empty">No hay vuelos canarios cargados para esta fecha.</div>';
      return;
    }
    const now = madridNow();
    els.canaryWatch.innerHTML = flights.map(f => {
      const arrival = parseDate(effectiveArrival(f))?.getTime();
      const past = els.date.value === now.operationalDate && arrival !== undefined && arrival < now.nowMs - 45 * 60 * 1000;
      const confirmedBelt = f.source === 'aena' && f.belt ? beltPosition(f) : null;
      const predictedBelt = f.predicted_belt ? `${f.predicted_hall || (Number(f.predicted_belt) >= 7 ? 'B' : 'A')}/${f.predicted_belt}` : null;
      const beltClass = ['7','8'].includes(String(f.belt)) || ['7','8'].includes(String(f.predicted_belt)) || f.belt_attention ? 'danger' : '';
      const sharedCodes = codeshareCodes(f);
      const watched = flightIsWatched(f.id);
      return `<article class="canary-card ${past?'past':''} ${beltClass}" data-flight-id="${Number(f.id)}" tabindex="0">
        <div class="d-flex justify-content-between align-items-start gap-2"><strong>${esc(f.origin_name)}</strong><span>${esc(f.indicator)}</span></div>
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
        <div class="canary-card-actions">
          <button class="canary-quick-watch ${watched?'active':''}" type="button" data-watch-flight="${Number(f.id)}" data-watch-compact="1" data-watching="${watched?'1':'0'}" aria-pressed="${watched?'true':'false'}"><span aria-hidden="true">${watched?'🔔':'🔕'}</span> ${watched?'Vigilando':'Vigilar'}</button>
          ${flightAwareLink(f, true)}
        </div>
      </article>`;
    }).join('');
    bindWatchButtons(els.canaryWatch);
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
    els.upcomingStrip.innerHTML = flights.map((f,index) => {
      const officialBelt = f.source === 'aena' && f.belt ? f.belt_label : 'pendiente Aena';
      return `<article class="upcoming-flight ${Number(f.is_canary)===1?'canary':''}" data-flight-id="${Number(f.id)}" tabindex="0">
        <span class="upcoming-order">${index + 1}</span>
        <span class="upcoming-plane" aria-hidden="true">✈</span>
        <div><strong>${esc(f.physical_flight)}</strong><b>${esc(f.origin_name)}</b><small>${esc(f.origin_iata)} · ${esc(f.aircraft_type || 'avión pendiente')}</small><small>μ cinta ${leadText(f.belt_lead_flight_average_minutes)} · n=${Number(f.belt_lead_flight_samples || 0)}</small></div>
        ${timingMarkup(f, 'upcoming-timing')}
        <em>${esc(officialBelt)}</em>
        ${flightAwareLink(f, true)}
      </article>`;
    }).join('');
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
      const rank = f._sceneRank ? `#${f._sceneRank} · ` : '';
      const direction = side === 'left' ? 'entrada oeste/09' : side === 'right' ? 'entrada este/27' : 'dirección pendiente';
      const positionText = f.stand ? `posición ${esc(f.stand)}` : f.gate ? `puerta ${esc(f.gate)} · puesto sin verificar` : 'posición pendiente';
      const detail = position.gps
        ? `${rank}${distance!==null?`${Math.round(distance)} km · `:''}${direction}`
        : `${rank}sin GPS · ${direction}`;
      node.className = `scene-plane scene-plane-${stage} scene-plane-side-${side} ${canary?'scene-plane-canary':''} ${position.gps?'scene-plane-live':'scene-plane-planned'}`;
      if (isNew) node.classList.add('scene-plane-entering');
      node.dataset.targetLeft = position.left;
      node.dataset.targetTop = position.top;
      node.style.setProperty('--scene-heading', `${sceneHeading(stage,f)}deg`);
      node.title = `${f.physical_flight} · ${f.origin_name} · ${stageLabels[stage]} · ${destination}`;
      node.innerHTML = `<span class="scene-plane-icon" aria-hidden="true">${stage==='ground'?'🧳':'✈'}</span>
        <span class="scene-plane-data"><strong>${esc(f.physical_flight)}${f._sceneRank?` · #${f._sceneRank}`:''}</strong><em>${esc(f.origin_name)}</em><small>${stage==='ground'?`${destination} oficial · entrega pendiente`:detail}</small></span>`;
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
      const watchSupport = `<p class="watch-explanation mb-3">Durante la última hora: Aena cada 15 min; desde 20 min antes, cada 5 min hasta resolver el equipaje. Incluye avisos de despegue, ETA, cinta, incidencias, aterrizaje y aproximadamente 40 min antes de llegar.</p>
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
      state.pinnedFlightId = Number(focusTab.dataset.focusTab);
      localStorage.setItem('matrix.focusFlightId', String(state.pinnedFlightId));
      renderTrackedFocus();
      return;
    }
    const button = event.target.closest('[data-open-flight]');
    if (button) openDetail(button.dataset.openFlight);
  });
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
  els.date.addEventListener('change', () => {
    updateMobileDateLabel();
    state.autoScrolledDate = null;
    state.mapHasFitted = false;
    loadBoard(true);
  });
  els.refresh.addEventListener('click', () => loadBoard(true));
  els.now.addEventListener('click', () => {
    if (els.arrivalsCollapse && !els.arrivalsCollapse.classList.contains('show')) {
      bootstrap.Collapse.getOrCreateInstance(els.arrivalsCollapse).show();
      return;
    }
    scrollToCurrent(true);
  });
  els.fitMap.addEventListener('click', fitTrackedAircraft);
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
  if (els.clearTrackedFocus) els.clearTrackedFocus.addEventListener('click', () => {
    state.pinnedFlightId = 0;
    localStorage.removeItem('matrix.focusFlightId');
    renderTrackedFocus();
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

  updateMobileDateLabel();
  updateNotificationButton();
  updateSceneClock();
  setInterval(updateSceneClock, 1000);
  Promise.race([
    refreshNotificationStatus(),
    new Promise(resolve => setTimeout(resolve, 1500))
  ]).finally(() => loadBoard(true));
  setInterval(() => loadBoard(false), Number(document.querySelector('main').dataset.pollSeconds || 15) * 1000);
})();
