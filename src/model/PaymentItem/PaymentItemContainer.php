<?php

namespace Crm\PaymentsModule\PaymentItem;

class PaymentItemContainer
{
    /** @var PaymentItemInterface[] */
    private $items = [];

    public function addItem(PaymentItemInterface $item): self
    {
        $this->items[] = $item;
        return $this;
    }

    public function addItems(array $items): self
    {
        foreach ($items as $item) {
            $this->addItem($item);
        }
        return $this;
    }

    /**
     * @return PaymentItemInterface[]
     */
    public function items(): array
    {
        return $this->items;
    }

    public function totalPrice(): float
    {
        $price = 0;
        foreach ($this->items() as $item) {
            $price += $item->price();
        }
        return $price;
    }
}
