import assert from 'node:assert/strict';
import {
  baggageStateFromAenaStatus,
  statusIndicatesArrival,
  statusIndicatesBaggageDelivery,
} from './aena-status.mjs';

for (const status of [
  'Entrega equipaje',
  'Entrega de equipaje',
  'Entrega de equipajes',
  'Recogida de maletas',
]) {
  assert.equal(statusIndicatesBaggageDelivery(status), true, status);
  assert.equal(baggageStateFromAenaStatus(status), 'entrega', status);
  assert.equal(statusIndicatesArrival(status), true, status);
}

assert.equal(statusIndicatesBaggageDelivery('Entrega pendiente'), false);
assert.equal(baggageStateFromAenaStatus('Entrega pendiente'), 'pendiente');
assert.equal(baggageStateFromAenaStatus('Finalizado'), 'finalizado');
assert.equal(statusIndicatesArrival('Aterrizado'), true);
assert.equal(statusIndicatesArrival('En vuelo'), false);

console.log('Reglas de estado Aena: OK');
