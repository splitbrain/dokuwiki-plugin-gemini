<?php

use dokuwiki\File\MediaResolver;
use dokuwiki\File\PageResolver;

/**
 * DokuWiki Plugin gemini (Renderer Component)
 *
 * This implements rendering to gemtext
 *
 * @link https://gemini.circumlunar.space/docs/gemtext.gmi
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */
class renderer_plugin_gemini extends Doku_Renderer
{
    const NL = "\n";

    protected $linklist = [];
    protected $schemes = null;

    protected $table = null;

    /**
     * constructor
     */
    public function __construct()
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    /** @inheritDoc */
    public function getFormat()
    {
        return 'gemini';
    }

    /** @inheritdoc */
    public function plugin($name, $data, $state = '', $match = '')
    {
        $this->doc .= $match; // FIXME always?
    }

    /** @inheritdoc */
    public function header($text, $level, $pos)
    {
        if ($level > 3) $level = 3;
        $this->doc .= str_pad('', $level, '#');
        $this->doc .= $text;
        $this->linebreak();
    }

    /**
     * Output links at the end of the section
     * @inheritdoc
     */
    public function section_close()
    {
        if (empty($this->linklist)) return;

        $this->linebreak();
        foreach ($this->linklist as $number => $link) {
            $this->doc .= '=> ' . $link['url'] . " [$number] " . $link['title'];
            $this->linebreak();
        }
        $this->linebreak();

        $this->linklist = [];
    }

    /** @inheritdoc */
    public function cdata($text)
    {
        $this->doc .= $text;
    }

    /** @inheritdoc */
    public function p_close()
    {
        $this->linebreak();
        $this->linebreak();
    }

    /**
     * @inheritdoc
     * @params bool $optional only output linebreak if not already one there
     */
    public function linebreak($optional = false)
    {
        if ($optional && $this->doc[-1] == self::NL) return;
        $this->doc .= self::NL;
    }

    /** @inheritdoc */
    public function hr()
    {
        $this->linebreak();
        $this->doc .= str_pad('', 70, '━');
        $this->linebreak();
    }

    /** @inheritdoc */
    public function footnote_open()
    {
        $this->doc .= ' ❲';
    }

    /** @inheritdoc */
    public function footnote_close()
    {
        $this->doc .= '❳ ';
    }

    /** @inheritdoc */
    public function listu_close()
    {
        $this->linebreak();
    }

    /** @inheritdoc */
    public function listo_close()
    {
        $this->linebreak();
    }

    /** @inheritdoc */
    public function listitem_open($level, $node = false)
    {
        $this->doc .= '*';
    }

    public function listcontent_close()
    {
        $this->linebreak();
    }

    /** @inheritdoc */
    public function php($text)
    {
        $this->cdata($text);
    }

    /** @inheritdoc */
    public function phpblock($text)
    {
        $this->preformatted($text);
    }

    /** @inheritdoc */
    public function html($text)
    {
        $this->cdata($text);
    }

    /** @inheritdoc */
    public function htmlblock($text)
    {
        $this->preformatted($text);
    }

    /** @inheritdoc */
    public function preformatted($text)
    {
        $this->file($text);
    }

    /** @inheritdoc */
    public function quote_open()
    {
        $this->doc .= '> ';
    }

    /** @inheritdoc */
    public function quote_close()
    {
        $this->linebreak(true);
    }

    /** @inheritdoc */
    public function file($text, $lang = null, $file = null)
    {
        $this->linebreak(true);
        $this->doc .= "```$file";
        $this->linebreak();
        $this->cdata($text);
        $this->linebreak(true);
        $this->doc .= '```';
        $this->linebreak();
    }

    /** @inheritdoc */
    public function code($text, $lang = null, $file = null)
    {
        $this->file($text, $lang, $file);
    }

    /** @inheritdoc */
    public function acronym($acronym)
    {
        $this->cdata($acronym);
    }

    /** @inheritdoc */
    public function smiley($smiley)
    {
        $this->cdata($smiley);
    }

