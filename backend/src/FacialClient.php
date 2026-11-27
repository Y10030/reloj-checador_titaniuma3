<?php
declare(strict_types=1);

interface FacialClientInterface
{
    public function health(): array;
    public function extract(array $imagesBase64, bool $sourceProfile = false): array;
    public function verify(array $imagesBase64, array $referenceEmbedding, string $livenessChallenge): array;
    public function identify(array $imagesBase64, array $candidates, string $livenessChallenge): array;
}

/**
 * Cliente HTTP local hacia el microservicio facial Python (FastAPI + InsightFace ONNX).
 * Solo se comunica por loopback (127.0.0.1) con encabezado de autenticación interno.
 */
final class FacialClient implements FacialClientInterface
{
    public function __construct(private array $cfg)
    {
    }

    public function health(): array
    {
        try {
            return $this->request('GET', '/health', null);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extrae el embedding facial y valida calidad + rostro único (para enrolamiento).
     *
     * @param string[] $imagesBase64
     */
    public function extract(array $imagesBase64, bool $sourceProfile = false): array
    {
        return $this->request('POST', '/extract', [
            'images_base64' => $imagesBase64,
            'source_profile' => $sourceProfile,
        ]);
    }

    /**
     * Verificación 1:1 en servidor (Modo Individual o Modo Escáner con Clave).
     *
     * @param string[] $imagesBase64
     * @param float[]  $referenceEmbedding
     */
    public function verify(array $imagesBase64, array $referenceEmbedding, string $livenessChallenge): array
    {
        return $this->request('POST', '/verify', [
            'images_base64' => $imagesBase64,
            'reference_embedding' => $referenceEmbedding,
            'liveness_challenge' => $livenessChallenge,
            'threshold' => $this->cfg['threshold_1_1'],
        ]);
    }

    /**
     * Identificación 1:N en servidor (Modo Escáner).
     * Exige superar umbral y margen mínimo de separación respecto al 2do candidato.
     *
     * @param string[] $imagesBase64
     * @param array<int, array{clave_empleado:int, embedding:float[]}> $candidates
     */
    public function identify(array $imagesBase64, array $candidates, string $livenessChallenge): array
    {
        return $this->request('POST', '/identify', [
            'images_base64' => $imagesBase64,
            'candidates' => $candidates,
            'liveness_challenge' => $livenessChallenge,
            'threshold' => $this->cfg['threshold_1_n'],
            'min_margin' => $this->cfg['margin_1_n'],
        ]);
    }

    private function request(string $method, string $path, ?array $body): array
    {
        $url = $this->cfg['url'] . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL hacia el microservicio facial.');
        }

        $headers = [
            'Accept: application/json',
            'X-Facial-Token: ' . $this->cfg['token'],
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->cfg['timeout'],
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $json;
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Microservicio facial no disponible (' . $err . ').');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Respuesta inválida del microservicio facial (HTTP ' . $code . ').');
        }
        if ($code >= 400) {
            $msg = (string)($decoded['detail'] ?? $decoded['message'] ?? ('HTTP ' . $code));
            throw new RuntimeException('Error en microservicio facial: ' . $msg);
        }
        return $decoded;
    }
}
