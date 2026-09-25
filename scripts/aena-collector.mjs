import { chromium } from 'playwright';

const AENA_URL = 'https://www.aena.es/es/infovuelos.html';
const INGEST_URL = process.env.MATRIX_INGEST_URL || 'https://ojito.top/public/api/aena-board.php';
const INGEST_TOKEN = process.env.MATRIX_INGEST_TOKEN || '';
const TIME_ZONE = 'Europe/Madrid';
const MAX_VISIBLE_ROWS = 20;
const MODE = process.env.AENA_MODE === 'week' ? 'week' : 'live';
const FORM_TIMEOUT = 60000;
const DATE_TIMEOUT = 15000;
const RESULT_TIMEOUT = 45000;

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

function dayLabel(isoDate) {
  const [year, month, day] = isoDate.split('-').map(Number);
  const date = new Date(Date.UTC(year, month - 1, day, 12));
  const weekday = new Intl.DateTimeFormat('en-US', { weekday: 'long', timeZone: 'UTC' }).format(date);
  const monthName = new Intl.DateTimeFormat('en-US', { month: 'long', timeZone: 'UTC' }).format(date);
  const mod100 = day % 100;
  const suffix = mod100 >= 11 && mod100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] || 'th');
  return `Choose ${weekday}, ${monthName} ${day}${suffix}, ${year}`;
}

function mysqlDateTime(day, time) {
  return `${day} ${time}:00`;
}

function minutesToParts(value) {
  return { hour: Math.floor(value / 60), minute: value % 60 };
}

async function chooseClock(page, side, totalMinutes) {
  const { hour, minute } = minutesToParts(totalMinutes);
  const minuteIndex = [0, 15, 30, 45].indexOf(minute);
  if (minuteIndex < 0) throw new Error(`Minuto no admitido por Aena: ${minute}`);
  await page.locator(`#horario .${side} .hora`).click();
  await page.locator(`#horario .${side} .hora li`).nth(hour).click();
  await page.locator(`#horario .${side} .minuto`).click();
  await page.locator(`#horario .${side} .minuto li`).nth(minuteIndex).click();
}

async function selectDay(page, day) {
  const dateField = page.locator('#fecha');
  await dateField.waitFor({ state: 'visible', timeout: FORM_TIMEOUT });
  await dateField.click();
  const buttonName = dayLabel(day);
  // Aena usa un selector de rango, pero un único clic sobre el mismo día ya
  // fija inicio y fin y cierra el calendario. El segundo clic anterior esperaba
  // un botón que ya no estaba en el DOM y agotaba el tiempo de GitHub Actions.
  const dayButton = page.getByRole('button', { name: buttonName, exact: true }).first();
  await dayButton.waitFor({ state: 'visible', timeout: FORM_TIMEOUT });
  await dayButton.click();
  const [year, month, dayOfMonth] = day.split('-');
  const expected = `${dayOfMonth}/${month}/${year} - ${dayOfMonth}/${month}/${year}`;
  await page.waitForFunction(value => document.querySelector('#fecha')?.value === value, expected, { timeout: DATE_TIMEOUT });
}

