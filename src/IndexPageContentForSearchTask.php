<?php

namespace PlasticStudio\Search;

use SilverStripe\Dev\BuildTask;
use SilverStripe\View\SSViewer;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;

class IndexPageContentForSearchTask extends BuildTask
{
    protected $title = 'Index Page Content for Search';

    protected $description = 'Collate all page content from elements and save to a field for search. Add optional query string, "reindex=true" to reindex all pages across all subsites.';

    public function run($request)
    {
        $reindex = $request->getVar('reindex');
        $offset = $request->getVar('offset') ? (int)$request->getVar('offset') : 0;
        $limit = $request->getVar('limit') ? (int)$request->getVar('limit') : 10;

        // Store the original subsite ID to restore later
        $originalSubsiteID = Subsite::currentSubsiteID();

        // Fetch all subsites
        $subsites = Subsite::get();

        // Initialize an ArrayList to hold all sites, including the main site
        $allSites = new ArrayList();

        // Create a representation of the main site (ID = 0)
        $mainSubsite = Injector::inst()->create(Subsite::class);
        $mainSubsite->ID = 0;
        $mainSubsite->Title = 'Main Site'; // Optional: Set a title for clarity
        $allSites->push($mainSubsite);

        // Add all existing subsites to the ArrayList
        if ($subsites->exists()) {
            foreach ($subsites as $subsite) {
                $allSites->push($subsite);
            }
        }

        echo 'Starting reindexing process for all subsites, including the Main Site...<br />';

        foreach ($allSites as $subsite) {
            // Change context to the current subsite
            Subsite::changeSubsite($subsite->ID);
            echo "<h2>Reindexing Subsite: {$subsite->Title} (ID: {$subsite->ID})</h2>";

            // Retrieve SiteTree items for the current subsite with pagination
            $items = SiteTree::get()->limit($limit, $offset);
            echo 'Limit: ' . $limit . '<br />';
            echo 'Offset: ' . $offset . '<br />';

            if (!$reindex) {
                $items = $items->filter(['ElementalSearchContent' => null]);
                echo 'Generating first index for new items...<br />';
            }

            if (!$items->count()) {
                echo 'No items to update in this subsite.<br />';
            } else {
                foreach ($items as $item) {
                    // Get the page content as plain content string
                    $content = $this->collateSearchContent($item);

                    // Update this item in the database
                    $update = SQLUpdate::create();
                    $update->setTable('"SiteTree"');
                    $update->addWhere(['ID' => $item->ID]);
                    $update->addAssignments([
                        '"ElementalSearchContent"' => $content
                    ]);
                    $update->execute();

                    // If the page is published, update the live table
                    if ($item->isPublished()) {
                        $update = SQLUpdate::create();
                        $update->setTable('"SiteTree_Live"');
                        $update->addWhere(['ID' => $item->ID]);
                        $update->addAssignments([
                            '"ElementalSearchContent"' => $content
                        ]);
                        $update->execute();
                    }

                    echo '<p>Page "' . $item->Title . '" indexed.</p>' . PHP_EOL;
                }
            }

            echo '<hr />';
        }

        // Restore the original subsite context
        Subsite::changeSubsite($originalSubsiteID);
        echo 'Reindexing process completed for all subsites, including the Main Site.<br />';
    }

    /**
     * Generate the search content to use for the searchable object
     *
     * We just retrieve it from the templates.
     */
    private function collateSearchContent($page): string
    {
        // Get the page content
        $content = '';

        if (self::isElementalPage($page)) {
            // Get the page's elemental content
            $content .= $this->collateSearchContentFromElements($page);
        }

        return $content;
    }

    /**
     * Determine if the page has the ElementalPageExtension
     *
     * @param SiteTree $page
     * @return bool
     */
    private static function isElementalPage($page)
    {
        return $page->hasExtension("DNADesign\Elemental\Extensions\ElementalPageExtension");
    }

    /**
     * Collate search content from Elemental elements
     *
     * @param SiteTree $page
     * @return string
     */
    private function collateSearchContentFromElements($page)
    {
        // Get the original themes
        $originalThemes = SSViewer::get_themes();

        // Initialize content
        $content = '';

        try {
            // Enable frontend themes to correctly render the elements as they would for the frontend
            Config::nest();
            SSViewer::set_themes(SSViewer::config()->get('themes'));

            // Get the elements' content
            $content .= $page->getElementsForSearch();

            // Clean up the content
            $content = preg_replace('/\s+/', ' ', $content);

            // Return themes back for the CMS
            Config::unnest();
        } finally {
            // Restore original themes
            SSViewer::set_themes($originalThemes);
        }

        return $content;
    }
}
