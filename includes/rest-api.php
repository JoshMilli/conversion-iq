<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check if any email address in the list contains 'basecamp'
 */
function conversioniq_has_basecamp_email($emails)
{
    if (is_string($emails)) {
        $emails = array_map('trim', explode(',', $emails));
    }

    foreach ($emails as $email) {
        if (stripos($email, 'basecamp') !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Extract HTML structure for AI analysis
 */
function conversioniq_extract_html_structure($html)
{
    // Identify likely page sections based on content and structure
    $sections = array();

    // Collect visible headings text
    preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $html, $headings);
    if (!empty($headings[1])) {
        $sections['headings'] = array_slice(array_map('wp_strip_all_tags', $headings[1]), 0, 10);
    }

    // Detect common sections by class/id patterns
    $section_patterns = array(
        'Hero' => 'hero|banner|jumbotron|intro|header-content',
        'Features' => 'feature|benefit|service|offer',
        'About' => 'about|story|mission|who-we-are',
        'Testimonials' => 'testimonial|review|feedback|social-proof',
        'Pricing' => 'pricing|plan|package|tier',
        'FAQ' => 'faq|question|accordion',
        'CTA' => 'cta|call-to-action|conversion|booking|contact-form',
        'Trust' => 'trust|guarantee|security|badge|certification',
    );

    $detected_sections = array();
    foreach ($section_patterns as $section_name => $pattern) {
        if (preg_match('/(?:class|id)=["\'][^"\']*(?:' . $pattern . ')[^"\']*["\']/i', $html)) {
            $detected_sections[] = $section_name . ' Section';
        }
    }

    // Extract testimonial names specifically for trust scoring
    $testimonial_names = array();
    
    // Strategy 1: Look for jet-listing or Elementor dynamic field patterns (specific to user's site structure)
    // Extract names from h3/h6 tags and titles from span tags within jet-listing-dynamic-field__content
    ciq_log('Ã°Å¸â€Â Strategy 1: Searching for jet-listing-dynamic-field__content patterns');
    
    if (preg_match_all('/<(?:h[3-6]|div)[^>]*class="[^"]*jet-listing-dynamic-field__content[^"]*"[^>]*>([^<]+)<\/(?:h[3-6]|div)>/is', $html, $name_elements)) {
        $potential_names = array_map('wp_strip_all_tags', $name_elements[1]);
        ciq_log('Ã°Å¸â€Â Found ' . count($potential_names) . ' potential name elements: ' . implode(', ', array_slice($potential_names, 0, 10)));
        
        // Extract titles from span elements with same class
        if (preg_match_all('/<span[^>]*class="[^"]*jet-listing-dynamic-field__content[^"]*"[^>]*>([^<]+)<\/span>/is', $html, $title_elements)) {
            $potential_titles = array_map('wp_strip_all_tags', $title_elements[1]);
            ciq_log('Ã°Å¸â€Â Found ' . count($potential_titles) . ' potential title elements: ' . implode(', ', array_slice($potential_titles, 0, 10)));
            
            // Match names with titles (skip non-name elements like quote text)
            $name_count = 0;
            foreach ($potential_names as $idx => $name) {
                $name = trim($name);
                // Check if this looks like a person's name (2+ words, starts with capital, less than 50 chars)
                if (preg_match('/^([A-Z][a-z]+(?:\s+[A-Z][a-zA-Z]+)+)$/u', $name) && strlen($name) < 50) {
                    // Find corresponding title - check both single words and multi-word titles
                    if (isset($potential_titles[$idx])) {
                        $title = trim($potential_titles[$idx]);
                        // Match common executive/business titles (single or multi-word)
                        if (preg_match('/^(CEO|COO|CTO|CFO|President|Vice President|VP|Director|Manager|Founder|Co-Founder|Owner|Chief|Head of [A-Za-z]+|Senior [A-Za-z]+|Lead [A-Za-z]+)$/i', $title)) {
                            $formatted_title = strtoupper($title);
                            // Normalize common multi-word titles
                            if (preg_match('/^HEAD OF (.+)$/i', $formatted_title, $head_match)) {
                                $formatted_title = 'HEAD OF ' . strtoupper($head_match[1]);
                            }
                            $testimonial_names[] = $name . ', ' . $formatted_title;
                            $name_count++;
                            ciq_log('Ã¢Å“â€¦ Matched testimonial: ' . $name . ', ' . $formatted_title);
                        } else {
                            ciq_log('Ã¢Å¡Â Ã¯Â¸Â Name found but title not recognized: ' . $name . ' -> "' . $title . '"');
                        }
                    }
                }
            }
            ciq_log('Ã°Å¸â€Â Strategy 1 result: Found ' . $name_count . ' valid testimonials');
        } else {
            ciq_log('Ã¢Å¡Â Ã¯Â¸Â No span elements with jet-listing-dynamic-field__content found');
        }
    } else {
        ciq_log('Ã¢Å¡Â Ã¯Â¸Â No h3-h6/div elements with jet-listing-dynamic-field__content found');
    }
    
    // Strategy 2: Look for testimonial blocks with traditional class names
    if (empty($testimonial_names)) {
        if (preg_match_all('/<[^>]*class="[^"]*(?:testimonial|review|feedback|client-info)[^"]*"[^>]*>(.*?)<\/[^>]+>/is', $html, $testimonial_blocks)) {
            foreach ($testimonial_blocks[1] as $block) {
                // Look for name in h3-h6 or strong tags
                if (preg_match('/<(?:h[3-6]|strong|div)[^>]*>([A-Z][a-z]+\s+[A-Z][a-zA-Z]+[^<]*)<\/(?:h[3-6]|strong|div)>/i', $block, $name_match)) {
                    $name = trim(wp_strip_all_tags($name_match[1]));
                    // Look for title in the same block
                    if (preg_match('/<(?:span|p|div)[^>]*>(CEO|COO|CTO|CFO|President|Director|Manager|Founder|Owner)<\/(?:span|p|div)>/i', $block, $title_match)) {
                        $testimonial_names[] = $name . ', ' . strtoupper($title_match[1]);
                    } else if (preg_match('/\b(CEO|COO|CTO|CFO|President|Director|Manager|Founder)\b/i', $block, $title_match)) {
                        $testimonial_names[] = $name . ', ' . strtoupper($title_match[1]);
                    }
                }
            }
        }
    }
    
    // Strategy 3: Fallback - look for name + title pattern with larger gap (but filter aggressively)
    if (empty($testimonial_names)) {
        if (preg_match_all('/\b([A-Z][a-z]{2,}\s+[A-Z][a-z]{2,}(?:\s+[A-Z][a-z]+)?)\b[\s\S]{0,300}?\b(CEO|COO|CTO|CFO|President|Director|Manager|Founder)\b/i', $html, $matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $name = trim($matches[1][$i]);
                $title = strtoupper($matches[2][$i]);
                
                // Aggressive filtering for false positives
                if (!preg_match('/^(Read More|Learn More|Click Here|Get Started|Contact Us|About Us|View All|See More|Our Team|Web Designer|Jhon Doe|John Doe|Jane Doe)$/i', $name) &&
                    !preg_match('/\b(class|div|span|button|link|script|cookie|hidden)\b/i', $name)) {
                    $testimonial_names[] = $name . ', ' . $title;
                }
            }
        }
    }
    
    // Limit to first 10 names and ensure uniqueness
    $testimonial_names = array_slice(array_unique($testimonial_names), 0, 10);
    
    // Log extracted testimonial names for debugging
    if (!empty($testimonial_names)) {
        ciq_log('Ã°Å¸â€˜Â¤ Extracted testimonial names: ' . implode('; ', $testimonial_names));
    } else {
        ciq_log('Ã°Å¸â€˜Â¤ No testimonial names found in HTML structure (' . strlen($html) . ' characters fetched)');
        ciq_log('Ã°Å¸â€œâ€ž HTML preview (first 500 chars): ' . substr($html, 0, 500));
    }

    // Build a concise summary for the AI
    $summary = "Page Structure Analysis:\n";
    if (!empty($detected_sections)) {
        $summary .= 'Detected sections: ' . implode(', ', $detected_sections) . "\n";
    }
    if (!empty($sections['headings'])) {
        $summary .= 'Main headings: ' . implode(' | ', $sections['headings']) . "\n";
    }
    if (!empty($testimonial_names)) {
        $summary .= 'Found testimonial attributions: ' . implode('; ', $testimonial_names) . "\n";
        $summary .= "**IMPORTANT**: These names indicate testimonials with attribution. Trust score should be 60+ (not 0).\n";
    }

    // â”€â”€ CRO Structural Signals â”€â”€
    // Extract explicit HTML evidence for each CRO checklist item so the AI
    // doesn't have to guess from text alone.
    $cro_signals = array();

    // 1. CTA Above the Fold â€” look for button/link in the first ~4000 chars (hero/header area)
    $above_fold = substr($html, 0, 4000);
    if (preg_match_all('/<(?:button|a)[^>]*(?:class|role)[^>]*(?:btn|button|cta|get-started|start|try|buy|book|request|contact|sign-up|signup)[^>]*>([^<]{2,60})<\/(?:button|a)>/i', $above_fold, $cta_matches)) {
        $cta_texts = array_unique(array_map('wp_strip_all_tags', $cta_matches[1]));
        $cro_signals[] = 'CTA Above the Fold: YES â€” found button/link element(s) in first screen area: "' . implode('", "', array_slice($cta_texts, 0, 3)) . '"';
    } elseif (preg_match('/<(?:button|a)[^>]*>([^<]{2,40})<\/(?:button|a)>/i', $above_fold, $m)) {
        $cro_signals[] = 'CTA Above the Fold: POSSIBLE â€” interactive element in hero area: "' . wp_strip_all_tags($m[1]) . '"';
    } else {
        $cro_signals[] = 'CTA Above the Fold: NOT DETECTED in first screen area';
    }

    // 2. Trust Signals â€” image alt text + class names for certs, awards, badges
    $trust_imgs = array();
    if (preg_match_all('/<img[^>]*alt=["\']([^"\']*(?:cert|award|badge|accredit|iso|ssl|secure|verified|partner|member|guarantee)[^"\']*)["\'][^>]*>/i', $html, $img_alts)) {
        $trust_imgs = array_map('wp_strip_all_tags', $img_alts[1]);
    }
    $has_trust_section = preg_match('/(?:class|id)=["\'][^"\']*(?:trust|badge|cert|award|accredit|partner|guarantee)[^"\']*["\']/i', $html);
    $trust_text_match = preg_match('/\b(?:certified|accredited|award[- ]winning|ISO[- ]\d+|BBB|google\s+partner|microsoft\s+partner|as\s+seen\s+in)\b/i', $html);
    if (!empty($trust_imgs)) {
        $cro_signals[] = 'Trust Signals (Certs/Awards): YES â€” trust image(s) with alt: "' . implode('", "', array_slice($trust_imgs, 0, 3)) . '"';
    } elseif ($has_trust_section || $trust_text_match) {
        $cro_signals[] = 'Trust Signals (Certs/Awards): POSSIBLE â€” trust-related class/text detected on page';
    } else {
        $cro_signals[] = 'Trust Signals (Certs/Awards): NOT DETECTED â€” no certification/award images or text found';
    }

    // 3. Inline Social Proof â€” testimonials (already handled above), star ratings, review counts
    $has_rating = preg_match('/(?:\d\.\d\s*(?:out of|\/)\s*5|\d+\s*(?:star|review|rating)s?|\b5\s*stars?\b|â˜…)/i', $html);
    $has_review_count = preg_match('/\d+\s*(?:\+\s*)?\s*(?:review|client|customer|testimonial)s?/i', $html);
    if (!empty($testimonial_names)) {
        $cro_signals[] = 'Inline Social Proof: YES â€” attributed testimonials found (see above)';
    } elseif ($has_rating || $has_review_count) {
        $cro_signals[] = 'Inline Social Proof: YES â€” star ratings or review counts detected in page content';
    } elseif (preg_match('/(?:class|id)=["\'][^"\']*(?:testimonial|review|social-proof|feedback)[^"\']*["\']/i', $html)) {
        $cro_signals[] = 'Inline Social Proof: POSSIBLE â€” testimonial/review section found but no attributed names extracted';
    } else {
        $cro_signals[] = 'Inline Social Proof: NOT DETECTED';
    }

    // 4. Urgency / Scarcity
    if (preg_match('/\b(?:limited\s+(?:time|offer|spots?|seats?|stock)|only\s+\d+\s+(?:left|remaining|available)|expires?|ending\s+soon|today\s+only|hurry|act\s+now|last\s+chance|countdown)\b/i', $html)) {
        $cro_signals[] = 'Urgency/Scarcity: YES â€” urgency/scarcity language detected in page copy';
    } else {
        $cro_signals[] = 'Urgency/Scarcity: NOT DETECTED';
    }

    // 5. Sticky CTA in Nav â€” nav element containing a button or CTA-style link
    if (preg_match('/<(?:nav|header)[^>]*>[\s\S]{0,3000}?<(?:button|a)[^>]*(?:btn|button|cta|get-started|start|try|buy|book|request|sign-up|signup)[^>]*>/i', $html)) {
        $cro_signals[] = 'Sticky CTA in Nav: YES â€” CTA button/link detected inside nav or header element';
    } elseif (preg_match('/(?:class|id)=["\'][^"\']*(?:sticky|fixed)[^"\']*["\']/i', $html) && preg_match('/<(?:button|a)[^>]*(?:btn|cta)[^>]*>/i', $html)) {
        $cro_signals[] = 'Sticky CTA in Nav: POSSIBLE â€” sticky/fixed element with CTA detected';
    } else {
        $cro_signals[] = 'Sticky CTA in Nav: NOT DETECTED';
    }

    // 6. Reassurance Micro-copy (friction reducers near CTAs)
    if (preg_match('/\b(?:no\s+credit\s+card|cancel\s+anytime|free\s+(?:trial|forever|plan)|no\s+commitment|no\s+obligation|no\s+contract|try\s+(?:it\s+)?free|risk[- ]free)\b/i', $html)) {
        $cro_signals[] = 'Reassurance Micro-copy: YES â€” friction-reducing phrases detected near CTAs';
    } else {
        $cro_signals[] = 'Reassurance Micro-copy: NOT DETECTED';
    }

    // 7. Clear Visual Hierarchy â€” presence of H1 + H2/H3 structure
    $h1_count = preg_match_all('/<h1[^>]*>/i', $html);
    $h2_count = preg_match_all('/<h2[^>]*>/i', $html);
    $h3_count = preg_match_all('/<h3[^>]*>/i', $html);
    if ($h1_count >= 1 && ($h2_count + $h3_count) >= 2) {
        $cro_signals[] = "Clear Visual Hierarchy: YES â€” page has H1 ({$h1_count}) + H2 ({$h2_count}) + H3 ({$h3_count}) heading structure";
    } elseif ($h1_count >= 1) {
        $cro_signals[] = "Clear Visual Hierarchy: PARTIAL â€” H1 present but limited sub-heading structure (H2: {$h2_count}, H3: {$h3_count})";
    } else {
        $cro_signals[] = 'Clear Visual Hierarchy: NOT DETECTED â€” no H1 found';
    }

    // 8. Mobile-First UX â€” viewport meta tag and responsive indicators
    $has_viewport = preg_match('/<meta[^>]*name=["\']viewport["\'][^>]*content=["\'][^"\']*width=device-width[^"\']*["\']/i', $html);
    $has_responsive_class = preg_match('/(?:class|id)=["\'][^"\']*(?:mobile|responsive|breakpoint|col-|flex|grid)[^"\']*["\']/i', $html);
    if ($has_viewport && $has_responsive_class) {
        $cro_signals[] = 'Mobile-First UX: YES â€” viewport meta tag present and responsive CSS classes detected';
    } elseif ($has_viewport) {
        $cro_signals[] = 'Mobile-First UX: LIKELY â€” viewport meta tag set for device-width';
    } else {
        $cro_signals[] = 'Mobile-First UX: NOT DETECTED â€” no mobile viewport meta tag found';
    }

    // 9. Speed / Ease Cues
    if (preg_match('/\b(?:instant(?:ly)?|in\s+\d+\s+(?:second|minute|hour|day)s?|quick(?:ly)?|fast(?:er)?|easy|simple|effortless(?:ly)?|set\s+up\s+in|done\s+in|takes?\s+(?:just\s+)?\d+|ready\s+in)\b/i', $html)) {
        $cro_signals[] = 'Speed/Ease Cues: YES â€” ease or speed language found in copy';
    } else {
        $cro_signals[] = 'Speed/Ease Cues: NOT DETECTED';
    }

    // 10. Risk Reversal (Guarantee)
    if (preg_match('/\b(?:\d+[- ]day\s+(?:money[- ]back|guarantee|refund|free\s+trial)|money[- ]back\s+guarantee|satisfaction\s+guaranteed?|full\s+refund|risk[- ]free|no\s+risk|try\s+(?:it\s+)?free|free\s+trial)\b/i', $html)) {
        $cro_signals[] = 'Risk Reversal (Guarantee): YES â€” money-back guarantee or risk-removal offer found in page copy';
    } elseif (preg_match('/(?:class|id)=["\'][^"\']*guarantee[^"\']*["\']/i', $html)) {
        $cro_signals[] = 'Risk Reversal (Guarantee): POSSIBLE â€” guarantee section element detected';
    } else {
        $cro_signals[] = 'Risk Reversal (Guarantee): NOT DETECTED';
    }

    // 11. Anchor Pricing â€” multiple price points suggesting comparison
    preg_match_all('/\$[\d,]+(?:\.\d{2})?|\b(?:USD|GBP|EUR)\s*[\d,]+/i', $html, $prices);
    $unique_prices = array_unique($prices[0]);
    if (count($unique_prices) >= 2) {
        $cro_signals[] = 'Anchor Pricing: YES â€” multiple price points detected (' . implode(', ', array_slice($unique_prices, 0, 4)) . '), suggesting comparison pricing';
    } elseif (preg_match('/(?:class|id)=["\'][^"\']*(?:pricing|plan|package|tier)[^"\']*["\']/i', $html)) {
        $cro_signals[] = 'Anchor Pricing: POSSIBLE â€” pricing section detected but single/no prices visible in markup';
    } else {
        $cro_signals[] = 'Anchor Pricing: NOT DETECTED';
    }

    // 12. Exit Intent / Retention elements
    if (preg_match('/(?:class|id)=["\'][^"\']*(?:exit[- ]intent|popup|modal|overlay|lightbox|optin|opt-in|lead[- ]magnet)[^"\']*["\']/i', $html) ||
        preg_match('/data-[^=]*(?:exit|popup|trigger)[^=]*=/i', $html)) {
        $cro_signals[] = 'Exit Intent: YES â€” popup/exit-intent/modal element found in page markup';
    } else {
        $cro_signals[] = 'Exit Intent: NOT DETECTED â€” no popup or exit-intent markup found';
    }

    // 13. Progress Indicators â€” multi-step forms or checkout flows
    if (preg_match('/(?:class|id)=["\'][^"\']*(?:progress|step[- ]?\d|wizard|multi[- ]step|breadcrumb|stepper)[^"\']*["\']/i', $html) ||
        preg_match('/\b(?:step\s+\d\s+of\s+\d|\d\s+of\s+\d\s+steps?)\b/i', $html)) {
        $cro_signals[] = 'Progress Indicators: YES â€” multi-step or progress element detected';
    } else {
        $cro_signals[] = 'Progress Indicators: NOT DETECTED';
    }

    if (!empty($cro_signals)) {
        $summary .= "\nCRO Structural Signals (HTML-derived â€” use these as primary evidence for cro_checklist):\n";
        foreach ($cro_signals as $signal) {
            $summary .= 'â€¢ ' . $signal . "\n";
        }
    }

    $summary .= "\nNote: Use these section names when categorizing your suggestions.";

    return $summary;
}

/**
 * Query WordPress Custom Post Types for review/testimonial entries
 * to supplement HTML trust signal analysis with database-sourced reviews.
 *
 * Handles CPTs like 'review', 'reviews', '_reviews', 'testimonial', etc.
 * Meta fields checked follow common patterns (e.g. _name, _position, _review-copy).
 *
 * @return string Formatted context string, or empty string if no reviews found.
 */
function conversioniq_get_cpt_reviews() {
    static $cached_result = null;
    if ($cached_result !== null) {
        return $cached_result;
    }

    // Scan all registered post types for review/testimonial CPTs
    $all_types = get_post_types(array(), 'names');
    $review_cpt_candidates = array();
    foreach ($all_types as $post_type) {
        $slug = strtolower($post_type);
        if (strpos($slug, 'review') !== false || strpos($slug, 'testimonial') !== false) {
            $review_cpt_candidates[] = $post_type;
        }
    }

    if (empty($review_cpt_candidates)) {
        ciq_log('Ã¢â€žÂ¹Ã¯Â¸Â No review/testimonial CPTs detected on this site');
        $cached_result = '';
        return '';
    }

    ciq_log('Ã°Å¸â€Â Detected review CPTs: ' . implode(', ', $review_cpt_candidates));

    // Common meta field names used by popular review/testimonial CPT setups
    $name_fields     = array('_name', 'reviewer_name', 'client_name', 'author_name', 'name', 'review_author');
    $position_fields = array('_position', 'reviewer_position', 'client_position', 'job_title', 'position', 'role');
    $text_fields     = array('_review-copy', 'review_text', 'review_content', 'testimonial_text', 'review_body', 'review_copy');

    $all_reviews = array();

    foreach ($review_cpt_candidates as $cpt) {
        $posts = get_posts(array(
            'post_type'   => $cpt,
            'post_status' => 'publish',
            'numberposts' => 20,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ));

        if (empty($posts)) {
            continue;
        }

        ciq_log('Ã°Å¸â€œÂ CPT "' . $cpt . '": ' . count($posts) . ' published reviews');

        foreach ($posts as $post) {
            // Reviewer name
            $reviewer_name = '';
            foreach ($name_fields as $field) {
                $val = trim((string) get_post_meta($post->ID, $field, true));
                if ($val !== '') {
                    $reviewer_name = sanitize_text_field($val);
                    break;
                }
            }
            // Fallback: post title when it looks like a person's name
            if ($reviewer_name === '' && preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-zA-Z]+)+$/', trim($post->post_title))) {
                $reviewer_name = trim($post->post_title);
            }

            // Reviewer position / job title
            $reviewer_position = '';
            foreach ($position_fields as $field) {
                $val = trim((string) get_post_meta($post->ID, $field, true));
                if ($val !== '') {
                    $reviewer_position = sanitize_text_field($val);
                    break;
                }
            }

            // Review body text
            $review_text = '';
            foreach ($text_fields as $field) {
                $val = trim((string) get_post_meta($post->ID, $field, true));
                if ($val !== '') {
                    $review_text = wp_strip_all_tags($val);
                    break;
                }
            }
            if ($review_text === '' && !empty($post->post_content)) {
                $review_text = wp_strip_all_tags($post->post_content);
            }

            if ($reviewer_name === '' && $review_text === '') {
                continue;
            }

            // Format: "Name, Position: "text preview""
            $entry = $reviewer_name;
            if ($reviewer_name !== '' && $reviewer_position !== '') {
                $entry .= ', ' . $reviewer_position;
            }
            if ($review_text !== '') {
                $preview = mb_strlen($review_text) > 150 ? mb_substr($review_text, 0, 150) . '...' : $review_text;
                $entry .= ($entry !== '' ? ': "' : '"') . $preview . '"';
            }

            $all_reviews[] = $entry;
        }
    }

    if (empty($all_reviews)) {
        ciq_log('Ã¢â€žÂ¹Ã¯Â¸Â Review CPTs found but no publishable review data could be extracted');
        $cached_result = '';
        return '';
    }

    $count = count($all_reviews);
    ciq_log('Ã¢Å“â€¦ ' . $count . ' CPT reviews extracted for trust scoring');

    $summary  = "\n\n**SITE REVIEWS (WordPress Custom Post Type - Database):**\n";
    $summary .= 'This site has ' . $count . ' published customer reviews stored in a WordPress Custom Post Type (these are manually curated reviews often not rendered in standard page HTML).' . "\n";
    foreach (array_slice($all_reviews, 0, 10) as $i => $review) {
        $summary .= ($i + 1) . '. ' . $review . "\n";
    }
    if ($count > 10) {
        $summary .= '... and ' . ($count - 10) . " more reviews in the database.\n";
    }
    $summary .= '**TRUST SIGNAL**: These ' . $count . ' verified manual customer reviews are a strong trust indicator. ';
    $summary .= 'The trust score must reflect this social proof (minimum 65 when named reviews exist, 75+ when reviews have both name and position).';

    $cached_result = $summary;
    return $summary;
}