    /** @inheritdoc */
    public function entity($entity)
    {
        if (array_key_exists($entity, $this->entities)) {
            $this->doc .= $this->entities[$entity];
        } else {
            $this->cdata($entity);
        }
    }

    /** @inheritdoc */
    public function multiplyentity($x, $y)
    {
        $this->cdata($x . '×' . $y);
    }

    /** @inheritdoc */
    public function singlequoteopening()
    {
        global $lang;
        $this->doc .= $lang['singlequoteopening'];
    }

    /** @inheritdoc */
    public function singlequoteclosing()
    {
        global $lang;
        $this->doc .= $lang['singlequoteclosing'];
    }

    /** @inheritdoc */
    public function apostrophe()
    {
        global $lang;
        $this->doc .= $lang['apostrophe'];
    }

    /** @inheritdoc */
    public function doublequoteopening()
    {
        global $lang;
        $this->doc .= $lang['doublequoteopening'];
    }

    /** @inheritdoc */
    public function doublequoteclosing()
    {
        global $lang;
        $this->doc .= $lang['doublequoteclosing'];
    }

    /** @inheritdoc */
    public function camelcaselink($link)
    {
        $this->internallink($link);
    }

    /** @inheritdoc */
    public function locallink($hash, $name = null)
    {
        if (!$name) $name = $hash;
        $this->cdata($name);
    }

    /** @inheritdoc */
    public function internallink($id, $title = null)
    {
        global $ID;

        $id = explode('?', $id, 2)[0];
        $id = explode('#', $id, 2)[0];
        if ($id === '') $id = $ID;
        $id = (new PageResolver($ID))->resolveId($id);

        if (page_exists($id)) {
            $url = '//' . $_SERVER['HTTP_HOST'] . '/' . $id;
        } else {
            $url = '';
        }

        // reuse externallink - handles media titles
        $this->externallink($url, $title);
    }

    /**
     * Note: $url may be empty when passed from internalmedia and pages does not exist
     * @inheritdoc
     */
    public function externallink($url, $title = null)
    {
        // image in link title - print it first
        if (is_array($title)) {
            if ($title['type'] == 'internalmedia') {
                $this->internalmedia($title['src'], $title['title']);
            } else {
                $this->externalmedia($title['src'], $title['title']);
            }
            $title = $title['title'];
            $ismedia = true;
        } else {
            $ismedia = false;
        }
        if (!$title) $title = $url;

        // add to list of links
        if ($url) {
            $count = count($this->linklist);
            $count++;
            $this->linklist[$count] = [
                'url' => $url,
                'title' => $title,
            ];
        }

        // output
        if (!$ismedia) {
            // print the title and count
            $this->cdata($title);
            if ($url) $this->doc .= "[$count]";
        } elseif ($url) {
            // remove newline, print count, add newline
            $this->doc = substr($this->doc, 0, -1);
            $this->doc .= "[$count]";
            $this->linebreak();
        }
    }

    /** @inheritdoc */
    public function rss($url, $params)
    {
        global $conf;
        $feed = new FeedParser();
        $feed->set_feed_url($url);

        $rc = $feed->init();
        if (!$rc) return;

        if ($params['nosort']) $feed->enable_order_by_date(false);

        //decide on start and end
        if ($params['reverse']) {
            $mod = -1;
            $start = $feed->get_item_quantity() - 1;
            $end = $start - ($params['max']);
            $end = ($end < -1) ? -1 : $end;
        } else {
            $mod = 1;
            $start = 0;
            $end = $feed->get_item_quantity();
            $end = ($end > $params['max']) ? $params['max'] : $end;
        }

        for ($x = $start; $x != $end; $x += $mod) {
            $item = $feed->get_item($x);
            $this->doc .= '=> ' . $item->get_permalink() . ' ' . $item->get_title();
            $this->linebreak();
            if ($params['author'] || $params['date']) {
                if ($params['author']) $this->cdata($item->get_author(0)->get_name());
                if ($params['date']) $this->cdata(' (' . $item->get_local_date($conf['dformat']) . ')');
                $this->linebreak();
            }
            if ($params['details']) {
                $this->doc .= '> ' . strip_tags($item->get_description());
                $this->linebreak();
            }
        }
    }

