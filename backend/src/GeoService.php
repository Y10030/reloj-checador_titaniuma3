<?php
declare(strict_types=1);

/**
 * Validación geográfica en servidor mediante la fórmula de Haversine.
 * Bloquea cualquier intento fuera de las ubicaciones activas o con señal GPS deficiente.
 */
final class GeoService
{
    private const EARTH_RADIUS_METERS = 6371000.0;

    public function __construct(private PDO $pdo, private int $defaultMaxAccuracy = 120)
    {
    }

    /**
     * Calcula la distancia en metros entre dos coordenadas usando Haversine.
     */
    public static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2.0) ** 2
           + cos($phi1) * cos($phi2) * (sin($dLambda / 2.0) ** 2);

        $c = 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Evalúa coordenadas contra todas las ubicaciones activas.
     *
     * @return array{
     *   dentro_area_permitida: bool,
     *   motivo_rechazo: ?string,
     *   mensaje: string,
     *   id_ubicacion: ?int,
     *   nombre_ubicacion: ?string,
     *   distancia_metros: ?float,
     *   precision_gps_metros: ?float
     * }
     */
    public function evaluate(?float $lat, ?float $lon, ?float $accuracy): array
    {
        if ($lat === null || $lon === null || !is_finite($lat) || !is_finite($lon)) {
            return [
                'dentro_area_permitida' => false,
                'motivo_rechazo' => 'SIN_GPS',
                'mensaje' => 'Fuera de área permitida (no se recibió ubicación GPS).',
                'id_ubicacion' => null,
                'nombre_ubicacion' => null,
                'distancia_metros' => null,
                'precision_gps_metros' => $accuracy,
            ];
        }

        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return [
                'dentro_area_permitida' => false,
                'motivo_rechazo' => 'SIN_GPS',
                'mensaje' => 'Fuera de área permitida (coordenadas inválidas).',
                'id_ubicacion' => null,
                'nombre_ubicacion' => null,
                'distancia_metros' => null,
                'precision_gps_metros' => $accuracy,
            ];
        }

        $locations = $this->pdo
            ->query('SELECT id_ubicacion, nombre, latitud, longitud, radio_metros, precision_max_metros FROM ubicaciones_permitidas WHERE activo = 1')
            ->fetchAll();

        if (empty($locations)) {
            return [
                'dentro_area_permitida' => false,
                'motivo_rechazo' => 'FUERA_DE_AREA',
                'mensaje' => 'Fuera de área permitida (no existen ubicaciones activas configuradas).',
                'id_ubicacion' => null,
                'nombre_ubicacion' => null,
                'distancia_metros' => null,
                'precision_gps_metros' => $accuracy,
            ];
        }

        $bestLocation = null;
        $bestDistance = INF;
        $matchedLocation = null;
        $matchedDistance = INF;

        foreach ($locations as $loc) {
            $dist = self::haversineMeters($lat, $lon, (float)$loc['latitud'], (float)$loc['longitud']);
            if ($dist < $bestDistance) {
                $bestDistance = $dist;
                $bestLocation = $loc;
            }
            if ($dist <= (float)$loc['radio_metros'] && $dist < $matchedDistance) {
                $matchedDistance = $dist;
                $matchedLocation = $loc;
            }
        }

        $accValue = $accuracy ?? 0.0;
        $maxAcc = $matchedLocation !== null
            ? (int)$matchedLocation['precision_max_metros']
            : ($bestLocation !== null ? (int)$bestLocation['precision_max_metros'] : $this->defaultMaxAccuracy);

        if ($accuracy === null || !is_finite($accuracy) || $accValue <= 0.0 || $accValue > $maxAcc) {
            return [
                'dentro_area_permitida' => false,
                'motivo_rechazo' => 'GPS_IMPRECISO',
                'mensaje' => 'Fuera de área permitida (señal GPS imprecisa).',
                'id_ubicacion' => $bestLocation ? (int)$bestLocation['id_ubicacion'] : null,
                'nombre_ubicacion' => $bestLocation ? (string)$bestLocation['nombre'] : null,
                'distancia_metros' => is_finite($bestDistance) ? round($bestDistance, 2) : null,
                'precision_gps_metros' => $accuracy,
            ];
        }

        if ($matchedLocation === null) {
            return [
                'dentro_area_permitida' => false,
                'motivo_rechazo' => 'FUERA_DE_AREA',
                'mensaje' => 'Fuera de área permitida.',
                'id_ubicacion' => $bestLocation ? (int)$bestLocation['id_ubicacion'] : null,
                'nombre_ubicacion' => $bestLocation ? (string)$bestLocation['nombre'] : null,
                'distancia_metros' => is_finite($bestDistance) ? round($bestDistance, 2) : null,
                'precision_gps_metros' => round($accValue, 2),
            ];
        }

        return [
            'dentro_area_permitida' => true,
            'motivo_rechazo' => null,
            'mensaje' => 'En área permitida.',
            'id_ubicacion' => (int)$matchedLocation['id_ubicacion'],
            'nombre_ubicacion' => (string)$matchedLocation['nombre'],
            'distancia_metros' => round($matchedDistance, 2),
            'precision_gps_metros' => round($accValue, 2),
        ];
    }
}
