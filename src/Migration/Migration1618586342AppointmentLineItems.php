<?php declare(strict_types=1);

namespace ASAppointment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1618586342AppointmentLineItems extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1618586342;
    }

    public function update(Connection $connection): void
    {
        $connection->exec(
                "CREATE TABLE IF NOT EXISTS `as_appointment_line_item` (
                `id`                BINARY(16) NOT NULL,
                `product_number`    VARCHAR(255) NOT NULL,
                `amount`            INTEGER NOT NULL,
                `appointment_date`  VARCHAR(255) NOT NULL,
                `customer_id`       VARCHAR(255) NOT NULL,
                `created_at`        DATETIME(3),
                `updated_at`        DATETIME(3)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
                );
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
