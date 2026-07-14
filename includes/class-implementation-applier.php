<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ConversionIQ Implementation Applier
 *
 * Applies approved changes from an implementation_reviews batch to a WordPress post.
 * Each change type has its own method. Errors are caught per-change so one failure
 * does not abort the rest. A WP draft is created for block content changes; meta/SEO
 * changes (title, description, alt_text, focus_keyword, og_image) are applied directly
 * to the live post because they are safe, non-visual, and easily reversible.
 *
 * Auth: all public entry points are called from conversioniq_apply_changes() which
 * already validates the X-CIQ-API-Key header before instantiating this class.
 *
 * @since 2.5.0
 */
class ConversionIQ_Implementation_Applier {

    /** @var array Results array: [change_id => [status, error_code, error_message]] */
    private $results = array();

    /** @var bool Whether any block-level change was successfully applied */
    private $blocks_modified = false;

    /** @var array Modified Gutenberg block array (null until first block change) */
    private $modified_blocks = null;

    /** @var string Detected page builder for the current post ('gutenberg'|'elementor'|…) */
    private $builder = '';

    /** @var bool Whether any Elementor widget change was successfully applied */
    private $elementor_modified = false;

    /** @var array|null Modified Elementor data tree (null until parsed) */
    private $modified_elementor = null;

    /** @var int Reserved (legacy). Always 0 — the applier no longer clones the post. */
    private $draft_id = 0;

    /** @var bool When true, changes are written to the LIVE post; when false, only staged for preview. */
    private $commit = false;

    /** Post meta key used to stage edited content for the native preview. */
    const PREVIEW_META = '_ciq_preview_data';

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Apply all approved changes to the given post.
     *
     * @param array    $approved_changes Array of change objects (decision = "approved").
     * @param WP_Post  $post             The target WordPress post.
     * @return array { success: bool, draft_url: string|null, results: array }
     */
    /**
     * Apply approved changes to a post — IN PLACE. Never creates a new post/slug/URL.
     *
     * @param array   $approved_changes Approved change objects.
     * @param WP_Post $post             The existing target post.
     * @param string  $mode             'stage' = compute + stage a native preview (live
     *                                  post untouched); 'publish' = write changes to the
     *                                  live post in place, then remove the staged preview.
     * @return array { success, draft_url (preview link on same permalink, stage only),
     *                 final_url (live permalink — unchanged), results, qa }
     */
    public function apply_all( array $approved_changes, WP_Post $post, string $mode = 'stage' ) {
        $this->results           = array();
        $this->blocks_modified    = false;
        $this->modified_blocks    = null;
        $this->elementor_modified = false;
        $this->modified_elementor = null;
        $this->draft_id           = 0;
        $this->commit             = ( $mode === 'publish' );

        // Detect the builder once — content appliers branch on it.
        $this->builder = $this->detect_page_builder( $post );

        // Pre-parse the content tree for whichever builder is in use.
        if ( $this->builder === 'gutenberg' && function_exists( 'parse_blocks' ) ) {
            $this->modified_blocks = parse_blocks( $post->post_content );
        } elseif ( $this->builder === 'elementor' ) {
            $this->modified_elementor = $this->parse_elementor_data( $post );
        }

        ciq_log( 'Implementation: apply_all — mode=' . $mode . ' post_id=' . $post->ID . ' builder=' . $this->builder . ' changes=' . count( $approved_changes ) );

        foreach ( $approved_changes as $change ) {
            $id   = $change['id']   ?? 'unknown';
            $type = $change['type'] ?? '';

            ciq_log( 'Implementation: processing change id=' . $id . ' type=' . $type );

            try {
                switch ( $type ) {
                    case 'copy_rewrite':
                    case 'reassurance_copy':
                    case 'urgency_copy':
                        $this->apply_copy_rewrite( $change, $post );
                        break;
                    case 'meta_title':
                        $this->apply_meta_title( $change, $post );
                        break;
                    case 'meta_description':
                        $this->apply_meta_description( $change, $post );
                        break;
                    case 'og_image':
                        $this->apply_og_image( $change, $post );
                        break;
                    case 'focus_keyword':
                        $this->apply_focus_keyword( $change, $post );
                        break;
                    case 'alt_text':
                        $this->apply_alt_text( $change );
                        break;
                    case 'insert_block':
                        $this->apply_insert_block( $change, $post );
                        break;
                    case 'schema_inject':
                        $this->apply_schema_inject( $change, $post );
                        break;
                    case 'sticky_cta_css':
                        $this->apply_sticky_cta_css( $change, $post );
                        break;
                    case 'headline_rewrite':
                        $this->apply_headline_rewrite( $change, $post );
                        break;
                    case 'cta_swap':
                        $this->apply_cta_swap( $change, $post );
                        break;
                    default:
                        $this->record( $id, 'skipped', 'unknown_type', 'Change type "' . esc_html( $type ) . '" is not supported.' );
                        ciq_log( 'Implementation: ⚠️ unknown change type "' . $type . '" — skipped' );
                }
            } catch ( Exception $e ) {
                $this->record( $id, 'failed', 'exception', $e->getMessage() );
                ciq_log( 'Implementation: ❌ exception on change id=' . $id . ' — ' . $e->getMessage() );
            }
        }

        // ── Persist — IN PLACE only. Never create a new post/slug/URL. ──────
        $preview_url   = null;
        $preview_id    = 0;
        $preview_token = '';
        $final_url     = get_permalink( $post ); // unchanged before/after, by design

        if ( $this->commit ) {
            // Publish: write the edited content onto the SAME live post.
            $this->commit_content_in_place( $post );
            delete_post_meta( $post->ID, self::PREVIEW_META ); // drop the staged preview
        } elseif ( $this->blocks_modified || $this->elementor_modified ) {
            // Apply: stage the edited content on the same post and build a shareable
            // preview link on the SAME permalink. We deliberately do NOT use WP's
            // preview=true/preview_id/preview_nonce params — that mechanism is bound to
            // a single user and 403s ("not allowed to preview drafts") for anyone else,
            // so it can't be shared. Instead we pass a random ciq_token that our own
            // front-end hook validates; the page stays published, so it renders for
            // logged-in admins AND logged-out clients alike.
            $preview_id    = (int) $post->ID;
            $preview_token = $this->stage_preview( $post );
            $preview_url   = add_query_arg( array(
                'ciq_preview' => $preview_id,
                'ciq_token'   => $preview_token,
            ), get_permalink( $preview_id ) );
            ciq_log( 'Implementation: staged preview — ' . $preview_url );
        }

        // ── Layer-1 QA: verify the edited copy is present in what we persisted/staged ──
        $qa = $this->run_qa( $approved_changes, $post );

        $applied_count = count( array_filter( $this->results, fn( $r ) => $r['status'] === 'applied' ) );
        $failed_count  = count( array_filter( $this->results, fn( $r ) => $r['status'] === 'failed' ) );
        ciq_log( 'Implementation: complete — mode=' . $mode . ' applied=' . $applied_count . ' failed=' . $failed_count );

        return array(
            'success'       => $applied_count > 0,
            'draft_url'     => $preview_url,          // stage: complete shareable preview link; publish: null
            'preview_id'    => $preview_id ?: null,   // drafted post ID
            'preview_token' => $preview_token ?: null,
            'final_url'     => $final_url,            // live permalink — identical before/after
            'results'       => array_values( $this->results ),
            'qa'            => $qa,
        );
    }

