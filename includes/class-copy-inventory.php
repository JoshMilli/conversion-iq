<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ConversionIQ Copy Inventory
 *
 * Deterministically enumerates the copy-bearing elements at the TOP of a page, in
 * document order, so the audit never misses or skips sections. We take the hero plus
 * the next few sections (default: hero + 5 = 6 top-level sections) and capture every
 * text element within them — with the hero always yielding a heading, sub-heading and
 * CTA when present.
 *
 * The output is handed to the AI as the authoritative list to rewrite (it no longer
 * discovers sections itself), and each item carries the exact current text plus a
 * selector the applier can target.
 *
 * Two engines:
 *   • Elementor  — walk the `_elementor_data` widget tree (same source the applier edits).
 *   • DOM        — parse the rendered HTML (Gutenberg / classic / Divi / Beaver / other).
 *
 * @since 2.5.0
 */
class ConversionIQ_Copy_Inventory {

    /** Default number of top-level sections to capture (hero + 5). */
    const DEFAULT_SECTIONS = 6;

    /**
     * Build the ordered copy inventory for a post.
     *
     * @param WP_Post     $post
     * @param int         $max_sections Top-level sections to capture (hero counts as 1).
     * @param string|null $rendered_html Pre-rendered HTML (optional; used for the DOM engine).
     * @return array Ordered list of items: each
     *   { id, section_index, section_label, role, text, selector }.
     */
    public static function extract( WP_Post $post, int $max_sections = self::DEFAULT_SECTIONS, ?string $rendered_html = null ): array {
        $max_sections = max( 1, $max_sections );

        $is_elementor = ( get_post_meta( $post->ID, '_elementor_edit_mode', true ) === 'builder' )
            || ! empty( get_post_meta( $post->ID, '_elementor_data', true ) );

        if ( $is_elementor ) {
            $items = self::extract_elementor( $post, $max_sections );
            if ( ! empty( $items ) ) {
                self::log( $post->ID, 'elementor', $items );
                return $items;
            }
        }

        // Gutenberg / classic / other → DOM engine over the rendered HTML.
        if ( $rendered_html === null && function_exists( 'conversioniq_render_page_content' ) ) {
            $rendered_html = conversioniq_render_page_content( $post );
        }
        $items = self::extract_dom( (string) $rendered_html, $max_sections );
        self::log( $post->ID, 'dom', $items );
        return $items;
    }

    // ── Elementor engine ────────────────────────────────────────────────────────

    private static function extract_elementor( WP_Post $post, int $max_sections ): array {
        $raw = get_post_meta( $post->ID, '_elementor_data', true );
        if ( empty( $raw ) ) return array();

        $tree = is_array( $raw ) ? $raw : json_decode( $raw, true );
        if ( ! is_array( $tree ) ) $tree = json_decode( wp_unslash( (string) $raw ), true );
        if ( ! is_array( $tree ) ) return array();

        // Top-level structural elements (sections/containers), in document order.
        $top = array_values( array_filter( $tree, function ( $el ) {
            return is_array( $el ) && in_array( $el['elType'] ?? '', array( 'section', 'container' ), true );
        } ) );
        if ( empty( $top ) ) $top = array_values( $tree ); // unusual layout — use whatever is there

        $items = array();
        $count = min( count( $top ), $max_sections );
        for ( $si = 0; $si < $count; $si++ ) {
            $widgets = array();
            self::collect_elementor_widgets( array( $top[ $si ] ), $widgets );
            if ( empty( $widgets ) ) continue;
            self::append_section_items( $items, $si, $widgets );
        }
        return $items;
    }

