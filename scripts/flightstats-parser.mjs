const first = (text, pattern) => text.match(pattern)?.[1]?.trim() || null;
const pad = number => String(number).padStart(2, '0');

export function localDateTime(value, timezone = 'Europe/Madrid') {
  const raw = String(value || '').trim();
  if (!raw) return null;
  if (raw.endsWith('Z') || /[+-]\d{2}:\d{2}$/.test(raw)) {
    const stamp = new Date(raw);
    if (Number.isNaN(stamp.getTime())) return null;
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
      timeZone: timezone || 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
    }).formatToParts(stamp).filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
  }
  const slice = raw.replace('T', ' ').slice(0, 19);
  const match = slice.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?/);
  return match ? `${match[1]} ${match[2]}:${match[3] || '00'}` : null;
}

export function parseDelayMinutes(numeric, text = '') {
  if (numeric !== null && numeric !== undefined && numeric !== '' && Number.isFinite(Number(numeric))) {
    return Math.max(-24 * 60, Math.min(24 * 60, Math.round(Number(numeric))));
  }
  const raw = String(text || '').trim();
  if (!raw) return null;
  const withMinutes = raw.match(/(?:delayed by\s*)?(?:(\d+)\s*h(?:ours?)?\s*)?(\d+)\s*m(?:in(?:utes?)?)?\b/i);
  if (withMinutes) return Number(withMinutes[1] || 0) * 60 + Number(withMinutes[2]);
  const hoursOnly = raw.match(/(?:delayed by\s*)?(\d+)\s*h(?:ours?)?\b/i);
  return hoursOnly ? Number(hoursOnly[1]) * 60 : null;
}

export function clockOnDay(baseDateTime, hhmm) {
  const clock = String(hhmm || '').trim().match(/^(\d{1,2}):(\d{2})$/);
  const base = String(baseDateTime || '').replace('T', ' ');
  const dateMatch = base.match(/^(\d{4})-(\d{2})-(\d{2})/);
  const baseClock = base.match(/(\d{2}):(\d{2})/);
  if (!clock || !dateMatch) return null;
  const hours = Number(clock[1]);
  const minutes = Number(clock[2]);
  const baseMinutes = baseClock ? Number(baseClock[1]) * 60 + Number(baseClock[2]) : hours * 60 + minutes;
  const clockMinutes = hours * 60 + minutes;
  const diff = clockMinutes - baseMinutes;
  const utc = Date.UTC(Number(dateMatch[1]), Number(dateMatch[2]) - 1, Number(dateMatch[3]));
  const shifted = new Date(utc + (diff < -12 * 60 ? 86400_000 : diff > 12 * 60 ? -86400_000 : 0));
  return `${shifted.toISOString().slice(0, 10)} ${pad(hours)}:${pad(minutes)}:00`;
}

function clockLabel(clock, zone, title) {
  if (!clock) return null;
  return [title, clock, zone].filter(Boolean).join(' ');
}

