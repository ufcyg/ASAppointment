<?php declare(strict_types=1);

namespace ASAppointment;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionEntity;
use Shopware\Core\System\StateMachine\StateMachineEntity;

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

        /** @var StateMachineEntity $stateMachine */
        $stateMachine = $this->getFilteredEntitiesOfRepository($this->container->get('state_machine.repository'), 
                                                                'technicalName', 
                                                                'order.state', 
                                                                $context)->first();
        /** @var EntityRepositoryInterface $stateMachineStateRepository */
        $stateMachineStateRepository = $this->container->get('state_machine_state.repository');
        /** @var StateMachineStateEntity */
        // add state
        if(!$this->entityExistsInRepositoryCk($stateMachineStateRepository,
                                                'technicalName', 
                                                'appointed', 
                                                $context))
        {
            /** @var EntityWrittenContainerEvent $stateTransitionWrittenEvent */
            $stateTransitionWrittenEvent = $stateMachineStateRepository->create([['name' => 'appointed',
                                                    'technicalName' => 'appointed', 
                                                    'stateMachineId' => $stateMachine->getId()
                                                    ]],$context);
        }
        //add state translations
        /** @var EntityRepositoryInterface $languageRepository */
        $languageRepository = $this->container->get('language.repository');
        $languages = $this->getAllEntitiesOfRepository($languageRepository,$context);
        $germanID = null;
        $englishID = null;
        /** @var LanguageEntity $language */
        foreach($languages as $languageID => $language)
        {
            switch($language->getName())
            {
                case 'Deutsch':
                    $germanID = $languageID;
                    break;
                case 'English':
                    $englishID = $languageID;
                    break;
            }
        }
        /** @var StateMachineStateEntity $appointedStateEntity */
        $appointedStateEntity = $this->getFilteredEntitiesOfRepository($stateMachineStateRepository, 'technicalName', 'appointed', $context)->first();
        /** @var EntityRepositoryInterface $stateMachineStateTranslationRepository */
        $stateMachineStateTranslationRepository = $this->container->get('state_machine_state_translation.repository');
        if($germanID != null)
        {
            if(!$this->entityExistsInRepositoryCk($stateMachineStateTranslationRepository, 'name', 'Terminbestellung', $context))
                $stateMachineStateTranslationRepository->upsert([
                    ['languageId' => $germanID, 
                    'stateMachineStateId' => $appointedStateEntity->getId(), 
                    'name' => 'Terminbestellung']
                ], $context);
        }
        if($englishID != null)
        {
            if(!$this->entityExistsInRepositoryCk($stateMachineStateTranslationRepository, 'name', 'Appointmentorder', $context))
                $stateMachineStateTranslationRepository->upsert([
                    ['languageId' => $englishID, 
                    'stateMachineStateId' => $appointedStateEntity->getId(), 
                    'name' => 'Appointmentorder']
                ], $context);
        }
        // add transitions
        /** @var EntityRepositoryInterface $stateMachineTransitionRepository */
        $stateMachineTransitionRepository = $this->container->get('state_machine_transition.repository');
        //open to appointed
        //get open state machine state
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachineId', $stateMachine->getId()));
        $criteria->addFilter(new EqualsFilter('technicalName', 'open'));
        /** @var StateMachineStateEntity $openState */
        $openState = $stateMachineStateRepository->search($criteria, $context)->first();
        //get open state machine state
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('stateMachineId', $stateMachine->getId()));
        $criteria->addFilter(new EqualsFilter('technicalName', 'cancelled'));
        /** @var StateMachineStateEntity $cancelledState */
        $cancelledState = $stateMachineStateRepository->search($criteria, $context)->first();

        //add open to appointed transition
        if(!$this->entityExistsInRepositoryCk($stateMachineTransitionRepository, 'actionName', 'setAppointed', $context))
        {
            $stateMachineTransitionRepository->create([[
                'actionName' => 'setAppointed',
                'stateMachineId' => $stateMachine->getId(),
                'fromStateId' => $openState->getId(),
                'toStateId' => $appointedStateEntity->getId()
            ]], $context);
        }
        //add appointed to open transition
        if(!$this->entityExistsInRepositoryCk($stateMachineTransitionRepository, 'actionName', 'openAppointment', $context))
        {
            $stateMachineTransitionRepository->create([[
                'actionName' => 'openAppointment',
                'stateMachineId' => $stateMachine->getId(),
                'fromStateId' => $appointedStateEntity->getId(),
                'toStateId' => $openState->getId()
            ]], $context);
        }

        //add appointed to cancelled transition
        if(!$this->entityExistsInRepositoryCk($stateMachineTransitionRepository, 'actionName', 'cancelAppointment', $context))
        {
            $stateMachineTransitionRepository->create([[
                'actionName' => 'cancelAppointment',
                'stateMachineId' => $stateMachine->getId(),
                'fromStateId' => $appointedStateEntity->getId(),
                'toStateId' => $cancelledState->getId()
            ]], $context);
        }
        //add cancelled to appointed transition
        if(!$this->entityExistsInRepositoryCk($stateMachineTransitionRepository, 'actionName', 'reopenAppointment', $context))
        {
            $stateMachineTransitionRepository->create([[
                'actionName' => 'reopenAppointment',
                'stateMachineId' => $stateMachine->getId(),
                'fromStateId' => $appointedStateEntity->getId(),
                'toStateId' =>$appointedStateEntity->getId()
            ]], $context);
        }
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

        $connection->executeUpdate('DROP TABLE IF EXISTS `as_appointment_line_item`');

        parent::uninstall($context);
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