<?php
declare(strict_types=1);

/**
 * Servicios criptográficos:
 * - Cifrado AES-256-GCM de vectores biométricos (embeddings) en reposo.
 * - Verificación de firmas ECDSA P-256 generadas por Web Crypto API (IEEE P1363 -> ASN.1 DER).
 * - Generación de UUID v4, nonces y códigos de vinculación.
 */
final class CryptoService
{
    private string $keyBytes;

    public function __construct(string $hexKey)
    {
        $bin = @hex2bin(trim($hexKey));
        if ($bin === false || strlen($bin) !== 32) {
            throw new RuntimeException('APP_ENCRYPTION_KEY debe ser una cadena hexadecimal válida de 64 caracteres (32 bytes).');
        }
        $this->keyBytes = $bin;
    }

    /**
     * Cifra un vector de flotantes (embedding facial) usando AES-256-GCM.
     *
     * @param float[] $embedding
     * @return array{ciphertext: string, iv: string, tag: string}
     */
    public function encryptEmbedding(array $embedding): array
    {
        $packed = '';
        foreach ($embedding as $v) {
            $packed .= pack('g', (float)$v); // IEEE 754 float32 little-endian
        }
        $iv = random_bytes(12); // Recomendado por NIST para GCM (96 bits)
        $tag = '';
        $ciphertext = openssl_encrypt($packed, 'aes-256-gcm', $this->keyBytes, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('Error al cifrar la plantilla biométrica.');
        }
        return [
            'ciphertext' => $ciphertext,
            'iv' => $iv,
            'tag' => $tag,
        ];
    }

    /**
     * Descifra un vector biométrico previamente almacenado con AES-256-GCM.
     *
     * @return float[]
     */
    public function decryptEmbedding(string $ciphertext, string $iv, string $tag): array
    {
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->keyBytes, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Error de integridad al descifrar la plantilla biométrica.');
        }
        $unpacked = unpack('g*', $plain);
        return $unpacked === false ? [] : array_values($unpacked);
    }

    /**
     * Verifica una firma ECDSA P-256 SHA-256 proveniente del navegador (Web Crypto API).
     * Acepta firmas en formato raw IEEE P1363 (64 bytes, estándar Web Crypto) o ASN.1 DER.
     */
    public function verifyEcdsaSignature(string $publicKeyPem, string $payload, string $signatureBase64): bool
    {
        $sigRaw = base64_decode($signatureBase64, true);
        if ($sigRaw === false || $sigRaw === '') {
            return false;
        }

        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            return false;
        }

        // Si viene en formato IEEE P1363 de 64 bytes (Web Crypto P-256), convertir a ASN.1 DER
        $derSig = strlen($sigRaw) === 64 ? $this->p1363ToDer($sigRaw) : $sigRaw;
        $ok = openssl_verify($payload, $derSig, $key, OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }

    /**
     * Convierte una firma ECDSA P-256 raw (r || s, 64 bytes) a formato ASN.1 DER para OpenSSL.
     */
    public function p1363ToDer(string $raw64): string
    {
        $r = $this->asn1Integer(substr($raw64, 0, 32));
        $s = $this->asn1Integer(substr($raw64, 32, 32));
        $seq = $r . $s;
        return "\x30" . $this->asn1Length(strlen($seq)) . $seq;
    }

    private function asn1Integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        // Si el bit más significativo es 1, anteponer 0x00 para mantener signo positivo
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . $this->asn1Length(strlen($bytes)) . $bytes;
    }

    private function asn1Length(int $len): string
    {
        if ($len < 128) {
            return chr($len);
        }
        $temp = '';
        while ($len > 0) {
            $temp = chr($len & 0xFF) . $temp;
            $len >>= 8;
        }
        return chr(0x80 | strlen($temp)) . $temp;
    }

    public static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function randomHex(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function inviteCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }
}
