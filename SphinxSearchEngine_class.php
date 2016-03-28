<?php

/**
 * Pluggable Sphinx search engine for MediaWiki 1.16+ and Sphinx 0.99+ (real-time indexing)
 * http://wiki.4intra.net/SphinxSearchEngine
 * (c) 2011-2013, Vitaliy Filippov
 * License: GNU GPL 3.0 or later (see http://www.fsf.org/licenses/gpl.html)
 */

class SphinxSearchEngine extends SearchEngine
{
    // Form parameters - enabled only on Special:Search
    var $isFormRequest = false;

    // Search order
    var $orderBy = 'weight';
    var $orderSort = 'desc';
    static $allowOrderByFields = array('weight' => true, 'date_insert' => true, 'date_modify' => true);

    // Category list
    var $categoryList = array();
    var $selCategoryList = array();

    function __construct($db)
    {
        global $wgSphinxQL_host, $wgSphinxQL_port, $wgSphinxQL_index, $wgSphinxSE_port;
        if ($db)
            $this->db = $db;
        else
            $this->db = wfGetDB(DB_SLAVE);
        if ($this->db->getType() != 'mysql')
            $wgSphinxSE_port = NULL;
        $this->sphinx = new SphinxQLClient($wgSphinxQL_host.':'.$wgSphinxQL_port);
        $this->index = $wgSphinxQL_index;
        $this->getSearchFormValues();
    }

    // This breaks the incapsulation principle, but is the easiest way to improve Special:Search
    function getSearchFormValues()
    {
        global $wgRequest, $wgTitle;
        $this->isFormRequest = $wgTitle && $wgTitle->getPrefixedText() == SpecialPage::getTitleFor('Search')->getPrefixedText();
        if ($this->isFormRequest)
        {
            // Get used category from request
            $this->selCategoryList = $wgRequest->getArray('category');

            // Get sort order from request
            $order = $wgRequest->getVal('orderBy');
            if (isset(self::$allowOrderByFields[$order]))
            {
                $this->orderBy = $order;
            }
            $sort = strtolower($wgRequest->getVal('sort'));
            if ($sort === 'asc' || $sort === 'desc')
            {
                $this->orderSort = $sort;
            }
        }
    }

    function searchTitle($term)
    {
        return NULL;
    }

    function acceptListRedirects()
    {
        return false;
    }

    static function setupEngine($special, $profile, $search)
    {
        global $wgRequest;
        $special->setExtraParam('category', $wgRequest->getArray('category'));
        $special->setExtraParam('orderBy', $wgRequest->getVal('orderBy'));
        return true;
    }

