<?php

/**
 * Pluggable Sphinx search engine for MediaWiki 1.16+ and Sphinx 0.99+ (real-time indexing)
 * http://wiki.4intra.net/SphinxSearchEngine
 * (c) 2011, Vitaliy Filippov
 * License: GPL 3.0 or later (see http://www.fsf.org/licenses/gpl.html)
 */

if (!defined('MEDIAWIKI'))
{
    echo("This file is an extension to the MediaWiki software and cannot be used standalone.\n");
    die(1);
}

$wgExtensionCredits['specialpage'][] = array(
    'version'     => '0.9',
    'name'        => 'SphinxSearchEngine',
    'author'      => 'Vitaliy Filippov',
    'email'       => 'vitalif@mail.ru',
    'url'         => 'http://wiki.4intra.net/SphinxSearchEngine',
    'description' => 'Replace MediaWiki search engine with [http://www.sphinxsearch.com/ Sphinx], using SphinxQL and real-time index.'
);

$dir = dirname(__FILE__);
$wgExtensionMessagesFiles['SphinxSearch'] = $dir . '/SphinxSearch.i18n.php';
$wgAutoloadClasses += array(
    'SphinxSearchEngine'        => "$dir/SphinxSearchEngine_class.php",
    'SphinxSearch_spell'        => "$dir/SphinxSearch_spell.php",
    'SphinxSearch'              => "$dir/SphinxSearch_body.php",
    'SphinxSearchPersonalDict'  => "$dir/SphinxSearch_PersonalDict.php",
);
$wgSearchType = 'SphinxSearchEngine';

##########################################################
# Default configuration
##########################################################

# Host and port on which searchd is listening for SphinxQL
if (!$wgSphinxSearch_host)
    $wgSphinxSearch_host = 'localhost';
if (!$wgSphinxSearch_port)
    $wgSphinxSearch_port = 9306;
if (!$wgSphinxSearch_index)
    $wgSphinxSearch_index = 'wiki';

# Options for building text snippets
$wgSphinxSearchExcerptsOptions = array(
    'before_match'    => "<span style='color:red'>",
    'after_match'     => "</span>",
    'chunk_separator' => " ... ",
    'limit'           => 200,
    'around'          => 15,
);

# Weights of individual indexed columns. This gives page titles extra weight
$wgSphinxSearch_weights = array('old_text' => 1, 'page_title' => 100);

##########################################################
# Suggest Mode configuration options
##########################################################
# Use Aspell to suggest possible misspellings. This could be provided via either
# PHP pspell module (http://www.php.net/manual/en/ref.pspell.php) or command line
# insterface to ASpell
##########################################################

# Should the suggestion mode be enabled?
if (!is_bool($wgSphinxSuggestMode))
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

if ($wgSphinxSuggestMode && $wgSphinxSearchPersonalDictionary)
{
    $wgSpecialPages['SphinxSearchPersonalDict'] = 'SphinxSearchPersonalDict';
    $wgSpecialPageGroups['SphinxSearchPersonalDict'] = 'search';
}
