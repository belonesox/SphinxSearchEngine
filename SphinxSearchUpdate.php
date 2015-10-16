<?php

/**
 * Pluggable Sphinx search engine for MediaWiki 1.16+ and Sphinx 0.99+ (real-time indexing)
 * http://wiki.4intra.net/SphinxSearchEngine
 * (c) 2011, Vitaliy Filippov
 * License: GPL 3.0 or later (see http://www.fsf.org/licenses/gpl.html)
 */

// 1.18 compatibility
if (!interface_exists('DeferrableUpdate'))
{
    interface DeferrableUpdate
    {
        function doUpdate();
    }
}

// Similar to standard SearchUpdate, but does not refuck the text
// using mysterious regexes, does not remove any links etc
class SearchUpdate implements DeferrableUpdate
{
    var $mId, $mTitle, $mText;

    function SearchUpdate($id, $title, $text = false)
    {
        $this->mTitle = Title::newFromText($title);
        if ($this->mTitle)
        {
            $this->mId = $id;
            $this->mText = $text;
        }
        else
            wfDebug(__CLASS__." object created with invalid title '$title'\n");
    }

    function doUpdate()
    {
        global $wgContLang, $wgDisableSearchUpdate, $wgVersion;

        if ($wgDisableSearchUpdate || !$this->mId)
            return false;
        wfProfileIn(__METHOD__);

        $search = SearchEngine::create();

        if ($this->mText === false)
        {
            $a = new WikiPage($this->mTitle);
            $this->mText = version_compare($wgVersion, '1.21', '>=') ? $a->getContent() : $a->getText();
        }

        if ($this->mText instanceof Content)
            $this->mText = $this->mText->getTextForSearchIndex();
        // Language-specific strip/conversion
        $text = $wgContLang->normalizeForSearch($this->mText);

        wfRunHooks('SearchUpdate', array($this->mId, $this->mTitle->getNamespace(), $this->mTitle->getText(), &$text));

        // Perform the actual update
        $search->update($this->mId, $this->mTitle, $text);

        wfProfileOut(__METHOD__);
    }
}
