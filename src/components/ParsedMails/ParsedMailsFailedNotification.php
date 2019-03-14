<?php

namespace Crm\PaymentsModule\Components;

use Crm\ApplicationModule\Widget\BaseWidget;
use Crm\ApplicationModule\Widget\WidgetManager;
use Crm\PaymentsModule\MailConfirmation\ParsedMailLogsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;

class ParsedMailsFailedNotification extends BaseWidget
{
    private $templateName = 'parsed_mails_failed_notification.latte';

    /** @var ParsedMailLogsRepository */
    private $parsedMailLogsRepository;

    /** @var  PaymentsRepository */
    private $paymentsRepository;

    /** @var WidgetManager */
    protected $widgetManager;

    public function __construct(
        WidgetManager $widgetManager,
        ParsedMailLogsRepository $parsedMailLogsRepository,
        PaymentsRepository $paymentsRepository
    ) {
        parent::__construct($widgetManager);
        $this->parsedMailLogsRepository = $parsedMailLogsRepository;
        $this->paymentsRepository = $paymentsRepository;
    }

    public function header($id = '')
    {
        return 'Zla suma';
    }

    public function identifier()
    {
        return 'parsedmailsfailed';
    }

    public function render($id = '')
    {
        $this->template->listPayments = $this->parsedMailLogsRepository->formPaymentsWithWrongAmount();

        $this->template->setFile(__DIR__ . '/' . $this->templateName);
        $this->template->render();
    }
}