async function preparePage(browser, day) {
  const page = await browser.newPage({ timezoneId: TIME_ZONE });
  await page.goto(AENA_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
  // El aviso de cookies añade un botón "Aceptar todas". Se cierra antes de
  // manejar los selectores de Aena para que no interfiera con sus botones.
  const acceptCookies = page.getByRole('button', { name: 'Aceptar todas', exact: true }).first();
  if (await acceptCookies.isVisible().catch(() => false)) {
    await acceptCookies.click();
  }
  const arrivals = page.getByRole('textbox', { name: 'Llegadas en la red Aena:' });
  await arrivals.waitFor({ state: 'visible', timeout: FORM_TIMEOUT });
  await arrivals.fill('Sevilla');
  await page.waitForTimeout(700);
  await selectDay(page, day);
  return page;
}

async function setTimeRange(page, start, end = null) {
  await page.getByRole('textbox', { name: 'Horas:' }).click();
  await chooseClock(page, 'desde', start);
  if (end !== null) await chooseClock(page, 'hasta', end);
  await page.getByRole('button', { name: 'Aceptar', exact: true }).click();
}

async function executeSearch(page) {
  const searchButton = page.getByRole('button', { name: 'Buscar', exact: true });
  await searchButton.waitFor({ state: 'visible', timeout: FORM_TIMEOUT });
  await page.waitForFunction(() => {
    const button = [...document.querySelectorAll('button')]
      .find(element => element.textContent?.trim() === 'Buscar');
    return button && !button.disabled && button.getAttribute('aria-disabled') !== 'true';
  }, null, { timeout: FORM_TIMEOUT });
  await searchButton.click({ timeout: FORM_TIMEOUT });
  await page.waitForTimeout(900);
  await page.waitForFunction(() => {
    const body = document.body.innerText || '';
    return /vuelos con las caracter.sticas buscadas/i.test(body)
      || /no se han encontrado/i.test(body)
      || document.querySelectorAll('.resultados .listado > .fila').length > 0;
  }, null, { timeout: RESULT_TIMEOUT }).catch(() => null);
  await page.waitForTimeout(350);
}

async function readResult(page, day) {
  return page.evaluate(({ day, maxVisible }) => {
    const bodyText = document.body.innerText || '';
    const totalMatch = bodyText.match(/(\d+)\s+vuelos con las caracter.sticas buscadas/i);
    const total = totalMatch ? Number(totalMatch[1]) : 0;
    const updatedMatch = bodyText.match(/Actualizado a las\s+(\d{2}:\d{2})/i);
    const rows = [...document.querySelectorAll('.resultados .listado > .fila')].map(node => {
      const text = selector => (node.querySelector(selector)?.textContent || '').replace(/\s+/g, ' ').trim();
      const times = [...node.querySelectorAll('.hora span')]
        .map(element => (element.textContent || '').trim())
        .filter(value => /^\d{2}:\d{2}$/.test(value));
      const scheduled = times.length ? times[times.length - 1] : null;
      const effective = times.length > 1 ? times[0] : scheduled;
      const originText = text('.origen-destino');
      const originMatch = originText.match(/\(([A-Z]{3})\)\s*$/);
      const status = text('.estadovuelo');
      const hallValue = text('.cintamostrador button');
      const beltValue = text('.puertaembarque span');
      const code = text('.vuelo').toUpperCase();
      const airline = text('.comp p') || node.querySelector('.comp img')?.getAttribute('alt') || null;
      return {
        day,
        code,
        airline,
        scheduled,
        effective,
        origin_iata: originMatch ? originMatch[1] : null,
        origin_name: originText.replace(/\s*\([A-Z]{3}\)\s*$/, '').trim(),
        hall: hallValue && hallValue !== '-' ? hallValue.toUpperCase() : null,
        belt: beltValue && beltValue !== '-' ? beltValue : null,
        status: status || null,
      };
    }).filter(row => row.code && row.scheduled && row.origin_iata);
    return { total, truncated: total > maxVisible, updatedLabel: updatedMatch?.[1] || null, rows };
  }, { day, maxVisible: MAX_VISIBLE_ROWS });
}

async function queryWindow(page, day, start, end = null) {
  await setTimeRange(page, start, end);
  await executeSearch(page);
  return readResult(page, day);
}

async function collectBounded(page, day, start, end, sink, labels) {
  const result = await queryWindow(page, day, start, end);
  if (result.total > MAX_VISIBLE_ROWS && end - start > 15) {
    const midpoint = start + Math.ceil(((end - start) / 2) / 15) * 15;
    await collectBounded(page, day, start, midpoint, sink, labels);
    await collectBounded(page, day, midpoint, end, sink, labels);
    return;
  }
  if (result.total > MAX_VISIBLE_ROWS) {
    throw new Error(`Aena devolvió ${result.total} filas en solo 15 minutos; se aborta para no perder vuelos.`);
  }
  sink.push(...result.rows);
  if (result.updatedLabel) labels.add(result.updatedLabel);
}

async function collectTail(browser, day, start, sink, labels) {
  let page = await preparePage(browser, day);
  let result = await queryWindow(page, day, start, null);
  if (result.total <= MAX_VISIBLE_ROWS) {
    sink.push(...result.rows);
    if (result.updatedLabel) labels.add(result.updatedLabel);
    await page.close();
    return;
  }
  await page.close();
  const step = result.total > 40 ? 30 : 60;
  for (let cursor = start; cursor < 24 * 60 - 15; cursor += step) {
    page = await preparePage(browser, day);
    const end = Math.min(cursor + step, 23 * 60 + 45);
    await collectBounded(page, day, cursor, end, sink, labels);
    await page.close();
  }
  page = await preparePage(browser, day);
  result = await queryWindow(page, day, 23 * 60 + 45, null);
  if (result.total > MAX_VISIBLE_ROWS) throw new Error('La última ventana Aena supera 20 filas.');
  sink.push(...result.rows);
  if (result.updatedLabel) labels.add(result.updatedLabel);
  await page.close();
}

function groupPhysicalFlights(rows, windowFrom) {
  const minimum = windowFrom.slice(0, 16);
  const groups = new Map();
  for (const row of rows) {
    const scheduledArrival = mysqlDateTime(row.day, row.scheduled);
    const effectiveArrival = mysqlDateTime(row.day, row.effective);
    if (scheduledArrival.slice(0, 16) < minimum && effectiveArrival.slice(0, 16) < minimum) continue;
    const signature = [row.day, row.scheduled, row.effective, row.origin_iata, row.hall || '', row.belt || '', row.status || ''].join('|');
    if (!groups.has(signature)) groups.set(signature, { ...row, codes: [], airlines: [] });
    const group = groups.get(signature);
    if (!group.codes.includes(row.code)) group.codes.push(row.code);
    if (row.airline && !group.airlines.includes(row.airline)) group.airlines.push(row.airline);
  }

  return [...groups.values()].map(group => {
    const physical = group.codes[0];
    const status = group.status || null;
    const arrived = /finalizado|entrega\s*equip|aterriz|llegad/i.test(status || '');
    const baggageState = /entrega\s*equip/i.test(status || '')
      ? 'entrega'
      : (/finalizado/i.test(status || '') ? 'finalizado' : 'pendiente');
    const scheduledArrival = mysqlDateTime(group.day, group.scheduled);
    const effectiveArrival = mysqlDateTime(group.day, group.effective);
    return {
      source_key: [group.day, physical, group.origin_iata, 'SVQ'].join('|'),
      flight_date: group.day,
      physical_flight: physical,
      origin_iata: group.origin_iata,
      origin_name: group.origin_name || group.origin_iata,
      scheduled_arrival: scheduledArrival,
      codeshares: group.codes.slice(1),
      status,
      eta: !arrived && effectiveArrival !== scheduledArrival ? effectiveArrival : null,
      actual_arrival: arrived ? effectiveArrival : null,
      hall: group.hall,
      belt: group.belt,
      baggage_state: baggageState,
      occupancy_level: 'no_verificable',
      confidence: 'confirmado',
      reason_code: 'aena_board_capture',
      reason_detail: 'Captura automática de Infovuelos; Aena no publicó una causa operativa para este estado.',
      raw_data: {
        transport: 'github_actions_browser',
        codes: group.codes,
        airlines: group.airlines,
        displayed_effective_time: group.effective,
      },
    };
  }).sort((a, b) => a.scheduled_arrival.localeCompare(b.scheduled_arrival));
}

async function postCapture(payload) {
  const response = await fetch(INGEST_URL, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${INGEST_TOKEN}`,
      'X-Matrix-Token': INGEST_TOKEN,
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'User-Agent': 'SevillaFlightMatrix-AenaCollector/1.0',
    },
    body: JSON.stringify(payload),
  });
  const text = await response.text();
  let body;
  try { body = JSON.parse(text); } catch { body = { raw: text.slice(0, 1000) }; }
  if (!response.ok || body.ok === false) {
    throw new Error(`El receptor respondió ${response.status}: ${body.error || body.raw || 'error desconocido'}`);
  }
  return body;
}

const browser = await chromium.launch({ headless: true });
try {
  const days = Array.from({ length: MODE === 'week' ? 7 : 1 }, (_, index) => localDay(index));
  const rows = [];
  const updatedLabels = new Set();
  for (const day of days) {
    const page = await preparePage(browser, day);
    for (let start = 0; start < 22 * 60; start += 120) {
      await collectBounded(page, day, start, start + 120, rows, updatedLabels);
    }
    await page.close();
    await collectTail(browser, day, 22 * 60, rows, updatedLabels);
  }

  const now = localNow();
  const nowDate = new Date();
  const fromDate = new Date(nowDate.getTime() - 2 * 60 * 60 * 1000);
  const fromParts = madridParts(fromDate);
  const windowFrom = `${fromParts.year}-${fromParts.month}-${fromParts.day} ${fromParts.hour}:${fromParts.minute}:00`;
  const windowTo = `${days[days.length - 1]} 23:59:59`;
  const flights = groupPhysicalFlights(rows, windowFrom);
  if (!flights.length) throw new Error('La captura Aena no produjo vuelos físicos en la ventana viva.');

  const response = await postCapture({
    collector: `aena-playwright-v1-${MODE}`,
    observed_at: now,
    window_from: windowFrom,
    window_to: windowTo,
    aena_updated_label: [...updatedLabels].join(', '),
    flights,
  });
  console.log(JSON.stringify({ ok: true, mode: MODE, days, raw_rows: rows.length, physical_flights: flights.length, response }, null, 2));
} finally {
  await browser.close();
}
