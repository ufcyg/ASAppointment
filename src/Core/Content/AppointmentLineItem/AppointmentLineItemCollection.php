<?php declare(strict_types=1);

namespace ASAppointment\Core\Content\AppointmentLineItem;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void              add(AppointmentLineItemCollection $entity)
 * @method void              set(string $key, AppointmentLineItemCollection $entity)
 * @method AppointmentLineItemCollection[]    getIterator()
 * @method AppointmentLineItemCollection[]    getElements()
 * @method AppointmentLineItemCollection|null get(string $key)
 * @method AppointmentLineItemCollection|null first()
 * @method AppointmentLineItemCollection|null last()
 */
class AppointmentLineItemCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return AppointmentLineItemEntity::class;
    }
}