add_action('rest_api_init', function () {
    // License routes
    register_rest_route('conversioniq/v1', '/license/status', array(
        'methods' => 'GET',
        'callback' => 'conversioniq_license_status',
        'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

    register_rest_route('conversioniq/v1', '/license/activate', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_license_activate',
        'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

    register_rest_route('conversioniq/v1', '/license/deactivate', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_license_deactivate',
        'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

    register_rest_route('conversioniq/v1', '/license/refresh', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_license_refresh',
        'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

    register_rest_route('conversioniq/v1', '/license/sites', array(
        'methods' => 'GET',
        'callback' => 'conversioniq_license_sites',
        'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

    register_rest_route('conversioniq/v1', '/license/remove-site', array(
        'methods' => 'POST',
        'callback' => 'conversioniq_license_remove_site',
        'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/settings', array(
                array(
                'methods' => 'POST',
                'callback' => 'conversioniq_save_settings',
                'permission_callback' => function () {
                return current_user_can('manage_options'); }
                ),
                    array(
                    'methods' => 'GET',
                    'callback' => 'conversioniq_get_settings',
                    'permission_callback' => function () {
                return current_user_can('manage_options'); }
                ),
            ));

            register_rest_route('conversioniq/v1', '/audit', array(
                'methods' => 'POST',
                'callback' => 'conversioniq_run_audit',
                'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/audits/supabase', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_list_audits_supabase',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        register_rest_route('conversioniq/v1', '/audits', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_list_audits',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Return published pages (id, title, permalink)
        register_rest_route('conversioniq/v1', '/pages', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_list_pages',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Get single page content for AI analysis
        register_rest_route('conversioniq/v1', '/page/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_get_page_content',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/report', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_generate_report',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Automated reporting settings
        register_rest_route('conversioniq/v1', '/automated-settings', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_get_automated_settings',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/automated-settings', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_save_automated_settings',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Auto-fill business information by analyzing homepage
        register_rest_route('conversioniq/v1', '/guess-business-info', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_guess_business_info',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Business profile â€” stored in Supabase organizations table
        register_rest_route('conversioniq/v1', '/business-profile', array(
            array(
                'methods'             => 'GET',
                'callback'            => 'conversioniq_get_business_profile',
                'permission_callback' => function () { return current_user_can('manage_options'); },
            ),
            array(
                'methods'             => 'POST',
                'callback'            => 'conversioniq_save_business_profile_endpoint',
                'permission_callback' => function () { return current_user_can('manage_options'); },
            ),
        ));

        // Test email endpoint
        register_rest_route('conversioniq/v1', '/test-email', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_test_email',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        // Send manual audit report email
        register_rest_route('conversioniq/v1', '/send-manual-report', array(
            'methods' => 'POST',
            'callback' => 'conversioniq_send_manual_report',
            'permission_callback' => function () {
            return current_user_can('manage_options'); }
        ));

        register_rest_route('conversioniq/v1', '/score-history', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_score_history',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Supabase audit history for a specific page URL (score trajectory)
        register_rest_route('conversioniq/v1', '/audit-history', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_audit_history',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Monthly visitor trend from KnockKnock local tables
        register_rest_route('conversioniq/v1', '/visitor-trend', array(
            'methods' => 'GET',
            'callback' => 'conversioniq_visitor_trend',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // Tracked pages for remote audit trigger
        register_rest_route('conversioniq/v1', '/tracked-pages', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_get_tracked_pages',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));
        register_rest_route('conversioniq/v1', '/tracked-pages', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_save_tracked_pages',
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ));

        // Remote audit trigger — authenticated by X-CIQ-API-Key header (no WP session required)
        register_rest_route('conversioniq/v1', '/remote-audit', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_remote_audit',
            'permission_callback' => '__return_true',
        ));
    });


function conversioniq_save_settings(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    if (empty($params)) {
        return new WP_REST_Response(array('success' => false, 'message' => __('No settings provided', 'conversion-iq')), 400);
    }
    // Save OpenAI API key separately for backend use
    if (isset($params['openai_api_key'])) {
        update_option('conversioniq_api_key', sanitize_text_field($params['openai_api_key']));
        unset($params['openai_api_key']);
    }

    // Save KnockKnock settings separately
    if (isset($params['knockknock_company_id'])) {
        update_option('conversioniq_knockknock_company_id', sanitize_text_field($params['knockknock_company_id']));
        unset($params['knockknock_company_id']);
    }
    if (isset($params['knockknock_webhook_secret'])) {
        update_option('conversioniq_knockknock_webhook_secret', sanitize_text_field($params['knockknock_webhook_secret']));
        unset($params['knockknock_webhook_secret']);
    }

    update_option('conversion_iq_settings', wp_json_encode($params));

    return array('success' => true);
}

function conversioniq_get_settings()
{
    $v = get_option('conversion_iq_settings', '{}');
    $decoded = json_decode($v, true);

    // Add KnockKnock settings
    $decoded['knockknock_company_id'] = get_option('conversioniq_knockknock_company_id', '');
    $decoded['knockknock_webhook_secret'] = get_option('conversioniq_knockknock_webhook_secret', '');
    $decoded['knockknock_webhook_url'] = home_url('/wp-json/conversioniq/v1/webhook');

    return rest_ensure_response($decoded);
}

function conversioniq_get_automated_settings()
{
    $settings = get_option('conversion_iq_automated_reports', array(
        'enabled' => false,
        'frequency' => 'weekly',
        'email' => '',
        'defaultPages' => array()
    ));
    return rest_ensure_response($settings);
}

function conversioniq_save_automated_settings(WP_REST_Request $request)
{
    $body = $request->get_json_params();

    // Process and validate emails (comma-separated)
    $email_input = isset($body['email']) ? sanitize_text_field($body['email']) : '';
    $emails = array_map('trim', explode(',', $email_input));
    $valid_emails = array_filter($emails, 'is_email');

    $settings = array(
        'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : false,
        'frequency' => isset($body['frequency']) ? sanitize_text_field($body['frequency']) : 'weekly',
        'email' => implode(', ', $valid_emails),
        'defaultPages' => isset($body['defaultPages']) ? array_map('intval', $body['defaultPages']) : array()
    );

    // Validate emails
    if ($settings['enabled'] && empty($valid_emails)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'At least one valid email address is required when automated reports are enabled'
        ), 400);
    }

    if ($settings['enabled'] && count($valid_emails) < count($emails)) {
        $invalid_count = count($emails) - count($valid_emails);
        return new WP_REST_Response(array(
            'success' => false,
            'message' => "Found {$invalid_count} invalid email address(es). Please check your email list."
        ), 400);
    }

    // Save settings
    update_option('conversion_iq_automated_reports', $settings);

    // Clear existing cron job
    $timestamp = wp_next_scheduled('conversioniq_automated_audit');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'conversioniq_automated_audit');
    }

    // Schedule new cron job if enabled
    if ($settings['enabled'] && !empty($settings['defaultPages'])) {
        $next_run = conversioniq_get_next_run_time($settings['frequency']);
        wp_schedule_event($next_run, 'conversioniq_' . $settings['frequency'], 'conversioniq_automated_audit');

        ciq_log('Scheduled automated audit: ' . $settings['frequency']);
    }

    return rest_ensure_response(array(
        'success' => true,
        'message' => 'Automated report settings saved successfully',
        'next_run' => $settings['enabled'] ? date('Y-m-d H:i:s', $next_run ?? time()) : null
    ));
}

function conversioniq_run_audit(WP_REST_Request $request)
{
    // Rate limiting: one audit request per 30 seconds per user
    $user_id = get_current_user_id();
    $transient_key = 'ciq_audit_lock_' . $user_id;
    if (get_transient($transient_key)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => __('Please wait 30 seconds between audit requests.', 'conversion-iq')
        ), 429);
    }
    set_transient($transient_key, 1, 30);

    // Weekly audit limit: check how many audits the user has run in the last 7 days
    $flags = ConversionIQ_Config_Manager::get_feature_flags();
    $audits_per_week = isset($flags['audits_per_week']) ? intval($flags['audits_per_week']) : 3;
    if ($audits_per_week > 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'conversioniq_audits';
        $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
        $recent_count = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $seven_days_ago)
        );
        if ($recent_count >= $audits_per_week) {
            return new WP_REST_Response(array(
                'success'       => false,
                'message'       => sprintf(__('Weekly audit limit reached. Your plan allows %d audits per week. Limit resets 7 days after your first audit this period.', 'conversion-iq'), $audits_per_week),
                'error_code'    => 'weekly_limit_reached',
                'audits_used'   => $recent_count,
                'audits_allowed' => $audits_per_week,
            ), 429);
        }
    }

    $body = $request->get_json_params();
    $pages = isset($body['pages']) ? $body['pages'] : array();
    if (empty($pages)) {
        return new WP_REST_Response(array('success' => false, 'message' => __('No pages specified', 'conversion-iq')), 400);
    }

    // Cap pages per plan
    $max_pages = isset($flags['max_pages_per_audit']) ? intval($flags['max_pages_per_audit']) : 1;
    $pages = array_slice($pages, 0, $max_pages);

    // Validate page IDs: must be integers referencing published pages/posts
    $allowed_types = array('page', 'post');
    $valid_pages = array();
    foreach ($pages as $pid) {
        $pid = absint($pid);
        if ($pid <= 0) continue;
        $post = get_post($pid);
        if ($post && $post->post_status === 'publish' && in_array($post->post_type, $allowed_types, true)) {
            $valid_pages[] = $pid;
        }
    }
    if (empty($valid_pages)) {
        return new WP_REST_Response(array('success' => false, 'message' => __('No valid published pages found.', 'conversion-iq')), 400);
    }
    $pages = $valid_pages;

    $business = json_decode(get_option('conversion_iq_settings', '{}'), true);

    // Research industry benchmarks once at start of audit
    ciq_log('Ã°Å¸â€Â¬ Researching industry benchmarks...');
    $benchmark_research = ConversionIQ_AI::research_industry_benchmarks(
        isset($business['industry']) ? $business['industry'] : '',
        isset($business['audience']) ? $business['audience'] : '',
        isset($business['goal']) ? $business['goal'] : ''
    );
    ciq_log('Ã°Å¸â€œÅ  Benchmark research complete: avg=' . ($benchmark_research['industry_average'] ?? 'N/A') . ', top=' . ($benchmark_research['top_performers_threshold'] ?? 'N/A'));

    $results = array();

    foreach ($pages as $page_id) {
        $post = get_post(intval($page_id));
        if (!$post)
            continue;

        // Get clean page content
        $content = $post->post_content;
        $content = strip_shortcodes($content);
        $content = wp_strip_all_tags($content);

        // Fetch HTML structure for better AI analysis
        $page_url = get_permalink($post);
        $html_structure = '';

        ciq_log('Fetching HTML from: ' . $page_url);
        $response = wp_remote_get($page_url, array(
            'timeout' => 10,
            'sslverify' => true,
        ));

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $html = wp_remote_retrieve_body($response);

            // Extract key HTML elements and their classes/IDs
            $html_structure = conversioniq_extract_html_structure($html);
            ciq_log('HTML structure extracted (' . strlen($html_structure) . ' chars)');
        }
        else {
            ciq_log('Could not fetch HTML: ' . (is_wp_error($response) ? $response->get_error_message() : 'HTTP error'));
        }

        // Supplement HTML-based trust signals with reviews stored in WordPress CPTs
        $cpt_reviews = conversioniq_get_cpt_reviews();
        if (!empty($cpt_reviews)) {
            $html_structure .= $cpt_reviews;
            ciq_log('CPT reviews appended to trust signal context');
        }

        $payload = array(
            'business' => $business,
            'page' => array(
                'title' => $post->post_title,
                'content' => $content,
                'url' => $page_url,
                'word_count' => str_word_count($content),
                'html_structure' => $html_structure,
            ),
        );

        // Calculate content hash for change detection
        $content_hash = hash('sha256', $content . $html_structure);

        ciq_log('Running audit for: ' . $post->post_title);
                ciq_log('Content hash: ' . $content_hash);

        $audit_start = microtime(true);
        try {
            $ai = ConversionIQ_AI::analyze($payload);
            $audit_time = round((microtime(true) - $audit_start), 2);

            // Validate AI response structure
            if (!is_array($ai)) {
                throw new Exception('AI returned invalid response type: ' . gettype($ai));
            }

            // Add benchmark research to audit results
            $ai['benchmark_research'] = $benchmark_research;

            // Generate a unique token for the public report URL
            $ai['report_token'] = bin2hex(random_bytes(16));

            // Check for required fields and log diagnostic info
            $has_clarity = isset($ai['clarity_score']);
            $has_suggestions = isset($ai['suggestions']);
            $has_ai_flag = isset($ai['ai_used']);
            $ai_used = isset($ai['ai_used']) ? $ai['ai_used'] : true;

            if (!$has_clarity || !$has_suggestions) {
                ciq_log('Ã¢Å¡Â Ã¯Â¸Â AI response missing required fields. Has clarity: ' . ($has_clarity ? 'YES' : 'NO') . ', Has suggestions: ' . ($has_suggestions ? 'YES' : 'NO'));
                ciq_log('Ã°Å¸â€œâ€¹ Response keys: ' . json_encode(array_keys($ai)));
            }

            $insert_id = ConversionIQ_DB::insert_audit($post->ID, $post->post_title, $ai, $content_hash);

            // Invalidate score history cache
            delete_transient('ciq_score_history');

            // Add company identifier for webhook tracking
            $account = get_option('conversioniq_account', null);
            $company_info = array(
                'company_name' => $account['company'] ?? '',
                'company_id' => $account['company_id'] ?? '',
                'site_url' => get_site_url()
            );

            $ai['insert_id'] = $insert_id;
            $ai['page_id'] = $post->ID;
            $ai['page_title'] = $post->post_title;
            $ai['page_url'] = $page_url;
            $ai['created_at'] = current_time('mysql');
            $ai['company_info'] = $company_info;
            $results[] = $ai;
            $last_result_idx = count($results) - 1;

            // Sync audit to Supabase cloud database
            try {
                $supabase_sync = new ConversionIQ_Supabase_Sync();
                $business_data = isset($payload['business']) ? $payload['business'] : array();
                $sync_success = $supabase_sync->send_audit(array(
                    'page_url' => $page_url,
                    'page_title' => $post->post_title,
                    'industry' => isset($business_data['industry']) ? $business_data['industry'] : null,
                    'clarity_score' => isset($ai['clarity_score']) ? $ai['clarity_score'] : null,
                    'emotional_score' => isset($ai['emotional_score']) ? $ai['emotional_score'] : null,
                    'cta_strength' => isset($ai['cta_strength']) ? $ai['cta_strength'] : null,
                    'readability_score' => isset($ai['readability_score']) ? $ai['readability_score'] : null,
                    'engagement_score' => isset($ai['engagement_score']) ? $ai['engagement_score'] : null,
                    'trust_score' => isset($ai['trust_score']) ? $ai['trust_score'] : null,
                    'overall_score' => isset($ai['overall_score']) ? $ai['overall_score'] : null,
                    'suggestions' => isset($ai['suggestions']) ? $ai['suggestions'] : array(),
                    'functionality_suggestions' => isset($ai['functionality_suggestions']) ? $ai['functionality_suggestions'] : array(),
                    'rewrites' => isset($ai['rewrites']) ? $ai['rewrites'] : array(),
                    'analysis_method' => isset($ai['analysis_method']) ? $ai['analysis_method'] : 'single',
                    'sections_analyzed' => isset($ai['sections_analyzed']) ? $ai['sections_analyzed'] : 1,
                    // Public report fields
                    'report_token'       => $ai['report_token'],
                    'insights'           => isset($ai['insights']) ? $ai['insights'] : null,
                    'recommendations'    => isset($ai['recommendations']) ? $ai['recommendations'] : null,
                    'benchmark_research' => isset($ai['benchmark_research']) ? $ai['benchmark_research'] : null,
                    'business_context'   => array(
                        'industry'    => $business_data['industry'] ?? null,
                        'product'     => $business_data['product'] ?? null,
                        'audience'    => $business_data['audience'] ?? null,
                        'goal'        => $business_data['goal'] ?? null,
                        'pain_points' => $business_data['pain_points'] ?? null,
                    ),
                    'lead_intelligence'  => isset($ai['lead_intelligence_summary']) ? $ai['lead_intelligence_summary'] : null,
                    'cro_checklist'      => isset($ai['cro_checklist']) ? $ai['cro_checklist'] : null,
                    'plan'               => ConversionIQ_Config_Manager::get_plan(),
                ));

                // Track usage for analytics
                $supabase_sync->track_usage('analyze_page');

                if (!$sync_success) {
                    ciq_log('Failed to sync audit to Supabase cloud');
                }
            }
            catch (Exception $e) {
                ciq_log('Supabase sync exception - ' . $e->getMessage());
            }

            ciq_log('Audit completed for: ' . $post->post_title . ' in ' . $audit_time . 's');
        }
        catch (Exception $e) {
            $audit_time = round((microtime(true) - $audit_start), 2);
            ciq_log('Audit EXCEPTION for ' . $post->post_title . ': ' . $e->getMessage());
            // Add fallback result
            $results[] = array(
                'page_id' => $post->ID,
                'page_title' => $post->post_title,
                'page_url' => $page_url,
                'clarity_score' => 70,
                'emotional_score' => 70,
                'cta_strength' => 70,
                'readability_score' => 70,
                'engagement_score' => 70,
                'trust_score' => 70,
                'suggestions' => array(
                        array('text' => 'Audit failed: ' . $e->getMessage(), 'target' => '')
                ),
                'ai_used' => false,
                'created_at' => current_time('mysql'),
            );
        }
    }

    return rest_ensure_response(array('success' => true, 'results' => $results));
}

function conversioniq_get_next_run_time($frequency)
{
    $now = current_time('timestamp');

    switch ($frequency) {
        case 'weekly':
            // Next Monday at 9 AM
            $next = strtotime('next Monday 9:00', $now);
            break;
        case 'monthly':
            // 1st of next month at 9 AM
            $next = strtotime('first day of next month 9:00', $now);
            break;
        case 'bimonthly':
            // 1st of month after next at 9 AM
            $next = strtotime('first day of next month +1 month 9:00', $now);
            break;
        default:
            $next = strtotime('+1 week', $now);
    }

    return $next;
}

function conversioniq_score_history(WP_REST_Request $request)
{
    // Cache for 5 minutes to avoid decoding all JSON blobs on every request
    $cached = get_transient('ciq_score_history');
    if ($cached !== false) {
        return rest_ensure_response($cached);
    }
    $history = ConversionIQ_DB::get_score_history();
    set_transient('ciq_score_history', $history, 5 * MINUTE_IN_SECONDS);
    return rest_ensure_response($history);
}

/**
 * GET /audit-history?page_url=<url>
 *
 * Returns all past Supabase audits for this organization + page, oldest first,
 * with only the columns needed for a score trajectory chart.
 */
function conversioniq_audit_history(WP_REST_Request $request)
{
    $page_url = sanitize_text_field($request->get_param('page_url'));
    if (empty($page_url)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'page_url is required'), 400);
    }

    $sync = new ConversionIQ_Supabase_Sync();
    $history = $sync->get_audit_history($page_url);

    if ($history === false) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Failed to fetch audit history from Supabase'), 502);
    }

    return rest_ensure_response($history);
}

/**
 * GET /visitor-trend
 *
 * Returns month-by-month counts of identified visitors and leads from the local
 * KnockKnock tables for the last 12 months (current month first).
 */
function conversioniq_visitor_trend(WP_REST_Request $request)
{
    global $wpdb;

    $table_sessions = $wpdb->prefix . 'conversioniq_visitor_sessions';
    $table_leads    = $wpdb->prefix . 'conversioniq_leads';

    // Build 12-month buckets: current month down to 11 months ago
    $months = array();
    for ($i = 0; $i < 12; $i++) {
        $ts    = strtotime("-{$i} months");
        $key   = date('Y-m', $ts);
        $label = date('M Y', $ts);
        $months[$key] = array('month' => $key, 'label' => $label, 'visitors' => 0, 'leads' => 0);
    }

    // Identified visitors by month
    $visitor_rows = $wpdb->get_results(
        "SELECT DATE_FORMAT(identified_at, '%Y-%m') AS mo, COUNT(*) AS cnt
         FROM {$table_sessions}
         WHERE identified_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
         GROUP BY mo",
        ARRAY_A
    );
    foreach ((array) $visitor_rows as $row) {
        if (isset($months[$row['mo']])) {
            $months[$row['mo']]['visitors'] = (int) $row['cnt'];
        }
    }

    // Leads by month
    $lead_rows = $wpdb->get_results(
        "SELECT DATE_FORMAT(converted_at, '%Y-%m') AS mo, COUNT(*) AS cnt
         FROM {$table_leads}
         WHERE converted_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
         GROUP BY mo",
        ARRAY_A
    );
    foreach ((array) $lead_rows as $row) {
        if (isset($months[$row['mo']])) {
            $months[$row['mo']]['leads'] = (int) $row['cnt'];
        }
    }

    // Return newest-first so index 0 = current month, index 1 = last month
    $result = array_values($months);

    return rest_ensure_response(array('success' => true, 'months' => $result));
}

function conversioniq_list_audits(WP_REST_Request $request)
{
    $rows = ConversionIQ_DB::get_audits();

    // Flatten structure: merge 'data' fields with top-level fields
    $formatted = array();
    foreach ($rows as $row) {
        $audit = is_array($row['data']) ? $row['data'] : array();
        $audit['id'] = $row['id'];
        $audit['page_id'] = $row['page_id'];
        $audit['page_title'] = $row['page_title']; // Ensure page_title is always present
        $audit['created_at'] = $row['created_at'];

        // Add content change detection
        $content_changed = ConversionIQ_DB::has_content_changed($row['id']);
        if ($content_changed !== null) {
            $audit['content_changed'] = $content_changed;
        }

        $formatted[] = $audit;
    }

    return rest_ensure_response($formatted);
}

function conversioniq_list_audits_supabase(WP_REST_Request $request)
{
    $sync = new ConversionIQ_Supabase_Sync();
    $rows = $sync->get_all_audits(100);

    if ($rows === false) {
        return rest_ensure_response(array());
    }

    // Build a page_url â†’ WP page map so we can resolve page_id and page_title
    $wp_pages = get_posts(array(
        'post_type'   => 'page',
        'numberposts' => -1,
        'post_status' => 'publish',
    ));
    $url_map = array();
    foreach ($wp_pages as $page) {
        $permalink = get_permalink($page->ID);
        $url_map[ trailingslashit($permalink) ]   = $page;
        $url_map[ untrailingslashit($permalink) ] = $page;
    }

    $formatted = array();
    foreach ($rows as $row) {
        $page_url = isset($row['page_url']) ? $row['page_url'] : '';
        $wp_page  = isset($url_map[ trailingslashit($page_url) ])
            ? $url_map[ trailingslashit($page_url) ]
            : (isset($url_map[ untrailingslashit($page_url) ])
                ? $url_map[ untrailingslashit($page_url) ]
                : null);

        // Derive a human title from the URL slug when the WP page isn't found.
        // Use a deterministic synthetic page_id (crc32) so OverviewTab can group
        // by page correctly even when the WP page no longer exists.
        if ($wp_page) {
            $page_title = $wp_page->post_title;
            $page_id    = $wp_page->ID;
        } else {
            $slug       = basename( rtrim( parse_url( $page_url, PHP_URL_PATH ), '/' ) );
            $page_title = $slug ? ucwords( str_replace( array('-', '_'), ' ', $slug ) ) : $page_url;
            // crc32 can return negative on 32-bit; abs() keeps it positive
            $page_id    = abs( crc32( $page_url ) );
        }

        // JSON fields may come back as strings from Supabase
        $decode = function($val) {
            return is_string($val) ? json_decode($val, true) : $val;
        };

        $formatted[] = array(
            'id'                => $row['id'],
            'insert_id'         => $row['id'],
            'page_url'          => $page_url,
            'page_id'           => $page_id,
            'page_title'        => $page_title,
            'overall_score'     => $row['overall_score']     ?? null,
            'clarity_score'     => $row['clarity_score']     ?? null,
            'emotional_score'   => $row['emotional_score']   ?? null,
            'cta_strength'      => $row['cta_strength']      ?? null,
            'readability_score' => $row['readability_score'] ?? null,
            'engagement_score'  => $row['engagement_score']  ?? null,
            'trust_score'       => $row['trust_score']       ?? null,
            'cro_checklist'     => $decode($row['cro_checklist']     ?? null),
            'insights'          => $decode($row['insights']          ?? null),
            'recommendations'   => $decode($row['recommendations']   ?? null),
            'report_token'      => $row['report_token'] ?? null,
            'created_at'        => $row['created_at']  ?? null,
            'ai_used'           => true,
        );
    }

    return rest_ensure_response($formatted);
}

function conversioniq_generate_report(WP_REST_Request $request)
{
    // Clear any output that might have been sent
    if (ob_get_level()) {
        ob_clean();
    }

    ciq_log('Ã°Å¸â€Âµ REST API: Report generation endpoint called');

    $params = $request->get_json_params();
    if (empty($params['audit_id'])) {
        ciq_log('Ã¢ÂÅ’ REST API: Missing audit_id');
        return new WP_REST_Response(array('success' => false, 'message' => 'Missing audit_id'), 400);
    }

    $audit_id = intval($params['audit_id']);
    ciq_log('Ã°Å¸â€Âµ REST API: Audit ID: ' . $audit_id);

    $audit = ConversionIQ_DB::get_audit($audit_id);
    if (!$audit) {
        ciq_log('Ã¢ÂÅ’ REST API: Audit not found: ' . $audit_id);
        return new WP_REST_Response(array('success' => false, 'message' => 'Audit not found'), 404);
    }

    ciq_log('Ã°Å¸â€Âµ REST API: Audit found, calling generate_pdf_for_audit()');

    // Generate report with error handling
    try {
        $res = ConversionIQ_Reports::generate_pdf_for_audit($audit);
        ciq_log('Ã°Å¸â€Âµ REST API: generate_pdf_for_audit() returned: ' . json_encode($res));
        return rest_ensure_response($res);
    }
    catch (Exception $e) {
        ciq_log('Ã¢ÂÅ’ REST API: Exception caught: ' . $e->getMessage());
        ciq_log('Ã¢ÂÅ’ REST API: Stack trace: ' . $e->getTraceAsString());
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Report generation error: ' . $e->getMessage()
        ), 500);
    }
    catch (Error $e) {
        ciq_log('Ã¢ÂÅ’ REST API: Fatal error caught: ' . $e->getMessage());
        ciq_log('Ã¢ÂÅ’ REST API: Stack trace: ' . $e->getTraceAsString());
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Report generation fatal error: ' . $e->getMessage()
        ), 500);
    }
}

function conversioniq_list_pages(WP_REST_Request $request)
{
    $args = array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'sort_column' => 'post_title',
        'sort_order' => 'asc',
        'number' => 999,
    );
    $pages = get_pages($args);
    $out = array();
    foreach ($pages as $p) {
        $out[] = array(
            'id' => $p->ID,
            'title' => $p->post_title,
            'permalink' => get_permalink($p),
        );
    }
    return rest_ensure_response($out);
}

function conversioniq_get_page_content(WP_REST_Request $request)
{
    $id = intval($request['id']);
    $post = get_post($id);
    if (!$post) {
        return new WP_REST_Response(array('success' => false, 'message' => __('Page not found', 'conversion-iq')), 404);
    }
    $content = apply_filters('the_content', $post->post_content);
    $excerpt = wp_strip_all_tags($post->post_excerpt);
    return rest_ensure_response(array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'permalink' => get_permalink($post),
        'content' => $content,
        'excerpt' => $excerpt,
        'word_count' => str_word_count(wp_strip_all_tags($content)),
    ));
}



