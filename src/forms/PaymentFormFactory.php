<?php

namespace Crm\PaymentsModule\Forms;

use Crm\ApplicationModule\DataProvider\DataProviderManager;
use Crm\PaymentsModule\DataProvider\PaymentFormDataProviderInterface;
use Crm\PaymentsModule\PaymentItem\DonationPaymentItem;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Subscription\SubscriptionType;
use Crm\UsersModule\Repository\AddressesRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Application\UI\Form;
use Nette\Database\Table\IRow;
use Nette\Forms\Controls\TextInput;
use Nette\Localization\ITranslator;
use Nette\Utils\DateTime;
use Nette\Utils\Html;
use Nette\Utils\Json;
use Tomaj\Form\Renderer\BootstrapRenderer;

class PaymentFormFactory
{
    const MANUAL_SUBSCRIPTION_START = 'start_at';
    const MANUAL_SUBSCRIPTION_START_END = 'start_end_at';

    private $donationPaymentVat;

    private $paymentsRepository;

    private $paymentGatewaysRepository;

    private $subscriptionTypesRepository;

    private $usersRepository;

    private $addressesRepository;

    private $dataProviderManager;

    private $translator;

    public $onSave;

    public $onUpdate;

    private $onCallback;

    public function __construct(
        $donationPaymentVat,
        PaymentsRepository $paymentsRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        UsersRepository $usersRepository,
        AddressesRepository $addressesRepository,
        DataProviderManager $dataProviderManager,
        ITranslator $translator
    ) {
        $this->donationPaymentVat = $donationPaymentVat;
        $this->paymentsRepository = $paymentsRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->usersRepository = $usersRepository;
        $this->addressesRepository = $addressesRepository;
        $this->dataProviderManager = $dataProviderManager;
        $this->translator = $translator;
    }