    /**
     * Recursively collect text-bearing Elementor widgets in document order.
     * Each entry: { role, text, selector } where role is heading|paragraph|cta|list.
     */
    private static function collect_elementor_widgets( array $elements, array &$out ): void {
        foreach ( $elements as $el ) {
            if ( ! is_array( $el ) ) continue;

            if ( ( $el['elType'] ?? '' ) === 'widget' ) {
                $wt = (string) ( $el['widgetType'] ?? '' );
                $s  = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
                $sel = self::elementor_selector( $wt, $s, $el['id'] ?? '' );

                if ( strpos( $wt, 'heading' ) !== false && ! empty( $s['title'] ) ) {
                    $out[] = array( 'role' => 'heading', 'text' => self::plain( $s['title'] ), 'selector' => $sel );
                } elseif ( strpos( $wt, 'button' ) !== false && ! empty( $s['text'] ) ) {
                    $out[] = array( 'role' => 'cta', 'text' => self::plain( $s['text'] ), 'selector' => $sel );
                } elseif ( ! empty( $s['editor'] ) ) {
                    $out[] = array( 'role' => 'paragraph', 'text' => self::plain( $s['editor'] ), 'selector' => $sel );
                } else {
                    // Composite widgets (icon-box, call-to-action, etc.).
                    if ( ! empty( $s['title_text'] ) )       $out[] = array( 'role' => 'heading',   'text' => self::plain( $s['title_text'] ),       'selector' => $sel );
                    if ( ! empty( $s['title'] ) )            $out[] = array( 'role' => 'heading',   'text' => self::plain( $s['title'] ),            'selector' => $sel );
                    if ( ! empty( $s['description_text'] ) ) $out[] = array( 'role' => 'paragraph', 'text' => self::plain( $s['description_text'] ), 'selector' => $sel );
                    if ( ! empty( $s['description'] ) )      $out[] = array( 'role' => 'paragraph', 'text' => self::plain( $s['description'] ),      'selector' => $sel );
                    if ( ! empty( $s['text'] ) )             $out[] = array( 'role' => 'paragraph', 'text' => self::plain( $s['text'] ),             'selector' => $sel );
                }
            }

            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                self::collect_elementor_widgets( $el['elements'], $out );
            }
        }
    }

    /** Best-effort CSS selector for an Elementor widget (custom class/id → tag fallback). */
    private static function elementor_selector( string $wt, array $settings, string $widget_id ): string {
        $classes = trim( (string) ( $settings['_css_classes'] ?? '' ) );
        if ( $classes !== '' ) {
            return '.' . preg_split( '/\s+/', $classes )[0];
        }
        $eid = trim( (string) ( $settings['_element_id'] ?? '' ) );
        if ( $eid !== '' ) return '#' . $eid;

        if ( strpos( $wt, 'heading' ) !== false ) {
            $level = strtolower( (string) ( $settings['header_size'] ?? '' ) );
            return preg_match( '/^h[1-6]$/', $level ) ? $level : 'h2';
        }
        if ( strpos( $wt, 'button' ) !== false ) return 'a';
        return '';
    }

    // ── DOM engine (Gutenberg / classic / Divi / Beaver / other) ─────────────────

    private static function extract_dom( string $html, int $max_sections ): array {
        $html = trim( $html );
        if ( $html === '' || ! class_exists( 'DOMDocument' ) ) return array();

        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors( true );
        // Force UTF-8 so accented copy is preserved.
        $doc->loadHTML( '<?xml encoding="UTF-8"><div id="ciq-root">' . $html . '</div>' );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        $xpath = new DOMXPath( $doc );

        // Prefer real <section> elements; else segment by top-level headings.
        $sections = $xpath->query( '//section' );
        $items    = array();

        if ( $sections && $sections->length >= 2 ) {
            $count = min( $sections->length, $max_sections );
            for ( $si = 0; $si < $count; $si++ ) {
                $widgets = array();
                self::collect_dom_widgets( $sections->item( $si ), $widgets );
                if ( ! empty( $widgets ) ) self::append_section_items( $items, $si, $widgets );
            }
            return $items;
        }

        // Fallback: one flat pass, split into pseudo-sections at each heading.
        $root = $xpath->query( '//*[@id="ciq-root"]' )->item( 0 );
        if ( ! $root ) return array();
        $flat = array();
        self::collect_dom_widgets( $root, $flat );

        $si = -1;
        $seen_heading = false;
        foreach ( $flat as $w ) {
            if ( $w['role'] === 'heading' ) {
                // A new heading starts a new pseudo-section.
                $si++;
                $seen_heading = true;
            } elseif ( ! $seen_heading ) {
                $si = 0; $seen_heading = true; // copy before any heading = section 0
            }
            if ( $si >= $max_sections ) break;
            self::append_section_items( $items, max( 0, $si ), array( $w ) );
        }
        return $items;
    }

    /** Collect copy elements from a DOM node in document order. */
    private static function collect_dom_widgets( ?DOMNode $node, array &$out ): void {
        if ( ! $node ) return;
        $skip = array( 'script', 'style', 'nav', 'footer', 'aside', 'noscript', 'head', 'form' );

        foreach ( $node->childNodes as $child ) {
            if ( ! ( $child instanceof DOMElement ) ) continue;
            $tag = strtolower( $child->tagName );
            if ( in_array( $tag, $skip, true ) ) continue;

            if ( preg_match( '/^h[1-4]$/', $tag ) ) {
                $t = self::plain( $child->textContent );
                if ( $t !== '' ) $out[] = array( 'role' => 'heading', 'text' => $t, 'selector' => self::dom_selector( $child, $tag ) );
                continue; // heading text captured; don't double-count descendants
            }
            if ( $tag === 'button' || ( $tag === 'a' && self::looks_like_cta( $child ) ) ) {
                $t = self::plain( $child->textContent );
                if ( $t !== '' ) $out[] = array( 'role' => 'cta', 'text' => $t, 'selector' => self::dom_selector( $child, $tag ) );
                continue;
            }
            if ( $tag === 'p' || $tag === 'li' ) {
                $t = self::plain( $child->textContent );
                if ( $t !== '' && mb_strlen( $t ) > 1 ) {
                    $out[] = array( 'role' => ( $tag === 'li' ? 'list' : 'paragraph' ), 'text' => $t, 'selector' => self::dom_selector( $child, $tag ) );
                }
                // Still descend for nested CTAs/headings inside the paragraph/list item.
            }
            self::collect_dom_widgets( $child, $out );
        }
    }

    private static function looks_like_cta( DOMElement $a ): bool {
        $class = strtolower( $a->getAttribute( 'class' ) );
        return (bool) preg_match( '/\b(btn|button|cta)\b/', $class );
    }

    private static function dom_selector( DOMElement $el, string $tag ): string {
        $class = trim( $el->getAttribute( 'class' ) );
        if ( $class !== '' ) {
            // Prefer a meaningful (non-elementor-internal) class.
            foreach ( preg_split( '/\s+/', $class ) as $c ) {
                if ( $c !== '' && strpos( $c, 'elementor' ) !== 0 && ! preg_match( '/^(wp-|has-|is-)/', $c ) ) {
                    return $tag . '.' . $c;
                }
            }
        }
        $id = trim( $el->getAttribute( 'id' ) );
        if ( $id !== '' ) return '#' . $id;
        return $tag;
    }

    // ── Shared: section item assembly + hero classification ──────────────────────

    /**
     * Append a section's widgets to the flat inventory, classifying roles. The hero
     * (section_index 0) always surfaces a heading, sub-heading and CTA when present.
     */
    private static function append_section_items( array &$items, int $section_index, array $widgets ): void {
        // De-dupe within the section and drop empties.
        $seen = array();
        $clean = array();
        foreach ( $widgets as $w ) {
            $t = trim( (string) ( $w['text'] ?? '' ) );
            if ( $t === '' ) continue;
            $key = strtolower( $t );
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;
            $clean[] = $w;
        }
        if ( empty( $clean ) ) return;

        $is_hero = ( $section_index === 0 );
        $heading_used = false;
        $sub_used     = false;
        $cta_used     = false;
        $seq          = 0;

        foreach ( $clean as $w ) {
            $seq++;
            $role = $w['role'];
            $label = '';

            if ( $is_hero ) {
                if ( $role === 'heading' && ! $heading_used ) {
                    $label = 'Hero Heading'; $role = 'hero_heading'; $heading_used = true;
                } elseif ( $role === 'heading' && ! $sub_used ) {
                    $label = 'Hero Sub-heading'; $role = 'hero_subheading'; $sub_used = true;
                } elseif ( $role === 'paragraph' && $heading_used && ! $sub_used ) {
                    // First paragraph after the heading serves as the sub-heading.
                    $label = 'Hero Sub-heading'; $role = 'hero_subheading'; $sub_used = true;
                } elseif ( $role === 'cta' && ! $cta_used ) {
                    $label = 'Hero CTA'; $role = 'hero_cta'; $cta_used = true;
                } elseif ( $role === 'cta' ) {
                    $label = 'Hero Secondary CTA'; $role = 'hero_cta_secondary';
                } else {
                    $label = 'Hero ' . ucfirst( $role );
                }
            } else {
                $n = $section_index + 1;
                if ( $role === 'heading' ) $label = "Section {$n} Heading";
                elseif ( $role === 'cta' ) $label = "Section {$n} CTA";
                elseif ( $role === 'list' ) $label = "Section {$n} List Item";
                else $label = "Section {$n} Copy";
            }

            $items[] = array(
                'id'            => 'sec' . $section_index . '-' . $seq,
                'section_index' => $section_index,
                'section_label' => $label,
                'role'          => $role,
                'text'          => trim( (string) $w['text'] ),
                'selector'      => (string) ( $w['selector'] ?? '' ),
            );
        }
    }

    /** Strip tags + decode entities + collapse whitespace. */
    private static function plain( $html ): string {
        $s = html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $s = wp_strip_all_tags( $s );
        return trim( preg_replace( '/\s+/', ' ', $s ) );
    }

    private static function log( int $post_id, string $engine, array $items ): void {
        if ( ! function_exists( 'ciq_log' ) ) return;
        $labels = array();
        foreach ( $items as $it ) {
            if ( count( $labels ) >= 12 ) break;
            $labels[] = $it['section_label'] . ': "' . mb_substr( $it['text'], 0, 40 ) . '"';
        }
        ciq_log( 'Copy inventory (' . $engine . ', post ' . $post_id . '): ' . count( $items )
            . ' item(s) — ' . implode( ' | ', $labels ) );
    }
}
