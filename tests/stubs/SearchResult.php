<?php

namespace WHMCS\Domains\DomainLookup;

/**
 * Stub of WHMCS\Domains\DomainLookup\SearchResult (domain lookup results).
 */
class SearchResult
{
    public const STATUS_NOT_REGISTERED = 'available';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_TLD_NOT_SUPPORTED = 'invalid';

    private string $sld;
    private string $tld;
    private string $status = '';
    private bool $premium = false;
    private array $premiumCostPricing = [];

    public function __construct(string $sld, string $tld)
    {
        $this->sld = $sld;
        $this->tld = $tld;
    }

    public function getSecondLevel(): string { return $this->sld; }
    public function getTopLevel(): string { return $this->tld; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setPremiumDomain(bool $p): self { $this->premium = $p; return $this; }
    public function isPremium(): bool { return $this->premium; }
    public function setPremiumCostPricing(array $p): self { $this->premiumCostPricing = $p; return $this; }
    public function getPremiumCostPricing(): array { return $this->premiumCostPricing; }
}