/**
 * GET /business-profile â€” fetch business profile via conversioniq-app.com (primary),
 * falling back to direct Supabase lookup if the SaaS call fails.
 */
function conversioniq_get_business_profile(WP_REST_Request $request)
{
    $fields = [ 'business_name', 'industry', 'product', 'audience', 'pain_points', 'competitors', 'goal', 'additional_info', 'unique_selling_points', 'target_geography', 'price_point', 'primary_traffic_source' ];

    // Always start from local WP cache so we never lose data
    $local = json_decode( get_option( 'conversion_iq_settings', '{}' ), true );
    if ( ! is_array( $local ) ) $local = [];

    $license_key = get_option( 'conversioniq_license_key', '' );
    $profile     = null;
    $source      = 'none';

    // â”€â”€ Primary: fetch via conversioniq-app.com (it knows the org association) â”€â”€
    if ( $license_key ) {
        $saas_response = wp_remote_post( 'https://conversioniq-app.com/api/get-business-profile', [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [
                'license_key' => $license_key,
                'site_url'    => get_site_url(),
            ] ),
        ] );

        if ( ! is_wp_error( $saas_response ) && wp_remote_retrieve_response_code( $saas_response ) === 200 ) {
            $saas_body = json_decode( wp_remote_retrieve_body( $saas_response ), true );
            if ( is_array( $saas_body ) && ! empty( $saas_body['profile'] ) ) {
                $profile = $saas_body['profile'];
                $source  = 'saas';
                // Cache the organization_id for future Supabase calls if returned
                if ( ! empty( $saas_body['organization_id'] ) ) {
                    update_option( 'conversioniq_organization_id', sanitize_text_field( $saas_body['organization_id'] ) );
                }
            }
        }
    }

    // â”€â”€ Fallback: direct Supabase lookup â”€â”€
    if ( $profile === null ) {
        $sync    = new ConversionIQ_Supabase_Sync();
        $profile = $sync->fetch_business_profile();
        if ( is_array( $profile ) ) {
            $source = 'supabase';
        }
    }

    if ( is_array( $profile ) ) {
        foreach ( $fields as $f ) {
            if ( isset( $profile[ $f ] ) && $profile[ $f ] !== null && $profile[ $f ] !== '' ) {
                $local[ $f ] = $profile[ $f ];
            }
        }
    }

    // Build response from merged data
    $result = [];
    foreach ( $fields as $f ) {
        $result[ $f ] = $local[ $f ] ?? null;
    }
    return rest_ensure_response( $result );
}

