<?php

/**
 * Pluggable Sphinx search engine for MediaWiki 1.16+ and Sphinx 0.99+ (real-time indexing)
 * Maintenance script for rebuilding the Sphinx index
 * http://wiki.4intra.net/SphinxSearchEngine
 * (c) 2011, Vitaliy Filippov
 * License: GPL 3.0 or later (see http://www.fsf.org/licenses/gpl.html)
 */

$dir = dirname($_SERVER['PHP_SELF']);
require_once "$dir/../../maintenance/commandLine.inc";

$eng = new SphinxSearchEngine(wfGetDB(DB_MASTER));
$eng->purge_deleted();
$eng->build_index();
$eng->sphinx->query('OPTIMIZE INDEX '.$eng->index);