    /** @inheritdoc */
    public function interwikilink($link, $title, $wikiName, $wikiUri)
    {
        $exists = null;
        $url = $this->_resolveInterWiki($wikiName, $wikiUri, $exists);
        if (!$title) $title = $wikiUri;
        if ($exists === null) {
            $this->externallink($url, $title);
        } elseif ($exists === true) {
            $this->internallink($url, $title);
        } else {
            $this->cdata($title);
        }
    }

    public function windowssharelink($link, $title = null)
    {
        parent::windowssharelink($link, $title); // TODO: Change the autogenerated stub
    }

    /** @inheritdoc */
    public function emaillink($address, $name = null)
    {
        if (!$name) $name = $address;
        $this->externallink('mailto:' . $address, $name);
    }

    /** @inheritdoc */
    public function internalmedia(
        $src,
        $title = null,
        $align = null,
        $width = null,
        $height = null,
        $cache = null,
        $linking = null
    ) {
        global $ID;

        $src = (new MediaResolver($ID))->resolveId($src);
        if (!media_exists($src)) return;
        $src = '//' . $_SERVER['HTTP_HOST'] . '/_media/' . $src;

        $this->externalmedia($src, $title);
    }

    /** @inheritdoc */
    public function externalmedia(
        $src,
        $title = null,
        $align = null,
        $width = null,
        $height = null,
        $cache = null,
        $linking = null
    ) {
        if (!$title) $title = basename($src);
        $title = "[$title]";

        $this->linebreak(true);
        $this->doc .= '=> ' . $src . ' ' . $title;
        $this->linebreak();
    }

    /** @inheritdoc */
    public function internalmedialink($src, $title = null, $align = null, $width = null, $height = null, $cache = null)
    {
        $this->internalmedia($src, $title, $align, $width, $height, $cache);
    }

    /** @inheritdoc */
    public function externalmedialink($src, $title = null, $align = null, $width = null, $height = null, $cache = null)
    {
        parent::externalmedia($src, $title, $align, $width, $height, $cache);
    }

    public function table_open($maxcols = null, $numrows = null, $pos = null)
    {

    }

    public function table_close($pos = null)
    {

    }

    public function tablethead_open()
    {
        parent::tablethead_open(); // TODO: Change the autogenerated stub
    }

    public function tablethead_close()
    {
        parent::tablethead_close(); // TODO: Change the autogenerated stub
    }

    public function tabletbody_open()
    {
        parent::tabletbody_open(); // TODO: Change the autogenerated stub
    }

    public function tabletbody_close()
    {
        parent::tabletbody_close(); // TODO: Change the autogenerated stub
    }

    public function tabletfoot_open()
    {
        parent::tabletfoot_open(); // TODO: Change the autogenerated stub
    }

    public function tabletfoot_close()
    {
        parent::tabletfoot_close(); // TODO: Change the autogenerated stub
    }

    public function tablerow_open()
    {
        parent::tablerow_open(); // TODO: Change the autogenerated stub
    }

    public function tablerow_close()
    {
        parent::tablerow_close(); // TODO: Change the autogenerated stub
    }

    public function tableheader_open($colspan = 1, $align = null, $rowspan = 1)
    {
        parent::tableheader_open($colspan, $align, $rowspan); // TODO: Change the autogenerated stub
    }

    public function tableheader_close()
    {
        parent::tableheader_close(); // TODO: Change the autogenerated stub
    }

    public function tablecell_open($colspan = 1, $align = null, $rowspan = 1)
    {
        parent::tablecell_open($colspan, $align, $rowspan); // TODO: Change the autogenerated stub
    }

    public function tablecell_close()
    {
        parent::tablecell_close(); // TODO: Change the autogenerated stub
    }

}

