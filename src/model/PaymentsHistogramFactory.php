<?php
namespace Crm\PaymentsModule;

use Crm\ApplicationModule\Cache\CacheRepository;
use Crm\ApplicationModule\Graphs\Criteria;
use Crm\ApplicationModule\Graphs\GraphData;
use Crm\ApplicationModule\Graphs\GraphDataItem;
use Nette\Utils\Json;

class PaymentsHistogramFactory
{
    private $graphData;

    private $cacheRepository;

    public function __construct(GraphData $graphData, CacheRepository $cacheRepository)
    {
        $this->graphData = $graphData;
        $this->cacheRepository = $cacheRepository;
    }

    /**
     * Compute payments count histogram for given payment status
     * @param      $status
     * @param bool $forceCacheUpdate
     *
     * @return mixed
     * @throws \Nette\Utils\JsonException
     */
    public function paymentsLastMonthDailyHistogram($status, $forceCacheUpdate = false)
    {
        $cacheKey = "payments_status_{$status}_last_month_daily_histogram";

        $callable = function () use ($status) {
            $graphDataItem = new GraphDataItem();
            $graphDataItem->setCriteria((new Criteria())
                ->setTableName('payments')
                ->setWhere("AND payments.status = '$status'"));

            $this->graphData->clear();
            $this->graphData->addGraphDataItem($graphDataItem);
            $this->graphData->setScaleRange('day')->setStart('-31 days');

            $data = $this->graphData->getData();
            return Json::encode($data);
        };

        return Json::decode($this->cacheRepository->loadAndUpdate(
            $cacheKey,
            $callable,
            \Nette\Utils\DateTime::from(CacheRepository::REFRESH_TIME_5_MINUTES),
            $forceCacheUpdate
        ), Json::FORCE_ARRAY);
    }
}
