import assert from 'node:assert/strict';
import { choosePhysicalCode } from './aena-flight-identity.mjs';

assert.equal(choosePhysicalCode(['IBE5338', 'VLG3047']), 'VLG3047');
assert.equal(choosePhysicalCode(['IBE5767', 'VLG3255']), 'VLG3255');
assert.equal(choosePhysicalCode(['IBE1071', 'ANE1071', 'QTR6948']), 'ANE1071');
assert.equal(choosePhysicalCode(['IBE1075', 'QTR1492']), 'IBE1075');
assert.equal(choosePhysicalCode(['RYR1234']), 'RYR1234');
assert.equal(choosePhysicalCode([]), null);

console.log('Identidad de vuelos Aena: OK');