    /**
     * @param int $paymentId
     * @param IRow $user
     * @return Form
     * @throws \Crm\ApplicationModule\DataProvider\DataProviderException
     * @throws \Nette\Utils\JsonException
     */
    public function create($paymentId, IRow $user = null)
    {
        $defaults = [
            'additional_type' => 'single',
        ];
        $payment = null;

        if (isset($paymentId)) {
            $payment = $this->paymentsRepository->find($paymentId);
            $defaults = $payment->toArray();
            $items = [];
            foreach ($payment->related('payment_items')->fetchAll() as $item) {
                $items[] = [
                    'amount' => $item->amount,
                    'name' => $item->name,
                    'vat' => $item->vat,
                ];
            }
            $defaults['payment_items'] = Json::encode($items);

            if (isset($defaults['subscription_end_at']) && isset($defaults['subscription_start_at'])) {
                $defaults['manual_subscription'] = self::MANUAL_SUBSCRIPTION_START_END;
            } elseif (isset($defaults['subscription_start_at'])) {
                $defaults['manual_subscription'] = self::MANUAL_SUBSCRIPTION_START;
            }
        }

        $form = new Form;

        $form->setRenderer(new BootstrapRenderer());
        $form->addProtection();
        $form->onSuccess[] = [$this, 'formSucceeded'];

        $form->addGroup('');

        $variableSymbol = $form->addText('variable_symbol', 'Variabilný symbol')
            ->setRequired('Musí byť zadaný variabilný symbol')
            ->setAttribute('placeholder', 'napríklad 87239102385');

        if (!$paymentId) {
            $variableSymbol->setOption('description', Html::el('a', ['href' => '/api/v1/payments/variable-symbol', 'class' => 'variable_symbol_generate'])->setHtml('Vygeneruj'));
        }

        $form->addText('amount', 'Suma')
            ->setRequired('Musí byť zadaná suma')
            ->setAttribute('readonly', 'readonly')
            ->addRule(Form::MIN, 'Suma platby musí byť nenulová, pravdepodobne nebola vybraná žiadna položka platby.', 0.01)
            ->setOption(
                'description',
                Html::el('span', ['class' => 'help-block'])
                    ->setHtml('Pozor: Suma je predvyplnena podla ceny vybraneho typu predplatneho. Ak chcete zadat inu sumu, zaskrnite <strong>vlastnu cenu poloziek</strong> a suma sa automaticky prepocita podla ceny jednotlivych poloziek.')
            );

        // subscription types and items

        $form->addGroup('Položky platby');

        $subscriptionTypes = SubscriptionType::getItems($this->subscriptionTypesRepository->getAllActive());
        $subscriptionTypePairs = SubscriptionType::getPairs($this->subscriptionTypesRepository->getAllActive());

        $form->addHidden('subscription_types', Json::encode($subscriptionTypes));

        $subscriptionType = $form->addSelect(
            'subscription_type_id',
            'Predplatné:',
            $subscriptionTypePairs
        )->setPrompt("Typ predplatného");
        $subscriptionType->getControlPrototype()->addAttributes(['class' => 'select2']);

        if (!$payment) {
            $form->addCheckbox('custom_payment_items', 'Vlastna cena poloziek')->setOption('id', 'custom_payment_items');
        }

        $form->addHidden('payment_items');

        /** @var PaymentFormDataProviderInterface[] $providers */
        $providers = $this->dataProviderManager->getProviders('payments.dataprovider.payment_form', PaymentFormDataProviderInterface::class);
        foreach ($providers as $sorting => $provider) {
            $form = $provider->provide(['form' => $form, 'payment' => $payment]);
        }

        if ($payment) {
            $subscriptionType->setAttribute('readonly', 'readonly');
        } else {
            $form->addText('additional_amount', 'Darovaná suma')
                ->setAttribute('placeholder', 'napríklad 14.52');

            $form->addSelect('additional_type', 'Darovaná suma - typ', ['single' => 'Jednorázova', 'recurrent' => 'Opakovaná'])
                ->setOption('description', 'Ak je vyplnená darovaná suma je potrebné vybrať aj tento typ')
                ->setDisabled(['recurrent']);
        }

        $form->addGroup('Ostatne nastavenia platby');

        $form->addSelect('payment_gateway_id', 'Platba:', $this->paymentGatewaysRepository->all()->fetchPairs('id', 'name'));

        $status = $form->addSelect('status', 'Stav', $this->paymentsRepository->getStatusPairs());

        $paidAt = $form->addText('paid_at', 'Čas zaplatenia')
            ->setAttribute('placeholder', 'napríklad 14.2.2016 14:21');
        $paidAt->setOption('id', 'paid-at');
        $paidAt->addConditionOn($status, Form::EQUAL, PaymentsRepository::STATUS_PAID)
            ->setRequired('Položka je povinná.');

        $paidAt = $form->addCheckbox('send_notification', 'Odoslať notifikáciu');
        $paidAt->setOption('id', 'send-notification');

        $status->addCondition(Form::EQUAL, PaymentsRepository::STATUS_PAID)->toggle('paid-at');
        $status->addCondition(Form::EQUAL, PaymentsRepository::STATUS_PAID)->toggle('send-notification');

        $manualSubscription = $form->addSelect('manual_subscription', 'Začiatok / Koniec predplatného', [
            self::MANUAL_SUBSCRIPTION_START => 'Ručne - nastaviť začiatok predplatného',
            self::MANUAL_SUBSCRIPTION_START_END => 'Ručne - nastaviť začiatok aj koniec predplatného',
        ])->setPrompt('Automaticky - podľa dátumu zaplatenia a dĺžky typu predplatného');

        $manualSubscription->addCondition(Form::EQUAL, self::MANUAL_SUBSCRIPTION_START)->toggle('subscription-start-at');
        $manualSubscription->addCondition(Form::EQUAL, self::MANUAL_SUBSCRIPTION_START_END)->toggle('subscription-start-at');
        $manualSubscription->addCondition(Form::EQUAL, self::MANUAL_SUBSCRIPTION_START_END)->toggle('subscription-end-at');

        $subscriptionStartAt = $form->addText('subscription_start_at', 'Začiatok predplatného')
            ->setAttribute('placeholder', 'napríklad 14.2.2016')
            ->setAttribute('class', 'flatpickr')
            ->setOption('id', 'subscription-start-at')
            ->setOption('description', 'Potrebné vyplniť len v prípade, že potrebujeme posunúť začiatok predplatného na konkrétny dátum v budúcnosti. V prípade, že platba bude potvrdená neskôr ako zadaný dátum, predplatné začne v čase potvrdenia platby.')
            ->setRequired(false)
            ->addRule(function (TextInput $field, $user) {
                if (DateTime::from($field->getValue()) < new DateTime('today midnight')) {
                    return false;
                }
                return true;
            }, 'Dátum začiatku predplatného nesmie byť v minulosti', $user);

        $subscriptionStartAt
            ->addConditionOn($manualSubscription, Form::EQUAL, self::MANUAL_SUBSCRIPTION_START)
            ->setRequired(true);
        $subscriptionStartAt
            ->addConditionOn($manualSubscription, Form::EQUAL, self::MANUAL_SUBSCRIPTION_START_END)
            ->setRequired(true);

        $subscriptionEndAt = $form->addText('subscription_end_at', 'Koniec predplatného')
            ->setAttribute('placeholder', 'napríklad 14.2.2016')
            ->setAttribute('class', 'flatpickr')
            ->setOption('id', 'subscription-end-at')
            ->setOption('description', 'Potrebné vyplniť len v prípade, že potrebujeme určit koniec predplatného na konkrétny dátum v budúcnosti.')
            ->setRequired(false)
            ->addRule(function (TextInput $field, $user) {
                if (DateTime::from($field->getValue()) < new DateTime()) {
                    return false;
                }
                return true;
            }, 'Dátum konca predplatného musí byť v budúcnosti.', $user);

        $subscriptionEndAt
            ->addConditionOn($manualSubscription, Form::EQUAL, self::MANUAL_SUBSCRIPTION_START_END)
            ->setRequired(true);

        // allow change of manual subscription start & end dates only for 'form' payments
        if ($payment && $payment->status !== 'form') {
            $manualSubscription
                ->setAttribute('readonly', 'readonly')
                ->setDisabled();
            $subscriptionStartAt
                ->setAttribute('readonly', 'readonly')
                ->setDisabled();
            $subscriptionEndAt
                ->setAttribute('readonly', 'readonly')
                ->setDisabled();
        }

        $form->addTextArea('note', 'Poznámka')
            ->setAttribute('placeholder', 'Vlastná poznámka k platbe')
            ->getControlPrototype()->addAttributes(['class' => 'autosize']);

        $form->addText('referer', 'Referrer')
            ->setAttribute('placeholder', 'URL odkial prišla platba');

        $addresses = $this->addressesRepository->addressesSelect($user, 'print');
        if (count($addresses) > 0) {
            $form->addSelect('address_id', "Adresa", $addresses)->setPrompt('--');
        }

        $form->addHidden('user_id', $user->id);

        $form->addSubmit('send', 'Ulož')
            ->getControlPrototype()
            ->setName('button')
            ->setHtml('<i class="fa fa-save"></i> Ulož');

        if ($payment) {
            $form->addHidden('payment_id', $payment->id);
        }

        $form->setDefaults($defaults);
        $form->onSuccess[] = [$this, 'callback'];

        return $form;
    }

