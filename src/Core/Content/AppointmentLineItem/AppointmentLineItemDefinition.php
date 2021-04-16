<?php declare(strict_types=1);

namespace ASAppointment\Core\Content\AppointmentLineItem;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
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
                (new IdField('id', 'id'))->addFlags(new Required(), new PrimaryKey())
                // new StringField('product_name', 'productName'),
                // new StringField('product_number', 'productNumber'),
                // new StringField('product_id', 'productId'),
                // new IntField('faulty', 'faulty'),
                // new IntField('clarification', 'clarification'),
                // new IntField('postprocessing', 'postprocessing'),
                // new IntField('other', 'other')
            ]
        );
    }
}