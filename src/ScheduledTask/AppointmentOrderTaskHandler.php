<?php declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use ASAppointment\Core\Content\AppointmentLineItem\AppointmentLineItemEntity;
use ASMailService\Core\MailServiceHelper;
use DateTimeImmutable;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\DeliveryTime\DeliveryTimeEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\Tax\TaxCollection;

class AppointmentOrderTaskHandler extends ScheduledTaskHandler
{    
    /** @var SystemConfigService $systemConfigService */
    private $systemConfigService;
    /** @var MailServiceHelper $mailService */
    private $mailService;
    public function __construct(EntityRepositoryInterface $scheduledTaskRepository,
                                SystemConfigService $systemConfigService,
                                MailServiceHelper $mailService)
    {
        parent::__construct($scheduledTaskRepository);
        $this->systemConfigService = $systemConfigService;
        $this->mailService = $mailService;
    }

    public static function getHandledMessages(): iterable
    {
        return [ AppointmentOrderTask::class ];
    }

    public function run(): void
    {
        $appointmentLineItems = $this->getAppointmentsOnDue();
        /** @var AppointmentLineItemEntity $appointmentLineItem */
        foreach($appointmentLineItems as $appointmentLineItemID => $appointmentLineItem)
        {
            /** @var Cart */
            $myCart = new Cart('appointmentOrder','appointment');
            $productNumber = $appointmentLineItem->getProductNumber();
            /** @var CustomerEntity $customerEntity */
            $customerEntity = $this->getFilteredEntitiesOfRepository($this->container->get('customer.repository'),
                                                                    'id',
                                                                    $appointmentLineItem->getCustomerId(), 
                                                                    Context::createDefaultContext()
                                                                    )->first();

            /** @var ProductEntity $productEntity */
            $productEntity = $this->getFilteredEntitiesOfRepository($this->container->get('product.repository'),
                                                                    'productNumber',
                                                                    $productNumber,
                                                                    Context::createDefaultContext()
                                                                    )->first();

            $lineItem = new LineItem(Uuid::randomHex(), "product", NULL, 1);
            $lineItem->setGood(false);
            $lineItem->setStackable(true);
            $lineItem->setRemovable(true);
            $quantity = $appointmentLineItem->getAmount();
            $lineItem->setQuantity($quantity);
            $lineItem->setReferencedId($productEntity->getId());
            
            $salesChannelContext = $this->createSalesChannelContext($customerEntity, Context::createDefaultContext());

            $myCart = $this->cartService->add($myCart,$lineItem,$salesChannelContext);
            $this->cartService->setCart($myCart);

            //remove appointment line item from db
            $this->container->get('as_appointment_line_item.repository')->delete([['id' => $appointmentLineItemID]],Context::createDefaultContext());
            
            $customerName = $customerEntity->getFirstName() . ' ' . $customerEntity->getLastName();
            $customerMail = $customerEntity->getEmail();
            $date = date('Y-m-d', $appointmentLineItem->getAppointmentDate()->getTimestamp());
            if(count($myCart->getLineItems()) == 0)
            { // product is currently not available, notification to customer support
                $this->systemConfigService->get('ASControllingReport.config.fallbackSaleschannelNotification');
                $recipients = $this->getRecipients();
                $customerName = $customerEntity->getFirstName() . ' ' . $customerEntity->getLastName();
                $customerMail = $customerEntity->getEmail();
                $appointmentLineItem->getAppointmentDate();
                
                $reason = 'Ungenügender Bestand';
                $notification = "Erstellen von Bestellung für Kunden $customerName ($customerMail) fehlgeschlagen.<br><br>Artikelnummer: $productNumber<br>Menge: $quantity<br>Wunschtermin: $date<br><br> Grund: $reason";
                $this->mailService->sendMyMail($recipients,
                                                null,
                                                'Terminbestellungs Plugin',
                                                'Terminbestellung fehlgeschlagen, kein Ausreichender Bestand',
                                                $notification,
                                                $notification,
                                                ['']);
                return;
            }
            $this->cartService->order($myCart,$salesChannelContext,null);
        }
        $notification = "Ihre Terminbestellung wurde aufgegeben.<br><br>Artikelnummer: $productNumber<br>Menge: $quantity<br>Wunschtermin: $date<br><br> Weitere Informationen können in Ihrem Kundenprofil aufgerufen werden.";
        $this->mailService->sendMyMail([$customerMail => $customerName],
                                        null,
                                        'Terminbestellung',
                                        'Terminbestellung ausgeführt',
                                        $notification,
                                        $notification,
                                        ['']);
        return;
    }    

