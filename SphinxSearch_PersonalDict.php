<?php

/**
 * Pluggable Sphinx search engine for MediaWiki 1.16+ and Sphinx 0.99+ (real-time indexing)
 * http://wiki.4intra.net/SphinxSearchEngine
 * Copied from SphinxSearch MW extension
 *
 * http://www.mediawiki.org/wiki/Extension:SphinxSearch
 *
 * Developed by Paul Grinberg and Svemir Brkic
 * Adjusted by Vitaliy Filippov and Stas Fomin
 *
 * Released under GNU General Public License (see http://www.fsf.org/licenses/gpl.html)
 */

class SphinxSearchPersonalDict extends SpecialPage
{
    function SphinxSearchPersonalDict()
    {
        SpecialPage::SpecialPage('SphinxSearchPersonalDict', 'delete');
        wfLoadExtensionMessages('SphinxSearch');
        return true;
    }

    function execute($par)
    {
        global $wgRequest, $wgOut, $wgUser;

        $this->setHeaders();
        $wgOut->setPagetitle(wfMsg('sphinxsearchpersonaldict'));

        if (!$wgUser->isAllowed("delete"))
        {
            $wgOut->addWikiText(wfMsg('sphinxsearchcantpersonaldict'));
            $wgOut->addWikiText('----');
        }

        $toberemoved = $wgRequest->getArray('indictionary', array());
        $tobeadded   = $wgRequest->getVal('tobeadded','');
        $tobeadded   = preg_split('/\s/', trim($tobeadded), -1, PREG_SPLIT_NO_EMPTY);

        $this->deleteFromPersonalDictionary($toberemoved);
        $this->addToPersonalDictionary($tobeadded);

        $this->CreateForm($wgUser->isAllowed("delete"));
    }

    function CreateForm($allowed_to_add)
    {
        global $wgOut;
        global $wgSphinxSearchPersonalDictionary;

        $wgOut->addHTML(
            '<form method="POST">'.
            '<table style="border-collapse: separate; padding: 10px" width="100%" cellspacing="10" border="0">'.
            '<tr><td valign="top"><center><b>' . wfMsg('sphinxsearchindictionary') . '</b></center><p>'.
            '<select name="indictionary[]" size="15" style="width: 100%" multiple="multiple">'
        );

        if (file_exists($wgSphinxSearchPersonalDictionary))
        {
            $this->readPersonalDictionary($langauge, $numwords, $words);
            sort($words);
            if ($words)
            {
                foreach ($words as $w)
                {
                    $w = htmlspecialchars($w);
                    $wgOut->addHTML("<option value='$w'>$w</option>");
                }
            }
            else
                $wgOut->addHTML('<option disabled="disabled" value="">'.wfMsg('sphinxsearchdempty').'</option>');
        }
        else
            $wgOut->addHTML('<option disabled value="">'.wfMsg('sphinxsearchdnotfound').'</option>');

        $wgOut->addHTML('</select></td><td valign=top>');
        if ($allowed_to_add)
        {
            $wgOut->addHTML(
                '<center><b>'.wfMsg('sphinxsearchtobeadded').'</b></center><p>'.
                '<textarea name="tobeadded" style="width: 100%" cols="30" rows="15"></textarea>'.
                '</td></tr><tr><td colspan=2>'.
                '<center><input type="submit" value="'.wfMsg('sphinxsearchdexecute').'" /></center>'
            );
        }
        $wgOut->addHTML('</td></tr></table></form>');
    }

    function addToPersonalDictionary($list)
    {
        $this->nonnative_addword($list);
    }

    function getSearchLanguage()
    {
        global $wgUser, $wgLanguageCode;
        // Try to read the default language from $wgUser:
        $language = trim($wgUser->getDefaultOption('language'));

        // Use global variable: $wgLanguageCode (from LocalSettings.php) as fallback:
        if (empty($language)) { $language = trim($wgLanguageCode); }

        // If we still don't have a valid language yet, assume English:
        if (empty($language)) { $language = 'en'; }

        return $language;
    }

    function nonnative_addword($list)
    {
        global $wgUser;
        global $wgSphinxSearchPersonalDictionary;

        if (!file_exists($wgSphinxSearchPersonalDictionary))
        {
            // create the personal dictionary file if it does not already exist
            $language = $this->getSearchLanguage();
            $numwords = 0;
            $words = array();
        }
        else
            $this->readPersonalDictionary($language, $numwords, $words);

        $write_needed = false;
        foreach ($list as $word)
        {
            if (!in_array($word, $words))
            {
                $numwords++;
                array_push($words, $word);
                $write_needed = true;
            }
        }

        if ($write_needed)
            $this->writePersonalDictionary($language, $numwords, $words);
    }

    function writePersonalDictionary($language, $numwords, $words)
    {
        global $wgSphinxSearchPersonalDictionary;

        $handle = fopen($wgSphinxSearchPersonalDictionary, "wt");
        if ($handle)
        {
            fwrite($handle, "personal_ws-1.1 $language $numwords\n");
            foreach ($words as $w)
                fwrite($handle, "$w\n");
            fclose($handle);
        }
    }

    function readPersonalDictionary(&$language, &$numwords, &$words)
    {
        global $wgSphinxSearchPersonalDictionary;

        $words = array();
        $lines = explode("\n", file_get_contents($wgSphinxSearchPersonalDictionary));
        foreach ($lines as $line)
        {
            trim($line);
            if (preg_match('/\s(\w+)\s(\d+)/', $line, $matches))
            {
                $language = $matches[1];
                $numwords = $matches[2];
            }
            elseif ($line)
                array_push($words, $line);
        }

        // Make sure that we have a valid value for language if it wasn't in the .pws file:
        if (empty($language)) { $language = $this->getSearchLanguage(); }
    }

    function deleteFromPersonalDictionary($list)
    {
        // there is no builtin way to delete from the personal dictionary.
        $this->readPersonalDictionary($language, $numwords, $words);

        $write_needed = false;
        foreach ($list as $w)
        {
            if ($w == '')
                continue;
            if (in_array($w, $words))
            {
                $index = array_keys($words, $w);
                unset($words[$index[0]]);
                $numwords--;
                $write_needed = true;
            }
        }

        if ($write_needed)
            $this->writePersonalDictionary($language, $numwords, $words);
    }
}