/**
 * POST /business-profile â€” save business profile to Supabase + local WP cache.
 */
function conversioniq_save_business_profile_endpoint(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    if ( empty( $params ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'No data provided' ], 400 );
    }

    $allowed = [ 'business_name', 'industry', 'product', 'audience', 'pain_points', 'competitors', 'goal', 'additional_info', 'unique_selling_points', 'target_geography', 'price_point', 'primary_traffic_source' ];
    $clean   = [];
    foreach ( $allowed as $field ) {
        if ( array_key_exists( $field, $params ) ) {
            $clean[ $field ] = ( $field === 'additional_info' )
                ? sanitize_textarea_field( $params[ $field ] )
                : sanitize_text_field( $params[ $field ] );
        }
    }

    // Always persist to local WP option â€” fast read path for AI audits
    $local = json_decode( get_option( 'conversion_iq_settings', '{}' ), true );
    if ( ! is_array( $local ) ) $local = [];
    $local = array_merge( $local, $clean );
    update_option( 'conversion_iq_settings', wp_json_encode( $local ) );

    // Best-effort sync to Supabase
    $sync = new ConversionIQ_Supabase_Sync();
    $sync->save_business_profile( $clean );

    return rest_ensure_response( [ 'success' => true ] );
}

function conversioniq_guess_business_info(WP_REST_Request $request)
{
    ciq_log('Auto-fill: Reading homepage content');

    $content = '';

    // --- Strategy 1: Read front page directly from DB (avoids loopback HTTP deadlock) ---
    $front_page_id = (int) get_option('page_on_front');
    if ($front_page_id > 0) {
        $page = get_post($front_page_id);
        if ($page && !empty($page->post_content)) {
            $rendered = apply_filters('the_content', $page->post_content);
            $content  = wp_strip_all_tags($rendered);
            ciq_log('Auto-fill: Front page read from DB (ID ' . $front_page_id . ', ' . strlen($content) . ' chars)');
        }
    }

    // Also append site name + tagline as context for the AI
    $site_meta = trim(get_bloginfo('name') . ' - ' . get_bloginfo('description'));
    if (!empty($site_meta)) {
        $content = $site_meta . ' ' . $content;
    }

    // --- Strategy 2: HTTP fallback for page builder sites (Elementor etc.) ---
    // Only used when DB content is thin (page builders store content outside post_content)
    if (strlen(trim($content)) < 200) {
        ciq_log('Auto-fill: DB content thin, trying HTTP fallback');
        $home_url      = get_home_url();
        $http_response = wp_remote_get($home_url, array(
            'timeout'    => 25,
            'sslverify'  => true,
            'user-agent' => 'ConversionIQ-AutoFill/1.0',
        ));

        if (!is_wp_error($http_response)) {
            $html    = wp_remote_retrieve_body($http_response);
            $content = wp_strip_all_tags($html);
            ciq_log('Auto-fill: HTTP fetch succeeded (' . strlen($content) . ' chars)');
        } else {
            ciq_log('Auto-fill: HTTP fallback failed: ' . $http_response->get_error_message());
        }
    }

    if (strlen(trim($content)) < 10) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not read homepage content. Please fill in your business information manually.',
        ), 500);
    }

    $content = preg_replace('/\s+/', ' ', $content); // Normalize whitespace
    $content = substr($content, 0, 3000);            // Limit to first 3000 chars

    ciq_log('Auto-fill: Final content length sent to AI: ' . strlen($content) . ' chars');
    // Build AI prompt for business info extraction
    $prompt = "You are analyzing a homepage to extract business information. Extract the following details from the page content below:

