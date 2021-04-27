<?php

declare(strict_types=1);

namespace ASAppointment\Subscriber;

use ASAppointment\Core\Checkout\Order\AppointmentOrderStates;
use DateInterval;
use DateTime;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityWriteResult;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\Tax\TaxCollection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
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
    /** @var CartService $cartService */
    private $cartService;
    /** @var Connection $connection */
    private $connection;

    public function __construct(
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        OrderService $orderService,
        CartService $cartService,
        Connection $connection
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->requestStack = $requestStack;
        $this->orderService = $orderService;
        $this->cartService = $cartService;
        $this->connection = $connection;
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
            'order_line_item.written' => 'onOrderLineItemWrittenEvent'
        ];
    }
    public function onOrderLineItemWrittenEvent(EntityWrittenEvent $event): void
    {
        $orderID = null;
        $appointmentData = null;
        /** @var EntityRepositoryInterface $orderLineItemRepository */
        $orderLineItemRepository = $this->container->get('order_line_item.repository');

        $request = $this->requestStack->getCurrentRequest();
        $content = $request->getContent();
        if ($content == '{}')
            return;
        if ($content == "")
            return;
        $contentExploded = explode('&', $content);
        $appointmentData = $this->extractAppointmentData($contentExploded);
        if ($appointmentData == null)
            return;
        /** @var Context $context */
        $context = $event->getContext();

        $writeResults = $event->getWriteResults();

        /** @var EntityWriteResult $result */
        foreach ($writeResults as $result) {
            if (array_key_exists('identifier', $result->getPayload())) {
                $lineItemID = $result->getPayload()['identifier'];
            } else {
                continue;
            }
            if (array_key_exists($lineItemID, $appointmentData)) {
                $appointmentDate = $appointmentData[$lineItemID];
            } else {
                continue;
            }
            /** @var EntityRepositoryInterface $productRepository */
            $productRepository = $this->container->get('product.repository');
            /** @var ProductEntity $productEntity */
            $productEntity = $this->getFilteredEntitiesOfRepository($productRepository, 'id', $result->getPayload()['referencedId'], $context)->first();
            $prevProductCloseout = $productEntity->getIsCloseout();
            $productRepository->update([['id' => $productEntity->getId(), 'isCloseout' => false]], Context::createDefaultContext());
            /** @var string $orderID */
            $orderID = $result->getPayload()['orderId'];
            /** @var OrderLineItemEntity $orderLineItem */
            $orderLineItem = $this->getFilteredEntitiesOfRepository($orderLineItemRepository, 'id', $result->getPrimaryKey(), $context)->first();
            //delete line item of this order
            $orderLineItemRepository->delete([['id' => $result->getPrimaryKey()]], $context);
            //create new order
            $newOrderID = $this->generateOrder(
                $productEntity->getProductNumber(),
                $this->getCustomerID($orderID, $context),
                $orderLineItem->getQuantity()
            );
            $productRepository->update([['id' => $productEntity->getId(), 'isCloseout' => $prevProductCloseout]], Context::createDefaultContext());
            $this->orderService->orderStateTransition(
                $newOrderID,
                'setAppointed',
                new ParameterBag(),
                $context
            );

            $dateTimeOfAppointment = new DateTime($appointmentDate); //$this->getAppointmentOrderDateTime($appointmentDate);
            $this->container->get('order.repository')->update([['id' => $newOrderID, 'orderDateTime' => $dateTimeOfAppointment]], $context);
        }
    }

    private function createSalesChannelContext(CustomerEntity $customerEntity, $context)
    {
        $salesChannelId = $customerEntity->getSalesChannelId();
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->getFilteredEntitiesOfRepository($this->container->get('sales_channel.repository'), 'id', $salesChannelId, $context)->first();

        $currency = $this->getAllEntitiesOfRepository($this->container->get('currency.repository'), $context)->first();
        $currentCustomerGroup = $this->getFilteredEntitiesOfRepository($this->container->get('customer_group.repository'), 'id', $customerEntity->getGroupId(), $context)->first();
        /** @var TaxCollection $taxes */
        $taxes = $this->getAllEntitiesOfRepository($this->container->get('tax.repository'), $context)->getEntities();
        foreach ($taxes as $tax) {
            $tax->setRules($this->getFilteredEntitiesOfRepository($this->container->get('tax_rule.repository'), 'taxId', $tax->getId(), $context)->getEntities());
        }

        $paymentMethod = $this->getFilteredEntitiesOfRepository($this->container->get('payment_method.repository'), 'id', $customerEntity->getDefaultPaymentMethodId(), $context)->first();
        $customerEntity->setLastPaymentMethod($paymentMethod);

        $shippingMethod = $this->getAllEntitiesOfRepository($this->container->get('shipping_method.repository'), $context)->first();

        /** @var CustomerAddressEntity $customerAddress */
        $customerAddress = $this->getFilteredEntitiesOfRepository($this->container->get('customer_address.repository'), 'customerId', $customerEntity->getId(), $context)->first();
        $customerEntity->setDefaultBillingAddress($customerAddress);
        $country = $this->getFilteredEntitiesOfRepository($this->container->get('country.repository'), 'id', $customerAddress->getCountryId(), $context)->first();
        $customerAddress->setCountry($country);
        $shippingLocation = ShippingLocation::createFromAddress($customerAddress);

        return $salesChannelContext = new SalesChannelContext(
            $context,
            '',
            $salesChannel,
            $currency,
            $currentCustomerGroup,
            $currentCustomerGroup,
            $taxes,
            $paymentMethod,
            $shippingMethod,
            $shippingLocation,
            $customerEntity,
            []
        );
    }

    private function extractAppointmentData($content)
    {
        array_shift($content);
        array_pop($content);
        $appointmentData = null;

        foreach ($content as $line) {
            $lineExploded = explode('-', $line);
            if (count($lineExploded) < 4)
                continue;
            $month = $lineExploded[2];
            $day = $lineExploded[3];
            $lineExploded = explode('=', $lineExploded[1]);
            $year = $lineExploded[1];

            $appointmentData[$lineExploded[0]] = $day . '-' . $month . '-' . $year;
        }

        return $appointmentData;
    }

    private function generateOrder(string $productNumber, string $customerID, $quantity): string
    {
        $myCart = new Cart('appointmentOrder', 'appointment');
        /** @var CustomerEntity $customerEntity */
        $customerEntity = $this->getFilteredEntitiesOfRepository(
            $this->container->get('customer.repository'),
            'id',
            $customerID,
            Context::createDefaultContext()
        )->first();

        /** @var ProductEntity $productEntity */
        $productEntity = $this->getFilteredEntitiesOfRepository(
            $this->container->get('product.repository'),
            'productNumber',
            $productNumber,
            Context::createDefaultContext()
        )->first();

        $lineItem = new LineItem(Uuid::randomHex(), "product", NULL, 1);
        $lineItem->setGood(false);
        $lineItem->setStackable(true);
        $lineItem->setRemovable(true);
        $lineItem->setQuantity($quantity);
        $lineItem->setReferencedId($productEntity->getId());

        $salesChannelContext = $this->createSalesChannelContext($customerEntity, Context::createDefaultContext());

        $myCart = $this->cartService->add($myCart, $lineItem, $salesChannelContext);
        $this->cartService->setCart($myCart);

        return $this->cartService->order($myCart, $salesChannelContext, null);
        // $customerName = $customerEntity->getFirstName() . ' ' . $customerEntity->getLastName();
        // $customerMail = $customerEntity->getEmail();
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
        $result = $repository->search($criteria, $context);

        return $result;
    }
    public function getFilteredEntitiesOfRepository(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): ?EntitySearchResult
    {
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria, $context);

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
