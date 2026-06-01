<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ConversionIQ SEO Analyzer
 *
 * Performs deterministic on-page SEO analysis for a WordPress page/post.
 * No external API calls required for Tier 1.
 * Tier 2 CWV data is sourced from Real User Metrics already collected by
 * the heatmap tracker (conversioniq_get_rum_cwv).
 */
class ConversionIQ_SEO_Analyzer {

    // Score category weights (must sum to 1.0)
    const WEIGHTS = [
        'meta'      => 0.20,
        'headings'  => 0.15,
        'keywords'  => 0.15,
        'images'    => 0.10,
        'links'     => 0.10,
        'schema'    => 0.10,
        'technical' => 0.20,
    ];

    /**
     * Run a full SEO analysis for a given post ID.
     *
     * @param  int  $post_id
     * @return array|WP_Error
     */
    public static function analyze( int $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            return new WP_Error( 'invalid_post', 'Post not found or not published.' );
        }

        $page_url = get_permalink( $post );
        ciq_log( 'SEO[' . $post_id . '] start: "' . $post->post_title . '" ' . $page_url );

        // Fetch rendered HTML once — used by multiple sub-analyzers
        $html = self::fetch_html( $page_url, $post );
        ciq_log( 'SEO[' . $post_id . '] html: ' . ( $html ? strlen( $html ) . ' bytes' : '⚠️ empty — scoring will be degraded' ) );

        // Collect all signals
        $meta_data    = self::extract_meta( $post, $html );
        $heading_data = self::extract_headings( $html );
        $keyword_data = self::extract_keywords( $post, $html, $meta_data, $heading_data );
        $image_data   = self::extract_images( $html );
        $link_data    = self::extract_links( $html, $page_url );
        $schema_data  = self::extract_schema( $html );
        $tech_data    = self::extract_technical( $post, $html, $page_url );

        // Score each category
        $scores = [
            'meta'      => self::score_meta( $meta_data ),
            'headings'  => self::score_headings( $heading_data ),
            'keywords'  => self::score_keywords( $keyword_data ),
            'images'    => self::score_images( $image_data ),
            'links'     => self::score_links( $link_data ),
            'schema'    => self::score_schema( $schema_data ),
            'technical' => self::score_technical( $tech_data ),
        ];

        // Weighted overall score
        $overall = 0;
        foreach ( $scores as $cat => $score ) {
            $overall += $score * self::WEIGHTS[ $cat ];
        }
        $overall = (int) round( $overall );

        ciq_log(
            'SEO[' . $post_id . '] scores: overall=' . $overall
            . ' meta=' . $scores['meta']
            . ' head=' . $scores['headings']
            . ' kw='   . $scores['keywords']
            . ' img='  . $scores['images']
            . ' lnk='  . $scores['links']
            . ' sch='  . $scores['schema']
            . ' tech=' . $scores['technical']
        );

        // Build issue/checklist items
        $checklist = self::build_checklist( $meta_data, $heading_data, $keyword_data, $image_data, $link_data, $schema_data, $tech_data );

        // Prioritised action items
        $actions = self::build_actions( $checklist, $scores );
        ciq_log( 'SEO[' . $post_id . '] checklist: ' . count( array_filter( $checklist, fn($i) => ! $i['pass'] ) ) . ' fail / ' . count( $checklist ) . ' total — ' . count( $actions ) . ' actions' );

        // Tier 2: Real User Metrics Core Web Vitals
        $cwv = function_exists( 'conversioniq_get_rum_cwv' ) ? conversioniq_get_rum_cwv( $page_url ) : null;
        $cwv_scores = $cwv ? self::score_cwv( $cwv ) : null;
        ciq_log( 'SEO[' . $post_id . '] cwv: ' . ( $cwv ? 'lcp=' . ( $cwv['lcp_ms'] ?? '?' ) . 'ms cls=' . ( $cwv['cls'] ?? '?' ) . ' inp=' . ( $cwv['inp_ms'] ?? '?' ) . 'ms' : 'no RUM data' ) );

        ciq_log( 'SEO[' . $post_id . '] done ✅' );

