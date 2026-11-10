<?php

namespace Nfgarching\Share\Tests\Unit;

use Nfgarching\Share\Support\AddressParser;
use PHPUnit\Framework\TestCase;

class AddressParserTest extends TestCase
{
    public function test_single_addresses(): void
    {
        $this->assertSame(['email' => 'a@b.de', 'name' => null], AddressParser::one('a@b.de'));
        $this->assertSame(['email' => 'erika@example.de', 'name' => 'Erika Muster'], AddressParser::one('Erika Muster <ERIKA@Example.de>'));
        $this->assertSame(['email' => 'x@y.de', 'name' => 'Max'], AddressParser::one(['email' => 'X@y.de', 'name' => ' Max ']));
        $this->assertNull(AddressParser::one('kaputt'));
    }

    public function test_lists_with_commas_in_names(): void
    {
        $result = AddressParser::many("Müller, Hans <h@x.de>, info@y.de; kaputt\nErika <e@z.de>, info@y.de");

        $this->assertSame(['h@x.de', 'info@y.de', 'e@z.de'], array_column($result['valid'], 'email'));
        $this->assertSame('Müller, Hans', $result['valid'][0]['name']);
        $this->assertSame(['kaputt'], $result['invalid']);
    }
}
