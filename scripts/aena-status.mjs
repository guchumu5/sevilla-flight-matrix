export const AENA_STATUS_LABELS = {
  SCH: 'Programado',
  INI: 'Programado',
  HOR: 'En hora',
  RET: 'Retrasado',
  EMB: 'Embarcando',
  ULL: 'Última llamada',
  NPT: 'Cambio de puerta',
  CER: 'Puerta cerrada',
  FLY: 'En vuelo',
  FNL: 'En aproximación',
  LND: 'Aterrizado',
  ATE: 'Aterrizado',
  OPE: 'Entrega de equipaje',
  OPF: 'Entrega de equipaje',
  IBK: 'Entrega de equipaje',
  BOR: 'Finalizado',
  CAN: 'Cancelado',
  DES: 'Desviado',
};

export function statusLabelFromCode(code) {
  const key = String(code || '').trim().toUpperCase();
  if (!key) return null;
  return AENA_STATUS_LABELS[key] || key;
}

export function statusIndicatesBaggageDelivery(status) {
  const code = String(status || '').trim().toUpperCase();
  if (['OPE', 'OPF', 'IBK'].includes(code)) return true;
  return /(?:entrega|recogida)(?:\s+de)?\s+(?:equip|malet)/i.test(String(status || ''));
}

export function baggageStateFromAenaStatus(status) {
  const code = String(status || '').trim().toUpperCase();
  if (['OPE', 'OPF', 'IBK'].includes(code) || statusIndicatesBaggageDelivery(status)) return 'entrega';
  if (code === 'BOR' || /finaliz/i.test(String(status || ''))) return 'finalizado';
  return 'pendiente';
}

export function statusIndicatesArrival(status) {
  const code = String(status || '').trim().toUpperCase();
  if (['LND', 'ATE', 'OPE', 'OPF', 'IBK', 'BOR'].includes(code)) return true;
  return /finaliz|aterriz|llegad/i.test(String(status || ''))
    || statusIndicatesBaggageDelivery(status);
}
