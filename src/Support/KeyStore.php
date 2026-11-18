<?php

namespace Nfgarching\Share\Support;

use RuntimeException;

/**
 * Schlüsselpaar (X25519) für den Rückkanal.
 *
 * Das Portal versiegelt den Schlüssel jeder eingegangenen Datei mit dem
 * öffentlichen Schlüssel; nur mit dem privaten Schlüssel aus dieser Datei
 * lässt er sich wieder öffnen. Die Datei liegt außerhalb der Datenbank,
 * Rechte 0600, Inhalt Base64.
 *
 * Für sich allein lauffähig (ohne Laravel).
 */
class KeyStore
{
    public function __construct(private readonly string $path) {}

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /** Erzeugt ein neues Schlüsselpaar. Ein vorhandenes wird nur mit $force ersetzt. */
    public function generate(bool $force = false): string
    {
        if ($this->exists() && ! $force) {
            throw new RuntimeException('Es gibt bereits ein Schlüsselpaar: '.$this->path);
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Verzeichnis für den Schlüssel lässt sich nicht anlegen: '.$directory);
        }

        $keypair = sodium_crypto_box_keypair();
        $temporary = $this->path.'.tmp';

        if (file_put_contents($temporary, base64_encode($keypair)."\n") === false) {
            throw new RuntimeException('Schlüssel lässt sich nicht schreiben: '.$this->path);
        }

        chmod($temporary, 0600);
        rename($temporary, $this->path);

        return $this->publicKey();
    }

    /** Öffentlicher Schlüssel, Base64 – wird im Portal hinterlegt. */
    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_box_publickey($this->keypair()));
    }

    public function fingerprint(): string
    {
        return substr(hash('sha256', sodium_crypto_box_publickey($this->keypair())), 0, 16);
    }

    /** Öffnet einen versiegelten Dateischlüssel (Base64). */
    public function openSealed(string $sealedBase64): string
    {
        $sealed = base64_decode($sealedBase64, true);
        $key = $sealed === false ? false : sodium_crypto_box_seal_open($sealed, $this->keypair());

        if ($key === false) {
            throw new RuntimeException('Der Dateischlüssel lässt sich nicht öffnen – passt das Schlüsselpaar zum Portal?');
        }

        return $key;
    }

    private function keypair(): string
    {
        if (! $this->exists()) {
            throw new RuntimeException('Kein Schlüsselpaar vorhanden. Bitte php artisan share:keygen ausführen.');
        }

        $keypair = base64_decode(trim((string) file_get_contents($this->path)), true);

        if ($keypair === false || strlen($keypair) !== SODIUM_CRYPTO_BOX_KEYPAIRBYTES) {
            throw new RuntimeException('Die Schlüsseldatei ist beschädigt: '.$this->path);
        }

        return $keypair;
    }
}
