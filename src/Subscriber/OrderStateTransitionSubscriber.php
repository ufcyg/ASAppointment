<?php

declare(strict_types=1);

namespace ASAppointment\Subscriber;

use ASAppointment\Core\Checkout\Order\AppointmentOrderStates;
use ASAppointment\ScheduledTask\AppointmentOrderTask;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Cache\CacheClearer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * 
 * 
 * 
 */
class OrderStateTransitionSubscriber implements EventSubscriberInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var ProductDefinition
     */
    private $definition;

    /**
     * @var CacheClearer
     */
    private $cache;

    /**
     * @var EntityCacheKeyGenerator
     */
    private $cacheKeyGenerator;

    public function __construct(
        Connection $connection,
        ProductDefinition $definition,
        CacheClearer $cache,
        EntityCacheKeyGenerator $cacheKeyGenerator
    ) {
        $this->connection = $connection;
        $this->definition = $definition;
        $this->cache = $cache;
        $this->cacheKeyGenerator = $cacheKeyGenerator;
    }
    public static function getSubscribedEvents(): array
    {
        // Return the events to listen to as array like this:  <event to listen to> => <method to execute>
        return [
            StateMachineTransitionEvent::class => 'stateChanged',
        ];
    }

    public function stateChanged(StateMachineTransitionEvent $event): void
    {
        if ($event->getContext()->getVersionId() !== Defaults::LIVE_VERSION) {
            return;
        }
        if ($event->getEntityName() !== 'order') {
            return;
        }

        if ($event->getToPlace()->getTechnicalName() === AppointmentOrderStates::STATE_APPOINTED) {
            $products = $this->getProductsOfOrder($event->getEntityId());

            $ids = array_column($products, 'referenced_id');

            $this->updateAvailableStockAndSales($ids, $event->getContext());

            $this->updateAvailableFlag($ids, $event->getContext());
        } else if ($event->getFromPlace()->getTechnicalName() === AppointmentOrderStates::STATE_APPOINTED && $event->getToPlace()->getTechnicalName() != AppointmentOrderStates::STATE_APPOINTMENT_CANCELLED) {
            $products = $this->getProductsOfOrder($event->getEntityId());

            $ids = array_column($products, 'referenced_id');

            $this->updateAvailableStockAndSales($ids, $event->getContext());

            $this->updateAvailableFlag($ids, $event->getContext());
        }
    }

    private function increaseStock(StateMachineTransitionEvent $event): void
    {
        $products = $this->getProductsOfOrder($event->getEntityId());

        $ids = array_column($products, 'referenced_id');

        $this->updateStock($products, +1);

        $this->updateAvailableStockAndSales($ids, $event->getContext());

        $this->updateAvailableFlag($ids, $event->getContext());
    }

    private function decreaseStock(StateMachineTransitionEvent $event): void
    {
        $products = $this->getProductsOfOrder($event->getEntityId());

        $ids = array_column($products, 'referenced_id');

        $this->updateStock($products, -1);

        $this->updateAvailableStockAndSales($ids, $event->getContext());

        $this->updateAvailableFlag($ids, $event->getContext());
    }

    private function getProductsOfOrder(string $orderId): array
    {
        $query = $this->connection->createQueryBuilder();
        $query->select(['referenced_id', 'quantity']);
        $query->from('order_line_item');
        $query->andWhere('type = :type');
        $query->andWhere('order_id = :id');
        $query->andWhere('version_id = :version');
        $query->setParameter('id', Uuid::fromHexToBytes($orderId));
        $query->setParameter('version', Uuid::fromHexToBytes(Defaults::LIVE_VERSION));
        $query->setParameter('type', LineItem::PRODUCT_LINE_ITEM_TYPE);

        return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function updateStock(array $products, int $multiplier): void
    {
        $query = new RetryableQuery(
            $this->connection->prepare('UPDATE product SET stock = stock + :quantity WHERE id = :id AND version_id = :version')
        );

        foreach ($products as $product) {
            $query->execute([
                'quantity' => (int) $product['quantity'] * $multiplier,
                'id' => Uuid::fromHexToBytes($product['referenced_id']),
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ]);
        }
    }

    private function updateAvailableStockAndSales(array $ids, Context $context): void
    {
        $ids = array_filter(array_keys(array_flip($ids)));

        if (empty($ids)) {
            return;
        }

        $sql = '
SELECT LOWER(HEX(order_line_item.product_id)) as product_id,
    IFNULL(
        SUM(IF(state_machine_state.technical_name = :completed_state, 0, order_line_item.quantity)),
        0
    ) as open_quantity,

    IFNULL(
        SUM(IF(state_machine_state.technical_name = :completed_state, order_line_item.quantity, 0)),
        0
    ) as sales_quantity,

    IFNULL(
        SUM(IF(state_machine_state.technical_name = :appointed_state, order_line_item.quantity, 0)),
        0
    ) as appointed_quantity,

    IFNULL(
        SUM(IF(state_machine_state.technical_name = :appointment_cancelled_state, order_line_item.quantity, 0)),
        0
    ) as appointed_cancelled_quantity

FROM order_line_item
    INNER JOIN `order`
        ON `order`.id = order_line_item.order_id
        AND `order`.version_id = order_line_item.order_version_id
    INNER JOIN state_machine_state
        ON state_machine_state.id = `order`.state_id
        AND state_machine_state.technical_name <> :cancelled_state

WHERE LOWER(order_line_item.referenced_id) IN (:ids)
    AND order_line_item.type = :type
    AND order_line_item.version_id = :version
    AND order_line_item.product_id IS NOT NULL
GROUP BY product_id;
        ';

        $rows = $this->connection->fetchAll(
            $sql,
            [
                'type' => LineItem::PRODUCT_LINE_ITEM_TYPE,
                'version' => Uuid::fromHexToBytes($context->getVersionId()),
                'completed_state' => OrderStates::STATE_COMPLETED,
                'cancelled_state' => OrderStates::STATE_CANCELLED,
                'appointed_state' => AppointmentOrderStates::STATE_APPOINTED,
                'appointment_cancelled_state' => AppointmentOrderStates::STATE_APPOINTMENT_CANCELLED,
                'ids' => $ids,
            ],
            [
                'ids' => Connection::PARAM_STR_ARRAY,
            ]
        );

        $fallback = array_column($rows, 'product_id');

        $fallback = array_diff($ids, $fallback);

        $update = new RetryableQuery(
            $this->connection->prepare('UPDATE product SET available_stock = stock - :open_quantity + :appointed_quantity + :appointed_cancelled_quantity, sales = :sales_quantity WHERE id = :id')
        );

        foreach ($fallback as $id) {
            $update->execute([
                'id' => Uuid::fromHexToBytes((string) $id),
                'open_quantity' => 0,
                'sales_quantity' => 0,
                'appointed_quantity' => 0,
                'appointed_cancelled_quantity' => 0,
            ]);
        }

        foreach ($rows as $row) {
            $update->execute([
                'id' => Uuid::fromHexToBytes($row['product_id']),
                'open_quantity' => $row['open_quantity'],
                'sales_quantity' => $row['sales_quantity'],
                'appointed_quantity' => $row['appointed_quantity'],
                'appointed_cancelled_quantity' => $row['appointed_cancelled_quantity'],
            ]);
        }
    }

    private function updateAvailableFlag(array $ids, Context $context): void
    {
        $ids = array_filter(array_keys(array_flip($ids)));

        if (empty($ids)) {
            return;
        }

        $bytes = Uuid::fromHexToBytesList($ids);

        $sql = '
            UPDATE product
            LEFT JOIN product parent
                ON parent.id = product.parent_id
                AND parent.version_id = product.version_id

            SET product.available = IFNULL((
                IFNULL(product.is_closeout, parent.is_closeout) * product.available_stock
                >=
                IFNULL(product.is_closeout, parent.is_closeout) * IFNULL(product.min_purchase, parent.min_purchase)
            ), 0)
            WHERE product.id IN (:ids)
            AND product.version_id = :version
        ';

        RetryableQuery::retryable(function () use ($sql, $context, $bytes): void {
            $this->connection->executeUpdate(
                $sql,
                ['ids' => $bytes, 'version' => Uuid::fromHexToBytes($context->getVersionId())],
                ['ids' => Connection::PARAM_STR_ARRAY]
            );
        });
    }
}
