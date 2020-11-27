<?php declare(strict_types=1);

namespace Hyva\Admin\Model;

use Hyva\Admin\ViewModel\HyvaGrid\ColumnDefinitionInterface;
use Hyva\Admin\ViewModel\HyvaGrid\ColumnDefinitionInterfaceFactory;

use Magento\Framework\Api\SearchCriteriaInterface;
use function array_combine as zip;
use function array_filter as filter;
use function array_keys as keys;
use function array_map as map;
use function array_merge as merge;
use function array_reduce as reduce;
use function array_slice as slice;
use function array_values as values;

class GridSource implements HyvaGridSourceInterface
{
    private GridSourceType\GridSourceTypeInterface $gridSourceType;

    private ColumnDefinitionInterfaceFactory $columnDefinitionFactory;

    private RawGridSourceContainer $rawGridData;

    public function __construct(
        GridSourceType\GridSourceTypeInterface $gridSourceType,
        ColumnDefinitionInterfaceFactory $columnDefinitionFactory
    ) {
        $this->gridSourceType          = $gridSourceType;
        $this->columnDefinitionFactory = $columnDefinitionFactory;
    }

    /**
     * @param ColumnDefinitionInterface[] $includedColumns
     * @param bool $keepAllSourceCols
     * @return ColumnDefinitionInterface[]
     */
    public function extractColumnDefinitions(array $includedColumns, bool $keepAllSourceCols = false): array
    {
        // Algorithm defining the sortOrder on grids:
        // 1. add sortOrder (larger than others) to all configured include columns without a specific sortOrder (pass 1)
        // 2. add sortOrder (larger than others) to all extracted columns that where not included (pass 2)
        // 3. sort columns (maybe in grid view model...?)

        $includedColumnsWithSortOrder = $this->addMissingSortOrder($includedColumns);

        $configuredKeys                = $this->extractKeys(...values($includedColumnsWithSortOrder));
        $mapKeyToDefinitions           = zip($configuredKeys, values($includedColumnsWithSortOrder));
        $availableColumnKeysFromSource = $this->gridSourceType->getColumnKeys();

        $this->validateConfiguredKeys($configuredKeys, $availableColumnKeysFromSource);

        $columnKeys = empty($mapKeyToDefinitions) || $keepAllSourceCols
            ? $availableColumnKeysFromSource
            : $configuredKeys;

        $extractedColumns = map(function (string $key) use ($mapKeyToDefinitions): ColumnDefinitionInterface {
            $extractedDefinition = $this->gridSourceType->getColumnDefinition($key);
            return $this->mergeColumnDefinitions($extractedDefinition, $mapKeyToDefinitions[$key] ?? null);
        }, zip($columnKeys, $columnKeys));

        $extractedColumnsWithSortOrder = $this->addMissingSortOrder($extractedColumns);
        return $this->sortColumns($extractedColumnsWithSortOrder);
    }

    /**
     * @param ColumnDefinitionInterface ...$columnDefinitions
     * @return string[]
     */
    private function extractKeys(ColumnDefinitionInterface ...$columnDefinitions): array
    {
        return map(function (ColumnDefinitionInterface $columnDefinition): string {
            return $columnDefinition->getKey();
        }, $columnDefinitions);
    }

    private function validateConfiguredKeys(array $configuredKeys, array $availableColumnKeysFromSource): void
    {
        if ($missing = array_diff($configuredKeys, $availableColumnKeysFromSource)) {
            throw new \OutOfBoundsException(sprintf('Column(s) not found on source: %s', implode(', ', $missing)));
        }
    }

    private function mergeColumnDefinitions(
        ColumnDefinitionInterface $columnA,
        ?ColumnDefinitionInterface $columnB
    ): ColumnDefinitionInterface {
        return $columnB
            ? $this->columnDefinitionFactory->create(merge($columnA->toArray(), filter($columnB->toArray())))
            : $columnA;
    }

    public function getRecords(SearchCriteriaInterface $searchCriteria): array
    {
        return $this->gridSourceType->extractRecords($this->getRawGridData($searchCriteria));
    }

    public function extractValue($record, string $key)
    {
        return $this->gridSourceType->extractValue($record, $key);
    }

    private function getRawGridData(SearchCriteriaInterface $searchCriteria): RawGridSourceContainer
    {
        if (!isset($this->rawGridData)) {
            $this->rawGridData = $this->gridSourceType->fetchData($searchCriteria);
        }
        return $this->rawGridData;
    }

    public function getTotalCount(SearchCriteriaInterface $searchCriteria): int
    {
        return $this->gridSourceType->extractTotalRowCount($this->getRawGridData($searchCriteria));
    }

    /**
     * @param ColumnDefinitionInterface[] $includeConfig
     * @return int
     */
    private function getMaxSortOrder(array $includeConfig): int
    {
        return reduce($includeConfig, function (int $maxSortOrder, ColumnDefinitionInterface $column): int {
            return max($maxSortOrder, $column->getSortOrder());
        }, 0);
    }

    /**
     * Add sortOrder to all columns that don't have a sortOrder already
     *
     * The generated sortOrder values are larger than the largest specified sortOrder.
     *
     * @param ColumnDefinitionInterface[] $columns
     * @return ColumnDefinitionInterface[]
     */
    private function addMissingSortOrder(array $columns): array
    {
        $currentMaxSortOrder = $this->getMaxSortOrder($columns);
        $nextSortOrders      = range($currentMaxSortOrder + 1, $currentMaxSortOrder + count($columns));
        $columnsWithSortOrder       = map(
            function (ColumnDefinitionInterface $column, int $nextSortOrder): ColumnDefinitionInterface {
                $sortOrder = $column->getSortOrder() ? $column->getSortOrder() : (string) $nextSortOrder;
                return $this->columnDefinitionFactory->create(merge($column->toArray(), ['sortOrder' => $sortOrder]));
            },
            $columns,
            slice($nextSortOrders, 0, count($columns))
        );
        return zip(keys($columns), $columnsWithSortOrder);
    }

    /**
     * @param ColumnDefinitionInterface[] $columns
     * @return ColumnDefinitionInterface[]
     */
    private function sortColumns(array $columns): array
    {
        uasort($columns, function (ColumnDefinitionInterface $a, ColumnDefinitionInterface $b) {
            return $a->getSortOrder() <=> $b->getSortOrder();
        });

        return $columns;
    }
}
