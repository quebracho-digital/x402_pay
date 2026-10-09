<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Drupal\x402_pay\InvalidPaymentException;
use Drupal\x402_pay\PaymentHeaders;

/**
 * The x402 v2 HTTP headers: base64 (standard, padded) of a JSON object.
 */
#[Group('x402_pay')]
final class PaymentHeadersTest extends UnitTestCase {

  /**
   * PAYMENT-SIGNATURE from the x402 v2 HTTP transport spec.
   */
  private const SPEC_PAYMENT_SIGNATURE = 'eyJ4NDAyVmVyc2lvbiI6MiwicmVzb3VyY2UiOnsidXJsIjoiaHR0cHM6Ly9hcGkuZXhhbXBsZS5jb20vcHJlbWl1bS1kYXRhIiwiZGVzY3JpcHRpb24iOiJBY2Nlc3MgdG8gcHJlbWl1bSBtYXJrZXQgZGF0YSIsIm1pbWVUeXBlIjoiYXBwbGljYXRpb24vanNvbiJ9LCJhY2NlcHRlZCI6eyJzY2hlbWUiOiJleGFjdCIsIm5ldHdvcmsiOiJlaXAxNTU6ODQ1MzIiLCJhbW91bnQiOiIxMDAwMCIsImFzc2V0IjoiMHgwMzZDYkQ1Mzg0MmM1NDI2NjM0ZTc5Mjk1NDFlQzIzMThmM2RDRjdlIiwicGF5VG8iOiIweDIwOTY5M0JjNmFmYzBDNTMyOGJBMzZGYUYwM0M1MTRFRjMxMjI4N0MiLCJtYXhUaW1lb3V0U2Vjb25kcyI6NjAsImV4dHJhIjp7Im5hbWUiOiJVU0RDIiwidmVyc2lvbiI6IjIifX0sInBheWxvYWQiOnsic2lnbmF0dXJlIjoiMHgyZDZhNzU4OGQ2YWNjYTUwNWNiZjBkOWE0YTIyN2UwYzUyYzZjMzQwMDhjOGU4OTg2YTEyODMyNTk3NjQxNzM2MDhhMmNlNjQ5NjY0MmUzNzdkNmRhOGRiYmY1ODM2ZTliZDE1MDkyZjllY2FiMDVkZWQzZDYyOTNhZjE0OGI1NzFjIiwiYXV0aG9yaXphdGlvbiI6eyJmcm9tIjoiMHg4NTdiMDY1MTlFOTFlM0E1NDUzODc5MWJEYmIwRTIyMzczZTM2YjY2IiwidG8iOiIweDIwOTY5M0JjNmFmYzBDNTMyOGJBMzZGYUYwM0M1MTRFRjMxMjI4N0MiLCJ2YWx1ZSI6IjEwMDAwIiwidmFsaWRBZnRlciI6IjE3NDA2NzIwODkiLCJ2YWxpZEJlZm9yZSI6IjE3NDA2NzIxNTQiLCJub25jZSI6IjB4ZjM3NDY2MTNjMmQ5MjBiNWZkYWJjMDg1NmYyYWViMmQ0Zjg4ZWU2MDM3YjhjYzVkMDRhNzFhNDQ2MmYxMzQ4MCJ9fX0=';

  public function testDecodesTheSpecExample(): void {
    $payload = PaymentHeaders::decode(self::SPEC_PAYMENT_SIGNATURE);
    $this->assertSame(2, $payload['x402Version']);
    $this->assertSame('eip155:84532', $payload['accepted']['network']);
    $this->assertSame('10000', $payload['payload']['authorization']['value']);
  }

  public function testRoundTrip(): void {
    $data = ['x402Version' => 2, 'accepts' => [['amount' => '10000', 'payTo' => '0xabc']], 'note' => 'ñandú/ombú'];
    $this->assertSame($data, PaymentHeaders::decode(PaymentHeaders::encode($data)));
  }

  public function testEncodesStandardPaddedBase64(): void {
    $header = PaymentHeaders::encode(['a' => '??>']);
    $this->assertMatchesRegularExpression('#^[A-Za-z0-9+/]+=*$#', $header);
    $this->assertSame('{"a":"??>"}', base64_decode($header));
  }

  #[DataProvider('garbage')]
  public function testRejectsGarbage(string $header): void {
    $this->expectException(InvalidPaymentException::class);
    PaymentHeaders::decode($header);
  }

  public static function garbage(): array {
    return [
      'not base64' => ['%%%'],
      'not json' => [base64_encode('nope')],
      'json scalar' => [base64_encode('42')],
      'empty' => [''],
    ];
  }

}
