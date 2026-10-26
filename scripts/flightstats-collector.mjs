import { parseFlightStatsHtml, parseFlightStatsJson } from './flightstats-parser.mjs';

const ENDPOINT = process.env.MATRIX_FLIGHTSTATS_URL
  || 'https://www.ojito.top/public/api/flightstats.php';
const TOKEN = process.env.MATRIX_INGEST_TOKEN || '';
if (TOKEN.length < 24) throw new Error('Falta MATRIX_INGEST_TOKEN o tiene menos de 24 caracteres.');

const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36 SevillaFlightMatrix/1.0';

const request = async (method, body) => {
  const response = await fetch(ENDPOINT, {
    method,
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      'X-Matrix-Token': TOKEN,
      Accept: 'application/json',
      ...(body ? {'Content-Type':'application/json'} : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await response.text();
  let data;
  try { data = JSON.parse(text); } catch { data = {error:text.slice(0,500)}; }
  if (!response.ok) throw new Error(`Receptor FlightStats HTTP ${response.status}: ${data.error || text.slice(0,300)}`);
  return data;
};

const fetchText = async (url, referer, json) => {
  const response = await fetch(url, {
    headers: {
      'User-Agent': USER_AGENT,
      Accept: json ? 'application/json,text/plain,*/*' : 'text/html,application/xhtml+xml',
      Referer: referer,
    },
  });
  const text = await response.text();
  if (!response.ok) throw new Error(`FlightStats HTTP ${response.status}`);
  return {text, type: response.headers.get('content-type') || ''};
};

const readSnapshot = async (flight) => {
  const carrier = String(flight.iata_carrier || '');
  const number = String(flight.iata_number || '');
  const year = Number(flight.year);
  const month = Number(flight.month);
  const date = Number(flight.date);
  if (!/^[A-Z][A-Z0-9]$/.test(carrier) || !/^\d{1,4}[A-Z]?$/.test(number)) {
    throw new Error('Código IATA no válido.');
  }
  const publicUrl = flight.public_url
    || `https://www.flightstats.com/v2/flight-tracker/${encodeURIComponent(carrier)}/${encodeURIComponent(number)}?year=${year}&month=${month}&date=${date}`;
  const jsonUrl = flight.json_url
    || `https://www.flightstats.com/v2/api-next/flight-tracker/${encodeURIComponent(carrier)}/${encodeURIComponent(number)}/${year}/${month}/${date}`;
  try {
    const payload = await fetchText(jsonUrl, publicUrl, true);
    if (payload.type.includes('json') || payload.text.startsWith('{')) {
      const parsed = parseFlightStatsJson(JSON.parse(payload.text), publicUrl);
      if (parsed) return {...parsed, source_kind:'json'};
    }
  } catch (error) {
    console.warn(`::warning::${flight.physical_flight}: JSON FlightStats no usable (${error.message}); se intenta la ficha HTML.`);
  }
  const html = await fetchText(publicUrl, publicUrl, false);
  const parsed = parseFlightStatsHtml(html.text, flight.scheduled_arrival || '', publicUrl);
  if (!parsed) throw new Error('La ficha pública no presentó retraso ni ETA.');
  return {...parsed, source_kind:'html'};
};

const control = await request('GET');
if (!control.configured) {
  console.log(control.message || 'FlightStats pendiente de actualización MySQL.');
  process.exit(0);
}
const flights = Array.isArray(control.flights) ? control.flights.slice(0, 8) : [];
if (!flights.length) {
  console.log('Sin vuelos vigilados en la ventana de FlightStats.');
  process.exit(0);
}

const snapshots = [];
const errors = [];
for (const flight of flights) {
  const code = String(flight.physical_flight || '').replace(/\s+/g, '').toUpperCase();
  try {
    const parsed = await readSnapshot(flight);
    snapshots.push({
      flight_id: Number(flight.id),
      physical_flight: code,
      observed_at: new Date().toISOString(),
      ...parsed,
      raw_excerpt: JSON.stringify({
        status_text: parsed.status_text,
        delay_minutes: parsed.delay_minutes,
        eta: parsed.eta,
        actual_departure: parsed.actual_departure,
        source_kind: parsed.source_kind,
      }).slice(0, 8000),
    });
    console.log(`${code}: ${parsed.status_text || 'estado pendiente'} · ETA ${parsed.eta || parsed.actual_arrival || '—'} · ${parsed.delay_minutes ?? '—'} min · ${parsed.source_kind}`);
  } catch (error) {
    errors.push(`${code}: ${error.message}`);
    console.warn(`::warning::${code}: ${error.message}`);
  }
  await new Promise(resolve => setTimeout(resolve, 800));
}

if (!snapshots.length) {
  console.log(JSON.stringify({requested:flights.length, received:0, errors}, null, 2));
  process.exit(errors.length ? 1 : 0);
}

const saved = await request('POST', {snapshots});
console.log(JSON.stringify({requested:flights.length, received:snapshots.length, errors, saved}, null, 2));