**Required Information:**
- Industry/Niche: What industry or market does this business operate in?
- Product/Service: What specific products or services do they sell?
- Target Audience: Who are their customers? (demographics, roles, etc.)
- Pain Points: What problems do they solve for customers? (comma-separated list)
- Competitors: Who might their competitors be in this space? (comma-separated list of similar businesses)
- Goal: What is the primary conversion goal? (e.g., 'Book a call', 'Purchase product', 'Sign up for trial')
- Additional Info: Any other relevant context about the business (unique selling points, special offers, guarantees, etc.)

**Homepage Content:**
{$content}

**Return format (JSON only, no markdown):**
{
  \"industry\": \"Industry name\",
  \"product\": \"What they sell\",
  \"audience\": \"Who they sell to\",
  \"pain_points\": \"Problem 1, Problem 2, Problem 3\",
  \"competitors\": \"Competitor 1, Competitor 2\",
  \"goal\": \"Primary conversion goal\",
  \"additional_info\": \"Other relevant context\"
}

IMPORTANT: Return ONLY valid JSON, no code blocks, no explanations.";

    // Call AI
    $api_key = get_option('conversioniq_api_key', '');
    if (empty($api_key)) {
        ciq_log('âŒ Guess fields: No API key found â€” license must be activated first.');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'License not activated. Please activate your license to use this feature.',
        ), 403);
    }

    $ai_body = array(
        'model' => 'gpt-4o-mini',
        'messages' => array(
                array(
                'role' => 'user',
                'content' => $prompt
            )
        ),
        'max_tokens' => 2000,
        'temperature' => 0.7,
        'stream' => false
    );

    $ai_args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json',
        ),
        'body' => wp_json_encode($ai_body),
        'timeout' => 60,
        'sslverify' => true,
    );

    ciq_log('Ã°Å¸Â¤â€“ Calling AI to extract business info...');
    $ai_response = wp_remote_post('https://routellm.abacus.ai/v1/chat/completions', $ai_args);

    if (is_wp_error($ai_response)) {
        ciq_log('Ã¢ÂÅ’ AI API error: ' . $ai_response->get_error_message());
        return new WP_REST_Response(array('success' => false, 'message' => 'AI analysis failed'), 500);
    }

    $status_code = wp_remote_retrieve_response_code($ai_response);
    ciq_log('Ã°Å¸â€œÂ¡ Auto-fill API response status: ' . $status_code);

    if ($status_code !== 200) {
        $error_body = wp_remote_retrieve_body($ai_response);
        ciq_log('Ã¢ÂÅ’ Auto-fill API returned non-200 status: ' . $status_code);
        ciq_log('Ã¢ÂÅ’ Response body: ' . substr($error_body, 0, 500));
        return new WP_REST_Response(array('success' => false, 'message' => 'AI API error: ' . $status_code), 500);
    }

    $response_body = wp_remote_retrieve_body($ai_response);
    ciq_log('Ã°Å¸â€œâ€ž Response body length: ' . strlen($response_body) . ' chars');
    ciq_log('Ã°Å¸â€œâ€ž First 500 chars: ' . substr($response_body, 0, 500));

    $ai_data = json_decode($response_body, true);

    if (!$ai_data) {
        ciq_log('Ã¢ÂÅ’ Failed to parse AI response as JSON: ' . json_last_error_msg());
        return new WP_REST_Response(array('success' => false, 'message' => 'Invalid JSON response'), 500);
    }

    ciq_log('Ã°Å¸â€Â Response structure keys: ' . json_encode(array_keys($ai_data)));

    if (!isset($ai_data['choices'][0]['message']['content'])) {
        ciq_log('Ã¢Å¡Â Ã¯Â¸Â No AI response content');
        ciq_log('Ã¢Å¡Â Ã¯Â¸Â Full response structure: ' . json_encode($ai_data));
        return new WP_REST_Response(array('success' => false, 'message' => 'AI returned no content'), 500);
    }

    $ai_content = trim($ai_data['choices'][0]['message']['content']);

    // Remove markdown code blocks if present
    if (preg_match('/```json\s*(.*?)\s*```/s', $ai_content, $matches)) {
        $ai_content = $matches[1];
    }
    elseif (preg_match('/```\s*(.*?)\s*```/s', $ai_content, $matches)) {
        $ai_content = $matches[1];
    }

    $fields = json_decode($ai_content, true);

    if (!$fields) {
        ciq_log('Ã¢Å¡Â Ã¯Â¸Â Failed to parse AI response as JSON');
        ciq_log('Raw AI response: ' . substr($ai_content, 0, 500));
        return new WP_REST_Response(array('success' => false, 'message' => 'Failed to parse AI response'), 500);
    }

    ciq_log('Ã¢Å“â€¦ Successfully extracted business info');

    return rest_ensure_response(array(
        'success' => true,
        'fields' => $fields
    ));
}

