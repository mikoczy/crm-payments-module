<?php

namespace Crm\PaymentsModule\DataProvider;

use Crm\ApplicationModule\DataProvider\DataProviderInterface;
use Crm\PaymentsModule\PaymentItem\PaymentItemInterface;
use Nette\Application\UI\Form;

interface PaymentFormDataProviderInterface extends DataProviderInterface
{
    public function provide(array $params): Form;

    /**
     * @param array $params
     * @return PaymentItemInterface[]
     */
    public function paymentItems(array $params): array;
}
