import assert from 'node:assert/strict';
import {
  baggageStateFromAenaStatus,
  statusIndicatesArrival,
  statusIndicatesBaggageDelivery,
  statusLabelFromCode,
} from './aena-status.mjs';

for (const status of [
  'Entrega equipaje',
  'Entrega de equipaje',
  'Entrega de equipajes',
  'Recogida de maletas',
  'IBK',
  'OPE',
]) {
  assert.equal(statusIndicatesBaggageDelivery(status), true, status);
  assert.equal(baggageStateFromAenaStatus(status), 'entrega', status);
  assert.equal(statusIndicatesArrival(status), true, status);
}

assert.equal(statusIndicatesBaggageDelivery('Entrega pendiente'), false);
assert.equal(baggageStateFromAenaStatus('Entrega pendiente'), 'pendiente');
assert.equal(baggageStateFromAenaStatus('Finalizado'), 'finalizado');
assert.equal(baggageStateFromAenaStatus('BOR'), 'finalizado');
assert.equal(statusIndicatesArrival('Aterrizado'), true);
assert.equal(statusIndicatesArrival('LND'), true);
assert.equal(statusIndicatesArrival('En vuelo'), false);
assert.equal(statusIndicatesArrival('FLY'), false);
assert.equal(statusLabelFromCode('FLY'), 'En vuelo');
assert.equal(statusLabelFromCode('IBK'), 'Entrega de equipaje');

console.log('Reglas de estado Aena: OK');
