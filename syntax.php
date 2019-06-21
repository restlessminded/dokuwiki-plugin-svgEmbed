<?php
/**
 * DokuWiki Plugin svgEmbed (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Bowers <restlessmind@gmail.com>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class syntax_plugin_svgembed extends DokuWiki_Syntax_Plugin
{
    /**
     * @return string Syntax mode type
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * @return string Paragraph type
     */
    public function getPType()
    {
        return 'normal';
    }

    /**
     * @return int Sort order - Low numbers go before high numbers
     */
    public function getSort()
    {
        // Run it before the standard media functionality
        return 315;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode)
    {
        // match everything the media component does, but short circuit into my code first
        $this->Lexer->addSpecialPattern("\{\{(?:[^\}]|(?:\}[^\}]))+\}\}", $mode, 'plugin_svgembed');
    }

    /**
     * Handle matches of the media syntax, overridden by this plugin
     *
     * @param string       $match   The match of the syntax
     * @param int          $state   The state of the handler
     * @param int          $pos     The position in the document
     * @param Doku_Handler $handler The handler
     *
     * @return array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $p = Doku_Handler_Parse_Media($match);
        $isSVG = preg_match('/\.svg$/i', trim($p['src']));

        if ($p['type'] != 'internalmedia' || !$isSVG) {
            // If it's external media or not an SVG, perform the regular processing...
            $handler->media($match, $state, $pos);
            return false;
        }
        else {
            // ...otherwise, feed into my renderer
            return ($p);
        }
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string        $mode     Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     *
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        // If no data or we're not rendering XHTML, exit without handling
        if (!$data || $mode != 'xhtml')
            return false;

        // From here, it's basically a copy of the default renderer, but it inserts SVG with an embed tag rather than img tag.
        $ret = '<embed src="' . ml($data['src'], array('w' => $data['width'], 'h' => $data['height'], 'cache' => $data['cache'],
                                                    'rev' => $renderer->_getLastMediaRevisionAt($data['src']))) . '"';

        $ret .= ' class="media' . $data['align'] . '"';

        if ($data['title']) {
            $ret .= ' title="' . $data['title'] . '"';
            $ret .= ' alt="' . $data['title'] . '"';
        } else {
            $ret .= ' alt=""';
        }

        if (!is_null($data['width']))
            $ret .= ' width="' . $renderer->_xmlEntities($data['width']) . '"';

        if (!is_null($data['height']))
            $ret .= ' height="' . $renderer->_xmlEntities($data['height']) . '"';

        $ret .= ' />';

        $renderer->doc .= $ret;

        return true;
    }
}