/**
 * Deactivate the license on this site (releases the site slot on the licensing server)
 */
function conversioniq_license_deactivate(WP_REST_Request $request)
{
    $license_key = get_option('conversioniq_license_key', '');

    if (empty($license_key)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'No active license found.'), 400);
    }

    // Notify the licensing server to release this site's slot
    $response = wp_remote_post('https://conversioniq-app.com/api/deactivate-license', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'license_key' => $license_key,
            'site_url'    => get_site_url(),
        )),
    ));

    // Clear local license data regardless of server response (allow offline deactivation)
    delete_option('conversioniq_license_key');
    delete_option('conversioniq_license_status');
    delete_option('conversioniq_license_validated_at');
    delete_option('conversioniq_license_customer');
    delete_option('conversioniq_api_key');

    if (is_wp_error($response)) {
        ciq_log('ConversionIQ: License server unreachable during deactivation â€” local data cleared anyway.');
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'License deactivated locally. Note: could not reach license server to release the slot â€” contact support if needed.',
        ));
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    ciq_log('ConversionIQ: License deactivated for site ' . get_site_url());

    return rest_ensure_response(array(
        'success' => true,
        'message' => $body['message'] ?? 'License deactivated successfully. This site slot has been released.',
    ));
}

/**
 * Fetch all active site activations for this license key from the licensing server
 */
function conversioniq_license_sites(WP_REST_Request $request)
{
    $license_key = get_option('conversioniq_license_key', '');

    if (empty($license_key)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'No active license found.'), 400);
    }

    $response = wp_remote_post('https://conversioniq-app.com/api/license-sites', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array('license_key' => $license_key)),
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Could not reach the license server.'), 503);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200 || !isset($body['sites'])) {
        return new WP_REST_Response(array('success' => false, 'message' => $body['message'] ?? 'Failed to retrieve site list.'), $code);
    }

    return rest_ensure_response(array(
        'success'    => true,
        'sites'      => $body['sites'],      // array of { site_url, activated_at }
        'max_sites'  => $body['max_sites'] ?? null,
        'plan'       => $body['plan'] ?? null,
    ));
}

/**
 * Remove a specific site activation from this license (admin removing another site's slot)
 */
function conversioniq_license_remove_site(WP_REST_Request $request)
{
    $params      = $request->get_json_params();
    $site_url    = esc_url_raw($params['site_url'] ?? '');
    $license_key = get_option('conversioniq_license_key', '');

    if (empty($license_key) || empty($site_url)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Missing license key or site URL.'), 400);
    }

    $response = wp_remote_post('https://conversioniq-app.com/api/deactivate-license', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array(
            'license_key' => $license_key,
            'site_url'    => $site_url,
        )),
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Could not reach the license server.'), 503);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200 || empty($body['success'])) {
        return new WP_REST_Response(array('success' => false, 'message' => $body['message'] ?? 'Failed to remove site.'), $code);
    }

    ciq_log('ConversionIQ: Removed site slot ' . $site_url . ' from license ' . $license_key);

    return rest_ensure_response(array(
        'success' => true,
        'message' => $body['message'] ?? 'Site removed successfully.',
    ));
}


/**
 * License functions
 */
function conversioniq_license_status()
{
    $license_key    = get_option('conversioniq_license_key', '');
    $license_status = get_option('conversioniq_license_status', 'inactive');
    $validated_at   = get_option('conversioniq_license_validated_at', 0);

    // Grace period: treat as active if last successful validation was within 7 days
    if ($license_status !== 'active' && $validated_at) {
        if ((time() - (int) $validated_at) < (7 * DAY_IN_SECONDS)) {
            $license_status = 'active';
        }
    }

    $customer = get_option('conversioniq_license_customer', null);

    $features = class_exists('ConversionIQ_Config_Manager')
        ? ConversionIQ_Config_Manager::get_feature_flags()
        : array();

    return rest_ensure_response(array(
        'activated'    => ($license_status === 'active'),
        'license_key'  => $license_key ? substr($license_key, 0, 7) . '...' : '',
        'license_key_full' => $license_key,
        'status'       => $license_status,
        'validated_at' => $validated_at,
        'customer'     => $customer,
        'features'     => $features,
    ));
}

/**
 * Refresh the cached plan/customer data by re-validating the stored license key.
 * Allows plan upgrades made in the dashboard to take effect without re-entering the key.
 */
function conversioniq_license_refresh()
{
    $license_key = get_option('conversioniq_license_key', '');
    if (empty($license_key)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No license key found. Please activate a license first.',
        ), 400);
    }

    $response = wp_remote_post('https://conversioniq-app.com/api/validate-license', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array_filter(array(
            'license_key'       => $license_key,
            'site_url'          => get_site_url(),
            'organization_id'   => get_option('conversioniq_organization_id', '') ?: null,
        ))),
    ));

    if (is_wp_error($response)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not reach the license server: ' . $response->get_error_message(),
        ), 503);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code !== 200 || empty($body['valid'])) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $body['message'] ?? 'License validation failed.',
        ), 400);
    }

    // Update stored data
    update_option('conversioniq_license_status', 'active');
    update_option('conversioniq_license_validated_at', time());

    if (!empty($body['api_key'])) {
        update_option('conversioniq_api_key', sanitize_text_field($body['api_key']));
    }

    $customer = null;
    if (!empty($body['customer']) && is_array($body['customer'])) {
        $customer = array(
            'name'    => sanitize_text_field($body['customer']['name'] ?? ''),
            'email'   => sanitize_email($body['customer']['email'] ?? ''),
            'company' => sanitize_text_field($body['customer']['company'] ?? ''),
            'plan'    => sanitize_text_field($body['customer']['plan'] ?? ''),
        );
        update_option('conversioniq_license_customer', $customer);
    }

    // Clear stale feature flag cache so plan defaults take effect immediately
    delete_option(ConversionIQ_Config_Manager::FEATURE_FLAGS_OPTION);

    // Also re-sync branding / feature flags
    if (class_exists('ConversionIQ_Config_Manager')) {
        ConversionIQ_Config_Manager::sync_from_saas();
    }

    // Return fresh feature flags so the frontend can update without a page reload
    $features = class_exists('ConversionIQ_Config_Manager')
        ? ConversionIQ_Config_Manager::get_feature_flags()
        : array();

    return rest_ensure_response(array(
        'success'  => true,
        'message'  => 'Plan refreshed successfully.',
        'customer' => $customer,
        'features' => $features,
    ));
}

function conversioniq_license_activate(WP_REST_Request $request)
{
    $params      = $request->get_json_params();
    $license_key = sanitize_text_field($params['license_key'] ?? '');

    if (empty($license_key)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'License key is required',
        ), 400);
    }

    // Basic format check: CIQ-XXXXX-XXXXX-XXXXX-XXXXX
    if (!preg_match('/^CIQ-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/i', $license_key)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid license key format. Keys look like: CIQ-XXXXX-XXXXX-XXXXX-XXXXX',
        ), 400);
    }

    // Call the Conversion IQ licensing server
    $response = wp_remote_post('https://conversioniq-app.com/api/validate-license', array(
        'timeout' => 15,
        'headers' => array('Content-Type' => 'application/json'),
        'body'    => wp_json_encode(array_filter(array(
            'license_key'       => $license_key,
            'site_url'          => get_site_url(),
            'organization_id'   => get_option('conversioniq_organization_id', '') ?: null,
        ))),
    ));

    if (is_wp_error($response)) {
        ciq_log('ConversionIQ License Validation Error: ' . $response->get_error_message());
        // Allow activation if we already had a recent successful validation (grace period)
        $validated_at = get_option('conversioniq_license_validated_at', 0);
        if ($validated_at && (time() - (int) $validated_at) < (7 * DAY_IN_SECONDS)) {
            return rest_ensure_response(array(
                'success' => true,
                'message' => 'License server unreachable - using cached validation.',
                'status'  => 'active',
            ));
        }
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Could not reach the license server. Please try again.',
        ), 503);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200 || empty($body['valid'])) {
        $msg = $body['message'] ?? 'License key is not valid or has expired.';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => $msg,
        ), 400);
    }

    // Store the validated license
    update_option('conversioniq_license_key', $license_key);
    update_option('conversioniq_license_status', 'active');
    update_option('conversioniq_license_validated_at', time());

    // Store API key if the validation response includes one
    if (!empty($body['api_key'])) {
        update_option('conversioniq_api_key', sanitize_text_field($body['api_key']));
        ciq_log('ConversionIQ License: API key stored from validation response');
    }

    // Store organization ID if the validation response includes one
    if (!empty($body['organization_id'])) {
        update_option('conversioniq_organization_id', sanitize_text_field($body['organization_id']));
        ciq_log('ConversionIQ License: Organization ID stored from validation response');
    }

    // Store customer info if the server returned it
    $customer = null;
    if (!empty($body['customer']) && is_array($body['customer'])) {
        $customer = array(
            'name'    => sanitize_text_field($body['customer']['name'] ?? ''),
            'email'   => sanitize_email($body['customer']['email'] ?? ''),
            'company' => sanitize_text_field($body['customer']['company'] ?? ''),
            'plan'    => sanitize_text_field($body['customer']['plan'] ?? ''),
        );
        update_option('conversioniq_license_customer', $customer);
    }

    // Trigger a config sync to pull branding, feature flags, and API key from the SaaS backend
    if (class_exists('ConversionIQ_Config_Manager')) {
        ConversionIQ_Config_Manager::sync_from_saas();
    }

    // Auto-push remote audit credentials to Supabase so the dashboard can reach this site
    try {
        $supabase = new ConversionIQ_Supabase_Sync();
        $supabase->push_remote_credentials();
    } catch (Exception $e) {
        ciq_log('ConversionIQ: push_remote_credentials on license activate: ' . $e->getMessage());
    }

    $features = class_exists('ConversionIQ_Config_Manager')
        ? ConversionIQ_Config_Manager::get_feature_flags()
        : array();

    return rest_ensure_response(array(
        'success'  => true,
        'message'  => 'License activated successfully!',
        'status'   => 'active',
        'customer' => $customer,
        'features' => $features,
    ));
}

/**
 * Test email functionality
 */
