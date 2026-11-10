<?php

namespace Nfgarching\Share\Client;

/**
 * Signaturverfahren der Portal-API (siehe docs/api.md im Portal-Repo).
 *
 * Signiert wird die Zeichenkette
 *
 *     METHODE \n PFAD-MIT-QUERY \n ZEITSTEMPEL \n SHA256-DES-INHALTS
 *
 * mit HMAC-SHA256 und dem Signaturschlüssel des API-Clients.
 *
 * Bewusst getrennt vom Portal umgesetzt; beide Seiten prüfen gegen dieselben
 * Testvektoren (tests/Unit/RequestSignerTest.php).
 */
class RequestSigner
{
    public static function canonical(string $method, string $pathWithQuery, string $timestamp, string $contentSha256): string
    {
        return implode("\n", [strtoupper($method), $pathWithQuery, $timestamp, strtolower($contentSha256)]);
    }

    public static function sign(string $secret, string $method, string $pathWithQuery, string $timestamp, string $contentSha256): string
    {
        return hash_hmac('sha256', self::canonical($method, $pathWithQuery, $timestamp, $contentSha256), $secret);
    }
}
