<?php

/**
 * Pluggable Sphinx search engine for MediaWiki 1.16+ and Sphinx 0.99+ (using real-time indexing)
 * http://wiki.4intra.net/SphinxSearchEngine
 * (c) 2011-2013, Vitaliy Filippov
 * License: GNU GPL 3.0 or later (see http://www.fsf.org/licenses/gpl.html)
 */

/*

Features:
- Real-time search updates
- Filtering of search results by category
- Sorting by relevance, creation or modification date

Example Sphinx index configuration:

index wiki
{
    # Required:
    type            = rt
    rt_field        = text
    rt_field        = title
    rt_attr_uint    = namespace
    rt_attr_string  = category
    rt_field        = category_search
    rt_attr_uint    = date_insert
    # Override some of these if you want
    path            = /var/lib/sphinxsearch/data/wiki
    enable_star     = 1
    charset_type    = utf-8
    charset_table   = 0..9, A..Z->a..z, a..z, U+410..U+42F->U+430..U+44F, U+430..U+44F
    blend_chars     = _, -, &, +, @, $
    morphology      = stem_enru
    min_word_len    = 2
}

*/

if (!defined('MEDIAWIKI'))
{
    echo("This file is an extension to the MediaWiki software and cannot be used standalone.\n");
    die(1);
}

$wgExtensionCredits['specialpage'][] = array(
    'version'     => '1.2',
    'name'        => 'SphinxSearchEngine',
    'author'      => 'Vitaliy Filippov',
    'email'       => 'vitalif@mail.ru',
    'url'         => 'http://wiki.4intra.net/SphinxSearchEngine',
    'description' => 'Replace MediaWiki search engine with [http://www.sphinxsearch.com/ Sphinx], using SphinxQL and real-time index.'
);

$dir = dirname(__FILE__);
$wgExtensionMessagesFiles['SphinxSearchEngine'] = $dir . '/SphinxSearchEngine.i18n.php';
$wgAutoloadClasses += array(
    'SphinxSearchEngine'        => "$dir/SphinxSearchEngine_class.php",
    'SphinxSearch_spell'        => "$dir/SphinxSearch_spell.php",
    'SphinxSearch'              => "$dir/SphinxSearch_body.php",
    'SphinxSearchPersonalDict'  => "$dir/SphinxSearch_PersonalDict.php",
);
// override core SearchUpdate class
$wgAutoloadLocalClasses['SearchUpdate'] = "$dir/SphinxSearchUpdate.php";
$wgHooks['LoadExtensionSchemaUpdates'][] = 'SphinxSearchEngine::LoadExtensionSchemaUpdates';
$wgHooks['ArticleDelete'][] = 'SphinxSearchEngine::ArticleDelete';
$wgSearchType = 'SphinxSearchEngine';

$wgResourceModules['ext.SphinxSearchEngine'] = array(
    'localBasePath' => $dir,
    'remoteExtPath' => 'SphinxSearchEngine',
    'styles'        => array('SphinxSearchEngine.css'),
    'scripts'       => array('SphinxSearchEngine.js'),
);

##########################################################
# Default configuration
##########################################################

# Host and port on which searchd is listening for SphinxQL
if (!isset($wgSphinxQL_host))
    $wgSphinxQL_host = '';
if (!isset($wgSphinxQL_port))
    $wgSphinxQL_port = '/var/run/sphinxsearch/searchd.sock';
if (!isset($wgSphinxQL_index))
    $wgSphinxQL_index = 'wiki';

# Options for building text snippets
$wgSphinxQL_ExcerptsOptions = array(
    'before_match'    => "<span style='color:red'>",
    'after_match'     => "</span>",
    'chunk_separator' => " ... ",
    'limit'           => 200,
    'around'          => 15,
);

# Weights of individual indexed columns. This gives page titles extra weight
$wgSphinxQL_weights = array('category' => 2, 'text' => 1, 'title' => 20);

##########################################################
# Suggest Mode configuration options
##########################################################
# Use Aspell to suggest possible misspellings. This could be provided via either
# PHP pspell module (http://www.php.net/manual/en/ref.pspell.php) or command line
# insterface to ASpell
##########################################################

# Should the suggestion mode be enabled?
if (!isset($wgSphinxSuggestMode))
    $wgSphinxSuggestMode = true;

# Path to where aspell has location and language data files. Leave commented out if unsure
#$wgSphinxSearchPspellDictionaryDir = "/usr/lib/aspell";

# Path to personal dictionary. Needed only if using a personal dictionary
#$wgSphinxSearchPersonalDictionary = dirname(__FILE__) . "/personal.en.pws";

# Path to Aspell. Needed only if using command line interface instead of the PHP built in PSpell interface.
#$wgSphinxSearchAspellPath = "/usr/bin/aspell";

##########################################################
# End of Suggest Mode configuration options
##########################################################

if ($wgSphinxSuggestMode && isset($wgSphinxSearchPersonalDictionary))
{
    $wgSpecialPages['SphinxSearchPersonalDict'] = 'SphinxSearchPersonalDict';
    $wgSpecialPageGroups['SphinxSearchPersonalDict'] = 'search';
}
