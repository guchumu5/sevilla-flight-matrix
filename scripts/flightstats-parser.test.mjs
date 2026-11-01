import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { isFlightStatsPlaceholder, parseFlightStatsHtml, parseFlightStatsJson } from './flightstats-parser.mjs';

const fixture = JSON.parse(readFileSync(new URL('./fixtures/flightstats-vy3190.json', import.meta.url), 'utf8'));
const parsed = parseFlightStatsJson(fixture, 'https://www.flightstats.com/v2/flight-tracker/VY/3190?year=2026&month=10&date=26');

assert.equal(parsed.iata_carrier, 'VY');
assert.equal(parsed.iata_number, '3190');
assert.equal(parsed.delay_minutes, 20);
assert.equal(parsed.eta, '2026-10-26 23:55:00');
assert.equal(parsed.actual_departure, '2026-10-26 20:44:00');
assert.equal(parsed.actual_arrival, null);
assert.match(parsed.status_text, /Delayed by 20m/i);
assert.equal(parsed.origin_iata, 'LPA');
assert.equal(parsed.destination_iata, 'SVQ');
assert.ok(!JSON.stringify(parsed).includes('"baggage"'));

const html = parseFlightStatsHtml(
  'Delayed by 20m Scheduled 20:20 WET Actual 20:44 WET Scheduled 23:35 CET Estimated 23:55 CET',
  '2026-10-26 23:40:00'
);
assert.equal(html.delay_minutes, 20);
assert.equal(html.actual_departure, '2026-10-26 20:44:00');
assert.equal(html.eta, '2026-10-26 23:55:00');

const live = JSON.parse(readFileSync(new URL('./fixtures/flightstats-vy3190-20261101.json', import.meta.url), 'utf8'));
const today = parseFlightStatsJson(live, 'https://www.flightstats.com/v2/flight-tracker/VY/3190?year=2026&month=11&date=1');
assert.equal(today.delay_minutes, 83);
assert.equal(today.eta, '2026-11-02 01:13:00');
assert.equal(today.destination_iata, 'SVQ');
assert.match(today.status_text, /Delayed by 1h 23m/i);

const hours = parseFlightStatsHtml(
  'Delayed by 1h 23m Scheduled 20:35 WET Estimated 21:58 WET Scheduled 23:50 CET Estimated 01:13 CET',
  '2026-11-01 23:50:00'
);
assert.equal(hours.delay_minutes, 83);
assert.equal(hours.eta, '2026-11-02 01:13:00');

const shell = parseFlightStatsHtml(
  'On time Scheduled Track flight VY 3190 Estimated 23:50 CET',
  '2026-11-01 23:50:00'
);
assert.equal(shell, null);
assert.equal(isFlightStatsPlaceholder({
  status_text: 'Scheduled · On time',
  delay_minutes: 0,
  eta: '2026-11-01 23:50:00',
}, '2026-11-01 23:50:00'), true);

console.log('Parser FlightStats público: OK');