    // Main search function
    function searchText($term)
    {
        global $wgSphinxQL_weights, $wgSphinxSE_port;

        // don't do anything for blank searches
        if (!preg_match('/[\w\pL\d]/u', $term))
        {
            return new SearchResultSet();
        }

        if ($this->isFormRequest)
        {
            // Get category list from search result
            $this->getCategoryList($term);
        }

        $sTerm = $this->filter($term);
        if ($this->selCategoryList)
        {
            // Search in selected categories
            $sTerm .= ' @category_search "'.implode('"|"', self::categoryForSearch($this->selCategoryList)).'"';
        }

        if (!$wgSphinxSE_port)
        {
            $query = 'SELECT *, WEIGHT() `weight` FROM '.$this->index.' WHERE MATCH(?)';
            if ($this->namespaces)
            {
                $query .= ' AND namespace IN ('.implode(',', $this->namespaces).')';
            }
            $query .= ' ORDER BY `'.$this->orderBy.'` '.$this->orderSort;
            $query .= ' LIMIT '.$this->offset.', '.$this->limit;
            $query .= ' OPTION ranker=expr(\'sum(lcs*user_weight)/max_lcs*2000+bm25+1000*pow(max(1-(now()-(date_insert+date_modify)/2)/47304000, 0), 4)\')';
            if ($wgSphinxQL_weights)
            {
                $ws = $wgSphinxQL_weights;
                foreach ($ws as $k => &$w)
                {
                    $w = "$k=".intval($w);
                }
                unset($w);
                $query .= ', field_weights=('.implode(', ', $ws).')';
            }
            $sphinxRows = $this->sphinx->select($query, 'id', array($sTerm));
            if ($sphinxRows === NULL)
            {
                global $wgOut;
                wfDebug("[ERROR] ".__METHOD__.": ".$this->sphinx->error());
                $wgOut->addWikiText("Query failed: " . $this->sphinx->error() . "\n");
                return NULL;
            }
            // Fetch statistics using SphinxQL
            $meta = $this->sphinx->select('SHOW META', 0);
            foreach ($meta as &$m)
            {
                $m = $m[1];
            }
        }

        $dbRows = array();
        $total = NULL;
        if ($wgSphinxSE_port || $sphinxRows)
        {
            $group_cats = $this->db->getType() == 'postgres' ? 'array_to_string(array_agg(cl_to), \',\')' : 'GROUP_CONCAT(cl_to SEPARATOR \',\')';
            $query = array(
                'tables' => array('page', 'revision', 'text', 'categorylinks'),
                'fields' => array('page.*', 'old_text', 'category' => $group_cats),
                'conds' => array(),
                'join_conds' => array(
                    'revision'      => array('INNER JOIN', array('rev_id=page_latest')),
                    'text'          => array('INNER JOIN', array('old_id=rev_text_id')),
                    'categorylinks' => array('LEFT JOIN', array('cl_from=page_id')),
                ),
                'options' => array('GROUP BY' => 'page_id, old_id', 'SQL_CALC_FOUND_ROWS'),
            );
            $maxScore = $this->getMaxScore($term);
            if ($wgSphinxSE_port)
            {
                $startTime = microtime(true);
                $seQuery = '';
                if ($wgSphinxQL_weights)
                {
                    foreach ($wgSphinxQL_weights as $k => $w)
                    {
                        $seQuery .= ",$k,".intval($w);
                    }
                    $seQuery = ';fieldweights='.substr($seQuery, 1);
                }
                $seQuery = str_replace(array(';', '='), array('\\\\;', '\\\\='), $sTerm).';mode=extended;limit=1000'.$seQuery;
                $seQuery .= ';ranker=expr:sum(lcs*user_weight)/max_lcs*2000+bm25+1000*pow(max(1-(now()-(date_insert+date_modify)/2)/47304000, 0), 4)';
                $query['tables'][] = 'sphinx_page';
                $query['fields'][] = 'sphinx_page.weight/'.$maxScore.' score';
                $query['join_conds']['page'] = array('INNER JOIN', array('page_id=sphinx_page.id'));
                // Strange escaping issues with SphinxSE... double escaping needed...
                $query['conds']['sphinx_page.query'] = addslashes($seQuery);
                if ($this->namespaces)
                {
                    $query['conds']['sphinx_page.namespace'] = $this->namespaces;
                }
                $query['options']['ORDER BY'] = 'sphinx_page.'.$this->orderBy.' '.$this->orderSort;
                $query['options']['LIMIT'] = $this->limit;
                $query['options']['OFFSET'] = $this->offset;
                // IntraACL DBMS-side filtering compatibility hook (for correct pagination)
                wfRunHooks('FilterPageQuery', array(&$query, 'page', NULL, NULL));
            }
            else
            {
                $query['conds']['page_id'] = array_keys($sphinxRows);
                $sortkey = array_flip(array_keys($sphinxRows));
            }
            $res = $this->db->select(
                $query['tables'], $query['fields'], $query['conds'],
                __METHOD__, $query['options'], $query['join_conds']
            );
            if ($wgSphinxSE_port)
            {
                foreach ($res as $row)
                {
                    $dbRows[] = $row;
                }
                $total = $this->db->selectField(NULL, 'FOUND_ROWS()', NULL);
                // Fetch statistics using SphinxSE
                $res = $this->db->query('SHOW STATUS LIKE \'sphinx_%\'');
                $meta = array();
                $k = 0;
                foreach ($res as $row)
                {
                    if ($row->Variable_name == 'Sphinx_words' && $row->Value)
                    {
                        foreach (explode(' ', $row->Value) as $p)
                        {
                            list($meta["keyword[$k]"], $meta["docs[$k]"], $meta["hits[$k]"]) = explode(':', $p, 3);
                            $k++;
                        }
                    }
                    else
                    {
                        $meta[substr($row->Variable_name, 7)] = $row->Value;
                    }
                }
                $meta['time'] = microtime(true)-$startTime;
            }
            else
            {
                foreach ($res as $row)
                {
                    $row->score = isset($sphinxRows[$row->page_id]['weight']) ? $sphinxRows[$row->page_id]['weight'] / $maxScore : NULL;
                    $dbRows[$sortkey[$row->page_id]] = $row;
                }
                $dbRows = array_values($dbRows);
            }
            // Build excerpts
            $this->buildExcerpts($dbRows, $this->index, $term);
        }
        $res = new SphinxSearchResultSet(
            $this->db, $term, $this->offset, $this->limit,
            $this->namespaces, $dbRows, $meta, $this->index, $this->isFormRequest,
            $this->categoryList, $this->selCategoryList, $this->orderBy, $this->orderSort, $total
        );
        Hooks::register('SpecialSearchResults', array($res, 'addInfo'));
        return $res;
    }

