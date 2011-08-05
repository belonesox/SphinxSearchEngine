<?php

/**
 * Pluggable Sphinx search engine for MediaWiki 1.16+ and Sphinx 0.99+ (real-time indexing)
 * http://wiki.4intra.net/SphinxSearchEngine
 * (c) 2011, Vitaliy Filippov
 * License: GPL 3.0 or later (see http://www.fsf.org/licenses/gpl.html)
 */

class SphinxSearchEngine extends SearchEngine
{
    function __construct($db)
    {
        global $wgSphinxQL_host, $wgSphinxQL_port, $wgSphinxQL_index;
        $this->db = $db;
        $this->sphinx = new SphinxQLClient();
        $this->index = $wgSphinxQL_index;
        $this->sphinx->connect($wgSphinxQL_host.':'.$wgSphinxQL_port);
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
        $found = 0;

        // don't do anything for blank searches
        if (!preg_match('/[\w\pL\d]/u', $term))
            return new SearchResultSet();

        $query = 'SELECT *, WEIGHT() `weight` FROM '.$this->index.' WHERE MATCH(?)';
        if ($this->namespaces)
            $query .= ' AND namespace IN ('.implode(',', $this->namespaces).')';
        if ($this->orderby)
            $query .= ' ORDER BY '.$this->orderby;
        $query .= ' LIMIT '.$this->offset.', '.$this->limit;
        if ($wgSphinxQL_weights)
        {
            $ws = $wgSphinxQL_weights;
            foreach ($ws as $k => &$w)
                $w = "$k=".intval($w);
            $query .= ' OPTION field_weights=('.implode(', ', $ws).')';
        }

        $rows = $this->sphinx->select($query, 'id', array($term));
        if ($rows === NULL)
        {
            global $wgOut;
            wfDebug("[ERROR] ".__METHOD__.": ".$this->sphinx->error());
            $wgOut->addWikiText("Query failed: " . $this->sphinx->error() . "\n");
            return NULL;
        }
        $res = new SphinxSearchResultSet($this->db, $this->sphinx, $term, $this->offset, $this->limit, $this->namespaces, $rows, $this->index);
        return $res;
    }

    // Filter $text - just a stub
    function filter($text)
    {
        return $text;
    }

