<?php

declare(strict_types=1);

namespace MyProof\LaravelClient\Data;

/** The order contract captures exactly one immutable asset version per order. */
final readonly class OrderInput
{
    public function __construct(public string $reference, public string $title, #[\SensitiveParameter] public string $buyerEmail, public int $amountMinor, public string $currency, public int $currencyExponent, public string $assetVersionId) {}

    public function toArray(): array
    {
        return ['reference' => $this->reference, 'title' => $this->title, 'buyer_email' => $this->buyerEmail, 'amount_minor' => $this->amountMinor,
            'currency' => $this->currency, 'currency_exponent' => $this->currencyExponent, 'asset_version_ids' => [$this->assetVersionId]];
    }

    public function __debugInfo(): array
    {
        return ['buyerEmail' => '[redacted]', 'assetVersionId' => $this->assetVersionId];
    }
}
