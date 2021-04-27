<?php

declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;

class DeleteEmptyOrdersTaskHandler extends ScheduledTaskHandler
{
    /** @var ContainerInterface $container */
    protected $container;

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository
    ) {
        parent::__construct($scheduledTaskRepository);
    }

    public static function getHandledMessages(): iterable
    {
        return [DeleteEmptyOrdersTask::class];
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
        foreach ($this->container->get('order.repository')->search(new Criteria(), $context) as $orderID => $order) {
            $this->deleteOrderIfEmpty($orderID, $context);
        }
    }
    private function deleteOrderIfEmpty(string $orderID, Context $context)
    {
        $searchResult = $this->getFilteredEntitiesOfRepository($this->container->get('order_line_item.repository'), 'orderId', $orderID, $context);
        if (count($searchResult) == 0)
            $this->container->get('order.repository')->delete([['id' => $orderID]], $context);
        if (count($searchResult) == 1) {
            /** @var OrderLineItemEntity $lineItem */
            $lineItem = $searchResult->first();
            if ($lineItem->getIdentifier() == 'INTERNAL_DISCOUNT')
                $this->container->get('order.repository')->delete([['id' => $orderID]], $context);
        }
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
