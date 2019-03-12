<?php

namespace Crm\PaymentsModule\Api;

use Crm\ApiModule\Api\ApiHandler;
use Crm\ApiModule\Api\JsonResponse;
use Crm\ApiModule\Authorization\ApiAuthorizationInterface;
use Crm\ApiModule\Params\InputParam;
use Crm\ApiModule\Params\ParamsProcessor;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;

class ListPaymentsHandler extends ApiHandler
{
    private $paymentsRepository;

    public function __construct(
        PaymentsRepository $paymentsRepository
    ) {
        $this->paymentsRepository = $paymentsRepository;
    }

    public function params()
    {
        return [
            new InputParam(
                InputParam::TYPE_POST,
                'sales_funnel_url_key',
                InputParam::REQUIRED
            )
        ];
    }

    /**
     * @param ApiAuthorizationInterface $authorization
     * @return \Nette\Application\IResponse
     */
    public function handle(ApiAuthorizationInterface $authorization)
    {
        $paramsProcessor = new ParamsProcessor($this->params());
        $params = $paramsProcessor->getValues();

        if (!$params['sales_funnel_url_key']) {
            $response = new JsonResponse(['status' => 'error', 'message' => 'No valid sales funnel url key', 'code' => 'url_key_missing']);
            $response->setHttpCode(Response::S200_OK);
            return $response;
        }

        $payments = $this->paymentsRepository->findBySalesFunnelUrlKey($params['sales_funnel_url_key'])
            ->select('payments.*')
            ->where('payments.status = "paid"')
            ->order('payments.created_at ASC');

        $data = [];

        /** @var $payment ActiveRow */
        foreach ($payments as $payment) {
            $item = [
                'amount' => $payment->amount,
                'meta' => [],
            ];

            foreach ($payment->related('payment_meta.payment_id') as $paymentMeta) {
                $item['meta'][$paymentMeta->key] = $paymentMeta->value;
            }

            $data[] = $item;
        }

        $response = new JsonResponse([
            'status' => 'ok',
            'data' => $data
        ]);
        $response->setHttpCode(Response::S200_OK);

        return $response;
    }
}
