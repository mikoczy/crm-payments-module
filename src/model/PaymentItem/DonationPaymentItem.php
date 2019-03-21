<?php

namespace Crm\PaymentsModule\PaymentItem;

class DonationPaymentItem implements PaymentItemInterface
{
    const TYPE = 'donation';

    private $name;

    private $price;

    private $vat;

    public function __construct(string $name, float $price, int $vat)
    {
        $this->name = $name;
        $this->price = $price;
        $this->vat = $vat;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function unitPrice(): float
    {
        return $this->price;
    }

    public function totalPrice(): float
    {
        return $this->unitPrice() * $this->count();
    }

    public function vat(): int
    {
        return $this->vat;
    }

    public function count(): int
    {
        return 1;
    }

    public function data(): array
    {
        return [];
    }
}
