<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
$client = PaypalServerSdkClientBuilder::init()
  ->clientCredentialsAuthCredentials(ClientCredentialsAuthCredentialsBuilder::init(getenv('PAYPAL_CLIENT_ID'), getenv('PAYPAL_CLIENT_SECRET')))
  ->environment(Environment::SANDBOX)->build();
$orders = $client->getOrdersController();
$payments = $client->getPaymentsController();