    function buildExcerpts(&$dbRows, $index, $term)
    {
        global $wgSphinxQL_ExcerptsOptions;
        $snip_query = 'CALL SNIPPETS(?, ?, ?';
        $options = array(
            'before_match' => "\x01",
            'after_match' => "\x02",
            'html_strip_mode' => 'none',
        ) + $wgSphinxQL_ExcerptsOptions;
        foreach ($options as $k => $v)
        {
            $snip_query .= ", ".$this->sphinx->quote($v)." AS $k";
        }
        $snip_query .= ')';
        foreach ($dbRows as &$row)
        {
            $excerpts = $this->sphinx->select($snip_query, NULL, array($row->old_text, $index, $term));
            if (!$excerpts)
            {
                $excerpts = array("ERROR: " . $this->sphinx->error(false));
            }
            foreach ($excerpts as &$entry)
            {
                // add excerpt to output, escape HTML
                if (is_array($entry))
                    $entry = $entry[0];
                $entry = htmlspecialchars($entry);
                $entry = preg_replace("/\n\s*/", "<br />", str_replace(
                    array("\x01", "\x02"),
                    array($wgSphinxQL_ExcerptsOptions['before_match'], $wgSphinxQL_ExcerptsOptions['after_match']),
                    $entry
                ));
                $entry = "<div style='margin: 0 0 0.2em 1em;'>$entry</div>\n";
            }
            $row->excerpts = implode("", $excerpts);
            unset($row->old_text);
        }
    }

    /**
     * Try our best to normalize scores - calculate maximum Sphinx score for a given search query
     * Max score for SPH_RANK_PROXIMITY_BM25 = num_keywords * sum(field_weights) * 1000 + 999
     */
    function getMaxScore($term)
    {
        global $wgSphinxQL_weights;
        preg_match_all('/[\w\pL\d]+/u', $term, $m, PREG_SET_ORDER);
        $maxScore = 0;
        foreach ($wgSphinxQL_weights as $w)
        {
            $maxScore += $w;
        }
        $maxScore = count($m) * $maxScore * 1000 + 999;
        if ($this->selCategoryList)
        {
            $maxScore += $wgSphinxQL_weights['category'] * count($this->selCategoryList);
        }
        return $maxScore;
    }

    /**
     * Get category list from search query
     *
     * @param $term Search string
     */
    function getCategoryList($term)
    {
        $rows = $this->sphinx->select(
            'SELECT id, category FROM '.$this->index.' WHERE MATCH(?) GROUP BY category LIMIT 1000',
            'id', array($this->filter($term))
        );
        foreach ($rows ?: array() as $row)
        {
            foreach (explode('|', $row['category']) as $c)
            {
                if ($c !== '')
                {
                    $this->categoryList[$c] = true;
                }
            }
        }
        if (!empty($this->categoryList))
        {
            $this->categoryList = array_keys($this->categoryList);
        }
    }

    // Filter $text - escape search query
    function filter($text)
    {
        $pattern_part = '\[\]:\(\)!@~&\/^$';
        $text = trim($text);
        $text = preg_replace('/^[|\-='.$pattern_part.']+|[|\-='.$pattern_part.']+$/', '', $text); // Erase special chars in the beginning and at the end of query
        if (substr_count($text, '"') % 2) // Search for double chars " and check them
        {
            $pattern_part .= '"';
        }
        $text = preg_replace('/(?<=[\s\-])-(?=[\s\-])/', '\\-', $text);
        return preg_replace('/((?<!\\\\)(?:\\\\\\\\)*)(['.$pattern_part.'])/', '\1\\\\\2', $text);
    }

