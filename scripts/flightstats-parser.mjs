const first = (text, pattern) => text.match(pattern)?.[1]?.trim() || null;
const pad = number => String(number).padStart(2, '0');

export function localDateTime(value) {
  const raw = String(value || '').trim();
  if (!raw) return null;
  if (raw.endsWith('Z') || /[+-]\d{2}:\d{2}$/.test(raw)) {
    const stamp = new Date(raw);
    if (Number.isNaN(stamp.getTime())) return null;
    return `${stamp.getFullYear()}-${pad(stamp.getMonth() + 1)}-${pad(stamp.getDate())} ${pad(stamp.getHours())}:${pad(stamp.getMinutes())}:${pad(stamp.getSeconds())}`;
  }
  const slice = raw.replace('T', ' ').slice(0, 19);
  const match = slice.match(/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?/);
  return match ? `${match[1]} ${match[2]}:${match[3] || '00'}` : null;
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
  const delayMinutes = Number(status.delay?.arrival?.minutes ?? status.delayStatus?.minutes);
  const statusText = [statusLabel, delayText && !statusLabel.toLowerCase().includes(delayText.toLowerCase()) ? delayText : '']
    .filter(Boolean).join(' · ') || delayText;
  const depTitle = String(schedule.estimatedActualDepartureTitle || departure.times?.estimatedActual?.title || '').toLowerCase();
  const arrTitle = String(schedule.estimatedActualArrivalTitle || arrival.times?.estimatedActual?.title || '').toLowerCase();
  const landed = Boolean(data.isLanded || note.landed);
  const canceled = Boolean(note.canceled) || /cancel/i.test(`${statusLabel} ${delayText}`);
  let eta = localDateTime(schedule.estimatedActualArrival);
  let actualArrival = landed || arrTitle === 'actual' ? eta : null;
  if (!(landed || arrTitle === 'actual')) actualArrival = null;
  const actualDeparture = (depTitle === 'actual' || note.hasActualGateDepartureTime || note.hasActualRunwayDepartureTime)
    ? localDateTime(schedule.estimatedActualDeparture || schedule.tookOff)
    : null;
  if (actualArrival) eta = null;
  if (!statusText && !eta && !actualDeparture && !actualArrival) return null;
  return {
    iata_carrier: /^[A-Z][A-Z0-9]$/.test(carrier) ? carrier : null,
    iata_number: /^\d{1,4}[A-Z]?$/.test(number) ? number : null,
    status_text: (statusText || (canceled ? 'Cancelled' : '')).slice(0, 160) || null,
    delay_minutes: Number.isFinite(delayMinutes) ? Math.round(delayMinutes) : null,
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
  const delay = first(text, /Delayed by (\d+)\s*m/i);
  if (delay) {
    out.delay_minutes = Number(delay);
    out.status_text = `Delayed by ${delay}m`;
  } else {
    out.status_text = first(text, /\b(On time|Arrived|Departed|Scheduled|Cancelled|Landed|In flight|En route)\b/i);
  }
  const dep = text.match(/Scheduled\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})\s+Actual\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})/);
  if (dep) {
    out.departure_text = `Actual ${dep[3]} ${dep[4]}`;
    out.actual_departure = clockOnDay(scheduledArrival, dep[3]);
  }
  const est = text.match(/Scheduled\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})\s+Estimated\s+(\d{1,2}:\d{2})\s+([A-Z]{2,4})/);
  if (est) {
    out.arrival_text = `Estimated ${est[3]} ${est[4]}`;
    out.eta = clockOnDay(scheduledArrival, est[3]);
  } else {
    const arrived = text.match(/Scheduled\s+(\d{1,2}:\d{2})\s+(CET|CEST)\s+Actual\s+(\d{1,2}:\d{2})\s+(CET|CEST)/);
    if (arrived) {
      out.arrival_text = `Actual ${arrived[3]} ${arrived[4]}`;
      out.actual_arrival = clockOnDay(scheduledArrival, arrived[3]);
    }
  }
  return (out.delay_minutes != null || out.eta || out.actual_departure || out.actual_arrival || out.status_text || out.canceled)
    ? out : null;
}
