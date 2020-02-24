<?php


namespace App\Subscriber;


use ApiPlatform\Core\EventListener\EventPriorities;
use App\Entity\Invoice;
use App\Entity\Organization;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\SerializerInterface;

class InvoiceSubscriber implements EventSubscriber
{
    private $params;
    private $em;
    private $serializer;
    private $nlxLogService;

    public function __construct(ParameterBagInterface $params, EntityManagerInterface $em, SerializerInterface $serializer)
    {
        $this->params = $params;
        $this->em = $em;
        $this->serializer = $serializer;
    }

    public function getSubscribedEvents()
    {
        return [
            Events::prePersist,
        ];
    }

    public function prePersist(LifecycleEventArgs $args)
    {
        $this->index($args);
    }

        $order =  json_decode($event->getRequest()->getContent(), true);

        $contentType = $event->getRequest()->headers->get('accept');
        if (!$contentType) {
            $contentType = $event->getRequest()->headers->get('Accept');
        }
        
        switch ($contentType) {
            case 'application/json':
                $renderType = 'json';
                break;
            case 'application/ld+json':
                $renderType = 'jsonld';
                break;
            case 'application/hal+json':
                $renderType = 'jsonhal';
                break;
            default:
                $contentType = 'application/json';
                $renderType = 'json';
        }

        if ($method != 'POST' && ($route != 'api_invoices_post_order_collection' || $order == null))
        {
            //var_dump('a');
            return $entity;
        }
        //var_dump('b');
        if(!$entity->getReference()){
            $organisation = $entity->getOrganization();

            if(!$organisation || !($organisation instanceof Organization)){
                $organisation = $this->em->getRepository('App\Entity\Organization')->findOrCreateByRsin($entity->getTargetOrganization());
                $this->em->persist($organisation);
                $this->em->flush();
                $entity->addOrganization($organisation);
            }

            $referenceId = $this->em->getRepository('App\Entity\Invoice')->getNextReferenceId($organisation);
            $entity->setReferenceId($referenceId);
            $entity->setReference($organisation->getShortCode().'-'.date('Y').'-'.$referenceId);
        }

        if(key_exists('items',$order))
        {
        	foreach($order['items'] as $item){
        		
                $invoiceItem = new InvoiceItem();
                $invoiceItem->setName($item['name']);
                $invoiceItem->setDescription($item['description']);
                $invoiceItem->setPrice($item['price']);
                $invoiceItem->setPriceCurrency($item['priceCurrency']);
                $invoiceItem->setOffer($item['offer']);
                $invoiceItem->setQuantity($item['quantity']);
                $invoice->addItem($invoiceItem);
                
                foreach($item['taxes'] as $taxPost){                	
                	$tax = new Tax();
                	$tax->setName($taxPost['name']);
                	$tax->setDescription($taxPost['description']);
                	$tax->setPrice($taxPost['price']);
                	$tax->setPriceCurrency($taxPost['priceCurrency']);
                	$tax->setPercentage($taxPost['percentage']);
                	$invoiceItem->addTax($tax);
                }
            }
        }
        
        // Lets throw it in the db
        $this->em->persist($invoice);
        $this->em->flush();

        // Only create payment links if a payment service is configured
        if(count($invoice->getOrganization()->getServices()) >0 )
        {
            //var_dump(count($invoice->getOrganization()->getServices()));
            $paymentService = $invoice->getOrganization()->getServices()[0];
            switch ($paymentService->getType()) {
                case 'mollie':
                    $mollieService = new MollieService($paymentService);
                    $paymentUrl = $mollieService->createPayment($invoice, $event->getRequest());
                    $invoice->setPaymentUrl($paymentUrl);
                    break;
                case 'sumup':
                    $sumupService = new SumUpService($paymentService);
                    $paymentUrl = $sumupService->createPayment($invoice);
                    $invoice->setPaymentUrl($paymentUrl);
            }
        }

        $json = $this->serializer->serialize(
            $invoice,
        	$renderType, ['enable_max_depth'=>true]
        );

		// Creating a response
        $response = new Response(
            $json,
            Response::HTTP_OK,
            ['content-type' => 'application/json+hal']
        );
        $event->setResponse($response);


        return $invoice;
    }
}
