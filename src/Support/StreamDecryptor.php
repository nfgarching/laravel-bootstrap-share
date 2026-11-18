<?php

namespace Nfgarching\Share\Support;

use RuntimeException;

/**
 * Entschlüsselt Dateien im Format des Portal-Vaults:
 * 24 Byte secretstream-Header (XChaCha20-Poly1305), danach Blöcke zu
 * 65 536 Byte Klartext plus 17 Byte Authentifizierung; der letzte Block
 * trägt das FINAL-Kennzeichen. Fehlt es, war die Datei abgeschnitten.
 *
 * Bewusst getrennt vom Portal umgesetzt; geprüft gegen dessen Vault.
 * Für sich allein lauffähig (ohne Laravel).
 */
class StreamDecryptor
{
    public const CHUNK = 65536;

    /**
     * @return array{size: int, sha256: string} Größe und SHA-256 des Klartexts
     */
    public static function decryptFile(string $source, string $target, string $key): array
    {
        $input = @fopen($source, 'rb');
        $output = @fopen($target, 'wb');

        if ($input === false || $output === false) {
            throw new RuntimeException('Datei lässt sich nicht öffnen.');
        }

        try {
            $header = (string) stream_get_contents($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Datei ist beschädigt (Kopf fehlt).');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $block = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            $hash = hash_init('sha256');
            $size = 0;
            $final = false;

            while (! $final) {
                $chunk = (string) stream_get_contents($input, $block);

                if ($chunk === '') {
                    throw new RuntimeException('Datei ist unvollständig.');
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);

                if ($result === false) {
                    throw new RuntimeException('Datei ist beschädigt oder wurde verändert.');
                }

                [$plain, $tag] = $result;
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

                if ($plain !== '') {
                    hash_update($hash, $plain);
                    $size += strlen($plain);

                    if (fwrite($output, $plain) !== strlen($plain)) {
                        throw new RuntimeException('Schreibfehler beim Entschlüsseln.');
                    }
                }
            }

            if (! feof($input) && stream_get_contents($input, 1) !== '') {
                throw new RuntimeException('Datei enthält Daten nach dem Ende.');
            }

            return ['size' => $size, 'sha256' => hash_final($hash)];
        } finally {
            fclose($input);
            fclose($output);
        }
    }
}
