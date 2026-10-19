const first = (text, pattern) => text.match(pattern)?.[1]?.trim() || null;
const integer = value => value ? Number(String(value).replace(/,/g,'')) : null;
const oneLine = value => value ? value.replace(/\s+/g,' ').trim() : null;

function bootstrapJson(html) {
  const source = String(html || '');
  const marker = 'var trackpollBootstrap =';
  const markerAt = source.indexOf(marker);
  if (markerAt < 0) return null;
  const start = source.indexOf('{', markerAt + marker.length);
  if (start < 0) return null;
  let depth = 0;
  let inString = false;
  let escaped = false;
  for (let index = start; index < source.length; index++) {
    const character = source[index];
    if (inString) {
      if (escaped) escaped = false;
      else if (character === '\\') escaped = true;
      else if (character === '"') inString = false;
      continue;
    }
    if (character === '"') {
      inString = true;
      continue;
    }
    if (character === '{') depth++;
    if (character === '}' && --depth === 0) {
      try {
        return JSON.parse(source.slice(start, index + 1));
      } catch {
        return null;
      }
    }
  }
  return null;
}

function bootstrapAircraft(html) {
  const flights = bootstrapJson(html)?.flights;
  if (!flights || typeof flights !== 'object') return {};
  const current = Object.values(flights)[0];
  if (!current || typeof current !== 'object') return {};
  const primary = current.aircraft || {};
  const activity = Array.isArray(current.activityLog?.flights)
    ? current.activityLog.flights.find(item => item?.flightId === current.flightId)
      || current.activityLog.flights[0]
    : null;
  const fallback = activity?.aircraft || {};
  const icao24 = [
    primary.icao24, primary.hex, primary.hexId, primary.modeS, primary.modeSCode,
    fallback.icao24, fallback.hex, fallback.hexId, fallback.modeS, fallback.modeSCode,
  ].map(value => String(value || '').trim().toLowerCase().replace(/^0x/, ''))
    .find(value => /^[0-9a-f]{6}$/.test(value)) || null;
  return {
    registration: primary.tail || fallback.tail || null,
    type: primary.friendlyType || fallback.friendlyType || primary.type || fallback.type || null,
    icao24,
  };
}

export function parseFlightAwarePublicPage(text, html = '') {
  const aircraft = bootstrapAircraft(html);
  const statusText = first(text, /^(LANDED[^\n]*|EN ROUTE[^\n]*|DEPARTED[^\n]*|ARRIVED[^\n]*|SCHEDULED[^\n]*|CANCELLED[^\n]*)$/mi);
  const aircraftType = oneLine(first(text, /Aircraft Type\s+(.+?)(?:\s+Photos\s|\nPhotos\s)/is))
    || oneLine(aircraft.type);
  const point = text.match(/(?:^|\n)([\d,]+)\s*ft\s*\n([\d,]+)\s*mph(?:\n|$)/i);
  const filedSpeed = first(text, /Speed\s+Filed:\s*([\d,]+)\s*mph/i);
  const distance = first(text, /Distance\s+Actual:\s*([\d,]+)\s*mi/i);
  const duration = first(text, /(\d+h(?:\s+\d+m)?|\d+m)\s+total travel time/i)
    || first(text, /Past Flights[\s\S]{0,500}?\b(\d+h(?:\s+\d+m)?)\b/i);
  const departureBlock = text.match(/Departure Times([\s\S]{0,500}?)Arrival Times/i)?.[1] || '';
  const arrivalBlock = text.match(/Arrival Times([\s\S]{0,500}?)Aircraft Details/i)?.[1] || '';
  const gateDeparture = first(departureBlock, /Gate Departure\s+([^\n]+)/i);
  const takeoff = first(departureBlock, /Takeoff\s+([^\n]+)/i);
  const landing = first(arrivalBlock, /Landing\s+([^\n]+)/i);
  const gateArrival = first(arrivalBlock, /Gate Arrival\s+([^\n]+)/i);
  return {
    status_text: oneLine(statusText),
    aircraft_registration: oneLine(aircraft.registration)?.toUpperCase() || null,
    aircraft_icao24: aircraft.icao24,
    aircraft_type: aircraftType,
    altitude_ft: integer(point?.[1]),
    speed_mph: integer(point?.[2] || filedSpeed),
    distance_mi: integer(distance),
    duration_text: oneLine(duration),
    departure_text: oneLine([gateDeparture && `Puerta ${gateDeparture}`, takeoff && `Despegue ${takeoff}`].filter(Boolean).join(' · ')),
    arrival_text: oneLine([landing && `Aterrizaje ${landing}`, gateArrival && `Puerta ${gateArrival}`].filter(Boolean).join(' · ')),
  };
}