export function parseFlightStatsJson(payload, publicUrl = '') {
  const data = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
  if (!data || typeof data !== 'object') return null;
  const schedule = data.schedule || {};
  const status = data.status || {};
  const note = data.flightNote || {};
  const header = data.resultHeader || {};
  const ticket = data.ticketHeader || {};
  const departure = data.departureAirport || {};
  const arrival = data.arrivalAirport || {};
  const carrier = String(header.carrier?.fs || ticket.carrier?.fs || '').toUpperCase();
  const number = String(header.flightNumber || ticket.flightNumber || '').trim();
  const statusLabel = String(status.status || header.status || '').trim();
  const delayText = String(status.statusDescription || header.statusDescription || '').trim();
  const delayMinutes = parseDelayMinutes(
    status.delay?.arrival?.minutes ?? status.delayStatus?.minutes,
    delayText || status.delayStatus?.wording || ''
  );
  const statusText = [statusLabel, delayText && !statusLabel.toLowerCase().includes(delayText.toLowerCase()) ? delayText : '']
    .filter(Boolean).join(' · ') || delayText;
  const depTitle = String(schedule.estimatedActualDepartureTitle || departure.times?.estimatedActual?.title || '').toLowerCase();
  const arrTitle = String(schedule.estimatedActualArrivalTitle || arrival.times?.estimatedActual?.title || '').toLowerCase();
  const landed = Boolean(data.isLanded || note.landed);
  const canceled = Boolean(note.canceled) || /cancel/i.test(`${statusLabel} ${delayText}`);
  let eta = localDateTime(schedule.estimatedActualArrivalUTC, 'Europe/Madrid')
    || localDateTime(schedule.estimatedActualArrival, 'Europe/Madrid');
  let actualArrival = landed || arrTitle === 'actual' ? eta : null;
  if (!(landed || arrTitle === 'actual')) actualArrival = null;
  const actualDeparture = (depTitle === 'actual' || note.hasActualGateDepartureTime || note.hasActualRunwayDepartureTime)
    ? localDateTime(schedule.estimatedActualDeparture || schedule.tookOff, 'Atlantic/Canary')
      || localDateTime(schedule.estimatedActualDepartureUTC, 'Atlantic/Canary')
    : null;
  if (actualArrival) eta = null;
  if (!statusText && !eta && !actualDeparture && !actualArrival) return null;
  return {
    iata_carrier: /^[A-Z][A-Z0-9]$/.test(carrier) ? carrier : null,
    iata_number: /^\d{1,4}[A-Z]?$/.test(number) ? number : null,
    status_text: (statusText || (canceled ? 'Cancelled' : '')).slice(0, 160) || null,
    delay_minutes: delayMinutes == null ? null : delayMinutes,
    eta,
    actual_departure: actualDeparture,
    actual_arrival: actualArrival,
    departure_text: clockLabel(departure.times?.estimatedActual?.time24, departure.times?.estimatedActual?.timezone, depTitle === 'actual' ? 'Actual' : null),
    arrival_text: clockLabel(arrival.times?.estimatedActual?.time24, arrival.times?.estimatedActual?.timezone, arrTitle === 'actual' ? 'Actual' : 'Estimated'),
    phase: String(note.phase || data.flightState || '').slice(0, 40) || null,
    public_url: publicUrl,
    canceled,
    origin_iata: String(departure.iata || header.departureAirportFS || '').toUpperCase(),
    destination_iata: String(arrival.iata || header.arrivalAirportFS || '').toUpperCase(),
  };
}

export function parseFlightStatsHtml(html, scheduledArrival = '', publicUrl = '') {
  const text = String(html || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
  if (!text) return null;
  const out = {
    iata_carrier: null, iata_number: null, status_text: null, delay_minutes: null,
    eta: null, actual_departure: null, actual_arrival: null, departure_text: null,
    arrival_text: null, phase: null, public_url: publicUrl, canceled: /\bCancelled\b/i.test(text),
    origin_iata: '', destination_iata: '',
  };
  const delay = first(text, /Delayed by\s+((?:\d+\s*h(?:ours?)?\s*)?\d+\s*m(?:in(?:utes?)?)?|\d+\s*h(?:ours?)?)/i);
  if (delay) {
    out.delay_minutes = parseDelayMinutes(null, delay);
    out.status_text = `Delayed by ${delay}`;
  } else {
    out.status_text = first(text, /\b(On time|Arrived|Departed|Scheduled|Cancelled|Landed|In flight|En route)\b/i);
  }
  const blocks = [...text.matchAll(/Scheduled\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})\s+(Actual|Estimated)\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})/g)];
  for (const found of blocks) {
    const zone = String(found[5] || '').toUpperCase();
    const kind = String(found[3] || '').toLowerCase();
    const label = `${kind === 'actual' ? 'Actual' : 'Estimated'} ${found[4]} ${zone}`;
    if (zone === 'CET' || zone === 'CEST') {
      out.arrival_text = label;
      if (kind === 'actual') out.actual_arrival = clockOnDay(scheduledArrival, found[4]);
      else out.eta = clockOnDay(scheduledArrival, found[4]);
    } else {
      out.departure_text = label;
      if (kind === 'actual') out.actual_departure = clockOnDay(scheduledArrival, found[4]);
    }
  }
  if (!out.eta && !out.actual_arrival && blocks.length) {
    const last = blocks[blocks.length - 1];
    const kind = String(last[3] || '').toLowerCase();
    out.arrival_text = `${kind === 'actual' ? 'Actual' : 'Estimated'} ${last[4]} ${last[5]}`;
    if (kind === 'actual') out.actual_arrival = clockOnDay(scheduledArrival, last[4]);
    else out.eta = clockOnDay(scheduledArrival, last[4]);
  }
  return (out.delay_minutes != null || out.eta || out.actual_departure || out.actual_arrival || out.status_text || out.canceled)
    ? out : null;
}
