const AUTHORITATIVE_OPERATORS = ['VLG', 'IBS', 'ANE'];

export function choosePhysicalCode(codes) {
  const normalized = [...new Set((codes || [])
    .map(code => String(code || '').trim().toUpperCase())
    .filter(Boolean))];
  if (!normalized.length) return null;

  // En Infovuelos el código compartido de Iberia puede aparecer antes que el
  // operador real. Solo corregimos combinaciones conocidas y conservadoras:
  // Vueling, Iberia Express y Air Nostrum prevalecen frente a IBE cuando ambos
  // códigos describen la misma llegada física.
  if (normalized.some(code => code.startsWith('IBE'))) {
    for (const prefix of AUTHORITATIVE_OPERATORS) {
      const operating = normalized.find(code => code.startsWith(prefix));
      if (operating) return operating;
    }
  }
  return normalized[0];
}
