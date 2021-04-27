<?php

declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use Symfony\Component\HttpFoundation\ParameterBag;
use ASMailService\Core\MailServiceHelper;
use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderCustomer\OrderCustomerEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;

class AppointmentOrderTaskHandler extends ScheduledTaskHandler
{
    /** @var MailServiceHelper $mailService */
    private $mailService;
    /** @var ContainerInterface $container */
    protected $container;
    /** @var CartService $cartService */
    protected $cartService;
    /** @var OrderService $orderService */
    private $orderService;

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        MailServiceHelper $mailService,
        OrderService $orderService
    ) {
        $this->mailService = $mailService;
        $this->orderService = $orderService;
        parent::__construct($scheduledTaskRepository);
    }

    public static function getHandledMessages(): iterable
    {
        return [AppointmentOrderTask::class];
    }

    /** @internal @required */
    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        $previous = $this->container;
        $this->container = $container;

        return $previous;
    }

    public function run(): void
    {
        $context = Context::createDefaultContext();
        /** @var StateMachineStateEntity $appointedState */
        $appointedState = $this->getFilteredEntitiesOfRepository(
            $this->container->get('state_machine_state.repository'),
            'technicalName',
            'appointed',
            $context
        )->first();
        /** @var EntitySearchResult $appointedOrders */
        $appointedOrders = $this->getFilteredEntitiesOfRepository(
            $this->container->get('order.repository'),
            'stateId',
            $appointedState->getId(),
            Context::createDefaultContext()
        );
        if (count($appointedOrders) == 0)
            return;
        /** @var OrderEntity $appointedOrder */
        foreach ($appointedOrders as $orderID => $orderEntity) {
            /** @var DateTimeImmutable $orderDateTime */
            $orderDateTime = $orderEntity->getOrderDateTime();
            /** @var OrderLineItemEntity $lineItem */
            $lineItem = $this->getFilteredEntitiesOfRepository(
                $this->container->get('order_line_item.repository'),
                'orderId',
                $orderID,
                $context
            )->first();
            /** @var ProductEntity $productEntity */
            $productEntity = $this->getFilteredEntitiesOfRepository(
                $this->container->get('product.repository'),
                'id',
                $lineItem->getProductId(),
                $context
            )->first();

            /** @var DeliveryTimeEntity $deliveryTimeEntity */
            $deliveryTimeEntity = $this->getFilteredEntitiesOfRepository(
                $this->container->get('delivery_time.repository'),
                'id',
                $productEntity->getDeliveryTimeId(),
                $context
            )->first();

            if ($this->isDue($orderDateTime, $deliveryTimeEntity)) {
                $this->orderService->orderStateTransition(
                    $orderID,
                    'openAppointment',
                    new ParameterBag(),
                    $context
                );
                $this->sendNotification($orderEntity, $orderDateTime->format('d-m-Y'));
            }
        }
    }

    private function isDue($appointmentDate, DeliveryTimeEntity $deliveryTime): bool
    {
        $deliveryTimeMax = $deliveryTime->getMax();
        $currentMinimumArrivalDate = date('Y-m-d', strtotime(date('Y-m-d') . " + {$deliveryTimeMax} days"));
        $currentMinimumArrivalDate = new DateTimeImmutable($currentMinimumArrivalDate);
        if ($appointmentDate <= $currentMinimumArrivalDate)
            return true;
        return false;
    }

    private function sendNotification(OrderEntity $order, string $appointmentDate)
    {
        $context = Context::createDefaultContext();
        $customerOrderNumber = $order->getOrderNumber();
        // get customer
        /** @var OrderCustomerEntity $orderCustomer */
        $orderCustomer = $this->getFilteredEntitiesOfRepository($this->container->get('order_customer.repository'), 'orderId', $order->getId(), $context)->first();
        /** @var CustomerEntity $customer */
        $customer = $this->getFilteredEntitiesOfRepository($this->container->get('customer.repository'), 'id', $orderCustomer->getCustomerId(), $context)->first();
        $customerMail = $customer->getEmail();
        $customerName = $customer->getFirstName() . ' ' . $customer->getLastName();
        $notification = "Hallo {$customerName},<br><br> Ihre Bestellung mit der Nummer {$customerOrderNumber} wird nun bearbeitet und sollte spätestens bis zum {$appointmentDate} bei Ihnen ankommen.<br><br>Mit freundlichen Grüßen<br>Webshop Support Team";
        $this->mailService->sendMyMail(
            [$customerMail => $customerName],
            null,
            'ZPMV Terminbestellungen',
            'Ihre Terminbestellung wird nun bearbeitet',
            $notification,
            $notification,
            ['']
        );
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
}
