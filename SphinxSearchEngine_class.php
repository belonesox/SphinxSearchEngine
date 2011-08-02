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
        global $wgSphinxSearch_host, $wgSphinxSearch_port, $wgSphinxSearch_index;
        $this->db = $db;
        $this->sphinx = new SphinxQLClient();
        $this->index = $wgSphinxSearch_index;
        $this->sphinx->connect($wgSphinxSearch_host.':'.$wgSphinxSearch_port);
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
        global $wgSphinxSearch_weights;
        $found = 0;

        // don't do anything for blank searches
        if (!preg_match('/[\w\pL\d]/u', $term))
            return new SearchResultSet();

        $query = 'SELECT *, WEIGHT() `weight` FROM '.$this->index.' WHERE MATCH(?)';
        if ($this->namespaces)
            $query .= ' AND page_namespace IN ('.implode(',', $this->namespaces).')';
        // Uncomment when Sphinx will support MVAs in RT indexes:
        //if ($this->categories)
        //    $query .= ' AND category IN ('.implode(',', $this->categories).')';
        if ($this->orderby)
            $query .= ' ORDER BY '.$this->orderby;
        $query .= ' LIMIT '.$this->offset.', '.$this->limit;
        if ($wgSphinxSearch_weights)
        {
            $ws = $wgSphinxSearch_weights;
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
        if (!$this->sphinx->query('REPLACE INTO '.$this->index.
                ' (id, page_namespace, page_title, old_text) VALUES (?, ?, ?, ?)',
                array($id, $title->getNamespace(), $title->getText(), $text)
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
        print("Filling the Sphinx full-text index...\n");
        $ids = array();
        $res = $this->db->select(
            array('page', 'revision', 'text'),
            'page_id, page_namespace, page_title, old_text',
            array('rev_id=page_latest', 'old_id=rev_text_id'),
            __METHOD__
        );
        $cur = array();
        $total = $res->numRows()-1;
        foreach ($res as $i => $row)
        {
            if (count($cur) < 1024 && $i < $total)
            {
                $cur[] = $row->page_id;
                $cur[] = $row->page_namespace;
                $cur[] = str_replace('_', ' ', $row->page_title);
                $cur[] = $row->old_text;
            }
            else
            {
                $q = 'REPLACE INTO '.$this->index.
                    ' (id, page_namespace, page_title, old_text) VALUES '.
                    substr(str_repeat(', (?, ?, ?, ?)', count($cur)/4), 2);
                if (!$this->sphinx->query($q, $cur))
                {
                    // Log the error
                    print("[ERROR] ".__METHOD__.": ".$this->sphinx->error()."\n");
                }
                $cur = array();
            }
        }
    }

    // This hook is called from maintenance/update.php, it builds the Sphinx index if it's empty
    static function LoadExtensionSchemaUpdates()
    {
        $eng = new SphinxSearchEngine(wfGetDB(DB_MASTER));
        $rows = $eng->sphinx->select('select `id` from '.$eng->index.' limit 1');
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
        global $wgSphinxSearchExcerptsOptions;
        if ($this->position < $this->numRows())
        {
            $doc = $this->ids[$this->position++];
            $snip_query = 'CALL SNIPPETS(?, ?, ?';
            $snip_bind = array($this->dbTexts[$doc], $this->index, $this->term);
            foreach ($wgSphinxSearchExcerptsOptions as $k => $v)
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
                $entry = join('<br />', preg_split('/(*UTF8)(\S{60})/', $entry, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE));
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
        $pos = array();
        $p = -1;
        while (($p = strpos($query, '?', $p+1)) !== false)
            $pos[] = $p;
        for ($i = count($pos)-1; $i >= 0; $i--)
        {
            $a = $args[$i];
            if (intval($a).'' !== "$a")
                $a = "'" . mysql_real_escape_string($a, $this->dbh) . "'";
            $query = substr($query, 0, $pos[$i]) . $a . substr($query, $pos[$i]+1);
        }
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
            return mysql_errno($this->dbh).': '.mysql_error($this->dbh);
        return NULL;
    }
}