    private function createSalesChannelContext(CustomerEntity $customerEntity, $context)
    {
        $salesChannelId = $customerEntity->getSalesChannelId();
        /** @var SalesChannelEntity $salesChannel */
        $salesChannel = $this->getFilteredEntitiesOfRepository($this->container->get('sales_channel.repository'),'id',$salesChannelId,$context)->first();

        $currency = $this->getAllEntitiesOfRepository($this->container->get('currency.repository'),$context)->first();
        $currentCustomerGroup = $this->getFilteredEntitiesOfRepository($this->container->get('customer_group.repository'),'id',$customerEntity->getGroupId(),$context)->first();
        /** @var TaxCollection $taxes */
        $taxes = $this->getAllEntitiesOfRepository($this->container->get('tax.repository'),$context)->getEntities();
        foreach($taxes as $tax)
        {
            $tax->setRules($this->getFilteredEntitiesOfRepository($this->container->get('tax_rule.repository'),'taxId',$tax->getId(),$context)->getEntities());
        }

        $paymentMethod = $this->getFilteredEntitiesOfRepository($this->container->get('payment_method.repository'),'id',$customerEntity->getDefaultPaymentMethodId(),$context)->first();
        $customerEntity->setLastPaymentMethod($paymentMethod);

        $shippingMethod = $this->getAllEntitiesOfRepository($this->container->get('shipping_method.repository'),$context)->first();

        /** @var CustomerAddressEntity $customerAddress */
        $customerAddress = $this->getFilteredEntitiesOfRepository($this->container->get('customer_address.repository'),'customerId',$customerEntity->getId(),$context)->first();
        $customerEntity->setDefaultBillingAddress($customerAddress);
        $country = $this->getFilteredEntitiesOfRepository($this->container->get('country.repository'),'id',$customerAddress->getCountryId(),$context)->first();
        $customerAddress->setCountry($country);
        $shippingLocation = ShippingLocation::createFromAddress($customerAddress);
        
        return $salesChannelContext = new SalesChannelContext($context,
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

    private function getAppointmentsOnDue(): ?array
    {
        $lineItemData = null;
        /** @var EntityRepositoryInterface $productRepository */
        $productRepository = $this->container->get('product.repository');
        /** @var EntityRepositoryInterface $appointmentLineItems */
        $appointmentLineItemsRepository = $this->container->get('as_appointment_line_item.repository');
        /** @var EntitySearchResult $appointmentLineItems */
        $appointmentLineItems = $this->getAllEntitiesOfRepository($appointmentLineItemsRepository, Context::createDefaultContext());
        /** @var AppointmentLineItemEntity $appointmentLineItem */
        foreach($appointmentLineItems as $appointmentLineItemID => $appointmentLineItem)
        {
            $productNumber = $appointmentLineItem->getProductNumber();
            $appointmentDate = $appointmentLineItem->getAppointmentDate();
            /** @var ProductEntity $product */
            $product = $this->getFilteredEntitiesOfRepository($productRepository,'productNumber',$productNumber,Context::createDefaultContext())->first();
            $deliveryTime = $this->getFilteredEntitiesOfRepository($this->container->get('delivery_time.repository'), 'id', $product->getDeliveryTimeId(), Context::createDefaultContext())->first();
            if($this->isDue($appointmentDate, $deliveryTime))
            {
                $lineItemData[$appointmentLineItemID] = $appointmentLineItem;
            }
        }
        return $lineItemData;
    }

    private function isDue($appointmentDate, DeliveryTimeEntity $deliveryTime): bool
    {
        $approxDeliveryTime = ceil(($deliveryTime->getMin() + $deliveryTime->getMax()) / 2);
        

        $appointmentDateExploded = explode('-', date('Y-m-d',$appointmentDate->getTimestamp()));
        $dateExploded = explode('-', date('Y-m-d'));

        if($appointmentDateExploded[0] == $dateExploded[0]) //same year
            if($appointmentDateExploded[1] == $dateExploded[1]) // same month
                if(intval($appointmentDateExploded[2]) <= intval($dateExploded[2]) + $approxDeliveryTime) //within deliverytime
                    return true;
        return false;
    }

    private function getRecipients()
    {
        $recipients = null;
        $recipientsRaw = $this->systemConfigService->get('ASAppointment.config.notificationRecipients');
        $recipientsExploded = explode(';', $recipientsRaw);
        for($i = 0; $i < count($recipientsExploded); $i += 2)
        {
            $recipients[$recipientsExploded[$i + 1]] = $recipientsExploded[$i];
        }   
        return $recipients;
    }


    public function getAllEntitiesOfRepository(EntityRepositoryInterface $repository, Context $context): ?EntitySearchResult
    {   
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria,$context);

        return $result;
    }

    public function getFilteredEntitiesOfRepository(EntityRepositoryInterface $repository, string $fieldName, $fieldValue, Context $context): ?EntitySearchResult
    {   
        /** @var Criteria $criteria */
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter($fieldName, $fieldValue));
        /** @var EntitySearchResult $result */
        $result = $repository->search($criteria,$context);

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