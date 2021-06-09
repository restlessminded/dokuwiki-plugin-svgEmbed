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
     * Get the new parameters added to the syntax.
     *
     * @param string $match The text that matched the lexer pattern that we are inspecting
     */
    private function Get_New_Parameters($match, &$p) {
        // Strip the opening and closing markup
        $link = preg_replace(array('/^\{\{/', '/\}\}$/u'), '', $match);

        // Split title from URL
        $link = explode('|', $link, 2);

        //remove aligning spaces
        $link[0] = trim($link[0]);

        //split into src and parameters (using the very last questionmark)
        $pos = strrpos($link[0], '?');

        if ($pos !== false) {
            $param = substr($link[0], $pos + 1);

            // Get units
            $p['inResponsiveUnits'] = (preg_match('/units:(\%|vw)/i', $param, $local_match) > 0);
            $p['responsiveUnits'] = ($p['inResponsiveUnits'] && count($local_match) > 1) ? $local_match[1] : NULL;

            // Get declared CSS classes
            unset($local_match);
            $p['hasCssClasses'] = (preg_match_all('/class:(-?[_a-z]+[_a-z0-9-]*)/i', $param, $local_match) > 0);
            $p['cssClasses'] = ($p['hasCssClasses'] && isset($local_match[1]) && count($local_match[1])) ? $local_match[1] : NULL;

            // Get printing
            if (preg_match_all('/(^|&)(print|print:(on|true|yes|1|off|false|no|0))(&|$)/i', $param, $local_match)) {
                $p['print'] = in_array(strtolower($local_match[2][0]), array('print', 'print:on', 'print:true', 'print:yes', 'print:1'));
            }
            else {
                $p['print'] = ($this->getConf('default_print') == '1');
            }

            // Re-parse width and height
            $param = preg_replace('/class:(-?[_a-zA-Z]+[_a-zA-Z0-9-]*)/i', '', $param);   // Remove the classes since they can have numbers embedded
            if(preg_match('/(\d+)(x(\d+))?/i', $param, $size)) {
                $p['width'] = (!empty($size[1]) ? $size[1] : null);
                $p['height'] = (!empty($size[3]) ? $size[3] : null);
            } else {
                $p['width'] = null;
                $p['height'] = null;
            }
        }
    }


    /**
     * Figure out the pixel adjustment if an absolute measurement unit is given.
     *
     * @param string $value Dimension to analyze for unit value (cm|mm|Q|in|pc|pt|px)
     */
    private function Get_SVG_Unit_Adjustment($value) {
        define('SVG_DPI', 96.0);

        $matches = array();
        $adjustment = 1;

        if (preg_match('/(cm|mm|Q|in|pc|pt|px)/', $value, $matches) && count($matches)) {
            switch ($matches[1]) {
            // Don't bother checking for "px", we already set adjustment to 1, but we still
            //   want to count it in the matches
            case 'pt':
                $adjustment = SVG_DPI / 72;
                break;
            case 'pc':
                $adjustment = SVG_DPI / 6;
                break;
            case 'in':
                $adjustment = SVG_DPI;
                break;
            case 'cm':
                $adjustment = SVG_DPI / 2.54;
                break;
            case 'mm':
                $adjustment = SVG_DPI / 25.4;
                break;
            case 'Q':
                $adjustment = SVG_DPI / 101.6;
                break;
            }
        }

        return $adjustment;
    }


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
        if ($isSVG)
            $this->Get_New_Parameters($match, $p);

        if (!$isSVG || $p['type'] != 'internalmedia') {
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

                    // Find the amount to adjust the pixels for layout if a unit is involved; use the
                    //   largest adjustment if they are mixed
                    $svg_adjustment = max($this->Get_SVG_Unit_Adjustment($svg_xml->attributes()->width),
                                          $this->Get_SVG_Unit_Adjustment($svg_xml->attributes()->height));

                    $svg_width = round(floatval($svg_xml->attributes()->width) * $svg_adjustment);
                    $svg_height = round(floatval($svg_xml->attributes()->height) * $svg_adjustment);

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
                                       $this->getConf('default_width');
                        $svg_height = isset($conf['plugin']['svgembed']['default_height']) ?
                                        $conf['plugin']['svgembed']['default_height'] :
                                        $this->getConf('default_height');
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

            $svgembed_md5 = sprintf('svgembed_%s', md5(ml($data['src'], $ml_array)));
            $ret .= '<span style="display:block';

            $spanunits = (isset($data['responsiveUnits'])) ? $data['responsiveUnits'] : 'px';

            if (isset($data['width']))
                $ret .= ";width:{$data['width']}{$spanunits}";

            if (isset($data['height']))
                $ret .= ";height:{$data['height']}{$spanunits}";

            if (strlen($styleextra))
                $ret .= ";{$styleextra}";

            $ret .= '">';


            $ml_array = array('cache' => $data['cache']);
            if (!$data['inResponsiveUnits'])
                $ml_array = array_merge($ml_array, array('w' => $data['width'], 'h' => $data['height']));

            $properties = '"' . ml($data['src'], $ml_array) . '" class="media' . $data['align'] . '"';

            if ($data['title']) {
                $properties .= ' title="' . $data['title'] . '"';
                $properties .= ' alt="' . $data['title'] . '"';
            } else {
                $properties .= ' alt=""';
            }


            if (!(is_null($data['width']) || is_null($data['height']))) {
                $properties .= ' style="width:100%"';
            }


            // Add any extra specified classes to the objects
            if ($data['hasCssClasses'] && count($data['cssClasses']) > 0) {
                $additionalCssClasses = '';
                foreach ($data['cssClasses'] as $newCssClass)
                    $additionalCssClasses .= " " . $renderer->_xmlEntities($newCssClass);
                $additionalCssClasses = trim($additionalCssClasses);

                if (preg_match('/class="([^"]*)"/i', $properties, $pmatches)) {
                    $properties = str_replace("class=\"{$pmatches[1]}\"", "class=\"{$pmatches[1]} {$additionalCssClasses}\"", $properties);
                }

                unset($additionalCssClasses, $newCssClass);
            }

            if (is_a($renderer, 'renderer_plugin_dw2pdf')) {
                $ret .= "<img id=\"" . $svgembed_md5 . "\" src={$properties} />";
            }
            else {
                $ret .= "<object id=\"" . $svgembed_md5 . "\" type=\"image/svg+xml\" data={$properties}><embed type=\"image/svg+xml\" src={$properties} /></object>";

                unset($properties);
    
                if ($data['print']) {
                    $ret .= '<div class="svgprintbutton_table"><button type="submit" title="Print SVG" onClick="svgembed_printContent(\'' .
                            urlencode(ml($data['src'], $ml_array)) . '\'); return false" onMouseOver="svgembed_onMouseOver(\'' .
                            $svgembed_md5 . '\'); return false" ' . 'onMouseOut="svgembed_onMouseOut(\'' . $svgembed_md5 . '\'); return false"' .
                            '>Print SVG</button></div>';
                }
            }

            $ret .= '</span>';

            $ret .= '<br />';

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