function conversioniq_test_email(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $test_email = sanitize_email($params['email'] ?? '');

    // Get settings to use configured email or fallback
    $settings = get_option('conversion_iq_automated_reports', array());
    $email = !empty($test_email) ? $test_email : ($settings['email'] ?? get_option('admin_email'));

    if (!is_email($email)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Invalid email address'
        ), 400);
    }

    $site_name = get_bloginfo('name');
    $subject = 'Ã¢Å“â€¦ Conversion IQ Test Email - ' . date('M j, Y g:i A');

    // Check if this is a Basecamp email
    $is_basecamp = conversioniq_has_basecamp_email($email);

    if ($is_basecamp) {
        // Send plain text version for Basecamp
        $message = "CONVERSION IQ TEST EMAIL\n";
        $message .= "=================================\n\n";
        $message .= "Email System Working!\n\n";
        $message .= "Your Conversion IQ email system is configured correctly and working as expected.\n";
        $message .= "Automated audit reports will be delivered to this address.\n\n";
        $message .= "SITE INFORMATION:\n";
        $message .= "- WordPress Site: " . $site_name . "\n";
        $message .= "- Site URL: " . get_home_url() . "\n";
        $message .= "- Recipient Email: " . $email . "\n";
        $message .= "- Test Time: " . date('F j, Y g:i A') . "\n\n";
        $message .= "WHAT HAPPENS NEXT?\n";
        $message .= "When you enable automated reports in Conversion IQ settings, audit reports will be sent\n";
        $message .= "to this email address according to your chosen schedule (weekly, monthly, or bi-monthly).\n\n";
        $message .= "---\n";
        $message .= "Conversion IQ by Webtec\n";
        $message .= "AI-Powered Website Conversion Optimization\n";

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );
    }
    else {
        // Send HTML version for regular emails
        $message = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f3f4f6; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
        .header { background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); padding: 40px 30px; text-align: center; }
        .header h1 { margin: 0; color: #ffffff; font-size: 28px; font-weight: 700; }
        .content { padding: 40px 30px; }
        .success-box { background: #ecfdf5; border-left: 4px solid #10b981; padding: 20px; border-radius: 8px; margin: 20px 0; }
        .success-box h2 { margin: 0 0 10px 0; color: #065f46; font-size: 20px; }
        .success-box p { margin: 0; color: #047857; line-height: 1.6; }
        .info-grid { background: #f9fafb; border-radius: 12px; padding: 20px; margin: 20px 0; }
        .info-row { padding: 12px 0; border-bottom: 1px solid #e5e7eb; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .info-value { font-size: 16px; color: #111827; margin-top: 4px; }
        .footer { background: #f9fafb; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb; }
        .footer p { margin: 5px 0; font-size: 13px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Ã¢Å“â€¦ Email System Working!</h1>
        </div>
        <div class="content">
            <div class="success-box">
                <h2>Test Successful</h2>
                <p>Your Conversion IQ email system is configured correctly and working as expected. Automated audit reports will be delivered to this address.</p>
            </div>
            
            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label">WordPress Site</div>
                    <div class="info-value">' . esc_html($site_name) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Site URL</div>
                    <div class="info-value">' . esc_html(get_home_url()) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Recipient Email</div>
                    <div class="info-value">' . esc_html($email) . '</div>
                </div>
                <div class="info-row">
                    <div class="info-label">Test Time</div>
                    <div class="info-value">' . date('F j, Y g:i A') . '</div>
                </div>
            </div>
            
            <p style="color: #6b7280; line-height: 1.6; margin-top: 20px;">
                <strong>What happens next?</strong><br>
                When you enable automated reports in Conversion IQ settings, audit reports will be sent to this email address according to your chosen schedule (weekly, monthly, or bi-monthly).
            </p>
        </div>
        <div class="footer">
            <p><strong>Conversion IQ</strong> by Webtec</p>
            <p>AI-Powered Website Conversion Optimization</p>
        </div>
    </div>
</body>
</html>';

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option('admin_email') . '>'
        );
    }

    ciq_log('Ã°Å¸â€œÂ§ Sending test email to: ' . $email . ($is_basecamp ? ' (Basecamp - Plain Text)' : ' (HTML)'));
    $sent = wp_mail($email, $subject, $message, $headers);

    if ($sent) {
        ciq_log('Ã¢Å“â€¦ Test email sent successfully');
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Test email sent successfully to ' . $email
        ));
    }
    else {
        ciq_log('Ã¢ÂÅ’ Failed to send test email');
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to send test email. Check your WordPress email configuration.'
        ), 500);
    }
}

/**
 * Send manual audit report with real results
 */
function conversioniq_send_manual_report(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $email_input = sanitize_text_field($params['email'] ?? '');
    $page_ids = isset($params['page_ids']) ? array_map('intval', $params['page_ids']) : array();

    $log = array();
    $log[] = 'Ã°Å¸â€Â Starting manual report generation...';

    // Get settings to use configured email or fallback
    $settings = get_option('conversion_iq_automated_reports', array());
    if (empty($email_input)) {
        $email_input = $settings['email'] ?? get_option('admin_email');
    }

    // Process comma-separated emails
    $emails = array_map('trim', explode(',', $email_input));
    $valid_emails = array_filter($emails, 'is_email');
    $email = implode(', ', $valid_emails);

    $log[] = 'Ã°Å¸â€œÂ§ Target email(s): ' . $email;

    if (empty($valid_emails)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'At least one valid email address is required',
            'log' => $log
        ), 400);
    }

    if (empty($page_ids)) {
        $log[] = 'Ã¢ÂÅ’ No pages selected';
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No pages selected for the report',
            'log' => $log
        ), 400);
    }

    $log[] = 'Ã°Å¸â€œâ€ž Selected page IDs: ' . implode(', ', $page_ids);

    // Get the most recent audits for the selected pages
    global $wpdb;
    $table = $wpdb->prefix . 'conversioniq_audits';
    $placeholders = implode(',', array_fill(0, count($page_ids), '%d'));

    $log[] = 'Ã°Å¸â€Å½ Querying database for audits...';

    $audits = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table 
         WHERE page_id IN ($placeholders) 
         ORDER BY created_at DESC",
        ...$page_ids
    ), ARRAY_A);

    // If no audits exist, run them automatically
    if (empty($audits)) {
        $log[] = 'Ã°Å¸â€œÅ  No existing audits found - running audits automatically...';

        // Run audits for each page
        foreach ($page_ids as $page_id) {
            $page = get_post($page_id);
            if (!$page) {
                $log[] = '  Ã¢Å¡Â Ã¯Â¸Â Page ID ' . $page_id . ' not found, skipping';
                continue;
            }

            $log[] = '  Ã°Å¸â€â€ž Running audit for: ' . $page->post_title;

            // Get page content
            $page_url = get_permalink($page_id);
            $content = $page->post_content;
            $content = strip_shortcodes($content);
            $content = wp_strip_all_tags($content);

            // Fetch HTML structure
            $html_structure = '';
            $response = wp_remote_get($page_url, array(
                'timeout' => 10,
                'sslverify' => true,
            ));

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $html = wp_remote_retrieve_body($response);
                $html_structure = conversioniq_extract_html_structure($html);
            }

            // Supplement HTML-based trust signals with reviews stored in WordPress CPTs
            $cpt_reviews = conversioniq_get_cpt_reviews();
            if (!empty($cpt_reviews)) {
                $html_structure .= $cpt_reviews;
            }

            // Get business settings
            $business_settings = get_option('conversion_iq_settings', '{}');
            $business = json_decode($business_settings, true);

            // Prepare payload for AI analysis
            $payload = array(
                'business' => $business,
                'page' => array(
                    'title' => $page->post_title,
                    'content' => $content,
                    'url' => $page_url,
                    'word_count' => str_word_count($content),
                    'html_structure' => $html_structure,
                ),
            );

            // Run the AI analysis
            if (!class_exists('ConversionIQ_AI')) {
                require_once CONVERSION_IQ_DIR . 'includes/class-ai-engine.php';
            }

            try {
                $ai_result = ConversionIQ_AI::analyze($payload);

                if (is_array($ai_result)) {
                    // Generate a unique token for the public report URL
                    $ai_result['report_token'] = bin2hex(random_bytes(16));

                    // Save audit to database
                    $inserted = $wpdb->insert(
                        $table,
                        array(
                        'page_id' => $page_id,
                        'page_title' => $page->post_title,
                        'page_url' => $page_url,
                        'data' => wp_json_encode($ai_result),
                        'ai_used' => true,
                        'created_at' => current_time('mysql')
                    ),
                        array('%d', '%s', '%s', '%s', '%d', '%s')
                    );

                    if ($inserted) {
                        $log[] = '    Ã¢Å“â€¦ Audit completed and saved (ID: ' . $wpdb->insert_id . ')';

                        // Sync to Supabase cloud database
                        try {
                            $supabase_sync = new ConversionIQ_Supabase_Sync();
                            $sync_success = $supabase_sync->send_audit(array(
                                'page_url' => $page_url,
                                'page_title' => $page->post_title,
                                'industry' => isset($business['industry']) ? $business['industry'] : null,
                                'clarity_score' => isset($ai_result['clarity_score']) ? $ai_result['clarity_score'] : null,
                                'emotional_score' => isset($ai_result['emotional_score']) ? $ai_result['emotional_score'] : null,
                                'cta_strength' => isset($ai_result['cta_strength']) ? $ai_result['cta_strength'] : null,
                                'readability_score' => isset($ai_result['readability_score']) ? $ai_result['readability_score'] : null,
                                'engagement_score' => isset($ai_result['engagement_score']) ? $ai_result['engagement_score'] : null,
                                'trust_score' => isset($ai_result['trust_score']) ? $ai_result['trust_score'] : null,
                                'overall_score' => isset($ai_result['overall_score']) ? $ai_result['overall_score'] : null,
                                'suggestions' => isset($ai_result['suggestions']) ? $ai_result['suggestions'] : array(),
                                'functionality_suggestions' => isset($ai_result['functionality_suggestions']) ? $ai_result['functionality_suggestions'] : array(),
                                'rewrites' => isset($ai_result['rewrites']) ? $ai_result['rewrites'] : array(),
                                'analysis_method' => isset($ai_result['analysis_method']) ? $ai_result['analysis_method'] : 'single',
                                'sections_analyzed' => isset($ai_result['sections_analyzed']) ? $ai_result['sections_analyzed'] : 1,
                                // Public report fields
                                'report_token'       => $ai_result['report_token'],
                                'insights'           => isset($ai_result['insights']) ? $ai_result['insights'] : null,
                                'recommendations'    => isset($ai_result['recommendations']) ? $ai_result['recommendations'] : null,
                                'benchmark_research' => isset($ai_result['benchmark_research']) ? $ai_result['benchmark_research'] : null,
                                'business_context'   => array(
                                    'industry'    => $business['industry'] ?? null,
                                    'product'     => $business['product'] ?? null,
                                    'audience'    => $business['audience'] ?? null,
                                    'goal'        => $business['goal'] ?? null,
                                    'pain_points' => $business['pain_points'] ?? null,
                                ),
                                'lead_intelligence'  => isset($ai_result['lead_intelligence_summary']) ? $ai_result['lead_intelligence_summary'] : null,
                                'cro_checklist'      => isset($ai_result['cro_checklist']) ? $ai_result['cro_checklist'] : null,
                                'plan'               => ConversionIQ_Config_Manager::get_plan(),
                            ));

                            $supabase_sync->track_usage('analyze_page');

                            if ($sync_success) {
                                $log[] = '    Ã¢ËœÂÃ¯Â¸Â Synced to Supabase cloud';
                            }
                        }
                        catch (Exception $e) {
                            $log[] = '    Ã¢Å¡Â Ã¯Â¸Â Supabase sync skipped: ' . $e->getMessage();
                        }
                    }
                    else {
                        $log[] = '    Ã¢Å¡Â Ã¯Â¸Â Audit completed but failed to save: ' . $wpdb->last_error;
                    }
                }
                else {
                    $log[] = '    Ã¢ÂÅ’ Audit failed: Invalid response from AI';
                }
            }
            catch (Exception $e) {
                $log[] = '    Ã¢ÂÅ’ Audit failed: ' . $e->getMessage();
            }
        }

        // Re-query for the newly created audits
        $log[] = 'Ã°Å¸â€â€ž Fetching newly created audits...';
        $audits = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE page_id IN ($placeholders) 
             ORDER BY created_at DESC",
            ...$page_ids
        ), ARRAY_A);

        if (empty($audits)) {
            $log[] = 'Ã¢ÂÅ’ Failed to create audits';
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Failed to generate audits for the selected pages.',
                'log' => $log
            ), 500);
        }
    }

    $log[] = 'Ã¢Å“â€¦ Found ' . count($audits) . ' audit record(s) in database';

    // Group audits by page_id and get the most recent one for each
    $latest_audits = array();
    $seen_pages = array();

    foreach ($audits as $audit) {
        $page_id = $audit['page_id'];
        if (!in_array($page_id, $seen_pages)) {
            $audit['data'] = json_decode($audit['data'], true);
            $latest_audits[] = $audit;
            $seen_pages[] = $page_id;
        }
    }

    $log[] = 'Ã°Å¸â€œÅ  Processing ' . count($latest_audits) . ' unique page audit(s)';

    // Prepare results array in the format expected by the email function
    $results = array();
    foreach ($latest_audits as $audit) {
        $data = $audit['data'];
        $log[] = '  Ã¢Å“â€œ ' . $audit['page_title'] . ' (ID: ' . $audit['id'] . ')';
        $results[] = array(
            'insert_id' => $audit['id'],
            'page_title' => $audit['page_title'],
            'page_url' => $audit['page_url'],
            'clarity_score' => $data['clarity_score'] ?? 0,
            'emotional_score' => $data['emotional_score'] ?? 0,
            'cta_strength' => $data['cta_strength'] ?? 0,
            'readability_score' => $data['readability_score'] ?? 0,
            'engagement_score' => $data['engagement_score'] ?? 0,
            'trust_score' => $data['trust_score'] ?? 0
        );
    }

    // Get business context
    $log[] = 'Ã°Å¸ÂÂ¢ Loading business context...';
    $business_settings = get_option('conversion_iq_settings', '{}');
    $business = json_decode($business_settings, true);
    $business_context = array(
        'industry' => $business['industry'] ?? '',
        'audience' => $business['audience'] ?? '',
        'goal' => $business['goal'] ?? ''
    );

    // Use the automated reports class to send the email
    $log[] = 'Ã°Å¸â€œâ€ž Generating PDF reports...';
    if (!class_exists('ConversionIQ_Automated_Reports')) {
        require_once CONVERSION_IQ_DIR . 'includes/class-automated-reports.php';
    }

    // Call the send_email_report method using reflection since it's private
    $log[] = 'Ã°Å¸â€œÂ§ Preparing email with attachments...';
    
    // Add error handler to capture wp_mail errors
    $mail_error = '';
    add_action('wp_mail_failed', function($wp_error) use (&$mail_error, &$log) {
        $mail_error = $wp_error->get_error_message();
        $log[] = 'Ã¢ÂÅ’ Email error: ' . $mail_error;
        ciq_log('Ã¢ÂÅ’ wp_mail error: ' . $mail_error);
    });
    
    $reflection = new ReflectionClass('ConversionIQ_Automated_Reports');
    $method = $reflection->getMethod('send_email_report');
    $method->setAccessible(true);

    $result = $method->invoke(null, $email, $results, $business_context);
    
    // Extract the success status and messages from the result
    $sent = isset($result['success']) ? $result['success'] : false;
    $email_messages = isset($result['messages']) ? $result['messages'] : array();
    
    // Merge the detailed messages into the main log
    foreach ($email_messages as $msg) {
        $log[] = $msg;
    }

    if ($sent) {
        $log[] = 'Ã¢Å“â€¦ Email sent successfully via wp_mail()';
        $log[] = 'Ã°Å¸â€œÂ¬ Email queued for delivery to: ' . $email;
        $log[] = 'Ã¢â€žÂ¹Ã¯Â¸Â If you don\'t receive the email, check:';
        $log[] = '  - Your spam/junk folder';
        $log[] = '  - WordPress email configuration';
        $log[] = '  - Server email sending limits';
        $log[] = '  - PDF attachment size (might be rejected by email server)';
        
        ciq_log('Ã¢Å“â€¦ Manual audit report queued for delivery to: ' . $email . ' with ' . count($results) . ' page(s)');
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Audit report queued for delivery to ' . $email . ' with ' . count($results) . ' page(s). Check your inbox and spam folder.',
            'log' => $log
        ));
    }
    else {
        $log[] = 'Ã¢ÂÅ’ wp_mail() returned false - email not sent';
        if (!empty($mail_error)) {
            $log[] = 'Ã¢ÂÅ’ Error details: ' . $mail_error;
        }
        $log[] = 'Ã°Å¸â€™Â¡ Troubleshooting steps:';
        $log[] = '  1. Test email delivery works (confirm this first)';
        $log[] = '  2. Check if PDFs are being generated';
        $log[] = '  3. Try sending to one page at a time';
        $log[] = '  4. Contact your hosting provider about email sending';
        
        ciq_log('Ã¢ÂÅ’ Failed to send manual audit report to: ' . $email . ($mail_error ? ' - Error: ' . $mail_error : ''));
        
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Failed to send audit report. ' . ($mail_error ? 'Error: ' . $mail_error : 'Check WordPress email configuration.'),
            'log' => $log
        ), 500);
    }
}

