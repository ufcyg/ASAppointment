<?php declare(strict_types=1);

namespace ASAppointment\Subscriber;

use DateInterval;
use DateTime;
use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
        $deleteData = null;
        $appointmentData = null;
        /** @var EntityRepositoryInterface $orderLineItemRepository */
        $orderLineItemRepository = $this->container->get('order_line_item.repository');
        /** @var EntityRepositoryInterface $appointmentRepository */
        $appointmentRepository = $this->container->get('as_appointment_line_item.repository');

        $request = $this->requestStack->getCurrentRequest();
        $content = $request->getContent();
        if($content == '{}')
            return;
        if($content == "")
            return;
        $contentExploded = explode('&',$content);
        $appointmentData = $this->extractAppointmentData($contentExploded);
        /** @var Context $context */
        $context = $event->getContext();
        
        $writeResults = $event->getWriteResults();

        /** @var EntityWriteResult $result */
        foreach($writeResults as $result)
        {
            if(array_key_exists('identifier',$result->getPayload())){
                $lineItemID = $result->getPayload()['identifier'];
            }
            else{
                continue;
            }
            if(array_key_exists($lineItemID,$appointmentData)){
                $appointmentDate = $appointmentData[$lineItemID];
            }
            else{
                continue;
            }

            /** @var ProductEntity $productEntity */
            $productEntity = $this->getFilteredEntitiesOfRepository($this->container->get('product.repository'), 'id', $result->getPayload()['referencedId'], $context)->first();
            
            /** @var string $orderID */
            $orderID = $result->getPayload()['orderId'];
            $customerId = $this->getCustomerID($orderID, $context);

            $appointmentRepositoryData[] = [
                'productNumber' => $productEntity->getProductNumber(),
                'appointmentDate' => $appointmentDate,
                'customerId' => $customerId,
                'amount' => $result->getPayload()['quantity']
            ];
            $deleteData[] = [ 'id' => $result->getPrimaryKey()];
        }    
        if($appointmentRepositoryData != null)
            $appointmentRepository->create($appointmentRepositoryData,$context);
        if($deleteData!=null)
            $orderLineItemRepository->delete($deleteData, $context);



        // update stock and available after deletion of line items
        $updateData = null;
        $productRepository = $this->container->get('product.repository');
        foreach($productRepository->search(new Criteria(), $context) as $productID => $product)
        {
            $updateData[] = ['id' => $productID];
        }
        $productRepository->upsert($updateData, $context);
    }

    private function extractAppointmentData($content)
    {
        array_shift($content);
        array_pop($content);
        $appointmentData = null;

        foreach($content as $line)
        {
            $lineExploded = explode('-', $line);
            if(count($lineExploded)<4)
                continue;
            $month = $lineExploded[2];
            $day = $lineExploded[3];
            $lineExploded = explode('=',$lineExploded[1]);
            $year = $lineExploded[1];

            $appointmentData[$lineExploded[0]] = $day . '-' . $month . '-' . $year;
        }

        return $appointmentData;
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

    private function getCustomerID($orderID, $context)
    {
        /** @var OrderCustomerEntity $orderCustomerEntity */
        $orderCustomerEntity = $this->getFilteredEntitiesOfRepository($this->container->get('order_customer.repository'), 'orderId', $orderID, $context)->first();
        return $orderCustomerEntity->getCustomerId();
    }

    public function getAllEntitiesOfRepository(EntityRepositoryInterface $repository, Context $context): ?EntitySearchResult
    {   
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria,$context);

        return $result;
    }

    public function getFilteredEntitiesOfRepository(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): ?EntitySearchResult
    {   
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria,$context);

        return $result;
    }

    public function entityExistsInRepositoryCk(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): bool
    {
        $criteria = new Criteria();

        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));

        /** @var EntitySearchResult $searchResult */
        $searchResult = $repository->search($criteria, $context);
        
        return count($searchResult) != 0 ? true : false;
    }

    /* Creates a timeStamp that will be attached to the end of the filename */
    public function createShortDateFromString(string $daytime): string
    {
        $timeStamp = new DateTime();
        $timeStamp->add(DateInterval::createFromDateString($daytime));
        $timeStamp = $timeStamp->format('Y-m-d_H-i-s_u');
        $timeStamp = substr($timeStamp, 0, strlen($timeStamp) - 3);

        return $timeStamp;
    }
}