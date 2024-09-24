<?php

declare(strict_types=1);

namespace GridElementsTeam\Gridelements\PageLayoutView;

use GridElementsTeam\Gridelements\Backend\LayoutSetup;
use GridElementsTeam\Gridelements\Helper\Helper;
use GridElementsTeam\Gridelements\View\BackendLayout\Grid\GridelementsGridColumn;
use GridElementsTeam\Gridelements\View\BackendLayout\Grid\GridelementsGridColumnItem;
use TYPO3\CMS\Backend\Preview\PreviewRendererInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\Grid;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridColumnItem;
use TYPO3\CMS\Backend\View\BackendLayout\Grid\GridRow;
use TYPO3\CMS\Backend\View\Event\PagePreviewRenderingEvent;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\QueryGenerator;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\BackendConfigurationManager;
use TYPO3\CMS\Fluid\View\StandaloneView;
use Psr\EventDispatcher\EventDispatcherInterface;
use UnexpectedValueException;

class GridelementsPreviewRenderer implements PreviewRendererInterface
{
    protected array $extensionConfiguration;
    protected Helper $helper;
    protected IconFactory $iconFactory;
    protected LanguageService $languageService;
    protected array $languageHasTranslationsCache = [];
    protected QueryGenerator $tree;
    protected bool $showHidden;
    protected string $backPath = '';

    /**
     * @throws \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException
     * @throws \TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException
     */
    public function __construct()
    {
        $this->extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('gridelements');
        $this->helper = Helper::getInstance();
        $this->iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $this->cleanupCollapsedStatesInUC();
    }

    public function cleanupCollapsedStatesInUC(): void
    {
        $backendUser = $this->getBackendUser();
        if (!empty($backendUser->uc['moduleData']['page']['gridelementsCollapsedColumns']) &&
            is_array($backendUser->uc['moduleData']['page']['gridelementsCollapsedColumns'])) {
            $collapsedGridelementColumns = $backendUser->uc['moduleData']['page']['gridelementsCollapsedColumns'];
            foreach ($collapsedGridelementColumns as $item => $collapsed) {
                if (empty($collapsed)) {
                    unset($collapsedGridelementColumns[$item]);
                }
            }
            $backendUser->uc['moduleData']['page']['gridelementsCollapsedColumns'] = $collapsedGridelementColumns;
            $backendUser->writeUC($backendUser->uc);
        }
    }

    public function renderPageModulePreviewContent(GridColumnItem $item): string
    {
        // PSR-14 Event Dispatcher for preview rendering
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $event = new PagePreviewRenderingEvent($item->getContext(), $item->getRecord());
        $eventDispatcher->dispatch($event);

        if ($event->isPropagationStopped()) {
            return $event->getPreviewContent();
        }

        // Fluid template-based rendering fallback
        $fluidPreview = $this->renderContentElementPreviewFromFluidTemplate($item->getRecord());
        if ($fluidPreview !== null) {
            return $fluidPreview;
        }

        return $this->renderGridContainer($item);
    }

    protected function renderGridContainer(GridColumnItem $item): string
    {
        $context = $item->getContext();
        $record = $item->getRecord();
        $grid = GeneralUtility::makeInstance(Grid::class, $context);
        $helper = GeneralUtility::makeInstance(Helper::class);
        $gridContainerId = $record['uid'];
        $pageId = $record['pid'];
        $originalRecord = $pageId < 0 
            ? BackendUtility::getRecord('tt_content', $record['t3ver_oid']) 
            : $record;

        $layoutSetup = GeneralUtility::makeInstance(LayoutSetup::class)->init($originalRecord['pid']);
        $gridElement = $layoutSetup->cacheCurrentParent($gridContainerId, true);
        $layoutId = $gridElement['tx_gridelements_backend_layout'];
        $layout = $layoutSetup->getLayoutSetup($layoutId);
        $layoutColumns = $layoutSetup->getLayoutColumns($layoutId);
        $activeColumns = !empty($layoutColumns['CSV']) ? array_flip(GeneralUtility::intExplode(',', $layoutColumns['CSV'])) : [];

        if (isset($layout['config']['rows.'])) {
            $children = $helper->getChildren('tt_content', $gridContainerId, $pageId, 'sorting', 0, '*');
            $childColumns = [];
            foreach ($children as $childRecord) {
                if (isset($childRecord['tx_gridelements_columns'])) {
                    $childColumns[$childRecord['tx_gridelements_columns']][] = $childRecord;
                }
            }
            foreach ($layout['config']['rows.'] as $row) {
                $gridRow = GeneralUtility::makeInstance(GridRow::class, $context);
                if (isset($row['columns.'])) {
                    foreach ($row['columns.'] as $column) {
                        $gridColumn = GeneralUtility::makeInstance(GridelementsGridColumn::class, $context, $column, $gridContainerId);
                        $gridColumn->setRestrictions($layoutColumns);
                        if (isset($column['colPos']) && isset($activeColumns[$column['colPos']])) {
                            $gridColumn->setActive();
                        }
                        $gridRow->addColumn($gridColumn);
                        if (isset($column['colPos']) && isset($childColumns[$column['colPos']])) {
                            $gridColumn->setCollapsed(!empty($this->helper->getBackendUser()->uc['moduleData']['page']['gridelementsCollapsedColumns'][$gridContainerId . '_' . $column['colPos']]));
                            foreach ($childColumns[$column['colPos']] as $child) {
                                $gridColumnItem = GeneralUtility::makeInstance(GridelementsGridColumnItem::class, $context, $gridColumn, $child, $layoutColumns);
                                $gridColumn->addItem($gridColumnItem);
                            }
                        }
                    }
                }
                $grid->addRow($gridRow);
            }
        }

        // Fluid rendering of the grid container
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $configurationManager = GeneralUtility::makeInstance(BackendConfigurationManager::class);
        $configuration = $configurationManager->getConfiguration('gridelements');

        $view->setTemplate($configuration['backendContainer']['view']['defaultTemplate'] ?? '');
        $view->setLayoutRootPaths($configuration['backendContainer']['view']['layoutRootPaths'] ?? []);
        $view->setTemplateRootPaths($configuration['backendContainer']['view']['templateRootPaths'] ?? []);
        $view->setPartialRootPaths($configuration['backendContainer']['view']['partialRootPaths'] ?? []);

        $view->assignMultiple([
            'hideRestrictedColumns' => !empty(BackendUtility::getPagesTSconfig($context->getPageId())['mod.']['web_layout.']['hideRestrictedCols']),
            'newContentTitle' => $this->getLanguageService()->getLL('newContentElement'),
            'newContentTitleShort' => $this->getLanguageService()->getLL('content'),
            'allowEditContent' => $this->getBackendUser()->check('tables_modify', 'tt_content'),
            'gridElementsBackendLayout' => $layout,
            'gridElementsContainer' => $grid,
        ]);

        return $view->render();
    }

    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
