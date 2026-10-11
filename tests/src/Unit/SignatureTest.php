<?php

declare(strict_types=1);

namespace Drupal\Tests\x402_pay\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\x402_pay\Eip712;
use Drupal\x402_pay\SellerProof;
use Drupal\x402_pay\Signer;

/**
 * EIP-712 hashing, signing and the seller proof, against viem's output.
 *
 * The vectors come from scripts/make-vectors.mjs: viem is what settled the
 * day-1 payment against Circle, so matching it byte for byte is the check.
 */
#[Group('x402_pay')]
final class SignatureTest extends UnitTestCase {

  /**
   * Expected values, computed with viem.
   */
  private array $v;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->v = json_decode(file_get_contents(__DIR__ . '/../../fixtures/vectors.json'), TRUE);
  }

  /**
   * Keccak of body matches viem.
   */
  public function testKeccakOfBodyMatchesViem(): void {
    $this->assertSame($this->v['bodyHash'], Eip712::keccak($this->v['body']));
  }

  /**
   * Seller request digest matches viem.
   */
  public function testSellerRequestDigestMatchesViem(): void {
    $sr = $this->v['sellerRequest'];
    $this->assertSame($sr['digest'], Eip712::hash($sr['domain'], $sr['types'], 'SellerRequest', $sr['message']));
  }

  /**
   * Address from private key.
   */
  public function testAddressFromPrivateKey(): void {
    $this->assertSame($this->v['address'], (new Signer($this->v['privateKey']))->address());
  }

  /**
   * Signature matches viem.
   */
  public function testSignatureMatchesViem(): void {
    $sr = $this->v['sellerRequest'];
    $this->assertSame($sr['signature'], (new Signer($this->v['privateKey']))->sign($sr['digest']));
  }

  /**
   * Seller proof header matches viem.
   */
  public function testSellerProofHeaderMatchesViem(): void {
    $m = $this->v['sellerRequest']['message'];
    $header = SellerProof::header(
      new Signer($this->v['privateKey']),
      'settle', 'POST', $this->v['body'], $m['network'],
      nonce: $m['nonce'], issuedAt: $m['issuedAt'],
    );
    $this->assertSame($this->v['sellerRequest']['header'], $header);
  }

  /**
   * Rejects malformed private key.
   */
  public function testRejectsMalformedPrivateKey(): void {
    $this->expectException(\InvalidArgumentException::class);
    new Signer('0x1234');
  }

}
