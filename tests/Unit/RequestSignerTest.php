<?php

namespace Nfgarching\Share\Tests\Unit;

use Nfgarching\Share\Client\RequestSigner;
use PHPUnit\Framework\TestCase;

/**
 * Testvektoren aus docs/api.md des Portals. Beide Seiten müssen sie erfüllen.
 */
class RequestSignerTest extends TestCase
{
    public function test_vector_post_with_body(): void
    {
        $hash = hash('sha256', '{"subject":"Test"}');

        $this->assertSame('53e3079a7e6598bf369f85566f55601c549c83a867e087d7b0586655c25d6414', $hash);
        $this->assertSame(
            '2b79322cf75b4ee5d4ea0787743b3cc2fb0f62e4f92765b7ea8cd50ae4495cc0',
            RequestSigner::sign('sk_test', 'POST', '/api/v1/shipments', '1794268800', $hash),
        );
    }

    public function test_vector_get_with_query(): void
    {
        $this->assertSame(
            'b21fd90f16e03ab5f4a7340540ac2dc8988aaaeaf2b99a8b66bdbcc90f0cca63',
            RequestSigner::sign('sk_test', 'GET', '/api/v1/events?after=10', '1794268800', hash('sha256', '')),
        );
    }
}
