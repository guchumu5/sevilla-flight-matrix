import {
  baggageStateFromAenaStatus,
  statusIndicatesArrival,
  statusLabelFromCode,
} from './aena-status.mjs';
import { choosePhysicalCode } from './aena-flight-identity.mjs';

const AENA_JSON_URL = 'https://www.aena.es/sites/Satellite?pagename=AENA_ConsultarVuelos&airport=SVQ&flightType=L&dosDias=si';
const canonicalAppUrl = value => String(value || '').replace(/^https:\/\/ojito\.top\//i, 'https://www.ojito.top/');
const INGEST_URL = canonicalAppUrl(process.env.MATRIX_INGEST_URL || 'https://www.ojito.top/public/api/aena-board.php');
const INGEST_TOKEN = process.env.MATRIX_INGEST_TOKEN || '';
const TIME_ZONE = 'Europe/Madrid';
const MODE = process.env.AENA_MODE === 'week' ? 'week' : 'live';

if (INGEST_TOKEN.length < 24) {
  throw new Error('Falta MATRIX_INGEST_TOKEN o tiene menos de 24 caracteres.');
}

const pad = value => String(value).padStart(2, '0');

function madridParts(date = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: TIME_ZONE,
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
  }).formatToParts(date).reduce((result, part) => {
    if (part.type !== 'literal') result[part.type] = part.value;
    return result;
  }, {});
  return parts;
}

