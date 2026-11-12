<?php

namespace WHMCS\Domain\TopLevel;

class ImportItem
{
    private string $extension = '';
    private int $minYears = 1;
    private int $maxYears = 10;
    private float $registerPrice = 0;
    private float $renewPrice = 0;
    private float $transferPrice = 0;
    private int $redemptionFeeDays = 0;
    private float $redemptionFeePrice = 0;
    private string $currency = 'EUR';
    private bool $eppRequired = true;

    public function setExtension(string $ext): self { $this->extension = $ext; return $this; }
    public function getExtension(): string { return $this->extension; }
    public function setMinYears(int $y): self { $this->minYears = $y; return $this; }
    public function getMinYears(): int { return $this->minYears; }
    public function setMaxYears(int $y): self { $this->maxYears = $y; return $this; }
    public function getMaxYears(): int { return $this->maxYears; }
    public function setRegisterPrice(float $p): self { $this->registerPrice = $p; return $this; }
    public function getRegisterPrice(): float { return $this->registerPrice; }
    public function setRenewPrice(float $p): self { $this->renewPrice = $p; return $this; }
    public function getRenewPrice(): float { return $this->renewPrice; }
    public function setTransferPrice(float $p): self { $this->transferPrice = $p; return $this; }
    public function getTransferPrice(): float { return $this->transferPrice; }
    public function setRedemptionFeeDays(int $d): self { $this->redemptionFeeDays = $d; return $this; }
    public function getRedemptionFeeDays(): int { return $this->redemptionFeeDays; }
    public function setRedemptionFeePrice(float $p): self { $this->redemptionFeePrice = $p; return $this; }
    public function getRedemptionFeePrice(): float { return $this->redemptionFeePrice; }
    public function setCurrency(string $c): self { $this->currency = $c; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setEppRequired(bool $r): self { $this->eppRequired = $r; return $this; }
    public function getEppRequired(): bool { return $this->eppRequired; }
}
