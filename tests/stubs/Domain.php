<?php

namespace WHMCS\Domain\Registrar;

class Domain
{
    public const STATUS_NOT_REGISTERED = 'not_registered';
    public const STATUS_REGISTERED = 'registered';
    public const STATUS_UNKNOWN = 'unknown';

    private string $domainName = '';
    private string $domainTld = '';
    private string $status = '';
    private bool $isPremiumName = false;
    private array $premiumCostPricing = [];

    public function setDomainName(string $n): self { $this->domainName = $n; return $this; }
    public function getDomainName(): string { return $this->domainName; }
    public function setDomainTld(string $t): self { $this->domainTld = $t; return $this; }
    public function getDomainTld(): string { return $this->domainTld; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setIsPremiumName(bool $p): self { $this->isPremiumName = $p; return $this; }
    public function getIsPremiumName(): bool { return $this->isPremiumName; }
    public function setPremiumCostPricing(array $p): self { $this->premiumCostPricing = $p; return $this; }
    public function getPremiumCostPricing(): array { return $this->premiumCostPricing; }
}