    // Format category list for indexing
    public static function categoryForSearch($categories)
    {
        if (!$categories)
        {
            return array('_empty_');
        }
        foreach ($categories as &$c)
        {
            $c = $c === '' ? '_empty_' : '__'.preg_replace("/[^\w\x7F-\xFF]/", "_", $c).'__';
        }
        return $categories;
    }

    // Updates an index entry
    function update($id, $title, $text)
    {
        $dbr = wfGetDB(DB_SLAVE);
        $res = $dbr->select('categorylinks', 'cl_to', array('cl_from' => $id), __METHOD__);
        $cat = array();
        foreach ($res as $row)
        {
            $cat[] = $row->cl_to;
        }
        $cat_search = implode('|', self::categoryForSearch($cat));
        $cat = str_replace('_', ' ', implode('|', $cat));
        $date_insert = $dbr->selectRow('revision', 'MIN(rev_timestamp) min_ts, MAX(rev_timestamp) max_ts', array('rev_page' => $id), __METHOD__);
        $date_modify = wfTimestamp(TS_UNIX, $date_insert->max_ts);
        $date_insert = wfTimestamp(TS_UNIX, $date_insert->min_ts);
        if (!$this->sphinx->query('REPLACE INTO '.$this->index.
                ' (id, namespace, title, text, category, category_search, date_insert, date_modify) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array($id, $title->getNamespace(), $title->getText(), $text, $cat, $cat_search, $date_insert, $date_modify)
            ))
        {
            // Log the error
            wfDebug("[ERROR] ".__METHOD__.": ".$this->sphinx->error()."\n");
        }
    }

    // Deletes an index entry
    function delete($id, $title)
    {
        if (!$this->sphinx->query('DELETE FROM '.$this->index.' WHERE id=?', array($id)))
        {
            // Log the error
            wfDebug("[ERROR] ".__METHOD__.": ".$this->sphinx->error()."\n");
        }
    }

    // Fills the Sphinx index with all current texts from the DB
    function build_index()
    {
        fwrite(STDERR, "Filling the Sphinx full-text index...\n");
        $ids = array();
        $res = $this->db->select(
            array('page', 'revision', 'text', 'categorylinks', 'rev' => 'revision'),
                'page_id, page_namespace, page_title, old_text, GROUP_CONCAT(DISTINCT cl_to SEPARATOR \'|\') category,
                MIN(rev.rev_timestamp) as date_insert, revision.rev_timestamp as date_modify',
            array('1'),
            __METHOD__,
            array('GROUP BY' => 'page_id'),
            array(
                'revision'      => array('INNER JOIN', array('rev_id=page_latest')),
                'text'          => array('INNER JOIN', array('old_id=rev_text_id')),
                'categorylinks' => array('LEFT JOIN', array('cl_from=page_id')),
                'rev' => array('LEFT JOIN', array('rev.rev_page=page_id')),
            )
        );
        $cur = array();
        $total = $res->numRows();
        $query_size = 0;
        foreach ($res as $i => $row)
        {
            $row->page_title = str_replace('_', ' ', $row->page_title);
            $row->category_search = implode('|', self::categoryForSearch(explode('|', $row->category)));
            $row->category = str_replace('_', ' ', $row->category);
            $row->date_insert = wfTimestamp(TS_UNIX, $row->date_insert);
            $row->date_modify = wfTimestamp(TS_UNIX, $row->date_modify);
            // Trigger SearchUpdate and allow extensions to change indexed text
            wfRunHooks('SearchUpdate', array($row->page_id, $row->page_namespace, $row->page_title, &$row->old_text));
            foreach (array('page_id', 'page_namespace', 'page_title', 'old_text', 'category', 'category_search', 'date_insert', 'date_modify') as $key)
            {
                // 2MB limit for field length
                $cur[] = substr($row->$key, 0, 2*1024*1024);
                $query_size += strlen($row->$key);
            }
            // Sphinx max_packet is 8MB by default, so use 6MB for partial query
            if (count($cur) >= 256*8 || $query_size > 7*1024*1024 || $i >= $total)
            {
                fwrite(STDERR, "\r$i / $total...");
                fflush(STDERR);
                $q = 'REPLACE INTO '.$this->index.
                    ' (id, namespace, title, text, category, category_search, date_insert, date_modify) VALUES '.
                    substr(str_repeat(', (?, ?, ?, ?, ?, ?, ?, ?)', count($cur)/8), 2);
                if (!$this->sphinx->query($q, $cur))
                {
                    // Print error
                    $msg = "[ERROR] ".__METHOD__.": ".$this->sphinx->error()."\n";
                    wfDebug($msg);
                    print($msg);
                }
                $cur = array();
                $query_size = 0;
            }
        }
        fwrite(STDERR, "\n");
    }

