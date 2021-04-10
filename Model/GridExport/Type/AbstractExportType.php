<?php declare(strict_types=1);

namespace Hyva\Admin\Model\GridExport\Type;

use function array_map as map;

use Hyva\Admin\Model\GridExport\ExportTypeInterface;
use Hyva\Admin\ViewModel\HyvaGrid\ColumnDefinitionInterface;
use Hyva\Admin\ViewModel\HyvaGridInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

abstract class AbstractExportType implements ExportTypeInterface
{
    /**
     * @var HyvaGridInterface
     */
    private $grid;

    /**
     * @var string
     */
    private $fileName;

    /**
     * @var string
     */
    private $contentType = 'application/octet-stream';

    public function __construct(HyvaGridInterface $grid, string $fileName)
    {
        $this->grid = $grid;
        if ($fileName) {
            $this->fileName = $fileName;
        }
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getExportDir(): string
    {
        return DirectoryList::VAR_DIR;
    }

    protected function getGrid(): HyvaGridInterface
    {
        return $this->grid;
    }

    protected function getHeaderData(): array
    {
        return map(function (ColumnDefinitionInterface $column): string {
            return $column->getLabel();
        }, $this->grid->getColumnDefinitions());
    }

    protected function iterateGrid()
    {
        $searchCriteria = $this->grid->getSearchCriteria();
        $searchCriteria->setPageSize(200);
        $searchCriteria->setCurrentPage(1);
        $current = 0;
        do {
            foreach ($this->grid->getRowsForSearchCriteria($searchCriteria) as $row) {
                yield $current++ => $row;
            }
            $searchCriteria->setCurrentPage($searchCriteria->getCurrentPage() + 1);
        } while ($current < $this->grid->getTotalRowsCount());
    }

    abstract public function createFileToDownload(): void;
}