    /**
     * Create a WordPress preview nonce bound to the logged-OUT user (uid 0) so the
     * preview link is shareable with clients who are not logged into WordPress.
     * Generate a random, shareable preview token.
     *
     * We do NOT use a WordPress preview nonce: those are bound to a single user
     * (uid + session token), so one nonce cannot work for both a logged-in admin and
     * a logged-out client — WP core's _show_post_preview() would 403 the other party.
     * A random token that our own preview hook validates works for anyone with the
     * link, and the page stays published so there is no draft-permission gate at all.
     */
    private function generate_preview_token(): string {
        if ( function_exists( 'wp_generate_password' ) ) {
            return wp_generate_password( 24, false, false ); // 24 alphanumerics
        }
        return substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 24 );
    }

    /**
     * Write the edited content onto the live post in place. Preserves post_name,
     * post_parent, guid, status and permalink (we only touch post_content / the
     * Elementor data meta), so the URL and post ID never change.
     */
    private function commit_content_in_place( WP_Post $post ): void {
        if ( $this->blocks_modified && $this->modified_blocks !== null && function_exists( 'serialize_blocks' ) ) {
            $res = wp_update_post( array(
                'ID'           => $post->ID,
                'post_content' => serialize_blocks( $this->modified_blocks ),
            ), true );
            if ( is_wp_error( $res ) ) {
                ciq_log( 'Implementation: ❌ wp_update_post failed — ' . $res->get_error_message() );
            } else {
                ciq_log( 'Implementation: ✅ post_content updated in place — post_id=' . $post->ID );
            }
        }

        if ( $this->elementor_modified && is_array( $this->modified_elementor ) ) {
            update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $this->modified_elementor ) ) );
            delete_post_meta( $post->ID, '_elementor_css' ); // force CSS regen
            $this->clear_elementor_cache();
            ciq_log( 'Implementation: ✅ _elementor_data updated in place — post_id=' . $post->ID );
        }
    }

    /**
     * Stage edited content on the SAME post for preview. Stored in a post meta (not a
     * cloned post) along with a random token; a front-end hook swaps it in for requests
     * carrying that token, so the live page is never modified until publish. Returns
     * the token so the caller can build the shareable preview URL.
     */
    private function stage_preview( WP_Post $post ): string {
        $token = $this->generate_preview_token();
        update_post_meta( $post->ID, self::PREVIEW_META, array(
            'builder'      => $this->builder,
            'post_content' => ( $this->blocks_modified && function_exists( 'serialize_blocks' ) )
                ? serialize_blocks( $this->modified_blocks ) : null,
            'elementor'    => $this->elementor_modified ? $this->modified_elementor : null,
            'token'        => $token,
            'ts'           => time(),
        ) );
        return $token;
    }

    /** Clear Elementor's cached CSS so republished text renders on the front end. */
    private function clear_elementor_cache(): void {
        if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            } catch ( \Throwable $t ) {
                ciq_log( 'Implementation: elementor clear_cache skipped — ' . $t->getMessage() );
            }
        }
    }

    // ── Change applicators ────────────────────────────────────────────────────

    /**
     * Copy rewrite / reassurance copy / urgency copy.
     *
     * Walks the block tree to find a block whose stripped innerHTML matches
     * the change's `before` text. Uses exact match first, then fuzzy (≥ 85%).
     * For page-builder sites (Elementor/Divi), returns page_builder_unsupported.
     */
    private function apply_copy_rewrite( array $change, WP_Post $post ) {
        $id     = $change['id']    ?? 'unknown';
        $before = trim( $change['before'] ?? '' );
        $after  = trim( $change['after']  ?? '' );

        if ( empty( $after ) ) {
            $this->record( $id, 'failed', 'invalid_change', 'Missing replacement text (after).' );
            return;
        }

        // Elementor pages: rewrite the widget tree instead of Gutenberg blocks.
        if ( $this->builder === 'elementor' ) {
            $this->apply_copy_rewrite_elementor( $change, $post );
            return;
        }

        if ( $this->builder !== 'gutenberg' ) {
            $this->record( $id, 'failed', 'page_builder_unsupported',
                'This page uses ' . $this->builder . '. Block-level copy rewrites are only available for Gutenberg and Elementor pages. Apply this change manually in your page builder.' );
            return;
        }

        if ( $this->modified_blocks === null ) {
            $this->record( $id, 'failed', 'parse_failed', 'Could not parse Gutenberg blocks from post content.' );
            return;
        }

        // Exact match only — never guess or insert. An empty "before" has nothing to match.
        if ( $before === '' || strtolower( $before ) === '(none)' ) {
            $this->record( $id, 'failed', 'before_not_found',
                'No "before" text supplied, so there is no element to match and replace.' );
            ciq_log( 'Implementation: ❌ copy_rewrite before_not_found (empty before) — id=' . $id );
            return;
        }

        $matched = $this->walk_blocks_for_rewrite( $this->modified_blocks, $before, $after );
        if ( $matched ) {
            $this->blocks_modified = true;
            $this->record( $id, 'applied', null, null );
            ciq_log( 'Implementation: ✅ copy_rewrite applied — id=' . $id );
        } else {
            $this->record( $id, 'failed', 'before_not_found',
                'Could not find the exact original text "' . mb_substr( $before, 0, 80 ) . '" in any block on this page.' );
            ciq_log( 'Implementation: ❌ copy_rewrite before_not_found — id=' . $id );
        }
    }

    /**
     * Meta title update. Works with Yoast, RankMath, AIOSEO.
     * Applied directly to post meta (not in draft — safe and reversible).
     */
    private function apply_meta_title( array $change, WP_Post $post ) {
        $id  = $change['id']  ?? 'unknown';
        $key = $this->resolve_meta_key( 'title', $change['target']['seo_plugin'] ?? '' );
        if ( ! $key ) {
            $this->record( $id, 'failed', 'plugin_not_active',
                'No SEO plugin detected (Yoast SEO, RankMath, or AIOSEO required). Meta title cannot be applied automatically.' );
            return;
        }
        if ( $this->commit ) {
            update_post_meta( $post->ID, $key, sanitize_text_field( $change['after'] ?? '' ) );
        }
        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ meta_title ' . ( $this->commit ? 'applied' : 'staged' ) . ' — post_id=' . $post->ID . ' key=' . $key );
    }

    /**
     * Meta description update.
     */
    private function apply_meta_description( array $change, WP_Post $post ) {
        $id  = $change['id']  ?? 'unknown';
        $key = $this->resolve_meta_key( 'description', $change['target']['seo_plugin'] ?? '' );
        if ( ! $key ) {
            $this->record( $id, 'failed', 'plugin_not_active',
                'No SEO plugin detected (Yoast SEO, RankMath, or AIOSEO required). Meta description cannot be applied automatically.' );
            return;
        }
        if ( $this->commit ) {
            update_post_meta( $post->ID, $key, sanitize_text_field( $change['after'] ?? '' ) );
        }
        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ meta_description ' . ( $this->commit ? 'applied' : 'staged' ) . ' — post_id=' . $post->ID . ' key=' . $key );
    }

    /**
     * OG image — set from the post's featured image.
     * Only applies when a featured image is set; does not upload or create new media.
     */
    private function apply_og_image( array $change, WP_Post $post ) {
        $id             = $change['id'] ?? 'unknown';
        $thumbnail_id   = get_post_thumbnail_id( $post->ID );
        $thumbnail_url  = $thumbnail_id ? get_the_post_thumbnail_url( $post->ID, 'full' ) : '';

        if ( empty( $thumbnail_url ) ) {
            $this->record( $id, 'failed', 'no_featured_image',
                'No featured image is set on this page. Set a featured image first, then re-apply this change.' );
            return;
        }

        $seo_plugin = $change['target']['seo_plugin'] ?? $this->detect_seo_plugin( $post );

        if ( ! in_array( $seo_plugin, array( 'yoast', 'rankmath', 'aioseo' ), true ) ) {
            $this->record( $id, 'failed', 'plugin_not_active',
                'No SEO plugin detected. OG image cannot be applied automatically.' );
            return;
        }

        if ( $this->commit ) {
            if ( $seo_plugin === 'yoast' ) {
                update_post_meta( $post->ID, '_yoast_wpseo_opengraph-image',    $thumbnail_url );
                update_post_meta( $post->ID, '_yoast_wpseo_opengraph-image-id', $thumbnail_id );
            } elseif ( $seo_plugin === 'rankmath' ) {
                update_post_meta( $post->ID, 'rank_math_facebook_image',    $thumbnail_url );
                update_post_meta( $post->ID, 'rank_math_facebook_image_id', $thumbnail_id );
            } elseif ( $seo_plugin === 'aioseo' ) {
                update_post_meta( $post->ID, '_aioseo_og_image_custom_url', $thumbnail_url );
            }
        }

        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ og_image ' . ( $this->commit ? 'applied' : 'staged' ) . ' — post_id=' . $post->ID . ' plugin=' . $seo_plugin . ' url=' . $thumbnail_url );
    }

    /**
     * Focus keyword — set the primary focus keyword in the active SEO plugin.
     */
    private function apply_focus_keyword( array $change, WP_Post $post ) {
        $id         = $change['id']  ?? 'unknown';
        $keyword    = sanitize_text_field( $change['after'] ?? '' );
        $seo_plugin = $change['target']['seo_plugin'] ?? $this->detect_seo_plugin( $post );

        if ( ! in_array( $seo_plugin, array( 'yoast', 'rankmath', 'aioseo' ), true ) ) {
            $this->record( $id, 'failed', 'plugin_not_active',
                'No SEO plugin detected. Focus keyword cannot be set automatically.' );
            return;
        }

        if ( $this->commit ) {
            if ( $seo_plugin === 'yoast' ) {
                update_post_meta( $post->ID, '_yoast_wpseo_focuskw', $keyword );
            } elseif ( $seo_plugin === 'rankmath' ) {
                update_post_meta( $post->ID, 'rank_math_focus_keyword', $keyword );
            } elseif ( $seo_plugin === 'aioseo' ) {
                $existing = get_post_meta( $post->ID, '_aioseo_keywords', true );
                $decoded  = $existing ? json_decode( $existing, true ) : array();
                if ( ! is_array( $decoded ) ) $decoded = array();
                if ( ! empty( $decoded ) ) {
                    $decoded[0] = array( 'label' => $keyword );
                } else {
                    $decoded[] = array( 'label' => $keyword );
                }
                update_post_meta( $post->ID, '_aioseo_keywords', wp_json_encode( $decoded ) );
            }
        }

        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ focus_keyword ' . ( $this->commit ? 'applied' : 'staged' ) . ' — post_id=' . $post->ID . ' keyword=' . $keyword );
    }

    /**
     * Alt text — update the attachment meta for the specified image.
     */
    private function apply_alt_text( array $change ) {
        $id            = $change['id']   ?? 'unknown';
        $attachment_id = intval( $change['target']['attachment_id'] ?? 0 );
        $new_alt       = sanitize_text_field( $change['after'] ?? '' );

        if ( $attachment_id <= 0 ) {
            $this->record( $id, 'failed', 'invalid_attachment_id', 'No attachment_id specified in change target.' );
            return;
        }

        // Verify the attachment exists
        $attachment = get_post( $attachment_id );
        if ( ! $attachment || $attachment->post_type !== 'attachment' ) {
            $this->record( $id, 'failed', 'post_not_found',
                'Attachment ID ' . $attachment_id . ' does not exist or is not a media attachment.' );
            return;
        }

        if ( $this->commit ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $new_alt );
        }
        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ alt_text ' . ( $this->commit ? 'applied' : 'staged' ) . ' — attachment_id=' . $attachment_id );
    }

    /**
     * Insert block — add a new Gutenberg block at a specified position.
     * Only works on Gutenberg pages.
     */
    private function apply_insert_block( array $change, WP_Post $post ) {
        $id       = $change['id']    ?? 'unknown';
        $content  = $change['after'] ?? '';
        $position = $change['target']['insert_position'] ?? 'after_cta';

        if ( empty( $content ) ) {
            $this->record( $id, 'failed', 'invalid_change', 'No block content (after) provided.' );
            return;
        }

        $builder = $this->detect_page_builder( $post );
        if ( $builder !== 'gutenberg' ) {
            $this->record( $id, 'failed', 'page_builder_unsupported',
                'This page uses ' . $builder . '. New block insertion is only available for Gutenberg pages. Apply this change manually in your page builder.' );
            return;
        }

        if ( $this->modified_blocks === null ) {
            $this->record( $id, 'failed', 'parse_failed', 'Could not parse Gutenberg blocks.' );
            return;
        }

        // Build the new block as a paragraph or HTML block
        $new_block = array(
            'blockName'    => 'core/paragraph',
            'attrs'        => array(),
            'innerBlocks'  => array(),
            'innerHTML'    => '<p>' . wp_kses_post( $content ) . '</p>',
            'innerContent' => array( '<p>' . wp_kses_post( $content ) . '</p>' ),
        );

        $insert_idx = $this->find_insert_position( $this->modified_blocks, $position );
        array_splice( $this->modified_blocks, $insert_idx, 0, array( $new_block ) );

        $this->blocks_modified = true;
        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ insert_block applied — id=' . $id . ' position=' . $position . ' idx=' . $insert_idx );
    }

    /**
     * Schema inject — add JSON-LD structured data via wp_options.
     * The existing wp_head hook reads ciq_schema_inject_{post_id} and outputs it.
     * Non-destructive: does not modify post_content at all.
     */
    private function apply_schema_inject( array $change, WP_Post $post ) {
        $id     = $change['id']    ?? 'unknown';
        $schema = $change['after'] ?? '';

        if ( empty( $schema ) ) {
            $this->record( $id, 'failed', 'invalid_change', 'No schema JSON provided in change.' );
            return;
        }

        // Validate that it's valid JSON
        $decoded = json_decode( $schema, true );
        if ( ! is_array( $decoded ) ) {
            $this->record( $id, 'failed', 'invalid_schema', 'Schema content is not valid JSON.' );
            return;
        }

        if ( $this->commit ) {
            update_option( 'ciq_schema_inject_' . $post->ID, wp_json_encode( $decoded ) );
            $this->ensure_schema_hook(); // register the wp_head output hook (idempotent)
        }

        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ schema_inject ' . ( $this->commit ? 'applied' : 'staged' ) . ' — post_id=' . $post->ID );
    }

    /**
     * Sticky CTA CSS/JS — inject a floating CTA via wp_options + wp_head hook.
     * Stored per-post so it only appears on the target page.
     */
    private function apply_sticky_cta_css( array $change, WP_Post $post ) {
        $id          = $change['id']    ?? 'unknown';
        $cta_text    = sanitize_text_field( $change['after'] ?? 'Get a Free Quote' );
        $booking_url = esc_url( ConversionIQ_Config_Manager::get( 'booking_url', '#' ) );
        $accent      = esc_attr( ConversionIQ_Config_Manager::get( 'accent_color', '#f59e0b' ) );

        $css_id = 'ciq_sticky_cta_' . $post->ID;

        // Build a minimal, accessible sticky CTA snippet
        $snippet = '<style id="ciq-sticky-cta-style-' . $post->ID . '">' .
            '#ciq-sticky-cta-' . $post->ID . '{' .
                'position:fixed;bottom:24px;right:24px;z-index:9999;' .
                'background:' . $accent . ';color:#000;' .
                'padding:12px 24px;border-radius:8px;font-weight:700;' .
                'box-shadow:0 4px 16px rgba(0,0,0,0.2);' .
                'text-decoration:none;font-size:15px;' .
            '}' .
            '#ciq-sticky-cta-' . $post->ID . ':hover{opacity:0.9;}' .
            '@media(max-width:480px){#ciq-sticky-cta-' . $post->ID . '{bottom:12px;right:12px;font-size:14px;padding:10px 18px;}}' .
            '</style>' .
            '<a id="ciq-sticky-cta-' . $post->ID . '" href="' . $booking_url . '">' . esc_html( $cta_text ) . '</a>';

        if ( $this->commit ) {
            update_option( $css_id, $snippet );
            $this->ensure_sticky_cta_hook();
        }

        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ sticky_cta_css ' . ( $this->commit ? 'applied' : 'staged' ) . ' — post_id=' . $post->ID );
    }

    /**
     * Headline rewrite — replace the text of the first matching heading block.
     * If `before` is empty, targets the first heading found on the page.
     */
    private function apply_headline_rewrite( array $change, WP_Post $post ): void {
        $id     = $change['id']    ?? 'unknown';
        $before = trim( $change['before'] ?? '' );
        $after  = trim( $change['after']  ?? '' );

        if ( empty( $after ) ) {
            $this->record( $id, 'failed', 'invalid_change', 'Missing replacement text (after) for headline_rewrite.' );
            return;
        }

        if ( $this->builder === 'elementor' ) {
            $this->apply_headline_rewrite_elementor( $change, $post );
            return;
        }

        if ( $this->builder !== 'gutenberg' ) {
            $this->record( $id, 'failed', 'page_builder_unsupported',
                'This page uses ' . $this->builder . '. Headline rewrites are only available for Gutenberg and Elementor pages.' );
            return;
        }

        if ( $this->modified_blocks === null ) {
            $this->record( $id, 'failed', 'parse_failed', 'Could not parse Gutenberg blocks.' );
            return;
        }

        $sel     = $this->parse_css_selector( $change['target'] ?? null );
        $matched = $this->walk_blocks_for_heading( $this->modified_blocks, $before, $after, $sel );
        if ( $matched ) {
            $this->blocks_modified = true;
            $this->record( $id, 'applied', null, null );
            ciq_log( 'Implementation: ✅ headline_rewrite applied — id=' . $id );
        } else {
            $hint = $sel ? ' (selector: ' . ( is_string( $change['target'] ?? null ) ? $change['target'] : json_encode( $change['target'] ) ) . ')' : '';
            $msg  = $before
                ? 'Could not find the exact heading "' . mb_substr( $before, 0, 80 ) . '"' . $hint . ' on this page.'
                : 'No "before" text supplied to match a heading' . $hint . '.';
            $this->record( $id, 'failed', 'before_not_found', $msg );
            ciq_log( 'Implementation: ❌ headline_rewrite before_not_found — id=' . $id );
        }
    }

    /**
     * CTA swap — replace the label of the first matching button block.
     * If `before` is empty, targets the first button found on the page.
     */
    private function apply_cta_swap( array $change, WP_Post $post ): void {
        $id     = $change['id']    ?? 'unknown';
        $before = trim( $change['before'] ?? '' );
        $after  = trim( $change['after']  ?? '' );

        if ( empty( $after ) ) {
            $this->record( $id, 'failed', 'invalid_change', 'Missing replacement text (after) for cta_swap.' );
            return;
        }

        if ( $this->builder === 'elementor' ) {
            $this->apply_cta_swap_elementor( $change, $post );
            return;
        }

        if ( $this->builder !== 'gutenberg' ) {
            $this->record( $id, 'failed', 'page_builder_unsupported',
                'This page uses ' . $this->builder . '. CTA swaps are only available for Gutenberg and Elementor pages.' );
            return;
        }

        if ( $this->modified_blocks === null ) {
            $this->record( $id, 'failed', 'parse_failed', 'Could not parse Gutenberg blocks.' );
            return;
        }

        $sel     = $this->parse_css_selector( $change['target'] ?? null );
        $matched = $this->walk_blocks_for_button( $this->modified_blocks, $before, $after, $sel );
        if ( $matched ) {
            $this->blocks_modified = true;
            $this->record( $id, 'applied', null, null );
            ciq_log( 'Implementation: ✅ cta_swap applied — id=' . $id );
        } else {
            $hint = $sel ? ' (selector: ' . ( is_string( $change['target'] ?? null ) ? $change['target'] : json_encode( $change['target'] ) ) . ')' : '';
            $msg  = $before
                ? 'Could not find the exact button/CTA "' . mb_substr( $before, 0, 80 ) . '"' . $hint . ' on this page.'
                : 'No "before" label supplied to match a button' . $hint . '.';
            $this->record( $id, 'failed', 'before_not_found', $msg );
            ciq_log( 'Implementation: ❌ cta_swap before_not_found — id=' . $id );
        }
    }

    // ── Elementor applicators ──────────────────────────────────────────────────
    //
    // Elementor stores the whole page as a JSON widget tree in the `_elementor_data`
    // post meta — NOT in post_content. These methods parse that tree, rewrite the
    // matching widget's text setting (exact match only), and mark $this->elementor_modified
    // so the edited tree is staged for preview (apply) or written back to the SAME post's
    // meta in place (publish) at the end of apply_all(). No clone is ever created.

    /**
     * Read and decode `_elementor_data` for a post into a widget-tree array.
     *
     * @return array|null Decoded tree, or null if the meta is missing/invalid.
     */
    private function parse_elementor_data( WP_Post $post ): ?array {
        $raw = get_post_meta( $post->ID, '_elementor_data', true );
        if ( empty( $raw ) ) return null;

        // Elementor stores the JSON slashed; decode both raw and unslashed forms.
        $data = is_array( $raw ) ? $raw : json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            $data = json_decode( wp_unslash( $raw ), true );
        }
        return is_array( $data ) ? $data : null;
    }

    private function apply_headline_rewrite_elementor( array $change, WP_Post $post ): void {
        $id     = $change['id']    ?? 'unknown';
        $before = trim( $change['before'] ?? '' );
        $after  = trim( $change['after']  ?? '' );

        if ( $this->modified_elementor === null ) {
            $this->record( $id, 'failed', 'parse_failed', 'Could not read Elementor data for this page.' );
            return;
        }

        if ( $after === '' ) {
            $this->record( $id, 'failed', 'invalid_change', 'Missing replacement text (after).' );
            return;
        }

        $sel = $this->parse_css_selector( $change['target'] ?? null );

        // Exact match only. Replace EVERY heading whose class matches or whose text
        // equals/contains `before` (covers duplicate hero titles: a visible <div>
        // title plus a separate SEO <h1>).
        $n = $this->elementor_replace_matches( $this->modified_elementor, $before, $after, $sel, 'heading', true );
        if ( $n > 0 ) { $this->mark_elementor_applied( $id, 'headline_rewrite (' . $n . ' heading[s])' ); return; }

        // The headline may live in a non-heading widget — exact text match across any widget.
        if ( $before !== '' && $this->elementor_replace_matches( $this->modified_elementor, $before, $after, null, 'any', true ) > 0 ) {
            $this->mark_elementor_applied( $id, 'headline_rewrite (text-match)' ); return;
        }

        $this->record( $id, 'failed', 'before_not_found',
            'Could not find the exact heading text "' . mb_substr( $before, 0, 80 ) . '"' . $this->sel_hint( $change ) . ' on this page. The headline may live in a theme/header template rather than this page.' );
        ciq_log( 'Implementation: ❌ headline_rewrite (elementor) before_not_found — id=' . $id );
        $this->log_elementor_inventory( $id );
    }

    private function apply_cta_swap_elementor( array $change, WP_Post $post ): void {
        $id     = $change['id']    ?? 'unknown';
        $before = trim( $change['before'] ?? '' );
        $after  = trim( $change['after']  ?? '' );

        if ( $this->modified_elementor === null ) {
            $this->record( $id, 'failed', 'parse_failed', 'Could not read Elementor data for this page.' );
            return;
        }

        if ( $after === '' ) {
            $this->record( $id, 'failed', 'invalid_change', 'Missing replacement text (after).' );
            return;
        }

        $sel = $this->parse_css_selector( $change['target'] ?? null );

        // Exact match only. Button(s) matched by selector class or exact `before` label.
        $n = $this->elementor_replace_matches( $this->modified_elementor, $before, $after, $sel, 'button', true );
        if ( $n > 0 ) { $this->mark_elementor_applied( $id, 'cta_swap (' . $n . ' button[s])' ); return; }

        // The CTA label may be a heading/link, not a button widget — exact text match anywhere.
        if ( $before !== '' && $this->elementor_replace_matches( $this->modified_elementor, $before, $after, null, 'any', true ) > 0 ) {
            $this->mark_elementor_applied( $id, 'cta_swap (text-match)' ); return;
        }

        $this->record( $id, 'failed', 'before_not_found',
            'Could not find the exact button/CTA label "' . mb_substr( $before, 0, 80 ) . '"' . $this->sel_hint( $change ) . ' on this page.' );
        ciq_log( 'Implementation: ❌ cta_swap (elementor) before_not_found — id=' . $id );
        $this->log_elementor_inventory( $id );
    }

    private function apply_copy_rewrite_elementor( array $change, WP_Post $post ): void {
        $id     = $change['id']    ?? 'unknown';
        $before = trim( $change['before'] ?? '' );
        $after  = trim( $change['after']  ?? '' );

        if ( $this->modified_elementor === null ) {
            $this->record( $id, 'failed', 'parse_failed', 'Could not read Elementor data for this page.' );
            return;
        }

        if ( $after === '' ) {
            $this->record( $id, 'failed', 'invalid_change', 'Missing replacement text (after).' );
            return;
        }

        // Exact match only — never insert or guess. An empty "before" has nothing to match.
        if ( $before === '' || strtolower( $before ) === '(none)' ) {
            $this->record( $id, 'failed', 'before_not_found',
                'No "before" text supplied, so there is no Elementor widget to match and replace.' );
            ciq_log( 'Implementation: ❌ copy_rewrite (elementor) before_not_found (empty before) — id=' . $id );
            return;
        }

        $sel = $this->parse_css_selector( $change['target'] ?? null );

        // Replace every widget whose text equals/contains `before` exactly.
        $n = $this->elementor_replace_matches( $this->modified_elementor, $before, $after, $sel, 'any', true );
        if ( $n > 0 ) { $this->mark_elementor_applied( $id, 'copy_rewrite (' . $n . ' widget[s])' ); return; }

        $this->record( $id, 'failed', 'before_not_found',
            'Could not find the exact original text "' . mb_substr( $before, 0, 80 ) . '" in any Elementor widget' . $this->sel_hint( $change ) . '.' );
        ciq_log( 'Implementation: ❌ copy_rewrite (elementor) before_not_found — id=' . $id );
        $this->log_elementor_inventory( $id );
    }

    // ── Elementor tree replacer ─────────────────────────────────────────────────

    /** Mark an Elementor change applied and log it. */
    private function mark_elementor_applied( string $id, string $label ): void {
        $this->elementor_modified = true;
        $this->record( $id, 'applied', null, null );
        ciq_log( 'Implementation: ✅ ' . $label . ' (elementor) applied — id=' . $id );
    }

    /**
     * Walk the tree and rewrite matching widgets. EXACT match only (class hit or
     * normalised text equality/substring — never fuzzy). Returns the number of
     * widgets actually changed (0 if none).
     *
     * @param string $family 'heading' | 'button' | 'any' — restricts the widget type.
     * @param bool   $all    Replace every match (true) or stop at the first (false).
     *
     * A replacement that would not actually change the stored value is skipped, so a
     * "match" can never be reported as applied without a real edit.
     */
    private function elementor_replace_matches( array &$elements, string $before, string $after, ?array $sel, string $family, bool $all = true ): int {
        static $keys_any = array( 'editor', 'text', 'title', 'description_text', 'title_text', 'description', 'content', 'caption', 'html' );
        $count = 0;

        foreach ( $elements as &$el ) {
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $count += $this->elementor_replace_matches( $el['elements'], $before, $after, $sel, $family, $all );
                if ( $count > 0 && ! $all ) { unset( $el ); return $count; }
            }
            if ( ( $el['elType'] ?? '' ) !== 'widget' ) continue;
            if ( empty( $el['settings'] ) || ! is_array( $el['settings'] ) ) continue;

            $wt = (string) ( $el['widgetType'] ?? '' );
            if ( $family === 'heading' && strpos( $wt, 'heading' ) === false ) continue;
            if ( $family === 'button'  && strpos( $wt, 'button' )  === false ) continue;

            $keys = ( $family === 'heading' ) ? array( 'title' )
                  : ( ( $family === 'button' ) ? array( 'text' ) : $keys_any );

            foreach ( $keys as $key ) {
                if ( ! isset( $el['settings'][ $key ] ) || ! is_string( $el['settings'][ $key ] ) ) continue;
                $val = $el['settings'][ $key ];
                if ( $val === '' ) continue;

                $plain = $this->el_plain( $val );
                if ( ! $this->el_strong_match( $el, $plain, $before, $sel ) ) continue;

                // Headings/buttons: swap the whole label. Generic copy: targeted replace.
                $new = ( $family === 'any' )
                    ? $this->el_apply_text_replacement( $val, $before, $after )
                    : $this->el_wrap_replace( $val, $after );

                if ( $this->el_text_key( $new ) === $this->el_text_key( $val ) ) continue; // no real change

                $el['settings'][ $key ] = $new;
                $count++;
                if ( ! $all ) { unset( $el ); return $count; }
                break; // one key per widget
            }
        }
        unset( $el );
        return $count;
    }

    /** Replace a value's text with $after, preserving a single wrapping tag if present. */
    private function el_wrap_replace( string $value, string $after ): string {
        if ( preg_match( '/^(\s*<[^>]+>)(.*)(<\/[^>]+>\s*)$/s', $value, $m ) ) {
            return $m[1] . $after . $m[3];
        }
        return $after;
    }

    /**
     * Reliable copy replacement. Whole-value overwrite when the widget's text IS the
     * `before` text (the common audit case); otherwise a whitespace/quote-tolerant
     * substring replace. Returns the value unchanged if it cannot place the edit.
     */
    private function el_apply_text_replacement( string $value, string $before, string $after ): string {
        $before_key = $this->el_text_key( $before );
        $value_key  = $this->el_text_key( $value );

        if ( $before_key === '' || $value_key === $before_key ) {
            return $this->el_wrap_replace( $value, $after );
        }

        $decoded   = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $before_ws = preg_replace( '/\s+/', ' ', trim( $before ) );
        if ( $before_ws !== '' && strpos( $decoded, $before_ws ) !== false ) {
            return str_replace( $before_ws, $after, $decoded );
        }
        // Tolerant substring replace on a tag-free value (quote/whitespace variants).
        if ( strip_tags( $value ) === $value && $before_key !== '' && strpos( $value_key, $before_key ) !== false ) {
            $parts   = array_map( fn( $w ) => preg_quote( $w, '/' ), preg_split( '/\s+/', $before_key ) );
            $pattern = '/' . implode( '\s+', $parts ) . '/iu';
            $res     = preg_replace_callback( $pattern, fn( $mm ) => $after, $decoded, 1, $cnt );
            if ( $cnt > 0 && $res !== null ) return $res;
        }
        return $value; // could not place the replacement
    }

    /**
     * Normalised comparison key: decode entities, strip tags, unify curly quotes /
     * dashes / non-breaking spaces, collapse whitespace, lowercase. Makes matching
     * and replacement robust to punctuation and encoding differences between the
     * audit's copy and what Elementor stored.
     */
    private function el_text_key( string $s ): string {
        $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $s = wp_strip_all_tags( $s );
        $s = str_replace( array( "\xE2\x80\x99", "\xE2\x80\x98", "\xE2\x80\x9B", "\xC2\xB4", '`' ), "'", $s ); // curly/back apostrophes
        $s = str_replace( array( "\xE2\x80\x9C", "\xE2\x80\x9D" ), '"', $s );                                  // curly double quotes
        $s = str_replace( array( "\xE2\x80\x94", "\xE2\x80\x93" ), '-', $s );                                  // em / en dash
        $s = str_replace( "\xC2\xA0", ' ', $s );                                                               // non-breaking space
        $s = preg_replace( '/\s+/', ' ', trim( $s ) );
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
    }

    /** Strong match: class hit, or normalised text equality/substring (no fuzzy). */
    private function el_strong_match( array $el, string $plain, string $before, ?array $sel ): bool {
        if ( $sel !== null && $this->el_selector_class_hit( $el, $sel ) ) return true;
        if ( $before === '' ) return false;
        $pk = $this->el_text_key( $plain );
        $bk = $this->el_text_key( $before );
        return $bk !== '' && ( $pk === $bk || strpos( $pk, $bk ) !== false );
    }


    // ── Elementor match helpers (exact match only) ──────────────────────────────

    /** True if all selector classes appear in the widget's custom classes / id. */
    private function el_selector_class_hit( array $el, array $sel ): bool {
        $classes = $sel['classes'] ?? array();
        if ( empty( $classes ) ) return false;
        $settings   = $el['settings'] ?? array();
        $css_classes = (string) ( $settings['_css_classes'] ?? '' );
        $element_id  = (string) ( $settings['_element_id'] ?? '' );
        $haystack    = ' ' . $css_classes . ' ' . $element_id . ' ';
        foreach ( $classes as $cls ) {
            if ( strpos( $haystack, (string) $cls ) === false ) return false;
        }
        return true;
    }

    /** Strip tags + normalise whitespace from an Elementor setting value. */
    private function el_plain( string $html ): string {
        $plain = wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        return preg_replace( '/\s+/', ' ', trim( $plain ) );
    }

    /**
     * Log a compact inventory of the page's Elementor widgets — used when a change
     * fails to match, so the log reveals the actual tree (widget types, tags,
     * custom classes, and a text excerpt) and we can see why a target was missed.
     */
    private function log_elementor_inventory( string $id ): void {
        if ( ! is_array( $this->modified_elementor ) ) return;
        $inv = array();
        $this->elementor_inventory( $this->modified_elementor, $inv, 30 );
        ciq_log( 'Implementation: elementor inventory (change=' . $id . '): '
            . ( $inv ? implode( ' || ', $inv ) : '(no widgets found — content likely lives in a theme/template)' ) );
    }

    private function elementor_inventory( array $elements, array &$out, int $limit ): void {
        $text_keys = array( 'title', 'text', 'editor', 'description_text', 'title_text', 'description', 'content' );
        foreach ( $elements as $el ) {
            if ( count( $out ) >= $limit ) return;
            if ( ( $el['elType'] ?? '' ) === 'widget' ) {
                $s   = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
                $txt = '';
                foreach ( $text_keys as $k ) {
                    if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) { $txt = $this->el_plain( $s[ $k ] ); break; }
                }
                $label = (string) ( $el['widgetType'] ?? '?' );
                if ( ! empty( $s['header_size'] ) )  $label .= '[' . $s['header_size'] . ']';
                if ( ! empty( $s['_css_classes'] ) ) $label .= ' .' . str_replace( ' ', '.', trim( (string) $s['_css_classes'] ) );
                $out[] = $label . ': "' . mb_substr( $txt, 0, 50 ) . '"';
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $this->elementor_inventory( $el['elements'], $out, $limit );
            }
        }
    }

    /** Human-readable selector hint for error messages. */
    private function sel_hint( array $change ): string {
        $t = $change['target'] ?? null;
        if ( empty( $t ) ) return '';
        return ' (selector: ' . ( is_string( $t ) ? $t : wp_json_encode( $t ) ) . ')';
    }

    // ── Block tree helpers ────────────────────────────────────────────────────

    /**
     * Recursively walk the block tree and replace the first matching text.
     * Modifies $this->modified_blocks in-place via reference traversal.
     *
     * @param array  &$blocks    Reference to the blocks array.
     * @param string  $before    Original text to find.
     * @param string  $after     Replacement text.
     * @return bool  True if a replacement was made.
     */
    private function walk_blocks_for_rewrite( array &$blocks, string $before, string $after ): bool {
        $text_blocks = array( 'core/paragraph', 'core/heading', 'core/button', 'core/list', 'core/list-item', 'core/quote', 'core/verse' );

        foreach ( $blocks as &$block ) {
            // Walk inner blocks recursively first
            if ( ! empty( $block['innerBlocks'] ) ) {
                if ( $this->walk_blocks_for_rewrite( $block['innerBlocks'], $before, $after ) ) {
                    return true;
                }
            }

            // Only attempt rewrite on text-bearing blocks (or unknown blockName which may be classic)
            $block_name = $block['blockName'] ?? '';
            if ( ! empty( $block_name ) && ! in_array( $block_name, $text_blocks, true ) ) {
                continue;
            }

            $inner = $block['innerHTML'] ?? '';
            if ( empty( $inner ) ) continue;

            $plain = wp_strip_all_tags( html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            $plain = preg_replace( '/\s+/', ' ', trim( $plain ) );

            // Exact match only (after whitespace normalisation). No fuzzy matching.
            $before_norm = preg_replace( '/\s+/', ' ', trim( $before ) );
            if ( $before_norm !== '' && ( $plain === $before_norm || strpos( $plain, $before_norm ) !== false ) ) {
                $block['innerHTML']    = $this->replace_text_in_html( $inner, $before, $after );
                $block['innerContent'] = array( $block['innerHTML'] );
                return true;
            }
        }
        unset( $block );

        return false;
    }

    /**
     * Walk the block tree and replace the first core/heading that matches $sel and/or $before.
     * Selector ($sel) is the primary match; $before anchors/verifies when set.
     * If neither is provided, the first heading found is replaced.
     */
    private function walk_blocks_for_heading( array &$blocks, string $before, string $after, ?array $sel = null ): bool {
        foreach ( $blocks as &$block ) {
            if ( ! empty( $block['innerBlocks'] ) ) {
                if ( $this->walk_blocks_for_heading( $block['innerBlocks'], $before, $after, $sel ) ) {
                    return true;
                }
            }

            if ( ( $block['blockName'] ?? '' ) !== 'core/heading' ) continue;

            $inner = $block['innerHTML'] ?? '';
            if ( empty( $inner ) ) continue;

            $plain = wp_strip_all_tags( html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            $plain = preg_replace( '/\s+/', ' ', trim( $plain ) );

            // Exact match only: selector class hit, or exact/substring `before` text.
            $before_norm = preg_replace( '/\s+/', ' ', trim( $before ) );
            $match = false;
            if ( $before_norm !== '' && ( $plain === $before_norm || strpos( $plain, $before_norm ) !== false ) ) {
                $match = true;
            } elseif ( $sel !== null && $this->block_matches_selector( $block, $sel ) && $before_norm === '' ) {
                $match = true; // selector match only when there is no before text to verify against
            }

            if ( $match ) {
                $block['innerHTML']    = $this->replace_text_in_html( $inner, $before_norm !== '' ? $before : $plain, $after );
                $block['innerContent'] = array( $block['innerHTML'] );
                return true;
            }
        }
        unset( $block );
        return false;
    }

    /**
     * Walk the block tree and replace the first core/button label matching $before exactly
     * (or, when there is no before text, the selector class). No fuzzy matching.
     * Also walks generic containers (core/buttons, core/group, etc.) to reach nested buttons.
     */
    private function walk_blocks_for_button( array &$blocks, string $before, string $after, ?array $sel = null ): bool {
        foreach ( $blocks as &$block ) {
            if ( ! empty( $block['innerBlocks'] ) ) {
                if ( $this->walk_blocks_for_button( $block['innerBlocks'], $before, $after, $sel ) ) {
                    return true;
                }
            }

            if ( ( $block['blockName'] ?? '' ) !== 'core/button' ) continue;

            $inner = $block['innerHTML'] ?? '';
            if ( empty( $inner ) ) continue;

            $plain = wp_strip_all_tags( html_entity_decode( $inner, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
            $plain = preg_replace( '/\s+/', ' ', trim( $plain ) );

            // Exact match only: selector class hit, or exact/substring `before` label.
            $before_norm = preg_replace( '/\s+/', ' ', trim( $before ) );
            $match = false;
            if ( $before_norm !== '' && ( $plain === $before_norm || strpos( $plain, $before_norm ) !== false ) ) {
                $match = true;
            } elseif ( $sel !== null && $this->block_matches_selector( $block, $sel ) && $before_norm === '' ) {
                $match = true;
            }

            if ( $match ) {
                $block['innerHTML']    = $this->replace_text_in_html( $inner, $before_norm !== '' ? $before : $plain, $after );
                $block['innerContent'] = array( $block['innerHTML'] );
                return true;
            }
        }
        unset( $block );
        return false;
    }

    /**
     * Parse a CSS selector string or change['target'] into {element, classes}.
     * Handles "h1.hero-title", "a.hero-cta", ".hero-title", {"selector":"h1.foo"}.
     *
     * @param mixed $target  String or array from the change's `target` field.
     * @return array|null    ['element' => 'h1', 'classes' => ['hero-title']] or null.
     */
    private function parse_css_selector( $target ): ?array {
        if ( is_string( $target ) ) {
            $selector = trim( $target );
        } elseif ( is_array( $target ) ) {
            $selector = trim( (string) ( $target['selector'] ?? $target['element'] ?? '' ) );
        } else {
            return null;
        }
        if ( empty( $selector ) ) return null;

        if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9]*)?((?:\.[a-zA-Z_-][a-zA-Z0-9_-]*)*)$/', $selector, $m ) ) {
            return null;
        }
        $classes = array();
        if ( ! empty( $m[2] ) ) {
            $classes = array_values( array_filter( explode( '.', ltrim( $m[2], '.' ) ) ) );
        }
        if ( empty( $m[1] ) && empty( $classes ) ) return null;
        return array( 'element' => strtolower( $m[1] ?? '' ), 'classes' => $classes );
    }

    /**
     * Return true if the block matches a parsed CSS selector.
     *
     * Element mapping: h1–h6 → core/heading (checks attrs.level); a|button → core/button.
     * Classes are matched against attrs.className and the raw innerHTML.
     */
    private function block_matches_selector( array $block, array $sel ): bool {
        $element = $sel['element'] ?? '';
        $classes = $sel['classes'] ?? array();
        $bn      = $block['blockName'] ?? '';
        $attrs   = $block['attrs']     ?? array();
        $inner   = $block['innerHTML'] ?? '';

        if ( ! empty( $element ) ) {
            if ( preg_match( '/^h([1-6])$/', $element, $hm ) ) {
                if ( $bn !== 'core/heading' ) return false;
                $block_level = (int) ( $attrs['level'] ?? 2 );
                if ( $block_level !== (int) $hm[1] ) return false;
            } elseif ( $element === 'a' || $element === 'button' ) {
                if ( $bn !== 'core/button' ) return false;
            }
            // other elements: skip element check, rely on class matching
        }

        foreach ( $classes as $cls ) {
            $cls = (string) $cls;
            $in_attrs = isset( $attrs['className'] ) && strpos( (string) $attrs['className'], $cls ) !== false;
            $in_html  = strpos( $inner, $cls ) !== false;
            if ( ! $in_attrs && ! $in_html ) return false;
        }

        return true;
    }

    /**
     * Replace visible text in an HTML string while preserving tags.
     * Decodes entities, replaces the text, re-encodes only if needed.
     */
    private function replace_text_in_html( string $html, string $find, string $replace ): string {
        // Decode and normalise the search string as it appears in plain text
        $find_norm    = preg_replace( '/\s+/', ' ', trim( html_entity_decode( $find, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
        $replace_safe = esc_html( $replace );

        // Strip tags from html to get the bare text, keeping tag boundaries
        $plain = wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        $plain = preg_replace( '/\s+/', ' ', trim( $plain ) );

        // If the plain text matches, do a targeted replacement in the HTML
        if ( strpos( $plain, $find_norm ) !== false ) {
            // Simple replacement: encode the replacement text and do str_replace on the decoded content
            $decoded_html     = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $replaced_decoded = str_replace( $find_norm, $replace, $decoded_html );
            // Re-encode entities (but only in text nodes — tags stay as-is)
            return $replaced_decoded;
        }

        // Fallback: replace entire inner text content (strip all tags, insert new text)
        // Preserves any wrapping tag structure (e.g. <p class="hero">NEW TEXT</p>)
        $tag_open  = '';
        $tag_close = '';
        if ( preg_match( '/^(<[^>]+>)(.*?)(<\/[^>]+>)$/s', $html, $m ) ) {
            $tag_open  = $m[1];
            $tag_close = $m[3];
        }
        return $tag_open . esc_html( $replace ) . $tag_close;
    }

    /**
     * Find the index at which to insert a new block, based on a position hint.
     *
     * @param array  $blocks   The current blocks array.
     * @param string $position after_cta | after_hero | before_footer | append
     * @return int  Insertion index.
     */
    private function find_insert_position( array $blocks, string $position ): int {
        $count = count( $blocks );

        switch ( $position ) {
            case 'after_cta':
                // Find the last core/button block
                for ( $i = $count - 1; $i >= 0; $i-- ) {
                    if ( ( $blocks[ $i ]['blockName'] ?? '' ) === 'core/button' ||
                         ( $blocks[ $i ]['blockName'] ?? '' ) === 'core/buttons' ) {
                        return $i + 1;
                    }
                }
                // Fall through to append
                return $count;

            case 'after_hero':
                // Insert after the first group/cover/image block (likely the hero)
                $hero_types = array( 'core/group', 'core/cover', 'core/image', 'core/media-text' );
                foreach ( $blocks as $i => $block ) {
                    if ( in_array( $block['blockName'] ?? '', $hero_types, true ) ) {
                        return $i + 1;
                    }
                }
                // Insert after the first heading if no hero found
                foreach ( $blocks as $i => $block ) {
                    if ( ( $block['blockName'] ?? '' ) === 'core/heading' ) {
                        return $i + 1;
                    }
                }
                return min( 2, $count );

            case 'before_footer':
                return max( 0, $count - 1 );

            case 'append':
            default:
                return $count;
        }
    }

    // ── SEO plugin helpers ────────────────────────────────────────────────────

    /**
     * Detect which SEO plugin is active for a given post.
     * Checks post meta for known keys from Yoast, RankMath, and AIOSEO.
     *
     * @param WP_Post $post
     * @return string  'yoast' | 'rankmath' | 'aioseo' | 'none'
     */
    private function detect_seo_plugin( WP_Post $post ): string {
        if ( get_post_meta( $post->ID, '_yoast_wpseo_title', true ) !== false &&
             is_plugin_active_for_network( 'wordpress-seo/wp-seo.php' ) ||
             $this->plugin_file_exists( 'wordpress-seo' ) ) {
            return 'yoast';
        }
        if ( $this->plugin_file_exists( 'seo-by-rank-math' ) ) {
            return 'rankmath';
        }
        if ( $this->plugin_file_exists( 'all-in-one-seo-pack' ) ) {
            return 'aioseo';
        }
        // Fallback: detect by existing meta
        if ( metadata_exists( 'post', $post->ID, '_yoast_wpseo_title' ) ) return 'yoast';
        if ( metadata_exists( 'post', $post->ID, 'rank_math_title' ) )    return 'rankmath';
        if ( metadata_exists( 'post', $post->ID, '_aioseo_title' ) )      return 'aioseo';

        return 'none';
    }

    /**
     * Check if a plugin directory exists in the plugins folder.
     */
    private function plugin_file_exists( string $slug ): bool {
        return is_dir( WP_PLUGIN_DIR . '/' . $slug );
    }

    /**
     * Map a field name + plugin name to the correct post meta key.
     *
     * @param string $field   'title' | 'description'
     * @param string $plugin  Plugin slug from the change target.
     * @return string|null    Meta key, or null if no plugin available.
     */
    private function resolve_meta_key( string $field, string $plugin ): ?string {
        $map = array(
            'yoast'    => array(
                'title'       => '_yoast_wpseo_title',
                'description' => '_yoast_wpseo_metadesc',
            ),
            'rankmath' => array(
                'title'       => 'rank_math_title',
                'description' => 'rank_math_description',
            ),
            'aioseo'   => array(
                'title'       => '_aioseo_title',
                'description' => '_aioseo_description',
            ),
        );

        if ( isset( $map[ $plugin ][ $field ] ) ) {
            return $map[ $plugin ][ $field ];
        }

        // Auto-detect if no plugin specified
        foreach ( $map as $p => $keys ) {
            if ( $this->plugin_file_exists( $p === 'yoast' ? 'wordpress-seo' : ( $p === 'rankmath' ? 'seo-by-rank-math' : 'all-in-one-seo-pack' ) ) ) {
                return $keys[ $field ] ?? null;
            }
        }
        return null;
    }

    // ── Page builder detection ────────────────────────────────────────────────

    /**
     * Detect which page builder (if any) created this post's content.
     *
     * @return string 'gutenberg' | 'elementor' | 'divi' | 'beaver_builder' | 'classic'
     */
    private function detect_page_builder( WP_Post $post ): string {
        // Elementor stores all content in _elementor_data
        $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
        if ( ! empty( $elementor_data ) && strlen( $elementor_data ) > 10 ) {
            return 'elementor';
        }

        // Divi uses its own shortcode format
        if ( strpos( $post->post_content, '[et_pb_section' ) !== false ) {
            return 'divi';
        }

        // Beaver Builder
        if ( get_post_meta( $post->ID, '_fl_builder_enabled', true ) ) {
            return 'beaver_builder';
        }

        // Gutenberg: block markers
        if ( strpos( $post->post_content, '<!-- wp:' ) !== false ) {
            return 'gutenberg';
        }

        // Classic editor / plain HTML
        return 'classic';
    }

    // ── Hooks ─────────────────────────────────────────────────────────────────

    /**
     * Register the wp_head hook that outputs injected JSON-LD schema.
     * Safe to call multiple times — checks if already hooked.
     */
    private function ensure_schema_hook(): void {
        if ( ! has_action( 'wp_head', 'ciq_output_injected_schema' ) ) {
            add_action( 'wp_head', 'ciq_output_injected_schema' );
        }
    }

    /**
     * Register the wp_footer hook that outputs per-post sticky CTA snippets.
     */
    private function ensure_sticky_cta_hook(): void {
        if ( ! has_action( 'wp_footer', 'ciq_output_sticky_cta' ) ) {
            add_action( 'wp_footer', 'ciq_output_sticky_cta' );
        }
    }

    // ── Layer-1 QA: post-apply read-back verification ───────────────────────────
    //
    // For every change we reported as "applied", re-read the saved value from the
    // draft (content) or the live post (SEO meta / options) and confirm the new
    // text is actually there. This catches silent write failures and wrong-widget
    // targeting without needing a browser. It also emits length/wrapping and
    // "unstyled insert" warnings so the dashboard can prompt a visual look.
    //
    // Returns a keyed list: change_id => { verified: bool|null, method, warnings[] }.
    // verified === null means "not applicable / not checked" (e.g. og_image).

    private function run_qa( array $changes, WP_Post $post ): array {
        $qa = array();

        // Build the content haystack once (draft if we created one, else live post).
        $content_haystack = $this->qa_content_haystack();

        foreach ( $changes as $change ) {
            $id   = $change['id']   ?? 'unknown';
            $type = $change['type'] ?? '';
            $r    = $this->results[ $id ] ?? null;

            // Only verify changes we claimed to have applied.
            if ( ! $r || $r['status'] !== 'applied' ) continue;

            $before   = trim( (string) ( $change['before'] ?? '' ) );
            $after    = trim( (string) ( $change['after']  ?? '' ) );
            $warnings = array();
            $verified = null;
            $method   = 'none';

            switch ( $type ) {
                case 'copy_rewrite':
                case 'reassurance_copy':
                case 'urgency_copy':
                case 'headline_rewrite':
                case 'cta_swap':
                case 'insert_block':
                    // Verify against the edited content we staged/committed (in-memory).
                    $method   = $this->commit ? 'live_content' : 'staged_content';
                    $verified = ( $after !== '' ) ? $this->qa_text_present( $content_haystack, $after ) : null;
                    $lw = $this->qa_length_warning( $before, $after );
                    if ( $lw ) $warnings[] = $lw;
                    break;

                case 'meta_title':
                case 'meta_description':
                case 'focus_keyword':
                    // Meta is only written on publish; when staging, there's nothing live to read back.
                    $method   = $this->commit ? 'live_meta' : 'staged';
                    $verified = $this->commit ? $this->qa_verify_meta( $post, $type, $after ) : null;
                    break;

                case 'schema_inject':
                    $method   = $this->commit ? 'option' : 'staged';
                    $verified = $this->commit ? ! empty( get_option( 'ciq_schema_inject_' . $post->ID ) ) : null;
                    break;

                case 'sticky_cta_css':
                    $method   = $this->commit ? 'option' : 'staged';
                    $verified = $this->commit ? ! empty( get_option( 'ciq_sticky_cta_' . $post->ID ) ) : null;
                    break;

                case 'alt_text':
                    $method = $this->commit ? 'attachment' : 'staged';
                    $att_id = intval( $change['target']['attachment_id'] ?? 0 );
                    if ( $this->commit && $att_id > 0 ) {
                        $val      = (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true );
                        $verified = $this->qa_norm( $val ) === $this->qa_norm( $after );
                    }
                    break;

                default:
                    // og_image and anything else: applied but not independently checkable here.
                    $verified = null;
            }

            $qa[ $id ] = array(
                'change_id' => $id,
                'type'      => $type,
                'verified'  => $verified,
                'method'    => $method,
                'warnings'  => $warnings,
            );

            $flag = $verified === true ? '✅' : ( $verified === false ? '❌' : '—' );
            ciq_log( 'Implementation: QA ' . $flag . ' id=' . $id . ' type=' . $type . ' method=' . $method
                . ( $warnings ? ' warnings=' . count( $warnings ) : '' ) );
        }

        return array_values( $qa );
    }

    /**
     * Plain text of the edited content we staged/committed (built from the in-memory
     * modified structures), so applied copy can be confirmed present. This reflects
     * exactly what stage_preview()/commit_content_in_place() writes.
     */
    private function qa_content_haystack(): string {
        if ( is_array( $this->modified_elementor ) ) {
            $buf = '';
            $this->qa_collect_elementor_text( $this->modified_elementor, $buf );
            return $this->el_plain( $buf );
        }
        if ( is_array( $this->modified_blocks ) && function_exists( 'serialize_blocks' ) ) {
            return $this->el_plain( serialize_blocks( $this->modified_blocks ) );
        }
        return '';
    }

    /** Recursively concatenate every string setting value in an Elementor tree. */
    private function qa_collect_elementor_text( array $elements, string &$buf ): void {
        foreach ( $elements as $el ) {
            if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
                array_walk_recursive( $el['settings'], function ( $v ) use ( &$buf ) {
                    if ( is_string( $v ) && $v !== '' ) $buf .= ' ' . $v;
                } );
            }
            if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
                $this->qa_collect_elementor_text( $el['elements'], $buf );
            }
        }
    }

    /** True if $needle text appears in $haystack after whitespace normalisation. */
    private function qa_text_present( string $haystack, string $needle ): bool {
        if ( $haystack === '' || $needle === '' ) return false;
        return strpos( $this->qa_norm( $haystack ), $this->qa_norm( $needle ) ) !== false;
    }

    /** Verify an SEO meta value was written to the live post. Null if not checkable. */
    private function qa_verify_meta( WP_Post $post, string $type, string $after ): ?bool {
        if ( $after === '' ) return null;
        $seo = $this->detect_seo_plugin( $post );

        if ( $type === 'meta_title' ) {
            $key = $this->resolve_meta_key( 'title', $seo );
        } elseif ( $type === 'meta_description' ) {
            $key = $this->resolve_meta_key( 'description', $seo );
        } else { // focus_keyword
            $key = ( $seo === 'yoast' ) ? '_yoast_wpseo_focuskw'
                 : ( ( $seo === 'rankmath' ) ? 'rank_math_focus_keyword' : null );
        }
        if ( ! $key ) return null;

        wp_cache_delete( $post->ID, 'post_meta' );
        $val = (string) get_post_meta( $post->ID, $key, true );
        $val = $this->qa_norm( $val );
        $need = $this->qa_norm( $after );
        return $val === $need || ( $need !== '' && strpos( $val, $need ) !== false );
    }

    /** Warn when the replacement is materially longer than the original. */
    private function qa_length_warning( string $before, string $after ): ?string {
        $b = mb_strlen( $before );
        $a = mb_strlen( $after );
        if ( $b > 0 && $a > $b && $a >= $b * 1.5 && ( $a - $b ) >= 12 ) {
            return 'New text is ' . round( $a / $b, 1 ) . '× longer than the original (' . $b . '→' . $a
                . ' chars) — check for wrapping or overflow in the draft preview.';
        }
        return null;
    }

    /** Lowercase + collapse whitespace for tolerant text comparison. */
    private function qa_norm( string $s ): string {
        $s = html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return trim( preg_replace( '/\s+/', ' ', mb_strtolower( $s ) ) );
    }

    // ── Result recorder ───────────────────────────────────────────────────────

    private function record( string $id, string $status, ?string $error_code, ?string $error_message ): void {
        $this->results[ $id ] = array(
            'change_id'     => $id,
            'status'        => $status,
            'error_code'    => $error_code,
            'error_message' => $error_message,
        );
    }
}

// ── Global hooks registered once at plugin load ───────────────────────────────

/**
 * Output any JSON-LD schema injected via apply-changes for the current page/post.
 * Hooked to wp_head; reads from wp_options('ciq_schema_inject_{post_id}').
 */
function ciq_output_injected_schema(): void {
    if ( ! is_singular() ) return;
    $post_id = get_the_ID();
    if ( ! $post_id ) return;
    $schema = get_option( 'ciq_schema_inject_' . $post_id );
    if ( empty( $schema ) ) return;
    // Output is already validated JSON stored via wp_json_encode
    echo '<script type="application/ld+json">' . wp_unslash( $schema ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'wp_head', 'ciq_output_injected_schema' );

/**
 * Output per-post sticky CTA snippets injected via apply-changes.
 * Hooked to wp_footer; reads from wp_options('ciq_sticky_cta_{post_id}').
 */
function ciq_output_sticky_cta(): void {
    if ( ! is_singular() ) return;
    $post_id = get_the_ID();
    if ( ! $post_id ) return;
    $snippet = get_option( 'ciq_sticky_cta_' . $post_id );
    if ( empty( $snippet ) ) return;
    echo $snippet . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput — stored via esc_html/esc_url at save time
}
add_action( 'wp_footer', 'ciq_output_sticky_cta' );