        return [
            'page_id'         => $post_id,
            'page_title'      => $post->post_title,
            'page_url'        => $page_url,
            'overall_score'   => $overall,
            'category_scores' => $scores,
            'checklist'       => $checklist,
            'actions'         => $actions,
            'details'         => [
                'meta'      => $meta_data,
                'headings'  => $heading_data,
                'keywords'  => $keyword_data,
                'images'    => $image_data,
                'links'     => $link_data,
                'schema'    => $schema_data,
                'technical' => $tech_data,
            ],
            'core_web_vitals' => $cwv,
            'cwv_scores'      => $cwv_scores,
            'analyzed_at'     => gmdate( 'c' ),
        ];
    }

    // ── HTML fetcher ──────────────────────────────────────────────────────

    private static function fetch_html( string $url, ?WP_Post $post = null ): string {
        $response = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => true ] );
        if ( is_wp_error( $response ) ) {
            ciq_log( 'SEO fetch_html error: ' . $response->get_error_message() . ' — url=' . $url );
        } elseif ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
            $code = wp_remote_retrieve_response_code( $response );
            ciq_log( 'SEO fetch_html non-200: HTTP ' . $code . ' — url=' . $url );
        } else {
            return wp_remote_retrieve_body( $response );
        }

        // Loopback blocked or timeout — build HTML from post content so heading/image/link/keyword
        // extraction still works even when the site can't HTTP-request itself.
        if ( $post ) {
            ciq_log( 'SEO fetch_html: using post-content fallback for post_id=' . $post->ID );
            $content = apply_filters( 'the_content', $post->post_content );
            $title   = esc_html( $post->post_title );
            return "<!DOCTYPE html><html><head><title>{$title}</title></head><body>{$content}</body></html>";
        }

        return '';
    }

    // ── Extractors ────────────────────────────────────────────────────────

    /**
     * Meta tags — sourced from Yoast/RankMath postmeta AND the rendered HTML.
     */
    private static function extract_meta( WP_Post $post, string $html ): array {
        $data = [
            'title'              => '',
            'title_length'       => 0,
            'description'        => '',
            'description_length' => 0,
            'og_title'           => '',
            'og_description'     => '',
            'og_image'           => '',
            'twitter_card'       => '',
            'robots'             => '',
            'focus_keyword'      => '',
            'seo_plugin'         => 'none',
        ];

        // ── Yoast SEO ──
        $yoast_title = get_post_meta( $post->ID, '_yoast_wpseo_title', true );
        $yoast_desc  = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
        $yoast_kw    = get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
        $yoast_robots_noindex = get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true );

        if ( $yoast_title || $yoast_desc || $yoast_kw ) {
            $data['seo_plugin']    = 'yoast';
            $data['focus_keyword'] = sanitize_text_field( $yoast_kw );
            $data['noindex_meta']  = ( $yoast_robots_noindex === '1' );
        }

        // ── RankMath ──
        if ( $data['seo_plugin'] === 'none' ) {
            $rm_title = get_post_meta( $post->ID, 'rank_math_title', true );
            $rm_desc  = get_post_meta( $post->ID, 'rank_math_description', true );
            $rm_kw    = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
            $rm_robots = get_post_meta( $post->ID, 'rank_math_robots', true );
            if ( $rm_title || $rm_desc || $rm_kw ) {
                $data['seo_plugin']    = 'rankmath';
                $data['focus_keyword'] = sanitize_text_field( explode( ',', $rm_kw )[0] ?? '' );
                $data['noindex_meta']  = ( is_array( $rm_robots ) && in_array( 'noindex', $rm_robots, true ) );
            }
        }

        // ── All in One SEO (AIOSEO) ──
        if ( $data['seo_plugin'] === 'none' ) {
            $aio_title = get_post_meta( $post->ID, '_aioseo_title', true );
            $aio_desc  = get_post_meta( $post->ID, '_aioseo_description', true );
            $aio_kw    = get_post_meta( $post->ID, '_aioseo_keywords', true );
            if ( $aio_title || $aio_desc ) {
                $data['seo_plugin']    = 'aioseo';
                if ( $aio_kw ) {
                    $kw_decoded = json_decode( $aio_kw, true );
                    $data['focus_keyword'] = sanitize_text_field( $kw_decoded[0]['label'] ?? '' );
                }
            }
        }

        // ── Parse rendered HTML for actual <meta> and <title> tags ──
        if ( $html ) {
            // <title>
            if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
                $data['title'] = trim( wp_strip_all_tags( $m[1] ) );
            }
            // meta description
            if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $html, $m )
              || preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']description["\'][^>]*>/is', $html, $m ) ) {
                $data['description'] = trim( $m[1] );
            }
            // robots meta
            if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $html, $m )
              || preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']robots["\'][^>]*>/is', $html, $m ) ) {
                $data['robots'] = strtolower( trim( $m[1] ) );
            }
            // OG tags
            if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $html, $m ) ) {
                $data['og_title'] = trim( $m[1] );
            }
            if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $html, $m ) ) {
                $data['og_description'] = trim( $m[1] );
            }
            if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $html, $m ) ) {
                $data['og_image'] = trim( $m[1] );
            }
            // Twitter Card
            if ( preg_match( '/<meta[^>]+name=["\']twitter:card["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/is', $html, $m ) ) {
                $data['twitter_card'] = trim( $m[1] );
            }
            // canonical
            if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/is', $html, $m ) ) {
                $data['canonical'] = trim( $m[1] );
            }
        }

        $data['title_length']       = mb_strlen( $data['title'] );
        $data['description_length'] = mb_strlen( $data['description'] );

        // Derive noindex from rendered robots tag if SEO plugin didn't set it
        if ( ! isset( $data['noindex_meta'] ) ) {
            $data['noindex_meta'] = ( strpos( $data['robots'], 'noindex' ) !== false );
        }

        return $data;
    }

    /**
     * Heading structure from rendered HTML.
     */
    private static function extract_headings( string $html ): array {
        if ( ! $html ) {
            return [ 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [], 'h1_word_count' => 0 ];
        }

        $data = [];
        for ( $i = 1; $i <= 6; $i++ ) {
            preg_match_all( "/<h{$i}[^>]*>(.*?)<\/h{$i}>/is", $html, $m );
            $data[ 'h' . $i ] = array_map( 'wp_strip_all_tags', $m[1] );
        }

        // Word count of the first H1 — used to detect hero H1/H2 pattern issues.
        $data['h1_word_count'] = ! empty( $data['h1'][0] )
            ? str_word_count( wp_strip_all_tags( $data['h1'][0] ) )
            : 0;

        return $data;
    }

    /**
     * Keyword usage across the page content.
     */
    private static function extract_keywords( WP_Post $post, string $html, array $meta, array $headings ): array {
        $keyword = strtolower( trim( $meta['focus_keyword'] ?? '' ) );
        $content = strtolower( wp_strip_all_tags( $post->post_content ) );

        // Get just body text from HTML (strip nav/footer/header noise) — best effort
        $body_text = $html ? strtolower( wp_strip_all_tags( $html ) ) : $content;
        $word_count = str_word_count( $body_text );

        $data = [
            'focus_keyword'        => $keyword,
            'has_focus_keyword'    => $keyword !== '',
            'in_title'             => false,
            'in_h1'                => false,
            'in_first_paragraph'   => false,
            'in_meta_description'  => false,
            'in_slug'              => false,
            'density_pct'          => 0,
            'occurrence_count'     => 0,
            'word_count'           => $word_count,
        ];

        if ( $keyword === '' ) {
            return $data;
        }

        // Title
        $data['in_title'] = ( strpos( strtolower( $meta['title'] ), $keyword ) !== false );

        // H1
        foreach ( $headings['h1'] ?? [] as $h1 ) {
            if ( strpos( strtolower( $h1 ), $keyword ) !== false ) {
                $data['in_h1'] = true;
                break;
            }
        }

        // First ~150 words of body text
        $first_para = implode( ' ', array_slice( explode( ' ', $body_text ), 0, 150 ) );
        $data['in_first_paragraph'] = ( strpos( $first_para, $keyword ) !== false );

        // Meta description
        $data['in_meta_description'] = ( strpos( strtolower( $meta['description'] ), $keyword ) !== false );

        // URL slug
        $slug = strtolower( $post->post_name );
        $data['in_slug'] = ( strpos( $slug, str_replace( ' ', '-', $keyword ) ) !== false
                          || strpos( $slug, str_replace( ' ', '_', $keyword ) ) !== false );

        // Density
        $kw_count = substr_count( $body_text, $keyword );
        $data['occurrence_count'] = $kw_count;
        if ( $word_count > 0 ) {
            $kw_words   = str_word_count( $keyword );
            $data['density_pct'] = round( ( $kw_count * $kw_words / $word_count ) * 100, 2 );
        }

        return $data;
    }

    /**
     * Image alt text coverage.
     */
    private static function extract_images( string $html ): array {
        if ( ! $html ) {
            return [ 'total' => 0, 'with_alt' => 0, 'without_alt' => 0, 'coverage_pct' => 100 ];
        }

        preg_match_all( '/<img[^>]+>/is', $html, $img_tags );
        $total    = count( $img_tags[0] );
        $with_alt = 0;

        foreach ( $img_tags[0] as $tag ) {
            // alt="" (empty) is actually intentional for decorative images — we count it as present
            if ( preg_match( '/\balt\s*=/i', $tag ) ) {
                $with_alt++;
            }
        }

        $without_alt = $total - $with_alt;

        return [
            'total'        => $total,
            'with_alt'     => $with_alt,
            'without_alt'  => $without_alt,
            'coverage_pct' => $total > 0 ? round( ( $with_alt / $total ) * 100 ) : 100,
        ];
    }

    /**
     * Internal vs. external link ratio.
     */
    private static function extract_links( string $html, string $page_url ): array {
        if ( ! $html ) {
            return [ 'internal' => 0, 'external' => 0, 'total' => 0 ];
        }

        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $internal  = 0;
        $external  = 0;

        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/is', $html, $links );
        foreach ( $links[1] as $href ) {
            $href = trim( $href );
            if ( strpos( $href, '#' ) === 0 || strpos( $href, 'javascript:' ) === 0 || strpos( $href, 'mailto:' ) === 0 ) {
                continue; // Skip anchors and mailto
            }
            $parsed_host = wp_parse_url( $href, PHP_URL_HOST );
            if ( ! $parsed_host || $parsed_host === $site_host || strpos( $href, '/' ) === 0 ) {
                $internal++;
            } else {
                $external++;
            }
        }

        return [
            'internal' => $internal,
            'external' => $external,
            'total'    => $internal + $external,
        ];
    }

    /**
     * Structured data / Schema.org detection.
     */
    private static function extract_schema( string $html ): array {
        if ( ! $html ) {
            return [ 'has_json_ld' => false, 'has_microdata' => false, 'types' => [], 'count' => 0 ];
        }

        $types = [];

        // JSON-LD
        preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $blocks );
        foreach ( $blocks[1] as $block ) {
            $parsed = json_decode( trim( $block ), true );
            if ( ! $parsed ) continue;

            // Could be a @graph array or a single item
            $items = isset( $parsed['@graph'] ) ? $parsed['@graph'] : [ $parsed ];
            foreach ( $items as $item ) {
                if ( isset( $item['@type'] ) ) {
                    $type = is_array( $item['@type'] ) ? $item['@type'] : [ $item['@type'] ];
                    $types = array_merge( $types, $type );
                }
            }
        }

        // Microdata (basic detection)
        preg_match_all( '/itemtype=["\']https?:\/\/schema\.org\/([^"\']+)["\']/i', $html, $micro );
        if ( ! empty( $micro[1] ) ) {
            $types = array_merge( $types, $micro[1] );
        }

        $types = array_unique( array_filter( $types ) );

        return [
            'has_json_ld'    => count( $blocks[1] ) > 0,
            'has_microdata'  => ! empty( $micro[1] ),
            'types'          => array_values( $types ),
            'count'          => count( $types ),
        ];
    }

    /**
     * Technical SEO signals.
     */
    private static function extract_technical( WP_Post $post, string $html, string $page_url ): array {
        $parsed = wp_parse_url( $page_url );

        // Slug quality: count words, check for stop words, check length
        $slug        = $post->post_name;
        $slug_words  = array_filter( explode( '-', $slug ) );
        $slug_length = count( $slug_words );

        // Common English stop words that add no SEO value to slugs
        $stop_words = [ 'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'it' ];
        $stop_word_count = count( array_intersect( $slug_words, $stop_words ) );

        // Canonical from meta extractor (passed in html)
        $canonical = '';
        if ( $html && preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\'][^>]*>/is', $html, $m ) ) {
            $canonical = trim( $m[1] );
        }

        // URL cleanliness: no query strings, no uppercase, no underscores
        $has_query_string = ! empty( $parsed['query'] ?? '' );
        $has_uppercase    = $slug !== strtolower( $slug );
        $has_underscores  = strpos( $slug, '_' ) !== false;

        return [
            'canonical_set'       => $canonical !== '',
            'canonical_url'       => $canonical,
            'is_https'            => ( ( $parsed['scheme'] ?? '' ) === 'https' ),
            'has_query_string'    => $has_query_string,
            'slug'                => $slug,
            'slug_length'         => $slug_length,
            'slug_has_stop_words' => $stop_word_count > 0,
            'slug_has_uppercase'  => $has_uppercase,
            'slug_has_underscores'=> $has_underscores,
        ];
    }

    // ── Scorers ───────────────────────────────────────────────────────────

    private static function score_meta( array $d ): int {
        $score = 0;

        // Title (40pts)
        if ( $d['title'] !== '' ) {
            $score += 15;
            $len = $d['title_length'];
            if ( $len >= 30 && $len <= 60 ) $score += 25;
            elseif ( $len >= 20 && $len <= 70 ) $score += 15;
            else $score += 5;
        }

        // Description (35pts)
        if ( $d['description'] !== '' ) {
            $score += 15;
            $len = $d['description_length'];
            if ( $len >= 120 && $len <= 155 ) $score += 20;
            elseif ( $len >= 70 && $len <= 180 ) $score += 10;
            else $score += 3;
        }

        // OG (15pts)
        if ( $d['og_title'] !== '' )       $score += 5;
        if ( $d['og_description'] !== '' ) $score += 5;
        if ( $d['og_image'] !== '' )       $score += 5;

        // Twitter Card (5pts)
        if ( $d['twitter_card'] !== '' )   $score += 5;

        // Focus keyword in title (5pts bonus if Yoast/RankMath present)
        if ( $d['focus_keyword'] !== '' && $d['title'] !== '' ) {
            if ( strpos( strtolower( $d['title'] ), strtolower( $d['focus_keyword'] ) ) !== false ) {
                $score += 5;
            }
        }

        return min( 100, $score );
    }

    private static function score_headings( array $d ): int {
        $score  = 0;
        $h1s    = count( $d['h1'] );
        $h2s    = count( $d['h2'] );
        $h3s    = count( $d['h3'] );

        // H1 (40pts)
        if ( $h1s === 1 )        $score += 40;
        elseif ( $h1s === 0 )    $score += 0;
        else                     $score += 15; // Multiple H1s — penalise

        // H2 (30pts)
        if ( $h2s >= 2 )         $score += 30;
        elseif ( $h2s === 1 )    $score += 18;

        // H3 (15pts)
        if ( $h3s >= 1 )         $score += 15;

        // Hierarchy: no skipping levels (H1 → H3 without H2) (15pts)
        if ( $h1s >= 1 && $h2s >= 1 ) $score += 15;
        elseif ( $h1s >= 1 )           $score += 5;

        // Hero H1 length: a long H1 (>10 words) tries to do double-duty as both
        // keyword and conversion copy — penalise slightly (-10pts).
        if ( ! empty( $d['h1_word_count'] ) && $d['h1_word_count'] > 10 ) {
            $score -= 10;
        }

        return min( 100, max( 0, $score ) );
    }

    private static function score_keywords( array $d ): int {
        if ( ! $d['has_focus_keyword'] ) {
            // No focus keyword configured — return neutral 50 with a note
            return 50;
        }

        $score = 0;

        // In H1 (25pts)
        if ( $d['in_h1'] )               $score += 25;

        // In first paragraph (20pts)
        if ( $d['in_first_paragraph'] )  $score += 20;

        // In meta description (20pts)
        if ( $d['in_meta_description'] ) $score += 20;

        // Keyword density 0.5–2.5% (25pts)
        $density = $d['density_pct'];
        if ( $density >= 0.5 && $density <= 2.5 )      $score += 25;
        elseif ( $density > 0 && $density < 0.5 )       $score += 10;
        elseif ( $density > 2.5 && $density <= 4.0 )    $score += 10;
        // density > 4% = keyword stuffing, 0pts

        // In URL slug (10pts)
        if ( $d['in_slug'] )             $score += 10;

        return min( 100, $score );
    }

    private static function score_images( array $d ): int {
        return (int) $d['coverage_pct']; // 0-100 directly
    }

    private static function score_links( array $d ): int {
        $score    = 0;
        $internal = $d['internal'];
        $external = $d['external'];

        // Internal links (60pts)
        if ( $internal >= 5 )      $score += 60;
        elseif ( $internal >= 3 )  $score += 45;
        elseif ( $internal >= 1 )  $score += 25;

        // External links presence (20pts — signals credibility)
        if ( $external >= 1 )      $score += 20;

        // Internal:external ratio >= 2:1 (20pts)
        if ( $external === 0 && $internal >= 2 ) {
            $score += 20;
        } elseif ( $external > 0 && ( $internal / $external ) >= 2 ) {
            $score += 20;
        } elseif ( $external > 0 && ( $internal / $external ) >= 1 ) {
            $score += 10;
        }

        return min( 100, $score );
    }

    private static function score_schema( array $d ): int {
        $score = 0;

        if ( $d['has_json_ld'] || $d['has_microdata'] ) {
            $score += 50;
        }

        $count = $d['count'];
        if ( $count >= 3 )      $score += 30;
        elseif ( $count === 2 ) $score += 20;
        elseif ( $count === 1 ) $score += 10;

        // Bonus for high-value schema types
        $high_value = [ 'Organization', 'LocalBusiness', 'FAQPage', 'Article', 'Product', 'BreadcrumbList', 'WebSite' ];
        foreach ( $high_value as $type ) {
            if ( in_array( $type, $d['types'], true ) ) {
                $score += 5;
                break; // one bonus per match group
            }
        }

        return min( 100, $score );
    }

    private static function score_technical( array $d ): int {
        $score = 0;

        // HTTPS (20pts)
        if ( $d['is_https'] ) $score += 20;

        // Canonical set (25pts)
        if ( $d['canonical_set'] ) $score += 25;

        // No query string in URL (15pts)
        if ( ! $d['has_query_string'] ) $score += 15;

        // Slug quality (40pts)
        $slug_score = 40;
        if ( $d['slug_length'] > 6 )    $slug_score -= 15;
        if ( $d['slug_has_stop_words'] ) $slug_score -= 10;
        if ( $d['slug_has_uppercase'] )  $slug_score -= 10;
        if ( $d['slug_has_underscores'] ) $slug_score -= 10;
        $score += max( 0, $slug_score );

        return min( 100, $score );
    }

    /**
     * Score Core Web Vitals against Google thresholds.
     * Returns [ metric => 'good'|'needs_improvement'|'poor' ]
     */
    public static function score_cwv( array $cwv ): array {
        $scores = [];

        // LCP (Largest Contentful Paint): good < 2500ms
        if ( isset( $cwv['lcp_ms'] ) && $cwv['lcp_ms'] !== null ) {
            $lcp = (int) $cwv['lcp_ms'];
            $scores['lcp'] = $lcp < 2500 ? 'good' : ( $lcp <= 4000 ? 'needs_improvement' : 'poor' );
        }

        // CLS (Cumulative Layout Shift): good < 0.1
        if ( isset( $cwv['cls'] ) && $cwv['cls'] !== null ) {
            $cls = (float) $cwv['cls'];
            $scores['cls'] = $cls < 0.1 ? 'good' : ( $cls <= 0.25 ? 'needs_improvement' : 'poor' );
        }

        // INP (Interaction to Next Paint): good < 200ms
        if ( isset( $cwv['inp_ms'] ) && $cwv['inp_ms'] !== null ) {
            $inp = (int) $cwv['inp_ms'];
            $scores['inp'] = $inp < 200 ? 'good' : ( $inp <= 500 ? 'needs_improvement' : 'poor' );
        }

        // FCP (First Contentful Paint): good < 1800ms
        if ( isset( $cwv['fcp_ms'] ) && $cwv['fcp_ms'] !== null ) {
            $fcp = (int) $cwv['fcp_ms'];
            $scores['fcp'] = $fcp < 1800 ? 'good' : ( $fcp <= 3000 ? 'needs_improvement' : 'poor' );
        }

        // TTFB (Time to First Byte): good < 800ms
        if ( isset( $cwv['ttfb_ms'] ) && $cwv['ttfb_ms'] !== null ) {
            $ttfb = (int) $cwv['ttfb_ms'];
            $scores['ttfb'] = $ttfb < 800 ? 'good' : ( $ttfb <= 1800 ? 'needs_improvement' : 'poor' );
        }

        return $scores;
    }

    // ── Checklist builder ─────────────────────────────────────────────────

    private static function build_checklist( array $meta, array $headings, array $keywords, array $images, array $links, array $schema, array $tech ): array {
        $items = [];

        // Meta
        $items[] = self::item( 'Title tag present',          $meta['title'] !== '',             'meta',     'Add a <title> tag to the page.' );
        $items[] = self::item( 'Title length 30–60 chars',   $meta['title_length'] >= 30 && $meta['title_length'] <= 60, 'meta',
            "Title is {$meta['title_length']} chars. Aim for 30–60 characters for best display in search results." );
        $items[] = self::item( 'Meta description present',   $meta['description'] !== '',       'meta',     'Add a meta description (120–155 characters).' );
        $items[] = self::item( 'Meta description 120–155 chars', $meta['description_length'] >= 120 && $meta['description_length'] <= 155, 'meta',
            "Description is {$meta['description_length']} chars. Aim for 120–155 characters." );
        $items[] = self::item( 'Open Graph image set',       $meta['og_image'] !== '',          'meta',     'Set an og:image meta tag for rich link previews on social media.' );
        $items[] = self::item( 'Open Graph title & description', $meta['og_title'] !== '' && $meta['og_description'] !== '', 'meta',
            'Set og:title and og:description meta tags for social sharing previews.' );

        // Headings
        $h1_count      = count( $headings['h1'] );
        $h1_word_count = $headings['h1_word_count'] ?? 0;
        $items[] = self::item( 'Single H1 tag',       $h1_count === 1,       'headings',
            $h1_count === 0 ? 'No H1 tag found. Every page needs exactly one H1.' : ( $h1_count > 1 ? "Found {$h1_count} H1 tags. Use only one H1 per page." : '' ) );
        $items[] = self::item( 'H2 subheadings present', count( $headings['h2'] ) >= 2, 'headings', 'Add at least 2 H2 subheadings to structure your content.' );
        $items[] = self::item( 'Logical heading hierarchy', count( $headings['h1'] ) >= 1 && count( $headings['h2'] ) >= 1, 'headings',
            'Use H1 → H2 → H3 in order. Do not skip heading levels.' );

        // Hero H1/H2 split: H1 should be short + keyword-focused; H2 handles conversion copy.
        // A long H1 (>10 words) is trying to do both jobs at once and failing at both.
        if ( $h1_count === 1 && $h1_word_count > 10 ) {
            $items[] = self::item(
                'Hero H1 is concise and keyword-focused',
                false,
                'headings',
                "Your H1 is {$h1_word_count} words — it's trying to be both a keyword signal and a conversion headline at the same time. Split it: keep a short H1 (3–6 words) that targets your primary keyword, then add a larger H2 directly below it with your persuasive, conversion-driven message. Visitors read the big text first (your H2) while Google reads the H1 for ranking signals. You get both."
            );
        } elseif ( $h1_count === 1 && $h1_word_count > 0 && count( $headings['h2'] ) === 0 ) {
            $items[] = self::item(
                'Hero has a conversion-focused H2 alongside the H1',
                false,
                'headings',
                'Your H1 exists but there is no H2 on the page. Consider adding an H2 directly after the H1 in the hero: let the H1 handle the keyword/search intent (short, specific), and use the H2 for a compelling, action-driving headline that converts visitors.'
            );
        }

        // Keywords
        if ( $keywords['has_focus_keyword'] ) {
            $kw = $keywords['focus_keyword'];
            $items[] = self::item( "Keyword in H1",                  $keywords['in_h1'],               'keywords', "Include \"{$kw}\" in your H1 heading." );
            $items[] = self::item( "Keyword in first paragraph",     $keywords['in_first_paragraph'],  'keywords', "Use \"{$kw}\" within the first 150 words of the page." );
            $items[] = self::item( "Keyword in meta description",    $keywords['in_meta_description'], 'keywords', "Include \"{$kw}\" in the meta description." );
            $items[] = self::item( "Keyword in URL slug",            $keywords['in_slug'],             'keywords', "Use \"{$kw}\" in the page slug (URL)." );
            $density = $keywords['density_pct'];
            $density_ok = $density >= 0.5 && $density <= 2.5;
            $items[] = self::item( "Keyword density 0.5–2.5%",       $density_ok,                      'keywords',
                $density < 0.5 ? "Keyword density is {$density}% — too low. Mention \"{$kw}\" more naturally." :
                ( $density > 2.5 ? "Keyword density is {$density}% — potential keyword stuffing. Reduce usage." : '' ) );
        } else {
            $items[] = self::item( 'Focus keyword configured', false, 'keywords', 'Set a focus keyword in Yoast SEO, RankMath, or AIOSEO for detailed keyword analysis.' );
        }

        // Images
        if ( $images['total'] > 0 ) {
            $items[] = self::item( "Image alt text coverage",
                $images['coverage_pct'] >= 90,
                'images',
                "Only {$images['with_alt']} of {$images['total']} images have alt attributes. Add descriptive alt text to all meaningful images." );
        }

        // Links
        $items[] = self::item( 'Internal links present (3+)', $links['internal'] >= 3, 'links',
            "Page has {$links['internal']} internal link(s). Link to at least 3 related internal pages to improve crawlability." );
        $items[] = self::item( 'External links present',      $links['external'] >= 1, 'links',
            'No external links found. Linking to authoritative sources can improve topical credibility.' );

        // Schema
        $items[] = self::item( 'Structured data (Schema.org) present', $schema['has_json_ld'] || $schema['has_microdata'], 'schema',
            'No Schema.org structured data found. Add JSON-LD for Organization, WebPage, or FAQPage to improve rich results eligibility.' );
        if ( $schema['count'] > 0 ) {
            $items[] = self::item( 'Multiple schema types',  $schema['count'] >= 2, 'schema',
                'Only one schema type detected. Consider adding BreadcrumbList, FAQPage, or Article schema for more rich result opportunities.' );
        }

        // Technical
        $items[] = self::item( 'HTTPS enabled',              $tech['is_https'],          'technical', 'Serve your site over HTTPS. HTTP pages are flagged as "Not Secure" by browsers and penalised by Google.' );
        $items[] = self::item( 'Canonical URL set',          $tech['canonical_set'],     'technical', 'Add a <link rel="canonical"> tag to prevent duplicate content issues.' );
        $items[] = self::item( 'Clean URL (no query strings)', ! $tech['has_query_string'], 'technical', 'The page URL contains query parameters. Use permalink slugs for cleaner, more SEO-friendly URLs.' );
        $items[] = self::item( 'Concise URL slug (≤6 words)', $tech['slug_length'] <= 6, 'technical',
            "Slug has {$tech['slug_length']} words. Keep slugs short and descriptive (3–5 words is ideal)." );
        $items[] = self::item( 'Slug uses hyphens (no underscores)', ! $tech['slug_has_underscores'], 'technical', 'Replace underscores in the slug with hyphens — Google treats hyphens as word separators.' );

        return $items;
    }

    /**
     * Helper: create a checklist item.
     */
    private static function item( string $label, bool $pass, string $category, string $fix = '' ): array {
        return [
            'label'    => $label,
            'pass'     => $pass,
            'category' => $category,
            'fix'      => $pass ? '' : $fix,
        ];
    }

    /**
     * Build prioritised action items from failed checklist items + category scores.
     */
    private static function build_actions( array $checklist, array $scores ): array {
        // Rank categories by how far below 80 they are
        $category_priority = [];
        foreach ( $scores as $cat => $score ) {
            if ( $score < 80 ) {
                $category_priority[ $cat ] = 80 - $score;
            }
        }
        arsort( $category_priority );

        $actions = [];
        // Add failed items, sorted by category priority
        foreach ( array_keys( $category_priority ) as $cat ) {
            foreach ( $checklist as $item ) {
                if ( ! $item['pass'] && $item['category'] === $cat && $item['fix'] !== '' ) {
                    $actions[] = [
                        'category' => $cat,
                        'label'    => $item['label'],
                        'fix'      => $item['fix'],
                        'priority' => $category_priority[ $cat ] >= 30 ? 'high' : ( $category_priority[ $cat ] >= 15 ? 'medium' : 'low' ),
                    ];
                }
            }
        }

        return array_slice( $actions, 0, 20 ); // Cap at 20 actions
    }
}