    public function formSucceeded($form, $values)
    {
        $values = clone($values);
        foreach ($values as $i => $item) {
            if ($item instanceof \Nette\Utils\ArrayHash) {
                unset($values[$i]);
            }
        }

        $subscriptionType = null;
        $subscriptionStartAt = null;
        $subscriptionEndAt = null;
        $sendNotification = $values['send_notification'];

        unset($values['display_order']);
        unset($values['subscription_types']);
        unset($values['send_notification']);

        if ($values['subscription_type_id']) {
            $subscriptionType = $this->subscriptionTypesRepository->find($values['subscription_type_id']);
        }

        if (!isset($values['subscription_start_at']) || $values['subscription_start_at'] == '') {
            $values['subscription_start_at'] = null;
        }
        if (!isset($values['subscription_end_at']) || $values['subscription_end_at'] == '') {
            $values['subscription_end_at'] = null;
        }

        if (!isset($values['additional_amount']) || $values['additional_amount'] == '0' || $values['additional_amount'] == '') {
            $values['additional_amount'] = null;
        }

        if ($values['referer'] == '') {
            $values['referer'] = null;
        }

        if (isset($values['manual_subscription'])) {
            if ($values['manual_subscription'] === self::MANUAL_SUBSCRIPTION_START) {
                if ($values['subscription_start_at'] === null) {
                    throw new \Exception("manual subscription start attempted without providing start date");
                }
                $subscriptionStartAt = DateTime::from($values['subscription_start_at']);
            } elseif ($values['manual_subscription'] === self::MANUAL_SUBSCRIPTION_START_END) {
                if ($values['subscription_start_at'] === null) {
                    throw new \Exception("manual subscription start attempted without providing start date");
                }
                $subscriptionStartAt = DateTime::from($values['subscription_start_at']);
                if ($values['subscription_end_at'] === null) {
                    throw new \Exception("manual subscription end attempted without providing end date");
                }
                $subscriptionEndAt = DateTime::from($values['subscription_end_at']);
            }
        }
        unset($values['subscription_end_at']);
        unset($values['subscription_start_at']);
        unset($values['manual_subscription']);

        $paymentGateway = $this->paymentGatewaysRepository->find($values['payment_gateway_id']);

        if (isset($values['paid_at'])) {
            if ($values['paid_at']) {
                $values['paid_at'] = DateTime::from(strtotime($values['paid_at']));
            } else {
                $values['paid_at'] = null;
            }
        }

        $payment = null;
        if (isset($values['payment_id'])) {
            $payment = $this->paymentsRepository->find($values['payment_id']);
            unset($values['payment_id']);
        }

        $paymentItemContainer = new PaymentItemContainer();
//        dump($values);
//        dump($subscriptionType);

        if ((isset($values['custom_payment_items']) && $values['custom_payment_items'])
            || ($payment && $payment->status === 'form')
        ) {
            foreach (Json::decode($values->payment_items) as $item) {
                if ($item->amount == 0) {
                    continue;
                }
                if ($item->amount < 0) {
                    $form['subscription_type_id']->addError('Cena položiek musí byť nezáporná');
                }
                if ($subscriptionType) {
//                    die('x');
                    $paymentItem = new SubscriptionTypePaymentItem($subscriptionType, 1);
                    $paymentItem->forceName($item->name);
                    $paymentItem->forceVat($item->vat);
                    $paymentItem->forcePrice($item->amount);

                    $paymentItemContainer->addItem($paymentItem);
                }
            }
        } else {
            if ($subscriptionType) {
                $paymentItemContainer->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($subscriptionType));
//                dump($paymentItemContainer);
//                die('j');
            }
//            die('p');
        }

//        die('c');

