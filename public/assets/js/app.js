(() => {
  'use strict';

  const SVQ = {lat: 37.4179, lon: -5.8931};
  const state = {
    flights: [], loading: false, autoScrolledDate: null, pastExtra: 0, futureExtra: 0,
    scenePositions: new Map(),
    map: null, mapLayer: null, aircraftLayer: null, mapHasFitted: false
  };
  const els = {
    date: document.querySelector('#flightDate'), hall: document.querySelector('#hallFilter'),
    search: document.querySelector('#searchInput'), canary: document.querySelector('#canaryOnly'),
    secondary: document.querySelector('#secondaryOnly'), refresh: document.querySelector('#refreshButton'),
    now: document.querySelector('#nowButton'), fitMap: document.querySelector('#fitMapButton'),
    body: document.querySelector('#flightsBody'), table: document.querySelector('#tableWrap'),
    loading: document.querySelector('#loadingState'), empty: document.querySelector('#emptyState'),
    error: document.querySelector('#errorAlert'), updated: document.querySelector('#lastUpdated'),
    connection: document.querySelector('#connectionBadge'), canaryWatch: document.querySelector('#canaryWatch'),
    upcomingStrip: document.querySelector('#upcomingStrip'),
    mapStatus: document.querySelector('#mapStatus'), airportFlow: document.querySelector('#airportFlow'),
    airportScene: document.querySelector('#airportScene'), sceneAircraft: document.querySelector('#sceneAircraft'),
    sceneTrails: document.querySelector('#sceneTrails'), sceneClock: document.querySelector('#sceneClock'),
    sceneMovementCount: document.querySelector('#sceneMovementCount'), sceneBelts: document.querySelector('#sceneBelts'),
    beltChangesSection: document.querySelector('#beltChangesSection'), beltChangesStrip: document.querySelector('#beltChangesStrip'),
    beltChangesCount: document.querySelector('#beltChangesCount'),
    radarToggleLabel: document.querySelector('#radarToggleLabel'), radarCollapse: document.querySelector('#radarCollapse'),
    metricFlights: document.querySelector('#metricFlights'), metricOrange: document.querySelector('#metricOrange'),
    metricRed: document.querySelector('#metricRed'), metricHall: document.querySelector('#metricHall'),
    detailTitle: document.querySelector('#flightDetailLabel'), detailBody: document.querySelector('#detailBody')
  };

  const esc = value => String(value ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const parseDate = value => value ? new Date(String(value).replace(' ', 'T')) : null;
  const time = value => value ? new Intl.DateTimeFormat('es-ES',{hour:'2-digit',minute:'2-digit'}).format(parseDate(value)) : '—';
  const dateTime = value => value ? new Intl.DateTimeFormat('es-ES',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}).format(parseDate(value)) : '—';
  const signed = value => Number(value) > 0 ? `+${value} min` : `${value} min`;
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
    return `${sign}${hours} h${remainder ? ` ${remainder} min` : ''}`;
  };
  const effectiveArrival = f => f.actual_arrival || f.eta || f.effective_arrival || f.scheduled_arrival;
  const minuteOfDay = value => {
    const match = String(value || '').match(/(?:T|\s)(\d{2}):(\d{2})/);
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
  };
  const madridNow = () => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
      timeZone:'Europe/Madrid', year:'numeric', month:'2-digit', day:'2-digit',
      hour:'2-digit', minute:'2-digit', hourCycle:'h23'
    }).formatToParts(new Date()).filter(p => p.type !== 'literal').map(p => [p.type, p.value]));
    return {date:`${parts.year}-${parts.month}-${parts.day}`, minutes:Number(parts.hour) * 60 + Number(parts.minute)};
  };
  const telemetryAgeMinutes = f => {
    const observed = parseDate(f.telemetry_observed_at);
    return observed ? Math.max(0, Math.round((Date.now() - observed.getTime()) / 60000)) : null;
  };
  const isFiniteNumber = value => value !== null && value !== '' && Number.isFinite(Number(value));
  const speedKmh = f => isFiniteNumber(f.ground_speed_ms) ? Math.round(Number(f.ground_speed_ms) * 3.6) : null;
  const speedKt = f => isFiniteNumber(f.ground_speed_ms) ? Math.round(Number(f.ground_speed_ms) * 1.94384) : null;
  const altitudeFt = f => isFiniteNumber(f.altitude_m) ? Math.round(Number(f.altitude_m) * 3.28084 / 100) * 100 : null;
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
  const compactHistory = history => history.reduce((groups, item) => {
    const previous = groups[groups.length - 1];
    const key = timelineKey(item);
    if (item.source === 'opensky' && previous?.source === 'opensky' && previous._timelineKey === key) {
      previous.repeat_count += 1;
      previous.last_observed_at = item.observed_at;
      return groups;
    }
    groups.push({...item, repeat_count:1, last_observed_at:item.observed_at, _timelineKey:key});
    return groups;
  }, []);
  const timelinePeriod = item => item.repeat_count > 1
    ? `${dateTime(item.observed_at)}–${item.observed_at?.slice(0,10)===item.last_observed_at?.slice(0,10)?time(item.last_observed_at):dateTime(item.last_observed_at)}`
    : dateTime(item.observed_at);
  const statusIsActive = flight => {
    const value = `${flight.status || ''} ${flight.baggage_state || ''}`.toLowerCase();
    return /vuelo|route|airborne|aproxim|landed|tierra|entrega|delivery/.test(value) && !/final|cancel/.test(value);
  };
  const beltAverageMarkup = flight => `<span class="belt-average" title="Media calculada con primeras publicaciones oficiales Aena de días anteriores">
    μ vuelo ${leadText(flight.belt_lead_flight_average_minutes)} · n=${Number(flight.belt_lead_flight_samples || 0)}<br>
    μ origen ${leadText(flight.belt_lead_origin_average_minutes)} · n=${Number(flight.belt_lead_origin_samples || 0)}
  </span>`;

  async function loadBoard(showSpinner = false) {
    if (state.loading) return;
    state.loading = true;
    if (showSpinner) setLoading(true);
    els.refresh.disabled = true;
    try {
      const response = await fetch(`api/board.php?date=${encodeURIComponent(els.date.value)}`, {headers:{Accept:'application/json'}, cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No se pudo actualizar el tablero.');
      state.flights = payload.flights || [];
      els.updated.textContent = `Actualizado ${time(payload.generated_at)} · ${payload.authority_note}`;
      els.connection.className = 'badge text-bg-success';
      els.connection.textContent = 'En directo';
      els.error.classList.add('d-none');
      render();
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

  function filtered() {
    const query = els.search.value.trim().toLowerCase();
    return state.flights.filter(f => (!els.hall.value || f.hall === els.hall.value)
      && (!els.canary.checked || Number(f.is_canary) === 1)
      && (!els.secondary.checked || Boolean(f.secondary_belt_state))
      && (!query || `${f.origin_name} ${f.origin_iata} ${f.physical_flight} ${f.codes}`.toLowerCase().includes(query)));
  }

  function windowedGroups(flights) {
    const now = madridNow();
    if (els.date.value !== now.date) {
      return {previous:[], current:flights.slice(0, 5), next:flights.slice(5, 10), totalPrevious:0, totalNext:Math.max(0, flights.length - 5)};
    }
    const withMinute = flights.map(f => ({flight:f, minute:minuteOfDay(effectiveArrival(f))})).filter(item => item.minute !== null);
    const current = withMinute
      .filter(item => Math.abs(item.minute - now.minutes) <= 60)
      .sort((a,b) => Number(statusIsActive(b.flight)) - Number(statusIsActive(a.flight))
        || Math.abs(a.minute-now.minutes) - Math.abs(b.minute-now.minutes))
      .slice(0,5).sort((a,b)=>a.minute-b.minute).map(item=>item.flight);
    const selected = new Set(current.map(f => Number(f.id)));
    const previousAll = withMinute.filter(item => item.minute < now.minutes && !selected.has(Number(item.flight.id)));
    const nextAll = withMinute.filter(item => item.minute >= now.minutes && !selected.has(Number(item.flight.id)));
    return {
      previous: previousAll.slice(-(5 + state.pastExtra)).map(item=>item.flight),
      current,
      next: nextAll.slice(0, 5 + state.futureExtra).map(item=>item.flight),
      totalPrevious: previousAll.length,
      totalNext: nextAll.length
    };
  }

  function flightRowMarkup(f, currentIds) {
    const beltClass = !f.belt ? 'standby' : (['7','8'].includes(String(f.belt)) ? 'red' : '');
    const interval = [f.previous_same_belt_minutes, f.next_same_belt_minutes].filter(v => v !== null).sort((a,b)=>a-b)[0];
    return `<tr class="flight-row ${Number(f.is_canary)===1?'canary':''} ${currentIds.has(Number(f.id))?'current-flight':''}" data-flight-id="${Number(f.id)}" data-effective-minute="${minuteOfDay(effectiveArrival(f)) ?? ''}" tabindex="0">
      <td><span class="indicator" title="${esc(pressureText(f.pressure))}">${esc(f.indicator)}</span></td>
      <td><span class="time-primary">${time(f.scheduled_arrival)}</span></td>
      <td><span class="belt ${beltClass}">${esc(f.belt_label)}</span>${secondaryBeltMarkup(f)}${beltAverageMarkup(f)}</td>
      <td><span class="origin-name">${esc(f.origin_name)}</span><span class="origin-code d-block">${esc(f.origin_iata)} · ${esc(f.traffic_class)}</span></td>
      <td><strong>${esc(f.physical_flight)}</strong><span class="meta d-block">${esc(f.codes || '')}</span></td>
      <td><strong>${time(effectiveArrival(f))}</strong><span class="meta d-block">${signed(f.deviation_minutes)}</span></td>
      <td><span class="pill pill-${esc(f.pressure.replace('_','-'))}">${interval===undefined?'Sin adyacente':`${interval} min`}</span></td>
      <td><strong>${hallLoadText(f.hall_load)}</strong><span class="meta d-block">${f.hall_flights_30m} vuelos · ${Number(f.hall_capacity_30m).toLocaleString('es-ES')} plazas</span></td>
      <td><span class="${f.source==='aena'?'source-aena':'source-other'}">${esc((f.source||'sin dato').toUpperCase())}</span><span class="meta d-block">${time(f.observed_at)}</span></td>
    </tr>`;
  }

  function groupHeader(label, count, moreType = '', hiddenCount = 0) {
    const button = moreType && hiddenCount > 0
      ? `<button type="button" class="btn btn-sm btn-outline-light" data-more-flights="${moreType}">Ver 5 más (${hiddenCount})</button>` : '';
    return `<tr class="flight-group-row"><td colspan="9"><span>${label}</span><b>${count}</b>${button}</td></tr>`;
  }

  function render() {
    const flights = filtered();
    const groups = windowedGroups(flights);
    const currentIds = new Set(groups.current.map(f => Number(f.id)));
    const previousHidden = Math.max(0, groups.totalPrevious - groups.previous.length);
    const nextHidden = Math.max(0, groups.totalNext - groups.next.length);
    els.body.innerHTML = [
      groupHeader('Anteriores', groups.previous.length, 'past', previousHidden),
      ...groups.previous.map(f => flightRowMarkup(f,currentIds)),
      groupHeader('Ahora · ±60 min', groups.current.length),
      ...groups.current.map(f => flightRowMarkup(f,currentIds)),
      groupHeader('Posteriores', groups.next.length, 'future', nextHidden),
      ...groups.next.map(f => flightRowMarkup(f,currentIds))
    ].join('');
    els.table.classList.toggle('d-none', flights.length === 0);
    els.empty.classList.toggle('d-none', flights.length !== 0);
    metrics(flights);
    renderCanaryWatch();
    renderUpcomingLine();
    renderMap();
    renderAirportFlow();
    renderBeltChanges();
    bindFlightOpeners();
    maybeScrollToNow();
  }

  function bindFlightOpeners() {
    document.querySelectorAll('.flight-row, .canary-card, .upcoming-flight, .flow-flight, .scene-plane, .scene-belt.has-flight, .belt-change-card').forEach(item => {
      item.addEventListener('click', () => openDetail(item.dataset.flightId));
      item.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          openDetail(item.dataset.flightId);
        }
      });
    });
  }

  function renderCanaryWatch() {
    const flights = state.flights.filter(f => Number(f.is_canary) === 1);
    if (!flights.length) {
      els.canaryWatch.innerHTML = '<div class="canary-empty">No hay vuelos canarios cargados para esta fecha.</div>';
      return;
    }
    const now = madridNow();
    els.canaryWatch.innerHTML = flights.map(f => {
      const minutes = minuteOfDay(effectiveArrival(f));
      const past = els.date.value === now.date && minutes !== null && minutes < now.minutes - 45;
      const beltClass = ['7','8'].includes(String(f.belt)) ? 'danger' : '';
      return `<article class="canary-card ${past?'past':''} ${beltClass}" data-flight-id="${Number(f.id)}" tabindex="0">
        <div class="d-flex justify-content-between align-items-start gap-2"><strong>${esc(f.origin_name)}</strong><span>${esc(f.indicator)}</span></div>
        <div class="canary-card-time">${time(effectiveArrival(f))}</div>
        <div class="d-flex justify-content-between gap-2"><span>${esc(f.physical_flight)}</span><b>${esc(f.belt_label || 'sin cinta')}</b></div>
        ${f.secondary_belt_state === 'candidate' ? `<small class="canary-proposal">● ${esc((f.secondary_belt_source||'API').toUpperCase())}: ${beltPosition({hall:f.secondary_hall,belt:f.secondary_belt})}</small>` : ''}
      </article>`;
    }).join('');
  }

  function renderUpcomingLine() {
    const now = madridNow();
    const isToday = els.date.value === now.date;
    const flights = state.flights.filter(f => {
      const status = `${f.status || ''} ${f.baggage_state || ''}`.toLowerCase();
      if (status.includes('final') || status.includes('cancel')) return false;
      const minute = minuteOfDay(effectiveArrival(f));
      return !isToday || minute === null || minute >= now.minutes - 10;
    }).slice(0, 10);
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
        <time>${time(effectiveArrival(f))}</time>
        <em>${esc(officialBelt)}</em>
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
        <small>OpenSky · ${age === null ? 'hora desconocida' : age === 0 ? 'ahora' : `hace ${age} min`}${stale?' · señal antigua':''}</small>
        <button type="button" class="btn btn-sm btn-success w-100 mt-2" data-open-flight="${Number(f.id)}">Ver ficha completa</button>
      </div>`, {maxWidth:300});
      bounds.push([lat, lon]);
    });
    els.mapStatus.textContent = tracked.length
      ? `${tracked.length} aeronave${tracked.length===1?'':'s'} con posición · OpenSky es telemetría secundaria`
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
    const baggage = String(f.baggage_state || '').toLowerCase();
    const status = `${f.status || ''} ${f.telemetry_status || ''}`.toLowerCase();
    if (baggage.includes('entrega') || baggage.includes('delivery')) return 'baggage';
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

  function renderAirportFlow() {
    const now = madridNow();
    let active = state.flights.filter(f => {
      const baggage = String(f.baggage_state || '').toLowerCase();
      if (baggage.includes('entrega')) return true;
      const minute = minuteOfDay(effectiveArrival(f));
      if (els.date.value !== now.date) return !baggage.includes('final');
      return minute !== null && minute >= now.minutes - 45 && minute <= now.minutes + 150 && !baggage.includes('final');
    });
    active = active.slice(0, 18);
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

  function projectTelemetry(latitude, longitude) {
    if (!isFiniteNumber(latitude) || !isFiniteNumber(longitude)) return null;
    return {
      left:clamp(50 + (Number(longitude) - SVQ.lon) * 25, 5, 95),
      top:clamp(33 - (Number(latitude) - SVQ.lat) * 18, 8, 58)
    };
  }

  function scenePosition(stage, index, flight) {
    const gps = projectTelemetry(flight.latitude, flight.longitude);
    if (gps && (stage === 'enroute' || stage === 'approach')) return {...gps, gps:true};
    const side = approachSide(flight);
    if (stage === 'approach') {
      return side === 'right'
        ? {left:72 - (index % 3) * 7, top:34 + (index % 2) * 6, gps:false}
        : {left:28 + (index % 3) * 7, top:34 + (index % 2) * 6, gps:false};
    }
    if (stage === 'enroute') {
      if (side === 'right') return {left:92 - (index % 3) * 7, top:12 + (index % 3) * 10, gps:false};
      if (side === 'left') return {left:8 + (index % 3) * 7, top:12 + (index % 3) * 10, gps:false};
      return {left:42 + (index % 4) * 6, top:10 + Math.floor(index / 4) * 8, gps:false};
    }
    if (stage === 'ground') return {left:66 + (index % 4) * 5, top:55 + (index % 2) * 7, gps:false};
    if (stage === 'baggage') return {left:77 + (index % 4) * 4, top:59 + (index % 2) * 6, gps:false};
    return {left:40 + (index % 5) * 5, top:10 + Math.floor(index / 5) * 7, gps:false};
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
    const baggageLinks = active.filter(f => f.source === 'aena' && f.belt && ['ground','baggage'].includes(flowStage(f))).map(f => {
      const beltNumber = Number(f.belt);
      if (!Number.isInteger(beltNumber) || beltNumber < 1 || beltNumber > 8) return '';
      const beltX = (8 - beltNumber + .5) * 12.5;
      return `<path class="scene-baggage-link" d="M78 60 Q${(78+beltX)/2} 72 ${beltX} 87"/>`;
    }).join('');
    els.sceneTrails.innerHTML = gpsTrails + baggageLinks;
  }

  function renderAirportScene(active) {
    if (!els.sceneAircraft) return;
    const counters = {waiting:0,enroute:0,approach:0,ground:0,baggage:0};
    const stageLabels = {waiting:'previsto',enroute:'en ruta',approach:'aterrizando',ground:'en tierra',baggage:'equipaje'};
    const nextPositions = new Map();
    els.sceneAircraft.innerHTML = active.map(f => {
      const stage = flowStage(f);
      const position = scenePosition(stage, counters[stage]++, f);
      const previous = state.scenePositions.get(Number(f.id)) || position;
      nextPositions.set(Number(f.id), position);
      const destination = f.belt ? beltPosition(f) : 'cinta pendiente';
      const canary = Number(f.is_canary) === 1;
      return `<button type="button" class="scene-plane scene-plane-${stage} ${canary?'scene-plane-canary':''}" data-flight-id="${Number(f.id)}"
        data-target-left="${position.left}" data-target-top="${position.top}" style="left:${previous.left}%;top:${previous.top}%;--scene-heading:${sceneHeading(stage,f)}deg"
        title="${esc(f.physical_flight)} · ${esc(f.origin_name)} · ${esc(stageLabels[stage])} · ${destination}">
        <span class="scene-plane-icon" aria-hidden="true">✈</span>
        <span class="scene-plane-data"><strong>${esc(f.physical_flight)}</strong><em>${esc(f.origin_name)}</em><small>${position.gps?'GPS ADS‑B':approachSide(f)==='unknown'?'sin dirección ADS‑B':'rumbo ADS‑B'} · ${time(effectiveArrival(f))} · ${destination}</small></span>
      </button>`;
    }).join('');
    requestAnimationFrame(() => els.sceneAircraft.querySelectorAll('.scene-plane').forEach(node => {
      node.style.left = `${node.dataset.targetLeft}%`;
      node.style.top = `${node.dataset.targetTop}%`;
    }));
    state.scenePositions = nextPositions;
    els.sceneMovementCount.textContent = active.length;
    renderSceneTrails(active);
    renderSceneBelts(active);
  }

  function renderSceneBelts(active) {
    if (!els.sceneBelts) return;
    els.sceneBelts.innerHTML = [8,7,6,5,4,3,2,1].map(number => {
      const flights = active.filter(f => f.source === 'aena' && String(f.belt) === String(number));
      const danger = number >= 7 ? 'danger' : '';
      return `<button type="button" class="scene-belt ${danger} ${flights.length?'has-flight':''}" ${flights.length?`data-flight-id="${Number(flights[0].id)}"`:''} title="${flights.length?esc(flights.map(f=>`${f.physical_flight} ${f.origin_name}`).join(' · ')):`Cinta ${number} sin vuelo activo`}">
        <span>${number}</span>${flights.length
          ? `<strong class="scene-belt-origin">${esc(flights[0].origin_name)}</strong><small>${esc(flights[0].physical_flight)} · ${time(effectiveArrival(flights[0]))}</small><em>μ ${leadText(flights[0].belt_lead_flight_average_minutes)}</em>`
          : '<strong class="scene-belt-origin free">LIBRE</strong><small>sin vuelo activo</small>'}${flights.length>1?`<b>+${flights.length-1}</b>`:''}
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
    if (els.date.value !== now.date) return false;
    const rows = [...els.body.querySelectorAll('.flight-row')];
    if (!rows.length) return false;
    const target = rows.find(row => Number(row.dataset.effectiveMinute) >= now.minutes - 5) || rows[rows.length - 1];
    if (!target) return false;
    target.scrollIntoView({behavior:force?'smooth':'auto', block:'start'});
    target.classList.add('current-flight');
    return true;
  }

  function maybeScrollToNow() {
    const now = madridNow();
    if (state.autoScrolledDate === els.date.value || els.date.value !== now.date) return;
    if (els.search.value || els.hall.value || els.canary.checked || els.secondary.checked) return;
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

  function telemetrySummary(flight) {
    if (!flight.telemetry_observed_at) return '';
    return `<section class="telemetry-card mb-4">
      <div class="telemetry-visual">${aircraftSilhouette(flight)}<span>${esc(flight.aircraft_type || 'tipo no verificado')}</span></div>
      <div class="telemetry-values">
        <div><small>Altura</small><strong>${altitudeFt(flight)!==null?`${altitudeFt(flight).toLocaleString('es-ES')} ft`:'—'}</strong></div>
        <div><small>Velocidad</small><strong>${speedKt(flight)!==null?`${speedKt(flight)} kt`:'—'}</strong></div>
        <div><small>Rumbo</small><strong>${isFiniteNumber(flight.track_deg)?`${Math.round(Number(flight.track_deg))}°`:'—'}</strong></div>
        <div><small>Posición ADS‑B</small><strong>${Number(flight.latitude).toFixed(3)}, ${Number(flight.longitude).toFixed(3)}</strong></div>
      </div>
      <small class="telemetry-note">OpenSky · ${dateTime(flight.telemetry_observed_at)}. La silueta representa el tipo; no es una fotografía de la matrícula.</small>
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
      const reason = publishedReason ? event.reason_detail : 'La fuente no publicó una causa operativa verificable.';
      return `<article class="belt-event-item">
        <header><strong>${esc(event.before_value || 'sin cinta')} → ${esc(event.after_value || 'retirada')}</strong><time>${dateTime(event.detected_at)}</time></header>
        <div><span class="badge ${event.source==='aena'?'text-bg-success':'text-bg-warning'}">${esc((event.source||'').toUpperCase())}</span> <span class="badge text-bg-secondary">${esc(event.confidence || 'provisional')}</span></div>
        <p>${esc(reason)}</p>
      </article>`;
    }).join('') : '<p class="text-secondary mb-0">No se han registrado cambios de cinta.</p>';
    return `<section class="belt-intelligence mb-4">
      <h3 class="h6">Inteligencia de cinta</h3>
      <div class="detail-grid mb-3">
        <div class="detail-stat"><small>Media del mismo vuelo</small><strong>${leadText(flight.belt_lead_flight_average_minutes)}</strong><span class="meta">${Number(flight.belt_lead_flight_samples || 0)} observaciones oficiales</span></div>
        <div class="detail-stat"><small>Media del origen ${esc(flight.origin_iata || '')}</small><strong>${leadText(flight.belt_lead_origin_average_minutes)}</strong><span class="meta">${Number(flight.belt_lead_origin_samples || 0)} observaciones oficiales</span></div>
      </div>
      <div class="belt-event-list">${eventMarkup}</div>
      ${changes.length ? `<aside class="observed-context"><strong>Contexto observado — no demuestra causalidad</strong>${context.map(item=>`<span>${esc(item)}</span>`).join('')}</aside>` : ''}
    </section>`;
  }

  async function openDetail(id) {
    const flight = state.flights.find(f => Number(f.id) === Number(id));
    if (!flight) return;
    els.detailTitle.textContent = `${flight.physical_flight || 'Vuelo'} · ${flight.origin_name || ''}`;
    els.detailBody.innerHTML = '<div class="loading-state"><div class="spinner-border spinner-border-sm"></div></div>';
    bootstrap.Offcanvas.getOrCreateInstance('#flightDetail').show();
    try {
      const response = await fetch(`api/history.php?flight_id=${encodeURIComponent(id)}`, {cache:'no-store'});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'No se pudo cargar el histórico.');
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
          ? `<span class="badge text-bg-info ms-2">${item.repeat_count} lecturas agrupadas</span>`
          : '';
        return `<article class="timeline-item ${isAena?'aena':''}">
          <div class="d-flex justify-content-between gap-2"><strong>${esc((item.source||'').toUpperCase())}${authority}${repeats}</strong><time class="meta text-end">${timelinePeriod(item)}</time></div>
          <div>${esc(item.status||'Observación')} · ${beltPosition(item)}</div>
          <div class="meta">ETA ${time(item.eta)}${item.baggage_state?` · Equipaje: ${esc(item.baggage_state)}`:''}</div>
        </article>`;
      }).join('') : '<p class="text-secondary">Todavía no hay observaciones.</p>';
      els.detailBody.innerHTML = summary + beltIntelligence(flight) + telemetrySummary(flight) + `<h3 class="h6 mb-3">Cronología</h3><div class="timeline">${timeline}</div>`;
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
    const button = event.target.closest('[data-open-flight]');
    if (button) openDetail(button.dataset.openFlight);
    const more = event.target.closest('[data-more-flights]');
    if (more) {
      if (more.dataset.moreFlights === 'past') state.pastExtra += 5;
      if (more.dataset.moreFlights === 'future') state.futureExtra += 5;
      render();
    }
  });
  [els.hall,els.canary,els.secondary].forEach(el => el.addEventListener('change', () => {
    state.pastExtra = 0; state.futureExtra = 0; render();
  }));
  els.search.addEventListener('input', () => { state.pastExtra = 0; state.futureExtra = 0; render(); });
  els.date.addEventListener('change', () => {
    state.autoScrolledDate = null;
    state.mapHasFitted = false;
    state.pastExtra = 0;
    state.futureExtra = 0;
    loadBoard(true);
  });
  els.refresh.addEventListener('click', () => loadBoard(true));
  els.now.addEventListener('click', () => scrollToCurrent(true));
  els.fitMap.addEventListener('click', fitTrackedAircraft);
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

  updateSceneClock();
  setInterval(updateSceneClock, 1000);
  loadBoard(true);
  setInterval(() => loadBoard(false), Number(document.querySelector('main').dataset.pollSeconds || 15) * 1000);
})();
