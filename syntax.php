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
    public function getType() {
        return 'container';
    }

    /**
     * @return string Paragraph type
     */
    public function getPType() {
        return 'block';
    }

    /**
     * @return int Sort order - Low numbers go before high numbers
     */
    public function getSort() {
        // Run it before the standard media functionality
        return 315;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {
        // match everything the media component does, but short circuit into my code first
        $this->Lexer->addSpecialPattern("\{\{(?:[^\}\>\<]|(?:\}[^\>\<\}]))+\}\}", $mode, 'plugin_svgembed');
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
    public function handle($match, $state, $pos, Doku_Handler $handler) {
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
     * @param string        $mode     Renderer mode (supported modes: xhtml, metadata)
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     *
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        // If no data or we're not rendering XHTML, exit without handling
        if (!$data)
            return false;


        if ($mode == 'xhtml') {
            global $conf;

            // Determine the maximum width allowed
            if (isset($data['width'])) {
                // Single width value specified?  Render with this width, but determine the file height and scale accordingly
                $svg_max = $data['width'];
            }
            else {
                // If a value is set, use that, else load the default value
                $svg_max = isset($conf['plugin']['svgembed']['max_svg_width']) ?
                             $conf['plugin']['svgembed']['max_svg_width'] :
                             $this->getConf('max_svg_width');
            }


            // From here, it's basically a copy of the default renderer, but it inserts SVG with an embed tag rather than img tag.
            $ret = '';
            $hasdimensions = (isset($data['width']) && isset($data['height']));

            // If both dimensions are not specified by the page then find them in the SVG file (if possible), and if not just pop out a default
            if (!$hasdimensions) {
                $svg_file = sprintf('%s%s', $conf['mediadir'], str_replace(':', '/', $data['src']));

                if (file_exists($svg_file) && ($svg_fp = fopen($svg_file, 'r'))) {
                    $svg_xml = simplexml_load_file($svg_file);
                    $svg_width = round(floatval($svg_xml->attributes()->width));
                    $svg_height = round(floatval($svg_xml->attributes()->height));

                    if ($svg_width < 1 || $svg_height < 1) {
                        if (isset($svg_xml->attributes()->viewBox)) {
                            $svg_viewbox = preg_split('/[ ,]{1,}/', $svg_xml->attributes()->viewBox);
                            $svg_width = round(floatval($svg_viewbox[2]));
                            $svg_height = round(floatval($svg_viewbox[3]));
                        }
                    }

                    if ($svg_width < 1 || $svg_height < 1) {
                        $svg_width = isset($conf['plugin']['svgembed']['default_width']) ?
                                       $conf['plugin']['svgembed']['default_width'] :
                                       $this->getConf('default_width');;
                        $svg_height = isset($conf['plugin']['svgembed']['default_height']) ?
                                        $conf['plugin']['svgembed']['default_height'] :
                                        $this->getConf('default_height');;
                    }

                    unset($svg_viewbox, $svg_xml);
                    fclose($svg_fp);
                }

                // Make sure we're not exceeding the maximum width; if so, let's scale the SVG value to the maximum size
                if ($svg_width > $svg_max) {
                    $svg_height = round($svg_height * $svg_max / $svg_width);
                    $svg_width = $svg_max;
                }


                $data['width'] = $svg_width;
                $data['height'] = $svg_height;
            }
            else {
                $svg_width = $data['width'];
                $svg_height = $data['height'];
            }

            switch($data['align']) {
                case 'center':
                    $styleextra = "margin:auto";
                    break;
                case 'left':
                case 'right':
                    $styleextra = "float:" . urlencode($data['align']);
                    break;
                default:
                    $styleextra = '';
            }

            $ret .= sprintf('<span style="display:block;width:%dpx;height:%dpx;%s">', $svg_width, $svg_height, $styleextra);

            $ret .= '<embed type="image/svg+xml" src="' . ml($data['src'], array('w' => $data['width'], 'h' => $data['height'], 'cache' => $data['cache'],
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

            $ret .= ' /></span>';

            $renderer->doc .= $ret;
        }

        if ($mode == 'metadata') {
            // Add metadata so the SVG is associated to the page
            if ($data['type'] == 'internalmedia') {
                global $ID;

                $src = $data['src'];
                list($src) = explode('#', $src, 2);

                if (media_isexternal($src))
                    return;

                resolve_mediaid(getNS($ID), $src, $exists);
                $renderer->meta['relation']['media'][$src] = $exists;
            }
        }


        return true;
    }
}

