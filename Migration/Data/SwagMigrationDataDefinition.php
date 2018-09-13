<?php declare(strict_types=1);

namespace SwagMigrationNext\Migration\Data;

use Shopware\Core\Framework\ORM\EntityDefinition;
use Shopware\Core\Framework\ORM\Field\BoolField;
use Shopware\Core\Framework\ORM\Field\CreatedAtField;
use Shopware\Core\Framework\ORM\Field\FkField;
use Shopware\Core\Framework\ORM\Field\IdField;
use Shopware\Core\Framework\ORM\Field\JsonField;
use Shopware\Core\Framework\ORM\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\ORM\Field\StringField;
use Shopware\Core\Framework\ORM\Field\TenantIdField;
use Shopware\Core\Framework\ORM\Field\UpdatedAtField;
use Shopware\Core\Framework\ORM\FieldCollection;
use Shopware\Core\Framework\ORM\Write\Flag\PrimaryKey;
use Shopware\Core\Framework\ORM\Write\Flag\Required;
use SwagMigrationNext\Migration\Run\SwagMigrationRunDefinition;

class SwagMigrationDataDefinition extends EntityDefinition
{
    public static function getEntityName(): string
    {
        return 'swag_migration_data';
    }

    public static function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->setFlags(new PrimaryKey(), new Required()),
            new TenantIdField(),
            (new FkField('run_id', 'runId', SwagMigrationRunDefinition::class))->setFlags(new Required()),
            (new StringField('entity', 'entity'))->setFlags(new Required()),
            (new JsonField('raw', 'raw'))->setFlags(new Required()),
            new JsonField('converted', 'converted'),
            new JsonField('unmapped', 'unmapped'),
            new BoolField('written', 'written'),
            new CreatedAtField(),
            new UpdatedAtField(),
            new ManyToOneAssociationField('run', 'run_id', SwagMigrationRunDefinition::class, true),
        ]);
    }

    public static function getCollectionClass(): string
    {
        return SwagMigrationDataCollection::class;
    }

    public static function getStructClass(): string
    {
        return SwagMigrationDataStruct::class;
    }
}