    // For maintenance: remove pages which are not in the DB any more, from the index
    function purge_deleted()
    {
        $lastid = 0;
        while (1)
        {
            $ids = $this->sphinx->select('SELECT id FROM '.$this->index.' WHERE id > '.$lastid.' LIMIT 100000');
            if (!$ids)
            {
                break;
            }
            foreach ($ids as &$id)
            {
                $id = $id[0];
            }
            $lastid = $ids[count($ids)-1];
            $res = $this->db->select('page', 'page_id', array('page_id' => $ids), __METHOD__);
            $deleted = array_flip($ids);
            foreach ($res as $row)
            {
                unset($deleted[$row->page_id]);
            }
            if ($deleted)
            {
                $this->sphinx->query('DELETE FROM '.$this->index.' WHERE id IN ('.implode(',', array_keys($deleted)).')');
            }
        }
    }

    // This hook is called from maintenance/update.php, it builds the Sphinx index if it's empty
    static function LoadExtensionSchemaUpdates($updater = NULL)
    {
        global $wgUpdates, $wgDBtype;
        if ($updater)
        {
            $updater->addExtensionUpdate(array('SphinxSearchEngine::init_index'));
        }
        else
        {
            $wgUpdates[$wgDBtype][] = array('SphinxSearchEngine::init_index');
        }
        return true;
    }

    // Initialise index, if not yet
    static function init_index()
    {
        global $wgSphinxSE_port, $wgSphinxQL_host, $wgSphinxQL_index;
        $eng = new SphinxSearchEngine(wfGetDB(DB_MASTER));
        $rows = $eng->sphinx->select('SELECT * FROM '.$eng->index.' LIMIT 1');
        if (!$rows && $eng->sphinx->dbh)
        {
            $eng->build_index();
        }
        if ($wgSphinxSE_port)
        {
            $dbw = wfGetDB(DB_MASTER);
            fwrite(STDERR, "Creating the SphinxSE proxy table...\n");
            if ($wgSphinxSE_port > 0)
            {
                $conn = "sphinx://".($wgSphinxQL_host ? $wgSphinxQL_host : '127.0.0.1').':'.$wgSphinxSE_port.'/'.$wgSphinxQL_index;
            }
            else
            {
                $conn = "unix://".$wgSphinxSE_port.':'.$wgSphinxQL_index;
            }
            $t = $dbw->tableName('sphinx_page');
            $dbw->query("DROP TABLE IF EXISTS $t");
            $dbw->query("CREATE TABLE $t (".
                "id BIGINT NOT NULL, ".
                "`weight` INT NOT NULL, ".
                "`query` VARCHAR(3072) NOT NULL, ".
                "`namespace` INT NOT NULL, ".
                "`category` VARCHAR(3072) NOT NULL, ".
                "`date_insert` BIGINT NOT NULL, ".
                "`date_modify` BIGINT NOT NULL, ".
                "INDEX(`query`)".
            ") ENGINE=SPHINX DEFAULT CHARSET=utf8 CONNECTION='$conn'", __METHOD__);
        }
        return true;
    }

    // This hook is called before deleting article
    static function ArticleDelete($article, &$user, &$reason, &$error)
    {
        $eng = new SphinxSearchEngine(wfGetDB(DB_MASTER));
        $eng->delete($article->getId(), $article->getTitle());
        return true;
    }
}

class SphinxSearchResultSet extends SearchResultSet
{
    var $orderBy = '';
    var $orderSort = '';

    // Category List variable
    var $categoryList = array();
    var $selCategoryList = array();

    function __construct($db, $term, $offset, $limit, $namespaces, $dbRows, $meta, $index,
        $isFormRequest = false, $categoryList = array(), $selCategoryList = array(),
        $orderBy = NULL, $orderSort = NULL, $total = NULL)
    {
        $this->meta = $meta;
        if ($total !== NULL)
        {
            $this->meta['total'] = $total;
        }

        $this->index = $index;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->namespaces = $namespaces;
        $this->term = $term;
        $this->db = $db;
        $this->position = 0;
        $this->dbRows = $dbRows;
        $this->isFormRequest = $isFormRequest;

        // Get categories from query
        $this->categoryList = $categoryList;
        $this->selCategoryList = !empty($selCategoryList) ? array_flip($selCategoryList) : array();

        // Get sorting from query
        $this->orderBy = $orderBy;
        $this->orderSort = $orderSort;
    }

