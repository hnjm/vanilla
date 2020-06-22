<?php
/**
 * @author Adam Charron <adam.c@vanillaforums.com>
 * @copyright 2009-2020 Vanilla Forums Inc.
 * @license GPL-2.0-only
 */

namespace Vanilla\Forum\Search;

use Garden\Schema\Schema;
use Garden\Web\Exception\HttpException;
use Vanilla\Adapters\SphinxClient;
use Vanilla\Exception\PermissionException;
use Vanilla\Forum\Navigation\ForumCategoryRecordType;
use Vanilla\Navigation\BreadcrumbModel;
use Vanilla\Search\MysqlSearchQuery;
use Vanilla\Search\SearchQuery;
use Vanilla\Search\AbstractSearchType;
use Vanilla\Search\SearchResultItem;
use Vanilla\Sphinx\Search\SphinxSearchQuery;
use Vanilla\Utility\ArrayUtils;

/**
 * Search record type for a discussion.
 */
class DiscussionSearchType extends AbstractSearchType {

    /** @var \DiscussionsApiController */
    protected $discussionsApi;

    /** @var \CategoryModel */
    protected $categoryModel;

    /** @var \TagModel */
    protected $tagModel;

    /** @var BreadcrumbModel */
    protected $breadcrumbModel;

    /**
     * DI.
     *
     * @param \DiscussionsApiController $discussionsApi
     * @param \CategoryModel $categoryModel
     * @param \TagModel $tagModel
     * @param BreadcrumbModel $breadcrumbModel
     */
    public function __construct(
        \DiscussionsApiController $discussionsApi,
        \CategoryModel $categoryModel,
        \TagModel $tagModel,
        BreadcrumbModel $breadcrumbModel
    ) {
        $this->discussionsApi = $discussionsApi;
        $this->categoryModel = $categoryModel;
        $this->tagModel = $tagModel;
        $this->breadcrumbModel = $breadcrumbModel;
    }


    /**
     * @inheritdoc
     */
    public function getKey(): string {
        return 'discussion';
    }

    /**
     * @inheritdoc
     */
    public function getSearchGroup(): string {
        return 'discussion';
    }

    /**
     * @inheritdoc
     */
    public function getType(): string {
        return 'discussion';
    }

    /**
     * @inheritdoc
     */
    public function getResultItems(array $recordIDs): array {
        try {
            $results = $this->discussionsApi->index([
                'discussionID' => implode(",", $recordIDs),
                'limit' => 100,
            ]);
            $results = $results->getData();

            $resultItems = array_map(function ($result) {
                $mapped = ArrayUtils::remapProperties($result, [
                    'recordID' => 'discussionID',
                ]);
                $mapped['recordType'] = $this->getSearchGroup();
                $mapped['type'] = $this->getType();
                $mapped['breadcrumbs'] = $this->breadcrumbModel->getForRecord(new ForumCategoryRecordType($mapped['categoryID']));
                return new SearchResultItem($mapped);
            }, $results);
            return $resultItems;
        } catch (HttpException $exception) {
            trigger_error($exception->getMessage(), E_USER_WARNING);
            return [];
        }
    }

    /**
     * @inheritdoc
     */
    public function applyToQuery(SearchQuery $query) {
        $types = $query->getQueryParameter('types');
        if ((count($types) > 0) && !in_array($this->getType(), $types)) {
            // discussions are not the part of this search query request
            // we don't need to do anything
            return;
        }
        // Notably includes 0 to still allow other normalized records if set.
        $tagNames = $query->getQueryParameter('tags', []);
        $tagIDs = $this->tagModel->getTagIDsByName($tagNames);
        $tagOp = $query->getQueryParameter('tagOperator', 'or');
        ;

        if ($query instanceof SphinxSearchQuery) {
            // TODO: Figure out the ideal time to do this.
            // Make sure we don't get duplicate discussion results.
            // $query->setGroupBy('DiscussionID', SphinxClient::GROUPBY_ATTR, 'sort DESC');
            // Always set.
            // discussionID
            if ($discussionID = $query->getQueryParameter('discussionID', false)) {
                $query->setFilter('DiscussionID', [$discussionID]);
            };
            $categoryIDs = $this->getCategoryIDs($query);
            if (!empty($categoryIDs)) {
                $query->setFilter('CategoryID', $categoryIDs);
            } else {
                // Only include non-category content.
                $query->setFilter('CategoryID', [0]);
            }

            // tags
            if (!empty($tagIDs)) {
                $query->setFilter('Tags', $tagIDs, false, $tagOp);
            }
        } elseif ($query instanceof MysqlSearchQuery) {
            $query->addSql($this->generateSql($query));
        }
    }

