<?php declare(strict_types=1);

namespace ASAppointment;

use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class ASAppointment extends Plugin
{
    /** @inheritDoc */
    public function install(InstallContext $installContext): void
    {
        if (!file_exists('../custom/plugins/ASControllingReport/Reports/')) {
            mkdir('../custom/plugins/ASControllingReport/Reports/', 0777, true);
        }
    }

    /** @inheritDoc */
    public function postInstall(InstallContext $installContext): void
    {
    }

    /** @inheritDoc */
    public function update(UpdateContext $updateContext): void
    {
    }

    /** @inheritDoc */
    public function postUpdate(UpdateContext $updateContext): void
    {
    }

    /** @inheritDoc */
    public function activate(ActivateContext $activateContext): void
    {
        $context = $activateContext->getContext();
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name' , 'order_line_item_custom_fields'));
        $searchResult = $customFieldSetRepository->search($criteria,$context);
        if(count($searchResult) > 0)
            return ;
        $customFieldSetRepository->upsert([
            [
                'name' => 'order_line_item_custom_fields',
                // 'global' => true,
                'config' => [
                    'label' => [
                        'de-DE' => 'Bestellpositionszusatzfelder',
                        'en-GB' => 'Order Line Item custom fields'
                    ]
                ],
                'customFields' => [
                    [
                        'name' => 'appointment_shipment_date',
                        'type' => CustomFieldTypes::DATETIME,
                        'config' => [
                            'type' => 'date',
                            'dateType' => 'date',
                            'label' => [
                                'de-DE' => 'Versanddatum',
                                'en-GB' => 'Shipment date'
                            ]
                        ]
                    ]
                ],
                'relations' => [[
                    'entityName' => 'order_line_item'
                ]],
            ]
        ], $context);
    }

    /** @inheritDoc */
    public function deactivate(DeactivateContext $deactivateContext): void
    {
    }

    /** @inheritDoc */
    public function uninstall(UninstallContext $context): void
    {
        if ($context->keepUserData()) {
            parent::uninstall($context);

            return;
        }

        $connection = $this->container->get(Connection::class);

        // $connection->executeUpdate('DROP TABLE IF EXISTS `repository_name`');

        parent::uninstall($context);
    }

}