<?php declare(strict_types=1);

namespace ASAppointment\Subscriber;

use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
/**
 * 
 * 
 * 
 */
class CartEventSubscriber implements EventSubscriberInterface
{
    /** @var SystemConfigService $systemConfigService */
    private $systemConfigService;
    /** @var ContainerInterface $container */
    protected $container;
    /** @var RequestStack $requestStack */
    private $requestStack;
    /** @var OrderService $orderService */
    private $orderService;

    public function __construct(SystemConfigService $systemConfigService,
                                RequestStack $requestStack,
                                OrderService $orderService)
    {
        $this->systemConfigService = $systemConfigService;
        $this->requestStack = $requestStack;
        $this->orderService = $orderService;
    }
    /** @internal @required */
    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        $previous = $this->container;
        $this->container = $container;

        return $previous;
    }
    public static function getSubscribedEvents(): array
    {
        // Return the events to listen to as array like this:  <event to listen to> => <method to execute>
        return [
            CartConvertedEvent::class => 'onCartConvertedEvent',
            'order_line_item.written' => 'onOrderLineItemWrittenEvent'
        ];
    }
    public function onOrderLineItemWrittenEvent(EntityWrittenEvent $event): void
    {
        
        /** @var EntityWriteResult $eventArray */
        $eventArray = $event->getWriteResults()[0];

        /** @var EntityRepositoryInterface $orderLineItemRepository */
        $orderLineItemRepository = $this->container->get('order_line_item.repository');
        // $criteria = new Criteria();
        // $criteria->addFilter(new EqualsFilter('id',$eventArray->getPrimaryKey()));
        // /** @var EntitySearchResult $searchResult */
        // $searchResult = $orderLineItemRepository->search($criteria,$event->getContext());
        if(array_key_exists('identifier',$eventArray->getPayload())){
            $lineItemID = $eventArray->getPayload()['identifier'];
        }
        else{
            return;
        }
        $request = $this->requestStack->getCurrentRequest();
        $content = $request->getContent();
        if($content == '{}')
            return;
        $contentExploded = explode('&',$content);

        foreach ($contentExploded as $contentExplodedItem) {
            $appointmentID = '';
            $appointmentDate = '';
            $contentExplodedItemExploded = explode('-', $contentExplodedItem);
            
            if ($contentExplodedItemExploded[0] === 'appointmentDate') {
                $appointmentID = explode('=', $contentExplodedItemExploded[1]);
                if ($appointmentID[1] == '') { // lineitem without date
                    continue;
                } 
                $appointmentID = $appointmentID[0];
                if($appointmentID == '')
                    continue;
                if($appointmentID === $lineItemID)
                {
                    $appointmentDate = explode('=', $contentExplodedItemExploded[1]);
                    $appointmentDate = $appointmentDate[1] . '-' . $contentExplodedItemExploded[2] . '-' . $contentExplodedItemExploded[3];
                    // // save appointment date to payload or w/e
                    // $orderId = $this->orderService->createOrder($customerId, $paymentMethodId, $this->context);
                    $orderLineItemRepository->update([['id' => $eventArray->getPrimaryKey(),
                    'customFields' => ['appointment_shipment_date' => $appointmentDate] ]],
                    $event->getContext());
                }
                
            }
        }        
    }

    public function onCartConvertedEvent(CartConvertedEvent $event): void
    {
        // $cart = $event->getCart();
        // /** @var LineItemCollection $lineItems */
        // $lineItems = $cart->getLineItems();
        // /** @var Request $request */
        // $request = $this->requestStack->getCurrentRequest();
        // $content = $request->getContent();
        // $contentExploded = explode('&',$content);

        // foreach($contentExploded as $contentExplodedItem) {
        //     $contentExplodedItemExploded = explode('-',$contentExplodedItem);
            
        //     if($contentExplodedItemExploded[0] === 'appointmentDate'){
        //         $appointmentID = explode('=',$contentExplodedItemExploded[1]);
        //         if($appointmentID[1] == '') { // lineitem without date
        //             continue;
        //         }
        //         else{
        //             $lineItems->remove($appointmentID[0]);
        //         }
        //         $appointmentID = $appointmentID[0];
        //         $appointmentDate = explode('=',$contentExplodedItemExploded[1]);
                // $appointmentDate = $appointmentDate[1] . '-' . $contentExplodedItemExploded[2] . '-' . $contentExplodedItemExploded[3];
        //         /** @var LineItem $lineItem */
        //         foreach($lineItems as $lineItem) {
        //             $lineItemID = $lineItem->getId();
                    
        //             if($lineItemID === $appointmentID){

        //                 $lineItem->setPayloadValue('customFields',['appointment_shipment_date' => $appointmentDate]);
        //                 break;
        //             }
        //         }
        //     }
        // }


        // $cart = $event->getCart();
        // /** @var LineItemCollection $lineItems */
        // $lineItems = $cart->getLineItems();
    }
}