    // Term statistics widget
    function getTermStats()
    {
        global $wgOut;
        // Exclude category term statistics
        $hideCategories = SphinxSearchEngine::categoryForSearch(array_keys($this->selCategoryList));
        foreach ($hideCategories as &$c)
        {
            $c = mb_strtolower($c);
        }
        $hideCategories[] = '_empty_';
        $hideCategories = array_flip($hideCategories);
        $fmt = wfMsgNoTrans('sphinxSearchStats');
        $wiki = '';
        for ($i = 0; !empty($this->meta["keyword[$i]"]); $i++)
        {
            if (!isset($hideCategories[mb_strtolower($this->meta["keyword[$i]"])]))
            {
                $wiki .= sprintf($fmt, $this->meta["keyword[$i]"], $this->meta["hits[$i]"], $this->meta["docs[$i]"]) . "\n";
            }
        }
        return $wgOut->parse($wiki);
    }

    // Sphinx suggest widget
    function getSuggest()
    {
        global $wgTitle, $wgSphinxSuggestMode;
        $html = '';
        if ($wgSphinxSuggestMode)
        {
            $didyoumean = SphinxSearch_spell::spell($this->term);
            if ($didyoumean)
            {
                return wfMsg('sphinxSearchDidYouMean') . " <b><a href='" .
                    $wgTitle->getLocalUrl(array('search' => $didyoumean)+$_GET) . "'>" .
                    htmlspecialchars($didyoumean) . '</a></b>?<br />' .
                    $html;
            }
        }
        return '';
    }

    // Sort order widget
    function getSortOrder()
    {
        global $wgTitle, $wgRequest;
        $req = $wgRequest->getValues();
        $html = '<div class="mw-search-sort"><label><b>'.wfMsg('searchSortTitle').'</b></label>';
        foreach (SphinxSearchEngine::$allowOrderByFields as $item => $true)
        {
            if ($item != $this->orderBy)
            {
                $sort = 'desc';
                $arrow = '';
            }
            else
            {
                $sort = ($this->orderSort == 'desc' ? 'asc' : 'desc');
                $arrow = ($this->orderSort == 'desc' ? '&#9660;' : '&#9650');
            }
            $url_option = array('orderBy' => $item, 'sort' => $sort) + $req;
            $html .= '<a href="'.$wgTitle->getLocalUrl($url_option).'">'.wfMsg('searchSortOrder_'.$item).$arrow.'</a>';
        }
        $html .= '</div>';
        return $html;
    }

    // Category selection widget
    function getCategorySelector()
    {
        if ($this->categoryList)
        {
            global $wgRequest, $wgTitle;
            $catListHtml = '';
            foreach ($this->categoryList as $key => $item)
            {
                $title = Title::makeTitle(NS_CATEGORY, $item);
                if ($title->userCan('read'))
                {
                    $catListHtml .= '<input type="checkbox" value="'.$item.'" name="category[]"'.
                        ((empty($this->selCategoryList) || isset($this->selCategoryList[$item])) ? ' checked="checked" ' : '').
                        ' id="scl_item_'.($key+1).'"/> <label for="scl_item_'.($key+1).'">'.$item.'</label>'.
                        ' <a href="'.$title->getLocalUrl().'">&rarr;</a><br />';
                }
            }
            if (!$catListHtml)
            {
                return '';
            }
            $hidden = '';
            foreach ($wgRequest->getValues() as $k => $v)
            {
                if ($k === 'offset')
                {
                    // Go to the first page
                    $v = 0;
                }
                if (is_array($v))
                {
                    foreach ($v as $sk => $sv)
                    {
                        $hidden .= Html::hidden($k.'['.$sk.']', $sv);
                    }
                }
                else
                {
                    $hidden .= Html::hidden($k, $v);
                }
            }
            return '
            <div class="mw-scl">
                <form action="?" method="GET">'.
                $hidden.
                '<b>'.wfMsg('sphinxsearchCatWidgetTitle').'</b><div class="close"><a href="javascript:void(0)" id="scl_button" title="'.wfMsg('sphinxsearchCatWidgetMin').'">[-]</a></div>
                <div class="divider" style=""></div>
                <input type="checkbox" value="1" name="select_all" id="select_all" '.(empty($this->selCategoryList) ? ' checked="checked" ' : '' ).' /> <label for="select_all" id="select_all_label">'.wfMsg('sphinxsearchCatSelectAll').'</label>
                <div id="scl">
                    '.$catListHtml.'
                </div>
                <input type="submit" value="'.wfMsg('sphinxsearchCatWidgetButton').'"/>
                </form>
            </div>
            ';
        }
        return '';
    }