    /**
     * @inheritdoc
     */
    public function getSorts(): array {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getQuerySchema(): Schema {
        return $this->schemaWithTypes(Schema::parse([
            'discussionID:i?' => [
                'x-search-scope' => true,
            ],
            'categoryID:i?' => [
                'x-search-scope' => true,
            ],
            'followedCategories:b?' => [
                'x-search-filter' => true,
            ],
            'includeChildCategories:b?' => [
                'x-search-filter' => true,
            ],
            'includeArchivedCategories:b?' => [
                'x-search-filter' => true,
            ],
            'tags:a?' => [
                'items' => [
                    'type' => 'string',
                ],
                'x-search-filter' => true,
            ],
            'tagOperator:s?' => [
                'items' => [
                    'type' => 'string',
                    'enum' => [SearchQuery::FILTER_OP_OR, SearchQuery::FILTER_OP_AND],
                ],
            ],
        ]));
    }

    /**
     * @inheritdoc
     */
    public function validateQuery(SearchQuery $query): void {
        // Validate category IDs.
        $categoryID = $query->getQueryParameter('categoryID', null);
        if ($categoryID !== null && !$this->categoryModel::checkPermission($categoryID, 'Vanilla.Discussions.View')) {
            throw new PermissionException('Vanilla.Discussions.View');
        }
    }

    public function generateSql(MysqlSearchQuery $query): string {
        /** @var \Gdn_SQLDriver $db */
        $db = $query->getDB();
        $db->reset();

        $categoryIDs = $this->getCategoryIDs($query);

        $db->reset();

        // Build base query
        $db->from('Discussion d')
            ->select('d.DiscussionID as PrimaryID, d.Name as Title, d.Body as Summary, d.Format, d.CategoryID, d.Score')
            ->select('d.DiscussionID', "concat('/discussion/', %s)", 'Url')
            ->select('d.DateInserted')
            ->select('d.Type')
            ->select('d.InsertUserID as UserID')
            ->select("'Discussion'", '', 'RecordType')
            ->orderBy('d.DateInserted', 'desc')
        ;

        $terms = $query->get('query', false);
        if ($terms) {
            $terms = $db->quote('%'.str_replace(['%', '_'], ['\%', '\_'], $terms).'%');
            $db->beginWhereGroup();
            foreach (['d.Name', 'd.Body'] as $field) {
                $db->orWhere("$field like", $terms, false, false);
            }
            $db->endWhereGroup();
        }

        if ($title = $query->get('title', false)) {
            $db->where('d.Name like', $db->quote('%'.str_replace(['%', '_'], ['\%', '\_'], $title).'%'));
        }

        if ($users = $query->get('users', false)) {
            $author = array_column($users, 'UserID');
            $db->where('d.InsertUserID', $author);
        }

        if ($discussionID = $query->get('discussionid', false)) {
            $db->where('d.DiscussionID', $discussionID);
        }

        if (!empty($categoryIDs)) {
            $db->whereIn('d.CategoryID', $categoryIDs);
        }

        $limit = $query->get('limit', 100);
        $offset = $query->get('offset', 0);
        $db->limit($limit + $offset);

        $sql = $db->getSelect();
        $db->reset();

        return $sql;
    }

    protected function getCategoryIDs(SearchQuery $query): array {
        $categoryIDs = $this->categoryModel->getSearchCategoryIDs(
            $query->getQueryParameter('categoryID'),
            $query->getQueryParameter('followedCategories'),
            $query->getQueryParameter('includeChildCategories'),
            $query->getQueryParameter('includeArchivedCategories')
        );
        return $categoryIDs;
    }
}
