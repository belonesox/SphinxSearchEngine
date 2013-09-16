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
    var $orderBy = '';
    var $orderSort = '';
    static $allowOrderByFields = array('weight' => true, 'date_insert' => true, 'date_modify' => true);

    // Category list
    var $categoryList = array();
    var $selCategoryList = array();

    function __construct($db)
    {
        global $wgSphinxQL_host, $wgSphinxQL_port, $wgSphinxQL_index;
        if ($db)
        {
            $this->db = $db;
        }
        else
        {
            $this->db = wfGetDB(DB_SLAVE);
        }
        $this->sphinx = new SphinxQLClient($wgSphinxQL_host.':'.$wgSphinxQL_port);
        $this->index = $wgSphinxQL_index;
        $this->getSearchFormValues();
    }

    // This breaks the incapsulation principle, but is the easiest way to improve Special:Search
    function getSearchFormValues()
    {
        global $wgRequest, $wgTitle;
        $this->isFormRequest = $wgTitle->getPrefixedText() == SpecialPage::getTitleFor('Search')->getPrefixedText();
        if ($this->isFormRequest)
        {
            // Get used category from request
            $this->selCategoryList = $wgRequest->getArray('category');

            // Get sort order from request
            $this->orderBy = $wgRequest->getVal('orderBy');
            if (!isset(self::$allowOrderByFields[$this->orderBy]))
            {
                $this->orderBy = 'weight';
            }
            $this->orderSort = strtolower($wgRequest->getVal('sort'));
            if ($this->orderSort !== 'asc' && $this->orderSort !== 'desc')
            {
                $this->orderSort = 'desc';
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

    // Main search function
    function searchText($term)
    {
        global $wgSphinxQL_weights;

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

        $query = 'SELECT *, WEIGHT() `weight` FROM '.$this->index.' WHERE MATCH(?)';
        if ($this->namespaces)
        {
            $query .= ' AND namespace IN ('.implode(',', $this->namespaces).')';
        }
        if ($this->orderBy && $this->orderSort)
        {
            $query .= ' ORDER BY `'.$this->orderBy.'` '.$this->orderSort;
        }
        $query .= ' LIMIT '.$this->offset.', '.$this->limit;
        if ($wgSphinxQL_weights)
        {
            $ws = $wgSphinxQL_weights;
            foreach ($ws as $k => &$w)
            {
                $w = "$k=".intval($w);
            }
            unset($w);
            $query .= ' OPTION field_weights=('.implode(', ', $ws).')';
        }

        $sTerm = $this->filter($term);

        // Search in selected categories
        if ($this->selCategoryList)
        {
            $sTerm .= ' @category_search "'.implode('"|"', self::categoryForSearch($this->selCategoryList)).'"';
        }

        $rows = $this->sphinx->select($query, 'id', array($sTerm));
        if ($rows === NULL)
        {
            global $wgOut;
            wfDebug("[ERROR] ".__METHOD__.": ".$this->sphinx->error());
            $wgOut->addWikiText("Query failed: " . $this->sphinx->error() . "\n");
            return NULL;
        }
        $res = new SphinxSearchResultSet(
            $this->db, $this->sphinx, $term, $this->offset, $this->limit,
            $this->namespaces, $rows, $this->index, $this->isFormRequest,
            $this->categoryList, $this->selCategoryList, $this->orderBy, $this->orderSort
        );
        return $res;
    }

    /**
     * Get category list from search query
     *
     * @param $term Search string
     */
    function getCategoryList($term)
    {
        $rows = $this->sphinx->select('SELECT id, category FROM '.$this->index.' WHERE MATCH(?) GROUP BY category', 'id', array($this->filter($term)));
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
        if (substr_count($text, '"') % 2) // Search for double chars " and check it
        {
            $pattern_part .= '"';
        }
        return preg_replace('/((^|[^\\\\])(?:\\\\\\\\)*)(['.$pattern_part.'])/', '\1\\\\\2', $text);
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
        $date_modify = $date_insert->max_ts;
        $date_insert = $date_insert->min_ts;
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
    function delete($id)
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
            // Trigger SearchUpdate and allow extensions to change indexed text
            wfRunHooks('SearchUpdate', array($row->page_id, $row->page_namespace, $row->page_title, &$row->old_text));
            foreach (array('page_id', 'page_namespace', 'page_title', 'old_text', 'category', 'category_search', 'date_insert', 'date_modify') as $key)
            {
                // 2MB limit for field length
                $cur[] = substr($row->$key, 0, 2*1024*1024);
                $query_size += strlen($row->$key);
            }
            // Sphinx max_packet is 8MB by default, so use 6MB for partial query
            if (count($cur) >= 256*5 || $query_size > 6*1024*1024 || $i >= $total)
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
            $ids = $this->sphinx->select('SELECT id FROM '.$this->index.' WHERE id > '.$lastid.' ORDER BY id');
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
                $this->sphinx->query('DELETE FROM '.$this->index.' WHERE id IN ('.implode(',', $deleted).')');
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
        $eng = new SphinxSearchEngine(wfGetDB(DB_MASTER));
        $rows = $eng->sphinx->select('select * from '.$eng->index.' limit 1');
        if (!$rows && $eng->sphinx->dbh)
        {
            $eng->build_index();
        }
        return true;
    }

    // This hook is called before deleting article
    static function ArticleDelete($article, &$user, &$reason, &$error)
    {
        $eng = new SphinxSearchEngine(wfGetDB(DB_MASTER));
        $eng->delete($article->getId());
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

    function __construct($db, $sphinx, $term, $offset, $limit, $namespaces, $rows, $index,
        $isFormRequest = false, $categoryList = array(), $selCategoryList = array(), $orderBy = null, $orderSort = null)
    {
        $this->sphinxResult = $rows;
        $this->sphinx = $sphinx;
        $this->meta = $sphinx->select('SHOW META', 0);
        foreach ($this->meta as &$m)
        {
            $m = $m[1];
        }
        $this->index = $index;
        $this->limit = $limit;
        $this->offset = $offset;
        $this->namespaces = $namespaces;
        $this->term = $term;
        $this->db = $db;
        $this->position = 0;
        $this->ids = array_keys($rows);
        $this->dbTitles = array();
        $this->dbTexts = array();
        $this->isFormRequest = $isFormRequest;

        // Get categories from query
        $this->categoryList = $categoryList;
        $this->selCategoryList = !empty($selCategoryList) ? array_flip($selCategoryList) : array();

        // Get sorting from query
        $this->orderBy = $orderBy;
        $this->orderSort = $orderSort;

        // Try our best to normalize scores
        // Max score for SPH_RANK_PROXIMITY_BM25 = num_keywords * sum(field_weights) * 1000 + 999
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

        if ($rows)
        {
            $res = $this->db->select(
                array('page', 'revision', 'text', 'categorylinks'),
                'page.*, old_text, GROUP_CONCAT(cl_to SEPARATOR \',\') category',
                array('page_id' => array_keys($rows)),
                __METHOD__,
                array('GROUP BY' => 'page_id'),
                array(
                    'revision'      => array('INNER JOIN', array('rev_id=page_latest')),
                    'text'          => array('INNER JOIN', array('old_id=rev_text_id')),
                    'categorylinks' => array('LEFT JOIN', array('cl_from=page_id')),
                )
            );

            foreach ($res as $row)
            {
                $this->dbTexts[$row->page_id] = $row->old_text;
                $this->dbTitles[$row->page_id] = $row;
                $this->dbTitles[$row->page_id]->score = isset($rows[$row->page_id]['weight']) ? $rows[$row->page_id]['weight'] / $maxScore : null;
            }
        }
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
            global $wgRequest;
            $hidden = '';
            foreach ($wgRequest->getValues() as $k => $v)
            {
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
            $catListHtml = '<input type="checkbox" value="" name="category[]"'.
                ((empty($this->selCategoryList) || isset($this->selCategoryList[''])) ? ' checked="checked" ' : '').
                ' id="scl_item_0" /> <label for="scl_item_0">'.wfMsg('sphinxsearchCatNoCategory').'</label><br />';

            foreach ($this->categoryList as $key => $item)
            {
                $catListHtml .= '<input type="checkbox" value="'.$item.'" name="category[]"'.
                    ((empty($this->selCategoryList) || isset($this->selCategoryList[$item])) ? ' checked="checked" ' : '').
                    ' id="scl_item_'.($key+1).'"/> <label for="scl_item_'.($key+1).'">'.$item.'</label><br />';
            }

            return '
            <div class="mw-scl">
                <form action="?" method="GET">'.
                $hidden.
                '<b>'.wfMsg('sphinxsearchCatWidgetTitle').'</b><div class="close"><a href="javascript:void(0)" id="scl_button" title="'.wfMsg('sphinxsearchCatWidgetMin').'">[-]</a></div>
                <div class="divider" style=""></div>
                <div id="scl">
                    '.$catListHtml.'
                <input type="submit" value="'.wfMsg('sphinxsearchCatWidgetButton').'" />
                </div>
                </form>
            </div>
            ';
        }
        return '';
    }

    function getInfo()
    {
        global $wgOut;

        $wgOut->addModules('ext.SphinxSearchEngine');

        $html = $wgOut->parse(sprintf(
            wfMsgNoTrans('sphinxSearchPreamble'),
            $this->offset+1, $this->offset+$this->numRows(),
            $this->meta['total'], $this->term, $this->meta['time']
        ), false);

        $html .=
            '<div class="mw-search-formheader" style="padding: 0.5em; margin-bottom: 1em">'.
            $this->getTermStats() .
            $this->getSuggest() .
            $this->createNextPageBar($this->limit, $this->limit ? 1+$this->offset/$this->limit : 0, $this->meta['total']) .
            '</div>';

        if ($this->isFormRequest)
        {
            $html .= $this->getSortOrder();
            $html .= $this->getCategorySelector();
        }

        return "search words: --> $html <!-- /search words:";
    }

    function createNextPageBar($perpage, $page, $found)
    {
        global $wgTitle;

        $display_pages = 30;
        $max_page = ceil($found / $perpage);
        $center_page = $page;
        $first_page = $center_page - $display_pages / 2;
        if ($first_page < 1)
        {
            $first_page = 1;
        }
        $last_page = $first_page + $display_pages / 2;
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
                $prev_page = "&nbsp;<a href=\"" . $wgTitle->getLocalUrl(array('offset' => ($page-2)*$perpage)+$_GET);
                $prev_page .= "\">" . wfMsg('sphinxPreviousPage') ."</a> ";
                $html .= $prev_page;
            }
            for ($i = $first_page; $i < $page; $i++)
            {
                $html .= "&nbsp;<a href='".$wgTitle->getLocalUrl(array('offset' => ($i-1)*$perpage)+$_GET)."'>{$i}</a> ";
            }
            $html .= "&nbsp;<b>{$page}</b> ";
            for ($i = $page+1; $i <= $last_page; $i++)
            {
                $html .= "&nbsp;<a href='".$wgTitle->getLocalUrl(array('offset' => ($i-1)*$perpage)+$_GET)."'>{$i}</a> ";
            }
            if ($last_page < $max_page)
            {
                $next_page  = "&nbsp;<a href='".$wgTitle->getLocalUrl(array('offset' => $page*$perpage)+$_GET);
                $next_page .= "'>" . wfMsg('sphinxNextPage') ."</a> ";
                $html .= $next_page;
            }
        }
        return $html;
    }

    function numRows()
    {
        return count($this->sphinxResult);
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
        global $wgSphinxQL_ExcerptsOptions;
        if ($this->position < $this->numRows())
        {
            $doc = $this->ids[$this->position++];
            $snip_query = 'CALL SNIPPETS(?, ?, ?';
            $snip_bind = array($this->dbTexts[$doc], $this->index, $this->term);
            foreach ($wgSphinxQL_ExcerptsOptions as $k => $v)
            {
                $snip_query .= ", ? AS $k";
                $snip_bind[] = $v;
            }
            $snip_query .= ')';
            $excerpts = $this->sphinx->select($snip_query, NULL, $snip_bind);
            if (!$excerpts)
            {
                $excerpts = array("ERROR: " . $this->sphinx->error());
            }
            foreach ($excerpts as &$entry)
            {
                // add excerpt to output, remove some wiki markup and break apart long strings
                $entry = $entry[0];
                $entry = preg_replace('/([\[\]\{\}\*\#\|\!]+|==+)/', ' ', strip_tags($entry, '<span><br>'));
                $entry = join('<br />', preg_split('/(\P{Z}{60})/u', $entry, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE));
                $entry = "<div style='margin: 0 0 0.2em 1em;'>$entry</div>\n";
            }
            $excerpts = implode("", $excerpts);
            return new SphinxSearchResult($this->dbTitles[$doc], $excerpts, $this->dbTitles[$doc]->score);
        }
        return NULL;
    }

    function free()
    {
        $this->dbTitles = NULL;
        $this->dbTexts = NULL;
        $this->sphinxResult = NULL;
        $this->ids = array();
    }
}

// Overridden to allow custom snippets (via Sphinx)
class SphinxSearchResult extends SearchResult
{
    var $score;

    function __construct($dbRow, $snippet, $score)
    {
        parent::__construct($dbRow);
        $this->snippet = $snippet;
        $this->score = $score;
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
                if (is_int($args[$j]))
                    $nq .= $args[$j];
                else
                    $nq .= "'".$this->dbh->real_escape_string($args[$j])."'";
                $j++;
                $nq .= substr($query, $pos[$i][1]+1, $pos[$i+1][1]-$pos[$i][1]-1);
            }
            $query = $nq;
        }
        $this->lastq = $query;
        $res = $this->dbh->query($query);
        if ($this->dbh->errno == 2006)
        {
            // "MySQL server has gone away" - this query crashed Sphinx.
            // Reconnect on next query.
            $this->crashed = true;
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
    function error()
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
