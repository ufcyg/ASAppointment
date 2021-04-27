<?php

declare(strict_types=1);

namespace ASAppointment\ScheduledTask;

use ASMailService\Core\MailServiceHelper;
use Psr\Container\ContainerInterface;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class StockErrorNotificationTaskHandler extends ScheduledTaskHandler
{
    /** @var MailServiceHelper $mailService */
    private $mailService;
    /** @var ContainerInterface $container */
    protected $container;
    /** @var SystemConfigService $systemConfigService */
    private $systemConfigService;

    public function __construct(
        EntityRepositoryInterface $scheduledTaskRepository,
        MailServiceHelper $mailService,
        SystemConfigService $systemConfigService
    ) {
        $this->mailService = $mailService;
        $this->systemConfigService = $systemConfigService;
        parent::__construct($scheduledTaskRepository);
    }

    public static function getHandledMessages(): iterable
    {
        return [StockErrorNotificationTask::class];
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
        $sendMail = false;
        $criticalProducts = '';
        $context = Context::createDefaultContext();
        /** @var ProductEntity $product */
        foreach ($this->getAllEntitiesOfRepository($this->container->get('product.repository'), $context) as $productID => $product) {
            if($product->getAvailableStock() < 0)
            {
                $sendMail = true;
                $productNumber = $product->getProductNumber();
                $criticalProducts .= "{$productNumber}, ";
            }
        }

        if($sendMail)
        {
            $criticalProducts = rtrim($criticalProducts, ", ");
            $notification = "Kritischer Bestand nach Öffnung von Terminbestellungen für folgende Produkte:<br><br> {$criticalProducts}<br><br> Bestellungen überprüfen und gegebenfalls zurückstellen bis Bestand ausreichend ist.";
            $this->mailService->sendMyMail(
                $this->getRecipients(),
                null,
                'Terminbestellungs Plugin',
                'Bestand kritisch',
                $notification,
                $notification,
                ['']
            );
        }        
    }

    private function getRecipients(): ?array
    {
        $recipients = null;
        $recipientsRaw = $this->systemConfigService->get('ASAppointment.config.notificationRecipients');
        $recipientsExploded = explode(';', $recipientsRaw);
        for ($i = 0; $i < count($recipientsExploded); $i += 2) {
            $recipients[$recipientsExploded[$i + 1]] = $recipientsExploded[$i];
        }
        return $recipients;
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
