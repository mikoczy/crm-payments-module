<?php
namespace Crm\PaymentsModule;

use Crm\PaymentsModule\Gateways\GatewayAbstract;
use Crm\PaymentsModule\Gateways\PaymentInterface;
use Crm\PaymentsModule\Gateways\RecurrentPaymentInterface;
use Nette\DI\Container;
use Nette\Utils\Strings;

// TODO: [payments_module] refactor - registration of payment gateways should be in modules (see remp/crm#649)
class GatewayFactory
{
    protected $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @param $code
     * @return GatewayAbstract|PaymentInterface|RecurrentPaymentInterface|object
     * @throws UnknownPaymentMethodCode
     */
    public function getGateway($code)
    {
        $code = str_replace(' ', '', Strings::capitalize(str_replace('_', ' ', $code)));
        $class = 'Crm\\PaymentsModule\\Gateways\\' . $code;
        if (!class_exists($class)) {
            throw new UnknownPaymentMethodCode("Payment code: {$code}");
        }

        return $this->container->getByType($class);
    }
}
