<?php declare(strict_types=1);

namespace ASAppointment\Core\Content\AppointmentLineItem;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;

class AppointmentLineItemDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'as_appointment_line_item';
    }

    public function getCollectionClass(): string
    {
        return AppointmentLineItemCollection::class;
    }

    public function getEntityClass(): string
    {
        return AppointmentLineItemEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection(
            [
                (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey()),
                new StringField('product_number', 'productNumber'),
                new IntField('amount','amount'),
                new DateField('appointment_date', 'appointmentDate'),
                new StringField('customer_id', 'customerId')
            ]
        );
    }
}