    function addInfo($term, $titleMatches, $textMatches)
    {
        global $wgOut;
        $wgOut->addModules('ext.SphinxSearchEngine');

        $html = $wgOut->parse(sprintf(
            wfMsgNoTrans('sphinxSearchPreamble'),
            min($this->offset+1, $this->meta['total']), min($this->offset+$this->numRows(), $this->meta['total']),
            $this->meta['total'], $this->term, $this->meta['time']
        ), false);

        if ($this->isFormRequest)
        {
            $html .= $this->getCategorySelector();
        }

        $html .=
            '<div class="mw-search-formheader" style="padding: 0.5em; margin-bottom: 1em">'.
            $this->getTermStats() .
            $this->getSuggest() .
            $this->createNextPageBar($this->limit, $this->limit ? 1+$this->offset/$this->limit : 0, $this->meta['total']) .
            '</div>';

        if ($this->isFormRequest)
        {
            $html .= $this->getSortOrder();
        }

        $wgOut->addHTML("<!-- search words: --> $html <!-- /search words: -->");
        return true;
    }

    function createNextPageBar($perpage, $page, $found)
    {
        global $wgTitle, $wgOut;
        $params = $_GET;
        unset($params['title']);
        $max_page = ceil($found / $perpage);
        if ($max_page > 0 && $page > $max_page || $page < 0 || $page != intval($page))
        {
            $wgOut->redirect($wgTitle->getLocalUrl(array('offset' => ($max_page-1)*$perpage) + $params));
        }
        $display_pages = 20;
        $center_page = $page;
        $first_page = $center_page - $display_pages / 2;
        if ($first_page < 1)
        {
            $first_page = 1;
        }
        $last_page = $center_page + $display_pages / 2;
        if ($last_page > $max_page)
        {
            $last_page = $max_page;
        }
        $html = '';
        if ($first_page != $last_page)
        {
            $html .= wfMsg('sphinxResultPage');
            if ($first_page > 1)
            {
                $prev_page = "&nbsp;<a href=\"" . $wgTitle->getLocalUrl(array('offset' => ($page-2)*$perpage)+$params);
                $prev_page .= "\">" . wfMsg('sphinxPreviousPage') ."</a> ";
                $html .= $prev_page;
            }
            for ($i = $first_page; $i < $page; $i++)
            {
                $html .= "&nbsp;<a href='".$wgTitle->getLocalUrl(array('offset' => ($i-1)*$perpage)+$params)."'>{$i}</a> ";
            }
            $html .= "&nbsp;<b>{$page}</b> ";
            for ($i = $page+1; $i <= $last_page; $i++)
            {
                $html .= "&nbsp;<a href='".$wgTitle->getLocalUrl(array('offset' => ($i-1)*$perpage)+$params)."'>{$i}</a> ";
            }
            if ($last_page < $max_page)
            {
                $next_page = "&nbsp;<a href='".$wgTitle->getLocalUrl(array('offset' => $page*$perpage)+$params);
                $next_page .= "'>" . wfMsg('sphinxNextPage') ."</a> ";
                $html .= $next_page;
            }
        }
        return $html;
    }

    function numRows()
    {
        return count($this->dbRows);
    }

    function hasResults()
    {
        return $this->numRows() > 0;
    }

    function getTotalHits()
    {
        return $this->meta['total'];
    }

    function next()
    {
        $item = NULL;
        if ($this->position < $this->numRows())
        {
            $item = SphinxSearchResult::newFromRow($this->dbRows[$this->position]);
            $this->position++;
        }
        return $item;
    }

    function free()
    {
        $this->dbRows = NULL;
    }
}

// Overridden to allow custom snippets (via Sphinx)
class SphinxSearchResult extends SearchResult
{
    var $score;