    // Updates an index entry
    function update($id, $title, $text)
    {
        $dbr = wfGetDB(DB_SLAVE);
        $res = $dbr->select('categorylinks', 'cl_to', array('cl_from' => $id), __METHOD__);
        $cat = array();
        foreach ($res as $row)
            $cat[] = $row->cl_to;
        $cat = str_replace('_', ' ', implode(', ', $cat));
        if (!$this->sphinx->query('REPLACE INTO '.$this->index.
                ' (id, namespace, title, text, category) VALUES (?, ?, ?, ?, ?)',
                array($id, $title->getNamespace(), $title->getText(), $text, $cat)
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
            array('page', 'revision', 'text', 'categorylinks'),
            'page_id, page_namespace, page_title, old_text, GROUP_CONCAT(cl_to SEPARATOR \',\') category',
            array('rev_id=page_latest', 'old_id=rev_text_id', 'cl_from=page_id'),
            __METHOD__,
            array('GROUP BY' => 'page_id')
        );
        $cur = array();
        $total = $res->numRows();
        foreach ($res as $i => $row)
        {
            $cur[] = $row->page_id;
            $cur[] = $row->page_namespace;
            $cur[] = str_replace('_', ' ', $row->page_title);
            $cur[] = $row->old_text;
            $cur[] = str_replace('_', ' ', $row->category);
            if (count($cur) >= 256*5 || $i >= $total)
            {
                fwrite(STDERR, "\r$i / $total...");
                fflush(STDERR);
                $q = 'REPLACE INTO '.$this->index.
                    ' (id, namespace, title, text, category) VALUES '.
                    substr(str_repeat(', (?, ?, ?, ?, ?)', count($cur)/5), 2);
                if (!$this->sphinx->query($q, $cur))
                {
                    // Print error
                    print("[ERROR] ".__METHOD__.": ".$this->sphinx->error()."\n");
                }
                $cur = array();
            }
        }
        fwrite(STDERR, "\n");
    }

    // For maintenance: remove pages which are not in the DB any more, from the index
    function purge_deleted()
    {
        $lastid = 0;
        do
        {
            $ids = $this->sphinx->select('SELECT id FROM '.$eng->index.' WHERE id>'.$lastid.' ORDER BY id');
            if (!$ids)
                break;
            foreach ($ids as &$id)
                $id = $id[0];
            $lastid = $ids[count($ids)-1];
            $res = $this->db->select('page', 'page_id', array('page_id' => $ids), __METHOD__);
            $deleted = array_flip($ids);
            foreach ($res as $row)
                unset($deleted[$row->page_id]);
            if ($deleted)
                $this->sphinx->query('DELETE FROM '.$eng->index.' WHERE id IN ('.implode(',', $deleted).')');
        } while (1);
    }

    // This hook is called from maintenance/update.php, it builds the Sphinx index if it's empty
    static function LoadExtensionSchemaUpdates()
    {
        global $wgUpdates, $wgDBtype;
        $wgUpdates[$wgDBtype][] = array('SphinxSearchEngine::init_index');
        return true;
    }

    // Initialise index, if not yet
    static function init_index()
    {
        $eng = new SphinxSearchEngine(wfGetDB(DB_MASTER));
        $rows = $eng->sphinx->select('select * from '.$eng->index.' limit 1');
        if (!$rows && $eng->sphinx->dbh)
            $eng->build_index();
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
    function __construct($db, $sphinx, $term, $offset, $limit, $namespaces, $rows, $index)
    {
        wfLoadExtensionMessages('SphinxSearchEngine');
        $this->sphinxResult = $rows;
        $this->sphinx = $sphinx;
        $this->meta = $sphinx->select('SHOW META', 0);
        foreach ($this->meta as &$m)
            $m = $m[1];
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
        if ($rows)
        {
            $res = $this->db->select(array('page', 'revision', 'text'), 'page.*, old_text',
                array('page_id' => array_keys($rows), 'page_latest=rev_id', 'rev_text_id=old_id'),
                __METHOD__
            );
            foreach ($res as $row)
            {
                $this->dbTexts[$row->page_id] = $row->old_text;
                $this->dbTitles[$row->page_id] = $row;
            }
        }
    }

    function getInfo()
    {
        global $wgOut, $wgSphinxSuggestMode;
        $html = $wgOut->parse(sprintf(
            wfMsgNoTrans('sphinxSearchPreamble'),
            $this->offset+1, $this->offset+$this->numRows(),
            $this->meta['total'], $this->term, $this->meta['time']
        ), false);
        $fmt = wfMsgNoTrans('sphinxSearchStats');
        for ($i = 0; $this->meta["keyword[$i]"]; $i++)
            $wiki .= sprintf($fmt, $this->meta["keyword[$i]"], $this->meta["hits[$i]"], $this->meta["docs[$i]"]) . "\n";
        $html .= $wgOut->parse($wiki);
        if ($wgSphinxSuggestMode)
        {
            $didyoumean = SphinxSearch_spell::spell($this->term);
            if ($didyoumean)
            {
                global $wgTitle;
                $html = wfMsg('sphinxSearchDidYouMean') . " <b><a href='" .
                    $wgTitle->getLocalUrl(array('search' => $didyoumean)+$_GET) . "'>" .
                    htmlspecialchars($didyoumean) . '</a></b>?<br />' .
                    $html;
            }
        }
        $html .= $this->createNextPageBar($this->limit, $this->limit ? 1+$this->offset/$this->limit : 0, $this->meta['total']);
        $html = '<div class="mw-search-formheader" style="padding: 0.5em; margin-bottom: 1em">'.$html.'</div>';
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
            $first_page = 1;
        $last_page = $first_page + $display_pages / 2;
        if ($last_page > $max_page)
            $last_page = $max_page;
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
                $html .= "&nbsp;<a href='".$wgTitle->getLocalUrl(array('offset' => ($i-1)*$perpage)+$_GET)."'>{$i}</a> ";
            $html .= "&nbsp;<b>{$page}</b> ";
            for ($i = $page+1; $i <= $last_page; $i++)
                $html .= "&nbsp;<a href='".$wgTitle->getLocalUrl(array('offset' => ($i-1)*$perpage)+$_GET)."'>{$i}</a> ";
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
                $excerpts = array("ERROR: " . $this->sphinx->error());
            foreach ($excerpts as &$entry)
            {
                // add excerpt to output, remove some wiki markup and break apart long strings
                $entry = $entry[0];
                $entry = preg_replace('/([\[\]\{\}\*\#\|\!]+|==+)/', ' ', strip_tags($entry, '<span><br>'));
                $entry = join('<br />', preg_split('/(\P{Z}{60})/u', $entry, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE));
                $entry = "<div style='margin: 0 0 0.2em 1em;'>$entry</div>\n";
            }
            $excerpts = implode("", $excerpts);
            return new SphinxSearchResult($this->dbTitles[$doc], $excerpts);
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
    function __construct($dbRow, $snippet)
    {
        parent::__construct($dbRow);
        $this->snippet = $snippet;
    }

    // $terms is ignored
    function getTextSnippet($terms = NULL)
    {
        return $this->snippet;
    }
}

// This class is a simple wrapper around standard mysql PHP functions
// Just for convenience, and to support '?' placeholders
class SphinxQLClient
{
    // Connect to Sphinx, $host = "host:port"
    function connect($host)
    {
        $this->dbh = mysql_connect($host);
    }
    // Destructor disconnects automatically
    function __destruct()
    {
        if ($this->dbh)
            mysql_close($this->dbh);
    }
    // Interpolate $args into '?' inside $query and run it
    function query($query, $args = array())
    {
        if (!$this->dbh)
            return NULL;
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
                    $nq .= "'".mysql_escape_string($args[$j])."'";
                $j++;
                $nq .= substr($query, $pos[$i][1]+1, $pos[$i+1][1]-$pos[$i][1]-1);
            }
            $query = $nq;
        }
        $this->lastq = $query;
        return mysql_query($query, $this->dbh);
    }
    // Run the query with $args and return rows in the array with column $key as a key
    function select($query, $key = NULL, $args = array())
    {
        $res = $this->query($query, $args);
        if (!$res)
            return NULL;
        $rows = array();
        while ($r = mysql_fetch_array($res))
        {
            if ($key !== NULL)
                $rows[$r[$key]] = $r;
            else
                $rows[] = $r;
        }
        mysql_free_result($res);
        return $rows;
    }
    // Return error text, if any
    function error()
    {
        if ($this->dbh && mysql_errno($this->dbh))
            return mysql_errno($this->dbh).': '.mysql_error($this->dbh)."\nFailed query was:\n".$this->lastq;
        return NULL;
    }
}
