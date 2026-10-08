import { chromium } from 'playwright';
import { parseFlightAwarePublicPage } from './flightaware-parser.mjs';

const ENDPOINT = process.env.MATRIX_FLIGHTAWARE_URL
  || 'https://ojito.top/public/api/flightaware.php';
const TOKEN = process.env.MATRIX_INGEST_TOKEN || '';
if (TOKEN.length < 24) throw new Error('Falta MATRIX_INGEST_TOKEN o tiene menos de 24 caracteres.');

const request = async (method, body) => {
  const response = await fetch(ENDPOINT, {
    method,
    headers: {
      Authorization: `Bearer ${TOKEN}`,
      Accept: 'application/json',
      ...(body ? {'Content-Type':'application/json'} : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await response.text();
  let data;
  try { data = JSON.parse(text); } catch { data = {error:text.slice(0,500)}; }
  if (!response.ok) throw new Error(`Receptor FlightAware HTTP ${response.status}: ${data.error || text.slice(0,300)}`);
  return data;
};

const control = await request('GET');
if (!control.configured) {
  console.log(control.message || 'FlightAware web pendiente de actualización MySQL.');
  process.exit(0);
}
const flights = Array.isArray(control.flights) ? control.flights.slice(0,8) : [];
if (!flights.length) {
  console.log('Sin lecturas FlightAware solicitadas desde la aplicación.');
  process.exit(0);
}

const browser = await chromium.launch({headless:true});
const context = await browser.newContext({
  locale:'en-US',
  timezoneId:'Europe/Madrid',
  userAgent:'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36 SevillaFlightMatrix/1.0',
});
const snapshots = [];
const errors = [];
try {
  for (const flight of flights) {
    const code = String(flight.physical_flight || '').replace(/\s+/g,'').toUpperCase();
    if (!/^[A-Z0-9]{2,8}$/.test(code)) continue;
    const page = await context.newPage();
    try {
      const url = `https://www.flightaware.com/live/flight/${encodeURIComponent(code)}`;
      await page.goto(url, {waitUntil:'domcontentloaded', timeout:45000});
      await page.waitForTimeout(2500);
      const text = await page.locator('body').innerText({timeout:15000});
      if (!text.includes(code) && !text.match(/Flight Details|Departure Times/i)) {
        throw new Error('La ficha pública no presentó datos del vuelo.');
      }
      const parsed = parseFlightAwarePublicPage(text);
      if (!Object.values(parsed).some(value => value !== null)) {
        throw new Error('La estructura pública cambió o no contiene datos visibles.');
      }
      snapshots.push({
        flight_id:Number(flight.id),
        physical_flight:code,
        observed_at:new Date().toISOString(),
        ...parsed,
        raw_excerpt:text.slice(0,8000),
      });
      console.log(`${code}: ${parsed.status_text || 'estado pendiente'} · ${parsed.aircraft_type || 'tipo pendiente'} · ${parsed.speed_mph ?? '—'} mph`);
    } catch (error) {
      errors.push(`${code}: ${error.message}`);
      console.warn(`::warning::${code}: ${error.message}`);
    } finally {
      await page.close();
    }
    await new Promise(resolve => setTimeout(resolve, 1500));
  }
} finally {
  await browser.close();
}

const saved = await request('POST', {snapshots});
console.log(JSON.stringify({requested:flights.length,received:snapshots.length,errors,saved}, null, 2));