    static function newFromRow($dbRow)
    {
        $self = new self();
        $self->initFromTitle(Title::newFromRow($dbRow));
        $self->snippet = $dbRow->excerpts;
        $self->score = $dbRow->score;
        return $self;
    }

    // $terms is ignored
    function getTextSnippet($terms = NULL)
    {
        return $this->snippet;
    }

    // return weight of search item
    function getScore()
    {
        return $this->score;
    }
}

// This class is a simple wrapper around standard mysql PHP functions
// Just for convenience, and to support '?' placeholders
class SphinxQLClient
{
    protected $host;
    protected $crashed;
    public $dbh;

    /**
     * Create a client for SphinxQL running on $host
     * @param string $host Can be just "host", "host:port" or ":/var/run/searchd.sock" - path to UNIX socket
     */
    function __construct($host)
    {
        // We'll connect lazily
        $this->host = $host;
        $this->crashed = true;
    }

    /**
     * Connect to Sphinx
     */
    function connect()
    {
        $host = $this->host;
        $port = 3306;
        $socket = '';
        if (strpos($host, ':') !== false)
        {
            list($host, $port) = explode(':', $host, 2);
        }
        if (strpos($port, '/') !== false)
        {
            $socket = $port;
            $port = 3306;
            $host = '';
        }
        $this->dbh = new mysqli($host, '', '', 'any', $port, $socket);
        $this->dbh->set_charset('utf8');
        $this->crashed = false;
    }

    /**
     * Destructor disconnects automatically
     */
    function __destruct()
    {
        if ($this->dbh)
        {
            $this->dbh->close();
        }
    }

    /**
     * Escape a value for use inside the query
     */
    function quote($v)
    {
        if (is_int($v))
            return $v;
        if ($this->crashed)
        {
            // Reconnect after a crash
            $this->connect();
        }
        return "'".$this->dbh->real_escape_string($v)."'";
    }

    /**
     * Interpolate $args into '?' inside $query and run it
     * @param string $query
     * @param array $args
     */
    function query($query, $args = array())
    {
        if ($this->crashed)
        {
            // Reconnect after a crash
            $this->connect();
        }
        if (!$this->dbh)
        {
            return NULL;
        }
        preg_match_all('/\\?/', $query, $pos, PREG_PATTERN_ORDER|PREG_OFFSET_CAPTURE);
        $pos = $pos[0];
        if ($pos)
        {
            $nq = substr($query, 0, $pos[0][1]);
            $j = 0;
            $n = count($pos);
            $pos[$n] = array('', strlen($query));
            for ($i = 0; $i < $n; $i++)
            {
                $nq .= $this->quote($args[$j]);
                $j++;
                $nq .= substr($query, $pos[$i][1]+1, $pos[$i+1][1]-$pos[$i][1]-1);
            }
            $query = $nq;
        }
        $this->lastq = $query;
        $res = $this->dbh->query($query);
        if ($this->dbh->errno == 2006)
        {
            wfDebug("Sphinx crashed on query $query, retrying\n");
            // "MySQL server has gone away" - this query crashed Sphinx.
            // Retry it 1 time.
            $res = $this->dbh->query($query);
            if ($this->dbh->errno == 2006)
            {
                wfDebug("Sphinx crashed on query $query again, skipping query\n");
                // Sphinx crashed again, reconnect on next query.
                $this->crashed = true;
            }
        }
        return $res;
    }

    /**
     * Run the query with $args and return rows in the array with column $key as a key
     * @param string $query
     * @param string $key
     * @param array $args
     * @return array(array)
     */
    function select($query, $key = NULL, $args = array())
    {
        $res = $this->query($query, $args);
        if (!$res)
        {
            return NULL;
        }
        $rows = array();
        while ($r = $res->fetch_array())
        {
            if ($key !== NULL)
            {
                $rows[$r[$key]] = $r;
            }
            else
            {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /**
     * Return error text, if any
     * @return string or NULL
     */
    function error($include_query = true)
    {
        if ($this->dbh && $this->dbh->errno)
        {
            return $this->dbh->errno.': '.$this->dbh->error."\nFailed query was:\n".$this->lastq;
        }
        elseif (mysqli_connect_errno())
        {
            return mysqli_connect_errno().': '.mysqli_connect_error();
        }
        return NULL;
    }
}
