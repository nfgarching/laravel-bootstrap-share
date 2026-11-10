<?php

namespace Nfgarching\Share\Support;

/**
 * Liest Empfänger aus freier Eingabe:
 * "a@b.de", "Name <a@b.de>", "Müller, Hans <h@x.de>; info@y.de" oder ['email' => …, 'name' => …].
 *
 * Für sich allein lauffähig (ohne Laravel), damit es einzeln getestet werden kann.
 */
class AddressParser
{
    /**
     * @return array{email: string, name: ?string}|null
     */
    public static function one(mixed $value): ?array
    {
        if (is_array($value)) {
            $email = strtolower(trim((string) ($value['email'] ?? '')));
            $name = trim((string) ($value['name'] ?? '')) ?: null;

            return self::valid($email) ? ['email' => $email, 'name' => $name] : null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^(.*)<\s*([^<>\s]+)\s*>$/u', $value, $m)) {
            $email = strtolower(trim($m[2]));
            $name = trim($m[1], " \t\"'");

            return self::valid($email) ? ['email' => $email, 'name' => $name !== '' ? $name : null] : null;
        }

        $email = strtolower($value);

        return self::valid($email) ? ['email' => $email, 'name' => null] : null;
    }

    /**
     * Mehrere Adressen, getrennt durch Semikolon oder Zeilenumbruch; Kommas nur,
     * wenn sie nicht innerhalb eines Namens vor "<…>" stehen.
     *
     * @return array{valid: list<array{email: string, name: ?string}>, invalid: list<string>}
     */
    public static function many(string $input): array
    {
        $parts = [];

        foreach (preg_split('/[;\n\r]+/', $input) ?: [] as $chunk) {
            // "Müller, Hans <h@x.de>, info@y.de": Kommas gehören zum Namen, solange
            // im bisherigen Stück noch keine Adresse (@) steht.
            $buffer = '';

            foreach (explode(',', $chunk) as $piece) {
                $buffer = $buffer === '' ? $piece : $buffer.','.$piece;

                if (str_contains($buffer, '@')) {
                    $parts[] = trim($buffer);
                    $buffer = '';
                }
            }

            if (trim($buffer) !== '') {
                $parts[] = trim($buffer);
            }
        }

        $parts = array_values(array_filter($parts, fn (string $part) => $part !== ''));

        $valid = [];
        $invalid = [];

        foreach ($parts as $part) {
            $address = self::one($part);

            if ($address === null) {
                $invalid[] = $part;
            } elseif (! isset($valid[$address['email']])) {
                $valid[$address['email']] = $address;
            }
        }

        return ['valid' => array_values($valid), 'invalid' => $invalid];
    }

    public static function valid(string $email): bool
    {
        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
