<?php declare(strict_types=1);

namespace SwagMigrationNext\Migration\Mapping;

use Shopware\Core\Content\Rule\RuleEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Util\AccessKeyHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriterInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Write\WriteContext;
use Shopware\Core\Framework\Language\LanguageDefinition;
use Shopware\Core\Framework\Language\LanguageEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryDefinition;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyDefinition;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\Locale\LocaleEntity;
use Shopware\Core\System\NumberRange\NumberRangeDefinition;
use Shopware\Core\System\NumberRange\NumberRangeEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelType\SalesChannelTypeEntity;
use Shopware\Core\System\SalesChannel\SalesChannelDefinition;
use Shopware\Core\System\Tax\TaxDefinition;
use Shopware\Core\System\Tax\TaxEntity;
use SwagMigrationNext\Exception\LocaleNotFoundException;
use SwagMigrationNext\Migration\MigrationContextInterface;

class MappingService implements MappingServiceInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    protected $migrationMappingRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $localeRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $languageRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $countryRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $currencyRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $salesChannelRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $salesChannelTypeRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $paymentRepository;

    /**
     * @var EntityRepositoryInterface
     */
    protected $shippingMethodRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $taxRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $numberRangeRepo;

    protected $uuids = [];

    protected $uuidLists = [];

    protected $migratedSalesChannels = [];

    protected $writeArray = [];

    protected $languageData = [];

    /**
     * @var LanguageEntity
     */
    protected $defaultLanguageData;

    /**
     * @var EntityRepositoryInterface
     */
    private $ruleRepo;

    /**
     * @var EntityWriterInterface
     */
    private $entityWriter;

    /**
     * @var string
     */
    private $defaultAvailabilityRule;

    public function __construct(
        EntityRepositoryInterface $migrationMappingRepo,
        EntityRepositoryInterface $localeRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $countryRepository,
        EntityRepositoryInterface $currencyRepository,
        EntityRepositoryInterface $salesChannelRepo,
        EntityRepositoryInterface $salesChannelTypeRepo,
        EntityRepositoryInterface $paymentRepository,
        EntityRepositoryInterface $shippingMethodRepo,
        EntityRepositoryInterface $taxRepo,
        EntityRepositoryInterface $numberRangeRepo,
        EntityRepositoryInterface $ruleRepo,
        EntityWriterInterface $entityWriter
    ) {
        $this->migrationMappingRepo = $migrationMappingRepo;
        $this->localeRepository = $localeRepository;
        $this->languageRepository = $languageRepository;
        $this->countryRepository = $countryRepository;
        $this->currencyRepository = $currencyRepository;
        $this->salesChannelRepo = $salesChannelRepo;
        $this->salesChannelTypeRepo = $salesChannelTypeRepo;
        $this->paymentRepository = $paymentRepository;
        $this->shippingMethodRepo = $shippingMethodRepo;
        $this->taxRepo = $taxRepo;
        $this->numberRangeRepo = $numberRangeRepo;
        $this->ruleRepo = $ruleRepo;
        $this->entityWriter = $entityWriter;
    }

    public function getUuid(string $connectionId, string $entityName, string $oldId, Context $context): ?string
    {
        if (isset($this->uuids[$entityName][$oldId])) {
            return $this->uuids[$entityName][$oldId];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
        $criteria->addFilter(new EqualsFilter('entity', $entityName));
        $criteria->addFilter(new EqualsFilter('oldIdentifier', $oldId));
        $criteria->setLimit(1);
        $result = $this->migrationMappingRepo->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var SwagMigrationMappingEntity $element */
            $element = $result->getEntities()->first();
            $uuid = $element->getEntityUuid();

            $this->uuids[$entityName][$oldId] = $uuid;

            return $uuid;
        }

        return null;
    }

    public function createNewUuidListItem(
        string $connectionId,
        string $entityName,
        string $oldId,
        ?array $additionalData = null,
        ?string $newUuid = null
    ): string {
        $uuid = Uuid::randomHex();
        if ($newUuid !== null) {
            $uuid = $newUuid;

            if ($this->isUuidDuplicate($connectionId, $entityName, $oldId, $newUuid)) {
                return $newUuid;
            }
        }

        $this->saveListMapping(
            [
                'connectionId' => $connectionId,
                'entity' => $entityName,
                'oldIdentifier' => $oldId,
                'entityUuid' => $uuid,
                'additionalData' => $additionalData,
            ]
        );

        return $uuid;
    }

    public function isUuidDuplicate(string $connectionId, string $entityName, string $id, string $uuid): bool
    {
        foreach ($this->writeArray as $item) {
            if (
                $item['connectionId'] === $connectionId
                && $item['entity'] === $entityName
                && $item['oldIdentifier'] === $id
                && $item['entityUuid'] === $uuid
            ) {
                return true;
            }
        }

        return false;
    }

    public function getUuidList(string $connectionId, string $entityName, string $identifier, Context $context): array
    {
        if (isset($this->uuidLists[$entityName][$identifier])) {
            return $this->uuidLists[$entityName][$identifier];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
        $criteria->addFilter(new EqualsFilter('entity', $entityName));
        $criteria->addFilter(new EqualsFilter('oldIdentifier', $identifier));
        $result = $this->migrationMappingRepo->search($criteria, $context);

        $uuidList = [];
        if ($result->getTotal() > 0) {
            /** @var SwagMigrationMappingEntity $entity */
            foreach ($result->getEntities() as $entity) {
                $uuidList[] = $entity->getEntityUuid();
            }
        }

        $this->uuidLists[$entityName][$identifier] = $uuidList;

        return $uuidList;
    }

    public function createNewUuid(
        string $connectionId,
        string $entityName,
        string $oldId,
        Context $context,
        ?array $additionalData = null,
        ?string $newUuid = null
    ): string {
        $uuid = $this->getUuid($connectionId, $entityName, $oldId, $context);
        if ($uuid !== null) {
            return $uuid;
        }

        $uuid = Uuid::randomHex();
        if ($newUuid !== null) {
            $uuid = $newUuid;
        }

        $this->saveMapping(
            [
                'connectionId' => $connectionId,
                'entity' => $entityName,
                'oldIdentifier' => $oldId,
                'entityUuid' => $uuid,
                'additionalData' => $additionalData,
            ]
        );

        return $uuid;
    }

    public function getLanguageUuid(string $connectionId, string $localeCode, Context $context): ?string
    {
        if (isset($this->languageData[$localeCode])) {
            return $this->languageData[$localeCode];
        }

        $languageUuid = $this->searchLanguageInMapping($localeCode, $context);
        if ($languageUuid !== null) {
            return $languageUuid;
        }

        $localeUuid = $this->searchLocale($localeCode, $context);

        $languageUuid = $this->searchLanguageByLocale($localeUuid, $context);

        if ($languageUuid === null) {
            return $languageUuid;
        }
        $this->languageData[$localeCode] = $languageUuid;

        return $languageUuid;
    }

    public function getLocaleUuid(string $connectionId, string $localeCode, Context $context): string
    {
        // TODO: Implement getLocaleUuid() method.
    }

    public function getDefaultLanguageUuid(Context $context): LanguageEntity
    {
        if (!empty($this->defaultLanguageData)) {
            return $this->defaultLanguageData;
        }

        $languageUuid = $context->getLanguageId();
        /** @var LanguageEntity $language */
        $language = $this->languageRepository->search(new Criteria([$languageUuid]), $context)->first();

        $this->defaultLanguageData = $language;

        return $language;
    }

    public function getCountryUuid(string $oldId, string $iso, string $iso3, string $connectionId, Context $context): ?string
    {
        $countryUuid = $this->getUuid($connectionId, CountryDefinition::getEntityName(), $oldId, $context);

        if ($countryUuid !== null) {
            return $countryUuid;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', $iso));
        $criteria->addFilter(new EqualsFilter('iso3', $iso3));
        $criteria->setLimit(1);
        $result = $this->countryRepository->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var CountryEntity $element */
            $element = $result->getEntities()->first();

            $countryUuid = $element->getId();

            $this->saveMapping(
                [
                    'connectionId' => $connectionId,
                    'entity' => CountryDefinition::getEntityName(),
                    'oldIdentifier' => $oldId,
                    'entityUuid' => $countryUuid,
                ]
            );

            return $countryUuid;
        }

        return null;
    }

    public function getCurrencyUuid(string $connectionId, string $oldShortName, Context $context): ?string
    {
        $currencyUuid = $this->getUuid($connectionId, CurrencyDefinition::getEntityName(), $oldShortName, $context);

        if ($currencyUuid !== null) {
            return $currencyUuid;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('shortName', $oldShortName));
        $criteria->setLimit(1);
        $result = $this->currencyRepository->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var CurrencyEntity $element */
            $element = $result->getEntities()->first();
            $currencyUuid = $element->getId();

            $this->saveMapping(
                [
                    'connectionId' => $connectionId,
                    'entity' => CurrencyDefinition::getEntityName(),
                    'oldIdentifier' => $oldShortName,
                    'entityUuid' => $currencyUuid,
                ]
            );

            return $currencyUuid;
        }

        return null;
    }

    public function getTaxUuid(string $connectionId, float $taxRate, Context $context): ?string
    {
        $taxUuid = $this->getUuid($connectionId, TaxDefinition::getEntityName(), (string) $taxRate, $context);

        if ($taxUuid !== null) {
            return $taxUuid;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('taxRate', $taxRate));
        $criteria->setLimit(1);
        $result = $this->taxRepo->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var TaxEntity $tax */
            $tax = $result->getEntities()->first();
            $taxUuid = $tax->getId();

            $this->saveMapping(
                [
                    'connectionId' => $connectionId,
                    'entity' => TaxDefinition::getEntityName(),
                    'oldIdentifier' => (string) $taxRate,
                    'entityUuid' => $taxUuid,
                ]
            );

            return $taxUuid;
        }

        return null;
    }

    public function getNumberRangeUuid(string $type, string $oldId, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter(
            'number_range.type.technicalName',
            $type
        ));

        $result = $this->numberRangeRepo->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var NumberRangeEntity $numberRange */
            $numberRange = $result->getEntities()->first();

            $this->saveMapping(
                [
                    'connectionId' => $migrationContext->getConnection()->getId(),
                    'entity' => NumberRangeDefinition::getEntityName(),
                    'oldIdentifier' => $oldId,
                    'entityUuid' => $numberRange->getId(),
                ]
            );

            return $numberRange->getId();
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getMigratedSalesChannelUuids(string $connectionId, Context $context): array
    {
        if (isset($this->migratedSalesChannels[$connectionId])) {
            return $this->migratedSalesChannels[$connectionId];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
        $criteria->addFilter(new EqualsFilter('entity', SalesChannelDefinition::getEntityName()));

        $result = $this->migrationMappingRepo->search($criteria, $context);
        /** @var SwagMigrationMappingCollection $saleschannelMappingCollection */
        $saleschannelMappingCollection = $result->getEntities();

        $uuids = [];
        foreach ($saleschannelMappingCollection as $swagMigrationMappingEntity) {
            $uuid = $swagMigrationMappingEntity->getEntityUuid();
            $uuids[] = $uuid;
            $this->migratedSalesChannels[$connectionId][] = $uuid;
        }

        return $uuids;
    }

    //Todo: Remove if we migrate every data of the shipping method
    public function getDefaultAvailabilityRule(Context $context): ?string
    {
        if (isset($this->defaultAvailabilityRule)) {
            return $this->defaultAvailabilityRule;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'Cart >= 0'));
        /** @var RuleEntity $result */
        $result = $this->ruleRepo->search($criteria, $context)->first();

        $uuids = null;
        if ($result !== null) {
            $uuids = $result->getId();
        }

        return $uuids;
    }

    public function deleteMapping(string $entityUuid, string $connectionId, Context $context): void
    {
        foreach ($this->writeArray as $writeMapping) {
            if ($writeMapping['connectionId'] === $connectionId && $writeMapping['entityUuid'] === $entityUuid) {
                unset($writeMapping);
                break;
            }
        }

        if (!empty($this->uuids)) {
            foreach ($this->uuids as $entityName => $entityArray) {
                foreach ($entityArray as $oldId => $uuid) {
                    if ($uuid === $entityUuid) {
                        unset($this->uuids[$entityName][$oldId]);
                        break;
                    }
                }
            }
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entityUuid', $entityUuid));
        $criteria->addFilter(new EqualsFilter('connectionId', $connectionId));
        $criteria->setLimit(1);
        $result = $this->migrationMappingRepo->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var SwagMigrationMappingEntity $element */
            $element = $result->getEntities()->first();

            $this->migrationMappingRepo->delete([['id' => $element->getId()]], $context);
        }
    }

    public function bulkDeleteMapping(array $mappingUuids, Context $context): void
    {
        if (!empty($mappingUuids)) {
            $deleteArray = [];
            foreach ($mappingUuids as $uuid) {
                $deleteArray[] = [
                    'id' => $uuid,
                ];
            }

            $this->migrationMappingRepo->delete($deleteArray, $context);
        }
    }

    public function writeMapping(Context $context): void
    {
        if (empty($this->writeArray)) {
            return;
        }

        $this->entityWriter->insert(
            SwagMigrationMappingDefinition::class,
            $this->writeArray,
            WriteContext::createFromContext($context)
        );

        $this->writeArray = [];
        $this->uuids = [];
    }

    public function createSalesChannelMapping(string $connectionId, array $structure, Context $context): void
    {
        foreach ($structure as $structureItem) {
            $uuid = $this->getStructureToSalesChannelMapping($structureItem['id'], $connectionId, $context);

            if ($uuid !== null && !$this->existsSalesChannel($uuid, $context)) {
                $this->deleteMapping($uuid, $connectionId, $context);
                $uuid = null;
            }

            if ($uuid === null) {
                $uuid = $this->createSalesChannel($structureItem, $context);
                $this->insertSalesChannelMapping($structureItem['id'], $connectionId, $uuid, $context);
            }

            if (isset($structureItem['children'])) {
                $this->createChildrenMapping($connectionId, $structureItem['children'], $uuid, $context);
            }
        }

        $this->writeMapping($context);
    }

    public function pushMapping(string $connectionId, string $entity, string $oldIdentifier, string $uuid)
    {
        $this->saveMapping([
            'connectionId' => $connectionId,
            'entity' => $entity,
            'oldIdentifier' => $oldIdentifier,
            'entityUuid' => $uuid,
        ]);
    }

    protected function saveMapping(array $mapping): void
    {
        $entity = $mapping['entity'];
        $oldId = $mapping['oldIdentifier'];
        $uuid = $mapping['entityUuid'];

        $this->uuids[$entity][$oldId] = $uuid;
        $this->writeArray[] = $mapping;
    }

    protected function saveListMapping(array $mapping): void
    {
        $entity = $mapping['entity'];
        $oldId = $mapping['oldIdentifier'];
        $uuid = $mapping['entityUuid'];

        $this->uuids[$entity][$oldId][] = $uuid;
        $this->writeArray[] = $mapping;
    }

    private function createChildrenMapping(string $connectionId, array $children, string $uuid, Context $context): void
    {
        foreach ($children as $child) {
            $oldUuid = $this->getStructureToSalesChannelMapping($child['id'], $connectionId, $context);

            if ($oldUuid !== null && $oldUuid === $uuid) {
                continue;
            }

            if ($oldUuid !== null && $oldUuid !== $uuid) {
                $this->deleteMapping($oldUuid, $connectionId, $context);
            }

            $this->insertSalesChannelMapping($child['id'], $connectionId, $uuid, $context);
        }
    }

    private function getStructureToSalesChannelMapping(string $structureId, string $connectionId, Context $context): ?string
    {
        return $this->getUuid(
            $connectionId,
            SalesChannelDefinition::getEntityName(),
            $structureId,
            $context
        );
    }

    private function createSalesChannel(array $structureItem, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);
        /** @var SalesChannelTypeEntity $salesChannelType */
        $salesChannelType = $this->salesChannelTypeRepo->search($criteria, $context)->first();

        $validPaymentMethodId = $this->getFirstActivePaymentMethodId();
        $validShippingMethodId = $this->getFirstActiveShippingMethodId();
        $validCountryId = $this->getFirstActiveCountryId();

        // Todo: Replace default values with external values
        $createEvent = $this->salesChannelRepo->create([
            [
                'typeId' => $salesChannelType->getId(),

                'customerGroupId' => Defaults::FALLBACK_CUSTOMER_GROUP,

                'languageId' => Defaults::LANGUAGE_SYSTEM,
                'languages' => [
                    [
                        'id' => Defaults::LANGUAGE_SYSTEM,
                    ],
                ],

                'currencyId' => Defaults::CURRENCY,
                'currencies' => [
                    [
                        'id' => Defaults::CURRENCY,
                    ],
                ],

                'paymentMethodId' => $validPaymentMethodId,
                'paymentMethods' => [
                    [
                        'id' => $validPaymentMethodId,
                    ],
                ],

                'shippingMethodId' => $validShippingMethodId,
                'shippingMethods' => [
                    [
                        'id' => $validShippingMethodId,
                    ],
                ],

                'countryId' => $validCountryId,
                'countries' => [
                    [
                        'id' => $validCountryId,
                    ],
                ],

                'name' => $structureItem['name'],
                'accessKey' => AccessKeyHelper::generateAccessKey('sales-channel'),
            ],
        ], $context);

        /** @var EntityWrittenEvent $writtenEvent */
        $writtenEvent = $createEvent->getEvents()->first();
        $ids = $writtenEvent->getIds();

        return $ids[0]['salesChannelId'];
    }

    private function getFirstActiveShippingMethodId(): string
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('active', true));

        return $this->shippingMethodRepo->searchIds($criteria, Context::createDefaultContext())->getIds()[0];
    }

    private function getFirstActivePaymentMethodId(): string
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('active', true))
            ->addSorting(new FieldSorting('position'));

        return $this->paymentRepository->searchIds($criteria, Context::createDefaultContext())->getIds()[0];
    }

    private function getFirstActiveCountryId(): string
    {
        $criteria = (new Criteria())
            ->setLimit(1)
            ->addFilter(new EqualsFilter('active', true))
            ->addSorting(new FieldSorting('position'));

        return $this->countryRepository->searchIds($criteria, Context::createDefaultContext())->getIds()[0];
    }

    private function insertSalesChannelMapping(string $structureId, string $connectionId, string $salesChannelUuid, Context $context): void
    {
        $this->createNewUuid(
            $connectionId,
            SalesChannelDefinition::getEntityName(),
            $structureId,
            $context,
            [],
            $salesChannelUuid
        );
    }

    private function existsSalesChannel(string $salesChannelUuid, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $salesChannelUuid));

        return $this->salesChannelRepo->search($criteria, $context)->count() > 0;
    }

    private function searchLanguageInMapping(string $localeCode, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entity', LanguageDefinition::getEntityName()));
        $criteria->addFilter(new EqualsFilter('oldIdentifier', $localeCode));
        $criteria->setLimit(1);
        $result = $this->migrationMappingRepo->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var SwagMigrationMappingEntity $element */
            $element = $result->getEntities()->first();

            return $element->getEntityUuid();
        }

        return null;
    }

    /**
     * @throws LocaleNotFoundException
     */
    private function searchLocale(string $localeCode, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('code', $localeCode));
        $criteria->setLimit(1);
        $result = $this->localeRepository->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var LocaleEntity $element */
            $element = $result->getEntities()->first();

            return $element->getId();
        }

        throw new LocaleNotFoundException($localeCode);
    }

    private function searchLanguageByLocale(string $localeUuid, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('localeId', $localeUuid));
        $criteria->setLimit(1);
        $result = $this->languageRepository->search($criteria, $context);

        if ($result->getTotal() > 0) {
            /** @var LanguageEntity $element */
            $element = $result->getEntities()->first();

            return $element->getId();
        }

        return null;
    }
}
