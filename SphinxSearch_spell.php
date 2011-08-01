<?php

/**
 * Pluggable Sphinx search engine for MediaWiki 1.16+ and Sphinx 0.99+ (real-time indexing)
 * Search suggestion module
 * http://wiki.4intra.net/SphinxSearchEngine
 * (c) 2011, Vitaliy Filippov
 * License: GPL 3.0 or later (see http://www.fsf.org/licenses/gpl.html)
 */

class SphinxSearch_spell
{
    // Check spelling of $string and suggest a best guess, if spelling is not correct
    static function spell($string)
    {
        $suggestion_needed = false;

        preg_match_all('/\p{L}+/u', $string, $words, PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE);
        $words = $words[0];
        $spell = array();
        foreach ($words as $w)
            $spell[$w[0]] = true;
        if (function_exists('pspell_check'))
            $spell = self::builtin_spell(array_keys($spell));
        else
            $spell = self::nonnative_spell(array_keys($spell));

        $ret = $string;
        for ($i = count($words)-1; $i >= 0; $i--)
        {
            $word = $words[$i][0];
            if ($spell[$word] && $spell[$word] !== true)
            {
                list($r) = self::bestguess($word, $spell[$word]);
                if (mb_strtolower($word) != mb_strtolower($r))
                    $suggestion_needed = true;
            }
            else
                $r = $word;
            $ret = substr($ret, 0, $words[$i][1]) . $r .
                substr($ret, $words[$i][1]+strlen($word));
        }

        if ($suggestion_needed)
            return $ret;
        return '';
    }

    // Check spelling of $words and suggest options, if spelling is not correct,
    // using builtin PHP 'pspell' module
    static function builtin_spell($words)
    {
        global $wgUser, $wgSphinxSearchPersonalDictionary, $wgSphinxSearchPspellDictionaryDir;

        $conf_user = pspell_config_create(
            $wgUser->getDefaultOption('language'),
            $wgUser->getDefaultOption('variant'),
            NULL, 'utf-8'
        );
        pspell_config_mode($conf_user, PSPELL_FAST | PSPELL_RUN_TOGETHER);
        if ($wgSphinxSearchPspellDictionaryDir)
        {
            pspell_config_data_dir($conf_user, $wgSphinxSearchPspellDictionaryDir);
            pspell_config_dict_dir($conf_user, $wgSphinxSearchPspellDictionaryDir);
        }
        if ($wgSphinxSearchPersonalDictionary)
            pspell_config_personal($conf_user, $wgSphinxSearchPersonalDictionary);
        $pspell_user = pspell_new_config($conf_user);

        if (!$pspell_user)
        {
            wfDebug(__METHOD__.': Error starting pspell dictionary');
            return array();
        }

        if (strtolower($wgUser->getDefaultOption('language')) != 'en')
        {
            $conf_en = pspell_config_create('en', 'US', NULL, 'utf-8');
            pspell_config_mode($conf_en, PSPELL_FAST | PSPELL_RUN_TOGETHER);
            $pspell_en = pspell_new_config($conf_en);
        }

        $suggest = array();
        foreach ($words as $word)
        {
            if (!pspell_check($pspell_user, $word) &&
                (!$pspell_en || !pspell_check($pspell_en, $word)))
            {
                $suggest[$word] = pspell_suggest($pspell_user, $word);
                if ($pspell_en)
                    $suggest[$word] = array_merge($suggest[$word], pspell_suggest($pspell_en, $word));
            }
        }

        pspell_clear_session($pspell_user);
        if ($pspell_en)
            pspell_clear_session($pspell_en);
        return $suggest;
    }

    // Check spelling of $words and suggest options, if spelling is not correct,
    // using external application 'aspell'
    static function nonnative_spell($words)
    {
        global $wgUser, $wgSphinxSearchPersonalDictionary, $wgSphinxSearchAspellPath;

        // prepare the system call with optional dictionary
        $aspell = $wgSphinxSearchAspellPath;
        if (!$aspell)
            $aspell = 'aspell';
        elseif (preg_match('#[/\\\\]$#', $aspell))
            $aspell .= 'aspell';
        $aspellcommand = 'echo '.wfEscapeShellArg(implode(' ', $words)).' | '.
            wfEscapeShellArg($aspell).' -a --ignore-accents --ignore-case';
        if ($wgSphinxSearchPersonalDictionary)
        {
            $aspellcommand .= ' --home-dir='.dirname($wgSphinxSearchPersonalDictionary);
            $aspellcommand .= ' -p '.basename($wgSphinxSearchPersonalDictionary);
        }

        // run aspell
        $ulang = strtolower($wgUser->getDefaultOption('language'));
        $suggest_user = self::parse_aspell(wfShellExec($aspellcommand.' --lang='.$ulang), $words);
        if ($ulang != 'en')
            $suggest_en = self::parse_aspell(wfShellExec($aspellcommand.' --lang=en'), $words);

        $suggest = array();
        foreach ($words as $word)
        {
            if ($suggest_user[$word] !== true && ($ulang == 'en' || $suggest_en[$word] !== true))
            {
                $suggest[$word] = $suggest_user[$word];
                if ($ulang != 'en')
                    $suggest[$word] = array_merge($suggest[$word], $suggest_en[$word]);
            }
        }

        return $suggest;
    }

    // Parse aspell commandline output line by line
    static function parse_aspell($output, $words)
    {
        $suggest = array();
        foreach ($words as $word)
            $suggest[$word] = true;
        foreach (explode("\n", $output) as $str)
        {
            if ($str{0} == '&' || $str{0} == '#')
            {
                $word = substr($str, 2, strpos($str, ' ', 2)-2);
                $suggest[$word] = array();
                // lines with suggestions start with &
                if ($str{0} == '&')
                {
                    $s = explode(', ', substr($str, strpos($str, ':') + 2));
                    if (count($s) != 1 || $s[0] !== '')
                        $suggest[$word] = $s;
                }
            }
        }
        return $suggest;
    }

    /* This function takes a word, and an array of suggested words
     * and figure out which suggestion is closest sounding to
     * the word. This is made possible with the use of the
     * levenshtein() function.
     */
    static function bestguess($word, $suggestions)
    {
        if (!$suggestions)
            return array($word, 0x10000);
        $dist = -1;
        foreach ($suggestions as $suggested)
        {
            $lev = levenshtein(mb_strtolower($word), mb_strtolower($suggested));
            // if this distance is less than the next found shortest
            // distance, OR if a next shortest word has not yet been found
            if ($dist < 0 || $lev <= $dist)
            {
                // set the closest match, and shortest distance
                $r = $suggested;
                $dist = $lev;
            }
        }
        return array($r, $dist);
    }
}