// ============================================================
// GOOGLE ANALYTICS API ENDPOINTS
// ============================================================

function conversioniq_ga_status(WP_REST_Request $request)
{
    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->get_status());
}

function conversioniq_ga_save_credentials(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $client_id = $params['client_id'] ?? '';
    $client_secret = $params['client_secret'] ?? '';

    if (empty($client_id) || empty($client_secret)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Client ID and Client Secret are required'
        ), 400);
    }

    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->save_client_credentials($client_id, $client_secret));
}

function conversioniq_ga_auth_url(WP_REST_Request $request)
{
    $ga = new ConversionIQ_Google_Analytics();
    $url = $ga->get_auth_url();

    if (empty($url)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Please configure Google Analytics credentials first'
        ), 400);
    }

    return rest_ensure_response(array('success' => true, 'url' => $url));
}

function conversioniq_ga_properties(WP_REST_Request $request)
{
    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->get_properties());
}

function conversioniq_ga_save_property(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $property_id = $params['property_id'] ?? '';
    $property_name = $params['property_name'] ?? '';

    if (empty($property_id)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Property ID is required'
        ), 400);
    }

    $ga = new ConversionIQ_Google_Analytics();
    $result = $ga->save_property($property_id);

    // Also save property name for display
    if ($result['success'] && $property_name) {
        $options = get_option('conversioniq_ga_credentials', array());
        $options['property_name'] = $property_name;
        update_option('conversioniq_ga_credentials', $options);
    }

    return rest_ensure_response($result);
}

function conversioniq_ga_disconnect(WP_REST_Request $request)
{
    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->disconnect());
}

function conversioniq_ga_page_data(WP_REST_Request $request)
{
    $params = $request->get_json_params();
    $page_url = $params['url'] ?? '';
    $days = $params['days'] ?? 30;

    if (empty($page_url)) {
        return new WP_REST_Response(array(
            'success' => false,
            'error' => 'Page URL is required'
        ), 400);
    }

    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->get_page_conversions($page_url, $days));
}

function conversioniq_ga_top_pages(WP_REST_Request $request)
{
    $limit = $request->get_param('limit') ?? 10;
    $days = $request->get_param('days') ?? 30;

    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response($ga->get_top_pages($limit, $days));
}

// ── Remote Audit Trigger ─────────────────────────────────────────────────────

/**
 * GET /tracked-pages
 * Returns the list of pages configured for remote audit, plus the remote secret
 * and endpoint URL so the admin can copy them into their dashboard.
 */
function conversioniq_get_tracked_pages()
{
    // Auto-generate a dedicated remote secret if none exists yet
    $secret = get_option('conversioniq_remote_secret', '');
    if (empty($secret)) {
        $secret = 'ciq_' . bin2hex(random_bytes(24));
        update_option('conversioniq_remote_secret', $secret);
    }

    $tracked = get_option('conversioniq_tracked_pages', array());

    return rest_ensure_response(array(
        'tracked_pages'   => $tracked,
        'remote_secret'   => $secret,
        'endpoint'        => get_site_url() . '/wp-json/conversioniq/v1/remote-audit',
        'site_url'        => get_site_url(),
    ));
}

/**
 * POST /tracked-pages
 * Saves the list of pages to audit when a remote trigger fires.
 * Syncs the list (with title + URL) to Supabase organizations.tracked_pages.
 */
function conversioniq_save_tracked_pages(WP_REST_Request $request)
{
    $body     = $request->get_json_params();
    $page_ids = isset($body['page_ids']) ? $body['page_ids'] : array();

    // Validate: must be published pages/posts
    $allowed_types = array('page', 'post');
    $valid_ids     = array();
    foreach ($page_ids as $pid) {
        $pid  = absint($pid);
        if ($pid <= 0) continue;
        $post = get_post($pid);
        if ($post && $post->post_status === 'publish' && in_array($post->post_type, $allowed_types, true)) {
            $valid_ids[] = $pid;
        }
    }

    update_option('conversioniq_tracked_pages', $valid_ids);

    // Best-effort sync to Supabase so the dashboard can read the list
    $org_id = get_option('conversioniq_organization_id', '');
    if ($org_id) {
        try {
            $supabase = new ConversionIQ_Supabase_Sync();
            $supabase->push_tracked_pages($valid_ids);
        } catch (Exception $e) {
            ciq_log('conversioniq_save_tracked_pages: Supabase sync failed - ' . $e->getMessage());
        }
    }

    return rest_ensure_response(array('success' => true, 'tracked_pages' => $valid_ids));
}

/**
 * POST /remote-audit
 * Authenticated by X-CIQ-API-Key header (the site's remote secret).
 * No WordPress session required — designed to be called from conversioniq-app.com.
 *
 * Body (optional): { "page_ids": [123, 456] }
 * Falls back to stored tracked pages, then homepage.
 */
function conversioniq_remote_audit(WP_REST_Request $request)
{
    // ── Auth ──────────────────────────────────────────────────────────────────
    $provided_key = $request->get_header('X-CIQ-API-Key');
    $stored_key   = get_option('conversioniq_remote_secret', '');

    if (empty($provided_key) || empty($stored_key) || !hash_equals($stored_key, $provided_key)) {
        return new WP_REST_Response(array('success' => false, 'message' => 'Unauthorized'), 401);
    }

    // ── Rate limit (5 min between remote triggers) ────────────────────────────
    if (get_transient('ciq_remote_audit_lock')) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'An audit was triggered recently. Please wait 5 minutes between remote triggers.',
        ), 429);
    }
    set_transient('ciq_remote_audit_lock', 1, 300);

    // ── Resolve page IDs ─────────────────────────────────────────────────────
    $body     = $request->get_json_params();
    $page_ids = !empty($body['page_ids']) ? $body['page_ids'] : array();

    // Fall back to stored tracked pages
    if (empty($page_ids)) {
        $page_ids = get_option('conversioniq_tracked_pages', array());
    }

    // Last resort: homepage or first published page
    if (empty($page_ids)) {
        $front_id = (int) get_option('page_on_front');
        if ($front_id > 0) {
            $page_ids = array($front_id);
        } else {
            $fallback = get_posts(array('post_type' => array('page', 'post'), 'post_status' => 'publish', 'numberposts' => 1));
            if (!empty($fallback)) {
                $page_ids = array($fallback[0]->ID);
            }
        }
    }

    if (empty($page_ids)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'No pages to audit. Configure tracked pages in the plugin settings.',
        ), 400);
    }

    // ── Delegate to the existing audit runner ─────────────────────────────────
    // Set a WP user context so rate-limiting transient key is deterministic
    $admins = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ids'));
    if (!empty($admins)) {
        wp_set_current_user($admins[0]);
    }

    $audit_request = new WP_REST_Request('POST');
    $audit_request->set_body(json_encode(array('pages' => $page_ids)));
    $audit_request->set_header('Content-Type', 'application/json');

    $result = conversioniq_run_audit($audit_request);
    $data   = $result->get_data();

    return rest_ensure_response(array(
        'success'       => $data['success'] ?? false,
        'results'       => $data['results'] ?? array(),
        'pages_audited' => count($page_ids),
        'message'       => $data['message'] ?? null,
    ));
}
