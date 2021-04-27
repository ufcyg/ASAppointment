<?php

declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use ASAppointment\Core\Checkout\Order\AppointmentOrderStates;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Order\OrderStates;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Doctrine\RetryableQuery;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Uuid\Uuid;

class UpdateProductsTaskHandler extends ScheduledTaskHandler
{
    /** @var Connection $connection */
    private $connection;
    /** @var ContainerInterface $container */
    protected $container;
    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        Connection $connection
    ) {
        $this->connection = $connection;
        parent::__construct($scheduledTaskRepository);
    }

    /** @internal @required */
    public function setContainer(ContainerInterface $container): ?ContainerInterface
    {
        $previous = $this->container;
        $this->container = $container;

        return $previous;
    }

    public static function getHandledMessages(): iterable
    {
        return [UpdateProductsTask::class];
    }

    public function run(): void
    {
        $this->updateProductData(Context::createDefaultContext());
    }

    private function updateProductData($context)
    {
        // update stock and available after deletion of line items
        $productRepository = $this->container->get('product.repository');
        foreach ($productRepository->search(new Criteria(), $context) as $productID => $product) {
            $ids[] = $productID;
        }
        $this->updateAvailableStockAndSales($ids, $context);
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
}