function localNow() {
  const p = madridParts();
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`;
}

function localDay(offset = 0) {
  const p = madridParts();
  const shifted = new Date(Date.UTC(Number(p.year), Number(p.month) - 1, Number(p.day) + offset, 12));
  return shifted.toISOString().slice(0, 10);
}

function clean(value) {
  const text = String(value ?? '').trim();
  if (!text || text.toLowerCase() === 'null' || text === '-') return null;
  return text;
}

function spanishDateToIso(fecha) {
  const match = String(fecha || '').match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
  return match ? `${match[3]}-${match[2]}-${match[1]}` : null;
}

function clock(hora) {
  const match = String(hora || '').match(/^(\d{2}):(\d{2})(?::\d{2})?$/);
  return match ? `${match[1]}:${match[2]}` : null;
}

function codesFromRow(row) {
  const number = String(row.numVuelo || '').replace(/\D+/g, '');
  if (!number) return [];
  const codes = [];
  const push = value => {
    const code = String(value || '').replace(/\s+/g, '').toUpperCase();
    if (code && !codes.includes(code)) codes.push(code);
  };
  const oaci = clean(row.oaciCompania) || clean(row.compania);
  const iata = clean(row.iataCompania);
  if (oaci) push(`${oaci}${number}`);
  if (iata) push(`${iata}${number}`);
  for (const field of ['codigosCompania', 'iatasCompaniaSecundarios', 'oacisCompaniaSecundarios']) {
    const raw = clean(row[field]);
    if (!raw) continue;
    for (const token of raw.split(',')) {
      const part = token.trim().toUpperCase();
      if (!part || part === 'NULL') continue;
      if (/^[A-Z0-9]{2,3}\d{1,4}[A-Z]?$/.test(part)) push(part);
      else if (/^[A-Z]{2,3}$/.test(part)) push(`${part}${number}`);
    }
  }
  return codes;
}

function preferPhysical(codes) {
  const chosen = choosePhysicalCode(codes);
  if (!chosen) return null;
  const icao = codes.find(code => /^[A-Z]{3}\d/.test(code));
  return icao || chosen;
}

async function fetchInfovuelosJson() {
  const response = await fetch(AENA_JSON_URL, {
    headers: {
      Accept: 'application/json,text/plain,*/*',
      Referer: 'https://www.aena.es/es/infovuelos.html',
      'User-Agent': 'SevillaFlightMatrix-AenaCollectorJson/2.0',
    },
  });
  if (!response.ok) {
    throw new Error(`Infovuelos JSON HTTP ${response.status}`);
  }
  const rows = await response.json();
  if (!Array.isArray(rows)) {
    throw new Error('Infovuelos JSON devolvió un formato inesperado.');
  }
  return rows;
}

function groupPhysicalFlights(rows, windowFrom) {
  const minimum = windowFrom.slice(0, 16);
  const groups = new Map();
  for (const row of rows) {
    const day = spanishDateToIso(clean(row.fecha));
    const scheduledClock = clock(clean(row.horaProgramada));
    const estimatedDay = spanishDateToIso(clean(row.fechaEstimada)) || day;
    const estimatedClock = clock(clean(row.horaEstimada)) || scheduledClock;
    const origin = String(clean(row.iataOtro) || '').toUpperCase();
    if (!day || !scheduledClock || !origin) continue;
    const scheduledArrival = `${day} ${scheduledClock}:00`;
    const effectiveArrival = `${estimatedDay} ${estimatedClock}:00`;
    if (scheduledArrival.slice(0, 16) < minimum && effectiveArrival.slice(0, 16) < minimum) continue;

    const codes = codesFromRow(row);
    if (!preferPhysical(codes)) continue;
    const statusCode = String(clean(row.estado) || '').toUpperCase();
    const status = statusLabelFromCode(statusCode) || statusCode || null;
    const hall = clean(row.salaPrimera);
    const belt = clean(row.cintaPrimera);
    const signature = [day, scheduledClock, estimatedClock, origin, hall || '', belt || '', statusCode].join('|');
    if (!groups.has(signature)) {
      groups.set(signature, {
        day,
        scheduledArrival,
        effectiveArrival,
        origin_iata: origin,
        origin_name: clean(row.ciudadIataOtro) || origin,
        hall: hall ? hall.toUpperCase() : null,
        belt,
        statusCode,
        status,
        codes: [],
      });
    }
    const group = groups.get(signature);
    for (const code of codes) {
      if (!group.codes.includes(code)) group.codes.push(code);
    }
  }

  const flights = [...groups.values()].map(group => {
    const physical = preferPhysical(group.codes);
    const codeshares = group.codes.filter(code => code !== physical);
    const status = group.status || null;
    const arrived = statusIndicatesArrival(group.statusCode) || statusIndicatesArrival(status);
    const baggageState = baggageStateFromAenaStatus(group.statusCode || status);
    return {
      source_key: [group.day, physical, group.origin_iata, 'SVQ'].join('|'),
      flight_date: group.day,
      physical_flight: physical,
      origin_iata: group.origin_iata,
      origin_name: group.origin_name,
      scheduled_arrival: group.scheduledArrival,
      codeshares,
      status,
      eta: !arrived && group.effectiveArrival !== group.scheduledArrival ? group.effectiveArrival : null,
      actual_arrival: arrived ? group.effectiveArrival : null,
      hall: group.hall,
      belt: group.belt,
      baggage_state: baggageState,
      occupancy_level: 'no_verificable',
      confidence: 'confirmado',
      reason_code: 'aena_infovuelos_json',
      reason_detail: 'Reconfirmación Infovuelos JSON; Aena publica cinta/sala oficiales.',
      raw_data: {
        transport: 'infovuelos_json',
        status_code: group.statusCode,
        codes: group.codes,
        cintaPrimera: group.belt,
        salaPrimera: group.hall,
        displayed_effective_time: group.effectiveArrival.slice(11, 16),
      },
    };
  });

  const keyCounts = new Map();
  for (const flight of flights) {
    keyCounts.set(flight.source_key, (keyCounts.get(flight.source_key) || 0) + 1);
  }
  for (const flight of flights) {
    if ((keyCounts.get(flight.source_key) || 0) < 2) continue;
    const scheduledTime = flight.scheduled_arrival.slice(11, 16);
    flight.source_key = `${flight.source_key}|${scheduledTime.replace(':', '')}`;
    flight.raw_data.source_key_disambiguated_by = 'scheduled_time';
  }

  return flights.sort((a, b) => a.scheduled_arrival.localeCompare(b.scheduled_arrival));
}

async function postCapture(payload) {
  const response = await fetch(INGEST_URL, {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${INGEST_TOKEN}`,
      'X-Matrix-Token': INGEST_TOKEN,
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'User-Agent': 'SevillaFlightMatrix-AenaCollectorJson/2.0',
    },
    body: JSON.stringify(payload),
  });
  const text = await response.text();
  let body;
  try { body = JSON.parse(text); } catch { body = { raw: text.slice(0, 1000) }; }
  if (!response.ok || response.status !== 201 || body.ok === false || !body.fetch_run_id || !body.result) {
    throw new Error(`El receptor respondió ${response.status}: ${body.error || body.raw || 'error desconocido'}`);
  }
  return body;
}

const rows = await fetchInfovuelosJson();
const now = localNow();
const nowDate = new Date();
const fromDate = new Date(nowDate.getTime() - 2 * 60 * 60 * 1000);
const fromParts = madridParts(fromDate);
const windowFrom = `${fromParts.year}-${fromParts.month}-${fromParts.day} ${fromParts.hour}:${fromParts.minute}:00`;
const days = Array.from({ length: MODE === 'week' ? 2 : 1 }, (_, index) => localDay(index));
const windowTo = `${days[days.length - 1]} 23:59:59`;
const requestedDays = new Set(days);
const mapped = groupPhysicalFlights(rows, windowFrom);
const flights = mapped.filter(flight => requestedDays.has(flight.flight_date));
if (!flights.length) {
  throw new Error('La captura Aena JSON no produjo vuelos físicos en la ventana viva.');
}

const response = await postCapture({
  collector: `aena-json-v2-${MODE}`,
  observed_at: now,
  window_from: windowFrom,
  window_to: windowTo,
  aena_updated_label: 'infovuelos-json',
  flights,
});
console.log(JSON.stringify({
  ok: true,
  mode: MODE,
  transport: 'infovuelos_json',
  days,
  raw_rows: rows.length,
  physical_flights: flights.length,
  response,
}, null, 2));
