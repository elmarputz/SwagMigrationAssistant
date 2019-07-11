<?php declare(strict_types=1);

namespace SwagMigrationAssistant\Migration\Logging\Log;

abstract class BaseRunLogEntry implements LogEntryInterface
{
    /**
     * @var string
     */
    protected $runId;

    /**
     * @var ?string
     */
    protected $entity;

    /**
     * @var ?string
     */
    protected $sourceId;

    public function __construct(string $runId, ?string $entity = null, ?string $sourceId = null)
    {
        $this->runId = $runId;
        $this->entity = $entity;
        $this->sourceId = $sourceId;
    }

    public function getRunId(): ?string
    {
        return $this->runId;
    }

    public function getEntity(): ?string
    {
        return $this->entity;
    }

    public function getSourceId(): ?string
    {
        return $this->sourceId;
    }
}
