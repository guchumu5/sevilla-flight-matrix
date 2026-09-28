export function statusIndicatesBaggageDelivery(status) {
  return /(?:entrega|recogida)(?:\s+de)?\s+(?:equip|malet)/i.test(String(status || ''));
}

export function baggageStateFromAenaStatus(status) {
  if (statusIndicatesBaggageDelivery(status)) return 'entrega';
  if (/finaliz/i.test(String(status || ''))) return 'finalizado';
  return 'pendiente';
}

export function statusIndicatesArrival(status) {
  return /finaliz|aterriz|llegad/i.test(String(status || ''))
    || statusIndicatesBaggageDelivery(status);
}
