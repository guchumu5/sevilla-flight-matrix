<?php
declare(strict_types=1);

namespace SevillaMatrix;

/**
 * Cinta con doble fuente: Aena Infovuelos + FlightAware AeroAPI.
 * Ambas se respaldan; cualquier divergencia o cambio respecto a la última
 * conocida dispara atención (no se espera a que coincidan para avisar).
 */
final class BeltDualSource
{
    public const STATUS_NONE = 'none';
    public const STATUS_AENA_ONLY = 'aena_only';
    public const STATUS_AERO_ONLY = 'aeroapi_only';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DIVERGE = 'diverge';

    /**
     * @param array<string,mixed> $flight board row (puede incluir aeroapi snapshot)
     * @return array{
     *   status:string,
     *   aena_belt:?string,
     *   aena_hall:?string,
     *   aeroapi_belt:?string,
     *   display_belt:?string,
     *   display_hall:?string,
     *   display_source:?string,
     *   confidence:string,
     *   label:string,
     *   attention:?string,
     *   attention_reason:?string,
     *   needs_manual_review:bool
     * }
     */
    public static function evaluate(array $flight): array
    {
        $aenaBelt = self::normBelt(
            ($flight['source'] ?? null) === 'aena' ? ($flight['belt'] ?? null) : ($flight['aena_belt'] ?? null)
        );
        if ($aenaBelt === null && ($flight['source'] ?? null) === 'aena') {
            $aenaBelt = self::normBelt($flight['belt'] ?? null);
        }
        // Preferir cinta Aena explícita si el SQL la aporta aparte.
        if (isset($flight['aena_belt'])) {
            $aenaBelt = self::normBelt($flight['aena_belt']);
        }

        $aenaHall = self::normHall(
            isset($flight['aena_hall'])
                ? $flight['aena_hall']
                : (($flight['source'] ?? null) === 'aena' ? ($flight['hall'] ?? null) : null)
        );

        $aeroBelt = self::normBelt($flight['aeroapi']['belt'] ?? null)
            ?? self::normBelt(
                strtolower((string)($flight['secondary_belt_source'] ?? '')) === 'aeroapi'
                    ? ($flight['secondary_belt'] ?? null)
                    : null
            );

        $status = self::STATUS_NONE;
        $displayBelt = null;
        $displayHall = null;
        $displaySource = null;
        $confidence = 'ninguna';
        $label = 'Sin cinta en Aena ni FlightAware';
        $attention = null;
        $attentionReason = null;
        $manual = false;

        if ($aenaBelt !== null && $aeroBelt !== null) {
            if ($aenaBelt === $aeroBelt) {
                $status = self::STATUS_CONFIRMED;
                $displayBelt = $aenaBelt;
                $displayHall = $aenaHall;
                $displaySource = 'aena+aeroapi';
                $confidence = 'alta';
                $label = 'Cinta confirmada Aena + FlightAware';
            } else {
                $status = self::STATUS_DIVERGE;
                $displayBelt = $aenaBelt;
                $displayHall = $aenaHall;
                $displaySource = 'diverge';
                $confidence = 'conflicto';
                $label = 'ALERTA: Aena ' . $aenaBelt . ' ≠ FlightAware ' . $aeroBelt;
                $attention = 'critical';
                $attentionReason = 'Aena cinta ' . $aenaBelt . ' · FlightAware cinta ' . $aeroBelt
                    . '. Reconsultar ambas y análisis manual.';
                $manual = true;
            }
        } elseif ($aenaBelt !== null) {
            $status = self::STATUS_AENA_ONLY;
            $displayBelt = $aenaBelt;
            $displayHall = $aenaHall;
            $displaySource = 'aena';
            $confidence = 'media';
            $label = 'Cinta Aena (FlightAware aún sin baggage)';
        } elseif ($aeroBelt !== null) {
            $status = self::STATUS_AERO_ONLY;
            $displayBelt = $aeroBelt;
            $displayHall = null;
            $displaySource = 'aeroapi';
            $confidence = 'provisional';
            $label = 'Cinta FlightAware provisional (Aena aún sin cinta)';
            $attention = 'important';
            $attentionReason = 'FlightAware publica cinta ' . $aeroBelt
                . ' y Aena aún no; forzar Infovuelos y validar al llegar.';
            $manual = true;
        }

        $beltChanges = (int)($flight['belt_changes'] ?? 0)
            + (int)($flight['aena_belt_changes'] ?? 0)
            + (int)($flight['aeroapi_belt_changes'] ?? 0);
        if ($beltChanges > 0 && $attention !== 'critical') {
            $attention = 'critical';
            $attentionReason = $attentionReason
                ?? 'Cambio de cinta detectado en alguna fuente: seguimiento y análisis manual.';
            $manual = true;
        }

        return [
            'status' => $status,
            'aena_belt' => $aenaBelt,
            'aena_hall' => $aenaHall,
            'aeroapi_belt' => $aeroBelt,
            'display_belt' => $displayBelt,
            'display_hall' => $displayHall,
            'display_source' => $displaySource,
            'confidence' => $confidence,
            'label' => $label,
            'attention' => $attention,
            'attention_reason' => $attentionReason,
            'needs_manual_review' => $manual,
        ];
    }

    public static function normBelt(mixed $raw): ?string
    {
        $raw = strtoupper(trim((string)$raw));
        if ($raw === '' || $raw === 'NULL' || $raw === '-') return null;
        if (preg_match('/\b([A-H]?)(\d{1,2})\b/', $raw, $m)) return $m[2];
        if (preg_match('/^[A-Z0-9]{1,4}$/', $raw)) return $raw;
        return null;
    }

    public static function normHall(mixed $raw): ?string
    {
        $raw = strtoupper(trim((string)$raw));
        if ($raw === '' || $raw === 'NULL' || $raw === '-') return null;
        return $raw;
    }
}
