import assert from 'node:assert/strict';
import { parseFlightAwarePublicPage } from './flightaware-parser.mjs';

const sample = `Vueling Airlines 3051
VLG3051 / VY3051
LANDED 3 HOURS AGO
100 ft
159 mph
1h 49m total travel time
Departure Times
Gate Departure
09:35AM WEST
Takeoff
09:38AM WEST
Arrival Times
Landing
12:24PM CEST
Gate Arrival
12:33PM CEST
Aircraft Details
Aircraft Information
Aircraft Type Airbus A320 (twin-jet) (A320) Photos
Flight Data
Speed Filed: 448 mph
Distance Actual: 895 mi (Direct: 857 mi)`;

const parsed = parseFlightAwarePublicPage(sample);
assert.equal(parsed.status_text, 'LANDED 3 HOURS AGO');
assert.equal(parsed.aircraft_type, 'Airbus A320 (twin-jet) (A320)');
assert.equal(parsed.altitude_ft, 100);
assert.equal(parsed.speed_mph, 159);
assert.equal(parsed.distance_mi, 895);
assert.equal(parsed.duration_text, '1h 49m');
assert.match(parsed.departure_text, /09:35AM WEST/);
assert.match(parsed.arrival_text, /12:24PM CEST/);
console.log('Parser FlightAware público: OK');
