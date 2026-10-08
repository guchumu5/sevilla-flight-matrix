const first = (text, pattern) => text.match(pattern)?.[1]?.trim() || null;
const integer = value => value ? Number(String(value).replace(/,/g,'')) : null;
const oneLine = value => value ? value.replace(/\s+/g,' ').trim() : null;

export function parseFlightAwarePublicPage(text) {
  const statusText = first(text, /^(LANDED[^\n]*|EN ROUTE[^\n]*|DEPARTED[^\n]*|ARRIVED[^\n]*|SCHEDULED[^\n]*|CANCELLED[^\n]*)$/mi);
  const aircraftType = oneLine(first(text, /Aircraft Type\s+(.+?)(?:\s+Photos\s|\nPhotos\s)/is));
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
    aircraft_type: aircraftType,
    altitude_ft: integer(point?.[1]),
    speed_mph: integer(point?.[2] || filedSpeed),
    distance_mi: integer(distance),
    duration_text: oneLine(duration),
    departure_text: oneLine([gateDeparture && `Puerta ${gateDeparture}`, takeoff && `Despegue ${takeoff}`].filter(Boolean).join(' · ')),
    arrival_text: oneLine([landing && `Aterrizaje ${landing}`, gateArrival && `Puerta ${gateArrival}`].filter(Boolean).join(' · ')),
  };
}
