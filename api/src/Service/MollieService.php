<?php


namespace App\Service;


use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\Service;
use Doctrine\ORM\EntityManagerInterface;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Symfony\Component\HttpFoundation\Request;

class MollieService
{
    private $mollie;
    private $serviceId;

    public function __construct(Service $service)
    {
        $this->mollie = new MollieApiClient();
        $this->serviceId = $service->getId();
        try {
            $this->mollie->setApiKey($service->getAuthorization());
        }
        catch(ApiException $e){
            echo "<section><h2>Error: could not authenticate with Mollie API</h2><pre>". $e->getMessage()."</pre></section>";
        }
    }
    public function createPayment(Invoice $invoice, Request $request):string{
        //var_dump($request);
        $domain = $request->getHttpHost();
        if($request->isSecure())
            $protocol = "https://";
        else
            $protocol = "http://";

        if($invoice->getPrice() > 0.00) {
            $currency = $invoice->getPriceCurrency();
            $amount = "" . $invoice->getPrice();
            $description = $invoice->getDescription();
            $redirectUrl = $invoice->getOrganization()->getRedirectUrl() . '/' . $invoice->getId();
            $webhookUrl = "$protocol$domain/payments/mollie_webhook?provider=$this->serviceId";
            try {
                $molliePayment = $this->mollie->payments->create([
                    "amount" => [
                        "currency" => $currency,
                        "value" => $amount
                    ],
                    "description" => $description,
                    "redirectUrl" => $redirectUrl,
                    "webhookUrl" => $webhookUrl,
                    "metadata" => [
                        "order_id" => $invoice->getReference(),
                    ],
                ]);
                //var_dump($molliePayment->id);
                return $molliePayment->getCheckoutUrl();
            } catch (ApiException $e) {
                return "<section><h2>Could not connect to payment provider</h2>" . $e->getMessage() . "</section>";

            }
        }
        return $invoice->getOrganization()->getRedirectUrl() . '/' . $invoice->getId();
    }

    public function updatePayment(string $paymentId, Service $service, EntityManagerInterface $manager):?Payment
    {
        $molliePayment = $this->mollie->payments->get($paymentId);
        $payment = $manager->getRepository('App:Payment')->findOneBy(['paymentId'=> $paymentId]);
        if($payment instanceof Payment) {
            $payment->setStatus($molliePayment->status);
            return $payment;
        }
        else{
            $invoiceReference = $molliePayment->metadata->order_id;
            //var_dump($invoiceReference);
            $invoice = $manager->getRepository('App:Invoice')->findBy(['reference'=>$invoiceReference]);
            //var_dump(count($invoice));
            if(is_array($invoice))
                $invoice = end($invoice);
            if($invoice instanceof Invoice)
            {
                $payment = new Payment();
                $payment->setPaymentId($molliePayment->id);
                $payment->setPaymentProvider($service);
                $payment->setStatus($molliePayment->status);
                $payment->setInvoice($invoice);
                $manager->persist($payment);
                $manager->flush();

                return $payment;
            }
        }
        return null;
    }
}