        // test
        //  * vytvorenie beznej plaltby na subscripotionk
        //  * vytvoreni benzej platby na produkty
        //  X vytvorenie platby na subscription + produckt
        //  - platba s custom itemamy
        //  - editacia platieb s itemami??

        if ($payment !== null) {
            unset($values['payment_items']);

            $currentStatus = $payment->status;

            // edit form doesn't contain donation form fields, set them from payment
            if (!isset($values['additional_amount']) || is_null($values['additional_amount'])) {
                $values['additional_amount'] = $payment->additional_amount;
            }
            if (!isset($values['additional_type']) || is_null($values['additional_type'])) {
                $values['additional_type'] = $payment->additional_type;
            }

            if ($values['additional_amount']) {
                $paymentItemContainer->addItem(new DonationPaymentItem($this->translator->translate('payments.admin.donation'), $values['additional_amount'], $this->donationPaymentVat));
            }

            // we don't want to update subscription dates on payment if it's already paid
            if ($currentStatus === PaymentsRepository::STATUS_FORM) {
                $values['subscription_start_at'] = $subscriptionStartAt;
                $values['subscription_end_at'] = $subscriptionEndAt;
            }

            $this->paymentsRepository->update($payment, $values, $paymentItemContainer);

            if ($currentStatus !== $values['status']) {
                $this->paymentsRepository->updateStatus($payment, $values['status'], $sendNotification);
            }

            $this->onCallback = function () use ($form, $payment) {
                $this->onUpdate->__invoke($form, $payment);
            };
        } else {
            $address = null;
            if (isset($values['address_id']) && $values['address_id']) {
                $address = $this->addressesRepository->find($values['address_id']);
            }
            $variableSymbol = null;
            if ($values->variable_symbol) {
                $variableSymbol = $values->variable_symbol;
            }

            $additionalType = null;
            $additionalAmount = null;
            if (isset($values['additional_amount']) && $values['additional_amount']) {
                $additionalAmount = $values['additional_amount'];
            }
            if (isset($values['additional_type']) && $values['additional_type']) {
                $additionalType = $values['additional_type'];
            }

            if ($additionalAmount) {
                $paymentItemContainer->addItem(new DonationPaymentItem($this->translator->translate('payments.admin.donation'), $additionalAmount, $this->donationPaymentVat));
            }
//            dump($paymentItemContainer); die('pp');

            $user = $this->usersRepository->find($values['user_id']);

            $payment = $this->paymentsRepository->add(
                $subscriptionType,
                $paymentGateway,
                $user,
                $paymentItemContainer,
                $values['referer'],
                null,
                $subscriptionStartAt,
                $subscriptionEndAt,
                $values['note'],
                $additionalAmount,
                $additionalType,
                $variableSymbol,
                $address,
                false
            );

            $updateArray = [];
            if (isset($values['paid_at'])) {
                $updateArray['paid_at'] = $values['paid_at'];
            }
            $this->paymentsRepository->update($payment, $updateArray);

            $this->paymentsRepository->updateStatus($payment, $values['status'], $sendNotification);

            $this->onCallback = function () use ($form, $payment) {
                $this->onSave->__invoke($form, $payment);
            };
        }
    }

    public function callback()
    {
        if ($this->onCallback) {
            $this->onCallback->__invoke();
        }
    }
}
