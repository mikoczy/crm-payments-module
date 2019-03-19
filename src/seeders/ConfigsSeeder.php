<?php

namespace Crm\PaymentsModule\Seeders;

use Crm\ApplicationModule\Builder\ConfigBuilder;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Config\Repository\ConfigCategoriesRepository;
use Crm\ApplicationModule\Config\Repository\ConfigsRepository;
use Crm\ApplicationModule\Seeders\ISeeder;
use Symfony\Component\Console\Output\OutputInterface;

class ConfigsSeeder implements ISeeder
{
    private $configCategoriesRepository;

    private $configsRepository;

    private $configBuilder;

    public function __construct(
        ConfigCategoriesRepository $configCategoriesRepository,
        ConfigsRepository $configsRepository,
        ConfigBuilder $configBuilder
    ) {
        $this->configCategoriesRepository = $configCategoriesRepository;
        $this->configsRepository = $configsRepository;
        $this->configBuilder = $configBuilder;
    }

    public function seed(OutputInterface $output)
    {
        $category = $this->configCategoriesRepository->loadByName('Platby');
        if (!$category) {
            $category = $this->configCategoriesRepository->add('Platby', 'fa fa-credit-card', 300);
            $output->writeln('  <comment>* config category <info>Platby</info> created</comment>');
        } else {
            $output->writeln('  * config category <info>Platby</info> exists');
        }

        $this->addPaymentConfig(
            $output,
            $category,
            'donation_vat_rate',
            'Donation vat rate',
            null,
            800
        );

        $sorting = 1000;

        $this->addPaymentConfig($output, $category, 'tatrapay_mid', 'Tatrapay mid', 'aoj', $sorting++);
        $this->addPaymentConfig(
            $output,
            $category,
            'tatrapay_sharedsecret',
            'Tatrapay sharedsecret',
            '***REMOVED***',
            $sorting++
        );

        $this->addPaymentConfig($output, $category, 'cardpay_mid', 'Cardpay mid', '1joa', $sorting++);
        $this->addPaymentConfig(
            $output,
            $category,
            'cardpay_sharedsecret',
            'Cardpay sharedsecret',
            '***REMOVED***',
            $sorting++
        );

        $this->addPaymentConfig($output, $category, 'comfortpay_mid', 'Comforpay mid', '5120', $sorting++);
        $this->addPaymentConfig($output, $category, 'comfortpay_ws', 'Comforpay ws', '668862', $sorting++);
        $this->addPaymentConfig(
            $output,
            $category,
            'comfortpay_terminalid',
            'Comfortpay terminalid',
            '***REMOVED***',
            $sorting++
        );
        $this->addPaymentConfig(
            $output,
            $category,
            'comfortpay_sharedsecret',
            'Comfortpay sharedsecret',
            '***REMOVED***',
            $sorting++
        );
        $this->addPaymentConfig(
            $output,
            $category,
            'comfortpay_local_cert_path',
            'Comfortpay local cert path',
            '123',
            $sorting++,
            'Path to cert'
        );
        $this->addPaymentConfig(
            $output,
            $category,
            'comfortpay_local_passphrase_path',
            'Comfortpay local passphrase path',
            '123',
            $sorting++,
            'Cert pass path'
        );
        $this->addPaymentConfig(
            $output,
            $category,
            'comfortpay_tem',
            'Comfortpay tem',
            'info@info.sk',
            $sorting++,
            'Comfortpay seding info about registering cards'
        );
        $this->addPaymentConfig(
            $output,
            $category,
            'comfortpay_rem',
            'Comfortpay rem',
            'info@info.sk',
            $sorting++,
            'Comfortpay'
        );

        $this->addPaymentConfig($output, $category, 'paypal_mode', 'Paypal mode', 'live', $sorting++);
        $this->addPaymentConfig(
            $output,
            $category,
            'paypal_username',
            'Paypal username',
            'paypal_api1.projektn.sk',
            $sorting++
        );
        $this->addPaymentConfig(
            $output,
            $category,
            'paypal_password',
            'Paypal password',
            '***REMOVED***',
            $sorting++
        );
        $this->addPaymentConfig(
            $output,
            $category,
            'paypal_signature',
            'Paypal signature',
            '***REMOVED***',
            $sorting++
        );
        $this->addPaymentConfig($output, $category, 'paypal_merchant', 'Paypal merchant', '***REMOVED***', $sorting);

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_merchant_id',
            'ČSOB Merchant ID',
            '',
            $sorting++,
            'Merchant ID provided by bank or generated via https://iplatebnibrana.csob.cz/keygen/ (for development purpose)'
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_shop_name',
            'ČSOB Shop name',
            '',
            $sorting++,
            "Shop name displayed in the payment description (if it's autogenerated)"
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_bank_public_key_file_path',
            'ČSOB Public key of bank for verification of bank responses',
            '',
            $sorting++,
            'Path to public key of bank available at https://github.com/csob/paymentgateway/tree/master/keys (different keys for sandbox and production)'
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_private_key_file_path',
            'ČSOB Private key of merchant',
            '',
            $sorting++,
            'Path to private key provided by bank or generated via https://iplatebnibrana.csob.cz/keygen/ (for development purpose)'
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_mode',
            'ČSOB gateway mode',
            '',
            $sorting++,
            'Switch for "test" (sandbox) mode or "live" (production) mode'
        );

        // recurrent payments

        $this->addPaymentConfig(
            $output,
            $category,
            'recurrent_payment_gateway_fail_delay',
            'Pauza po neúspešnom spojení',
            'PT1H',
            $sorting++,
            'Definícia intervalu (https://en.wikipedia.org/wiki/ISO_8601#Durations) po chybe v komunikácii.'
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'recurrent_payment_charges',
            'Opakovanie rekurentnych platieb',
            'PT15M, PT6H, PT6H, PT6H, PT6H',
            $sorting++,
            'Definicia intervalov (https://en.wikipedia.org/wiki/ISO_8601#Durations) oddelenych ciarkou.'
        );

        // csob

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_merchant_id',
            'CSOB merchant ID',
            'M1MIPS4264',
            $sorting++,
            null
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_shop_name',
            'CSOB Shop name',
            'CRM (devel)',
            $sorting++,
            null
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_bank_public_key_file_path',
            'CSOB public key path',
            '/var/www/html/csob_bank.pub',
            $sorting++,
            null
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_private_key_file_path',
            'CSOB private key path',
            '/var/www/html/rsa_M1MIPS4264.key',
            $sorting++,
            null
        );

        $this->addPaymentConfig(
            $output,
            $category,
            'csob_mode',
            'CSOB mode',
            'test',
            $sorting++,
            null
        );
    }

    private function addPaymentConfig(OutputInterface $output, $category, $name, $displayName, $value, $sorting, $description = null)
    {
        $config = $this->configsRepository->loadByName($name);
        if (!$config) {
            $this->configBuilder->createNew()
                ->setName($name)
                ->setDisplayName($displayName)
                ->setDescription($description)
                ->setValue($value)
                ->setType(ApplicationConfig::TYPE_STRING)
                ->setAutoload(true)
                ->setConfigCategory($category)
                ->setSorting($sorting)
                ->save();
            $output->writeln("  <comment>* config item <info>$name</info> created</comment>");
        } elseif ($config->has_default_value && $config->value !== $value) {
            $this->configsRepository->update($config, ['value' => $value, 'has_default_value' => true]);
            $output->writeln("  <comment>* config item <info>$name</info> updated</comment>");
        } else {
            $output->writeln("  * config item <info>$name</info> exists");
        }
    }
}
