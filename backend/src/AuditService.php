<?php
declare(strict_types=1);

/**
 * Bitácora de rechazos (bitacora_rechazos) y control de tasa (rate limiting).
 */
final class AuditService
{
    public function __construct(private PDO $pdo, private array $securityCfg, private bool $trustProxy = true)
    {
    }

    public function clientIp(): string
    {
        if ($this->trustProxy) {
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
                return substr(trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']), 0, 45);
            }
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
                return substr(trim($parts[0]), 0, 45);
            }
        }
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'), 0, 45);
    }

    public function isRateLimited(?int $deviceId, string $ip): bool
    {
        $max = (int)($this->securityCfg['rate_limit_max_fails'] ?? 8);
        $windowMin = (int)($this->securityCfg['rate_limit_window_min'] ?? 10);
        $since = date('Y-m-d H:i:s', time() - ($windowMin * 60));

        if ($deviceId !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM bitacora_rechazos
                 WHERE (id_dispositivo = ? OR ip_origen = ?) AND fecha_hora_servidor >= ?'
            );
            $stmt->execute([$deviceId, $ip, $since]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM bitacora_rechazos
                 WHERE ip_origen = ? AND fecha_hora_servidor >= ?'
            );
            $stmt->execute([$ip, $since]);
        }

        return ((int)$stmt->fetchColumn()) >= $max;
    }

    /**
     * Protección anti-fuerza bruta exclusiva para acceso administrativo y códigos de invitación
     * (no afecta reintentos legítimos de checadas biométricas).
     */
    public function isBruteForceBlocked(string $motivo, string $ip, int $maxAttempts = 10, int $windowMinutes = 15): bool
    {
        $since = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM bitacora_rechazos
             WHERE motivo_rechazo = ? AND ip_origen = ? AND fecha_hora_servidor >= ?'
        );
        $stmt->execute([$motivo, $ip, $since]);
        return ((int)$stmt->fetchColumn()) >= $maxAttempts;
    }

    public function logRejection(
        string $motivo,
        ?int $deviceId = null,
        ?int $claveEmpleado = null,
        ?bool $dentroArea = null,
        ?float $distancia = null,
        ?string $detalle = null,
        ?string $ip = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bitacora_rechazos
             (id_dispositivo, clave_empleado_intento, motivo_rechazo, detalle, dentro_area_permitida, distancia_metros, fecha_hora_servidor, ip_origen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $deviceId,
            $claveEmpleado,
            $motivo,
            $detalle !== null ? substr($detalle, 0, 255) : null,
            $dentroArea === null ? null : ($dentroArea ? 1 : 0),
            $distancia,
            date('Y-m-d H:i:s'),
            $ip ?? $this->clientIp(),
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}
