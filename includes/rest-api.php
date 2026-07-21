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
function conversioniq_extract_html_structure( $html, $page_url = '' )
{
    global $wpdb;
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
    // 1. CTA Above the Fold — strip non-visible head/script/style regions first so
    // the first 5,000 chars reliably contain visible hero markup, not <head> boilerplate.
    $above_fold_html = preg_replace( '/<head[\s>][\s\S]*?<\/head>/i', '', $html );
    $above_fold_html = preg_replace( '/<script[\s>][\s\S]*?<\/script>/i', '', $above_fold_html );
    $above_fold_html = preg_replace( '/<style[\s>][\s\S]*?<\/style>/i',  '', $above_fold_html );
    $above_fold = substr( $above_fold_html, 0, 5000 );
    // First pass: CTA-class buttons (strongest HTML signal)
    if (preg_match_all('/<(?:button|a)[^>]*(?:class|role)[^>]*(?:btn|button|cta|get-started|start|try|buy|book|request|contact|sign-up|signup)[^>]*>([^<]{2,60})<\/(?:button|a)>/i', $above_fold, $cta_matches)) {
        $cta_texts = array_unique(array_map('wp_strip_all_tags', $cta_matches[1]));
        $cro_signals[] = 'CTA Above the Fold: YES — found CTA button/link in hero area: "' . implode('", "', array_slice($cta_texts, 0, 3)) . '"';
    // Second pass: any button/link with CTA-action text (catches custom-styled/theme buttons)
    } elseif (preg_match_all('/<(?:button|a)[^>]*>([^<]{2,80})<\/(?:button|a)>/i', $above_fold, $any_matches)) {
        $cta_action_texts = array_filter( array_map( 'wp_strip_all_tags', $any_matches[1] ), function( $t ) {
            return preg_match('/\b(?:join|book|get|start|explore|discover|shop|buy|contact|sign\s*up|register|try|request|enquire|enrol|apply|learn\s+more|find\s+out|speak|consult|schedule|reserve|access|download|claim)\b/i', trim( $t ) );
        } );
        if ( ! empty( $cta_action_texts ) ) {
            $cro_signals[] = 'CTA Above the Fold: YES — found CTA-action button/link in hero area: "' . implode('", "', array_slice( array_values( $cta_action_texts ), 0, 3 ) ) . '"';
        } else {
            $cro_signals[] = 'CTA Above the Fold: UNCONFIRMED FROM HTML (visual item — HTML could not confirm a CTA; use screenshot as primary evidence)';
        }
    } else {
        $cro_signals[] = 'CTA Above the Fold: UNCONFIRMED FROM HTML (visual item — HTML could not confirm a CTA; use screenshot as primary evidence)';
    }

    // 2. Trust Signals — image alt text, class names for certs/awards/badges, client logo bars,
    //    case studies, and previous work / portfolio sections.
    $trust_imgs = array();
    if (preg_match_all('/<img[^>]*alt=[\x22\x27]([^\x22\x27]*(?:cert|award|badge|accredit|iso|ssl|secure|verified|partner|member|guarantee)[^\x22\x27]*)[\x22\x27][^>]*>/i', $html, $img_alts)) {
        $trust_imgs = array_map('wp_strip_all_tags', $img_alts[1]);
    }
    // Generic "logo" alt text (client logos) — exclude common site/header logo labels
    $client_logo_imgs = array();
    if (preg_match_all('/<img[^>]*alt=[\x22\x27]([^\x22\x27]*\blogo\b[^\x22\x27]*)[\x22\x27][^>]*>/i', $html, $logo_alts)) {
        $client_logo_imgs = array_values( array_filter( array_map( 'wp_strip_all_tags', $logo_alts[1] ), function ( $alt ) {
            return ! preg_match( '/\b(?:site[-_\s]logo|header[-_\s]logo|brand[-_\s]logo|company[-_\s]logo|main[-_\s]logo)\b/i', $alt );
        } ) );
    }
    $has_trust_section   = preg_match('/(?:class|id)=[\x22\x27][^\x22\x27]*(?:trust|badge|cert|award|accredit|guarantee)[^\x22\x27]*[\x22\x27]/i', $html);
    $has_trust_text      = preg_match('/\b(?:certified|accredited|award[- ]winning|ISO[- ]\d+|BBB|google\s+partner|microsoft\s+partner|as\s+seen\s+in)\b/i', $html);
    // Client logo bars and "featured in" sections
    $has_logo_bar        = preg_match('/(?:class|id)=[\x22\x27][^\x22\x27]*(?:featured[-_]?in|as[-_]?seen|client[-_]?logo|logo[-_]?bar|media[-_]?logo|brand[-_]?logo|partner[-_]?logo|press[-_]?logo|featured[-_]?clients|client[-_]?strip|logo[-_]?strip|logos[-_]?section|client[-_]?grid)[^\x22\x27]*[\x22\x27]/i', $html);
    $has_featured_text   = preg_match('/\b(?:featured\s+(?:in|clients?)|as\s+seen\s+in|our\s+clients?|clients?\s+include|trusted\s+by|worked\s+with|in\s+the\s+press|press\s+coverage|media\s+coverage)\b/i', $html);
    // Case studies and previous work / portfolio — strong trust signals even without cert badges
    $has_case_study_cls  = preg_match('/(?:class|id)=[\x22\x27][^\x22\x27]*(?:case[-_]?stud|success[-_]?stor|client[-_]?result|project[-_]?result|work[-_]?(?:showcase|example|sample|highlight)|our[-_]?work|previous[-_]?work|portfolio[-_]?(?:item|card|grid|section))[^\x22\x27]*[\x22\x27]/i', $html);
    $has_case_study_text = preg_match('/\b(?:case\s+stud(?:y|ies)|success\s+stor(?:y|ies)|client\s+results?|project\s+results?|previous\s+work|our\s+work|work\s+we[\x27\x22]?ve\s+done|portfolio)\b/i', $html);
    if ( ! empty( $trust_imgs ) ) {
        $cro_signals[] = 'Trust Signals (Certs/Awards): YES — trust image(s) with alt: "' . implode('", "', array_slice($trust_imgs, 0, 3)) . '"';
    } elseif ( $has_trust_section || $has_trust_text ) {
        $cro_signals[] = 'Trust Signals (Certs/Awards): YES — trust-related class/text detected on page';
    } elseif ( $has_logo_bar || $has_featured_text ) {
        $cro_signals[] = 'Trust Signals (Certs/Awards): LIKELY — client/partner logo section or "featured in" text detected (logos are image-only; verify against screenshot)';
    } elseif ( $has_case_study_cls || $has_case_study_text ) {
        $cro_signals[] = 'Trust Signals (Certs/Awards): LIKELY — case study / previous work / portfolio section detected; client work examples are strong trust signals — verify visuals against screenshot';
    } elseif ( ! empty( $client_logo_imgs ) ) {
        $cro_signals[] = 'Trust Signals (Certs/Awards): LIKELY — logo image(s) found (e.g. "' . implode('", "', array_slice( $client_logo_imgs, 0, 3 )) . '"); likely client logos — verify against screenshot';
    } else {
        $cro_signals[] = 'Trust Signals (Certs/Awards): UNCONFIRMED FROM HTML (visual item — client logos, case study images, and award badges are image-only; use screenshot or browser data as primary evidence)';
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

    // 5. Sticky CTA in Nav — nav element containing a button or CTA-style link
    // First pass: CTA-class button/link in nav/header
    if (preg_match('/<(?:nav|header)[^>]*>[\s\S]{0,3000}?<(?:button|a)[^>]*(?:btn|button|cta|get-started|start|try|buy|book|request|sign-up|signup)[^>]*>/i', $html)) {
        $cro_signals[] = 'Sticky CTA in Nav: YES — CTA button/link detected inside nav or header element';
    // Second pass: any button/link with CTA-action text inside nav/header (catches custom themes)
    } elseif (preg_match('/<(?:nav|header)[^>]*>([\s\S]{0,5000}?)<\/(?:nav|header)>/i', $html, $nav_block_m) &&
              preg_match_all('/<(?:button|a)[^>]*>([^<]{3,80})<\/(?:button|a)>/i', $nav_block_m[1], $nav_link_m) &&
              ! empty( array_filter( array_map( 'wp_strip_all_tags', $nav_link_m[1] ), function( $t ) {
                  return preg_match('/\b(?:book|get|start|join|contact|try|request|enquire|apply|buy|shop|sign\s*up|free|consult|speak|schedule|reserve|access|claim)\b/i', trim($t) );
              } ) ) ) {
        $cro_signals[] = 'Sticky CTA in Nav: YES — CTA-action link/button detected in nav/header element';
    // Third pass: inline position:fixed/sticky style on nav/header containing a button/link
    } elseif (preg_match('/<(?:nav|header)[^>]*style=[\x22\x27][^\x22\x27]*position\s*:\s*(?:fixed|sticky)[^\x22\x27]*[\x22\x27][^>]*>[\s\S]{0,3000}?<(?:button|a)[^>]*/i', $html)) {
        $cro_signals[] = 'Sticky CTA in Nav: LIKELY — nav/header with inline position:fixed or position:sticky contains a button/link (screenshot cannot confirm sticky behaviour; rely on this HTML signal and browser data)';
    // Fourth pass: well-known sticky-nav class patterns with a button anywhere on page
    } elseif (preg_match('/(?:class|id)=[\x22\x27][^\x22\x27]*(?:sticky[-_](?:nav|header|bar|menu)|fixed[-_](?:nav|header|bar|menu)|nav[-_]sticky|header[-_]sticky|header[-_]fixed|js[-_]sticky|is[-_]sticky|navbar[-_]fixed|navbar[-_]sticky)[^\x22\x27]*[\x22\x27]/i', $html) &&
              preg_match('/<(?:button|a)[^>]*(?:btn|cta|button)[^>]*>/i', $html)) {
        $cro_signals[] = 'Sticky CTA in Nav: LIKELY — sticky/fixed-nav class with a CTA button detected (screenshot only shows initial page state and cannot confirm sticky behaviour; treat this HTML signal as primary evidence)';
    } elseif (preg_match('/(?:class|id)=[\x22\x27][^\x22\x27]*(?:sticky|fixed)[^\x22\x27]*[\x22\x27]/i', $html) && preg_match('/<(?:button|a)[^>]*(?:btn|cta)[^>]*>/i', $html)) {
        $cro_signals[] = 'Sticky CTA in Nav: POSSIBLE — sticky/fixed element with CTA detected';
    } else {
        $cro_signals[] = 'Sticky CTA in Nav: UNCONFIRMED FROM HTML (NOTE: a screenshot only captures the initial page state before scrolling — a sticky nav that hides on load is invisible in the screenshot. Use the [BROWSER-CONFIRMED] nav_cta signal from the JS tracker as primary evidence when available; fall back to HTML class detection above)';
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
        $summary .= "\nCRO Structural Signals (HTML-derived — PRIMARY evidence for COPY/BEHAVIOUR items 4,6,8,9,10,12; for VISUAL items 1,2,3,5,7,11,13 the screenshot overrides HTML — treat UNCONFIRMED/NOT DETECTED as no evidence, not as absent):\n";
        foreach ($cro_signals as $signal) {
            $summary .= 'â€¢ ' . $signal . "\n";
        }
    }

    // ── Real Browser Signals (from JS above-fold tracker) ────────────────
    // These are ground-truth observations from actual visitor sessions.
    // For VISUAL checklist items (1,2,3,5,11,13) these OVERRIDE HTML signals.
    if ( $page_url ) {
        $atf_table = $wpdb->prefix . 'conversioniq_above_fold';
        $atf_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT elements FROM {$atf_table}
             WHERE page_url = %s
             ORDER BY recorded_at DESC
             LIMIT 30",
            $page_url
        ), ARRAY_A );

        if ( ! empty( $atf_rows ) ) {
            $session_count   = count( $atf_rows );
            $elem_above_fold = array(); // type => sessions where element was above fold
            $elem_total      = array(); // type => sessions where element appeared at all

            foreach ( $atf_rows as $row ) {
                $elements = json_decode( $row['elements'], true );
                if ( ! is_array( $elements ) ) { continue; }
                $seen_types = array();
                foreach ( $elements as $el ) {
                    $type = $el['type'] ?? '';
                    if ( ! $type ) { continue; }
                    if ( ! isset( $seen_types[ $type ] ) ) {
                        $elem_total[ $type ] = ( $elem_total[ $type ] ?? 0 ) + 1;
                        $seen_types[ $type ] = true;
                    }
                    if ( ! empty( $el['above_fold'] ) && ! isset( $seen_types[ $type . '_atf' ] ) ) {
                        $elem_above_fold[ $type ]      = ( $elem_above_fold[ $type ] ?? 0 ) + 1;
                        $seen_types[ $type . '_atf' ]  = true;
                    }
                }
            }

            $browser_signals = array();

            // 1. CTA Above the Fold
            if ( isset( $elem_above_fold['cta'] ) ) {
                $pct = round( $elem_above_fold['cta'] / $session_count * 100 );
                $browser_signals[] = "CTA Above the Fold [BROWSER-CONFIRMED]: CTA visible in initial viewport on {$pct}% of {$session_count} real sessions -- set present=true";
            } elseif ( isset( $elem_total['cta'] ) ) {
                $browser_signals[] = "CTA Above the Fold [BROWSER-CONFIRMED]: CTA exists but appeared BELOW the fold in all {$session_count} measured sessions -- set present=false";
            }

            // 5. Sticky CTA in Nav
            if ( isset( $elem_above_fold['nav_cta'] ) ) {
                $pct = round( $elem_above_fold['nav_cta'] / $session_count * 100 );
                $browser_signals[] = "Sticky CTA in Nav [BROWSER-CONFIRMED]: Nav/header CTA visible above fold on {$pct}% of {$session_count} sessions -- set present=true";
            } elseif ( isset( $elem_total['nav_cta'] ) ) {
                $browser_signals[] = "Sticky CTA in Nav [BROWSER-CONFIRMED]: Nav CTA detected but not reliably in initial viewport";
            }

            // 2. Trust Signals
            if ( isset( $elem_above_fold['trust_badge'] ) ) {
                $pct = round( $elem_above_fold['trust_badge'] / $session_count * 100 );
                $browser_signals[] = "Trust Signals [BROWSER-CONFIRMED]: Trust badge/cert image above fold on {$pct}% of {$session_count} sessions -- set present=true";
            } elseif ( isset( $elem_total['trust_badge'] ) ) {
                $browser_signals[] = "Trust Signals [BROWSER-CONFIRMED]: Trust badge found on page but appears below the initial viewport";
            }

            // 3. Inline Social Proof
            if ( isset( $elem_above_fold['testimonial'] ) ) {
                $pct = round( $elem_above_fold['testimonial'] / $session_count * 100 );
                $browser_signals[] = "Inline Social Proof [BROWSER-CONFIRMED]: Testimonial/review section visible above fold on {$pct}% of sessions -- set present=true";
            } elseif ( isset( $elem_total['testimonial'] ) ) {
                $browser_signals[] = "Inline Social Proof [BROWSER-CONFIRMED]: Testimonial section exists on page (below fold) in {$session_count} measured sessions";
            }

            // 11. Anchor Pricing
            if ( isset( $elem_total['pricing'] ) ) {
                $above_note = isset( $elem_above_fold['pricing'] ) ? 'visible above fold' : 'appears below fold';
                $browser_signals[] = "Anchor Pricing [BROWSER-CONFIRMED]: Pricing section detected in {$session_count} real sessions ({$above_note})";
            }

            // 13. Progress Indicators
            if ( isset( $elem_total['progress'] ) ) {
                $above_note = isset( $elem_above_fold['progress'] ) ? 'visible above fold' : 'appears below fold';
                $browser_signals[] = "Progress Indicators [BROWSER-CONFIRMED]: Progress/step element detected in real browser sessions ({$above_note})";
            }

            if ( ! empty( $browser_signals ) ) {
                $summary .= "\nReal Browser Signals -- {$session_count} tracked visitor sessions on this URL (OVERRIDE HTML signals for visual checklist items 1,2,3,5,11,13):\n";
                foreach ( $browser_signals as $bs ) {
                    $summary .= '* ' . $bs . "\n";
                }
            }
        }
    }

    $summary .= "\nNote: Use these section names when categorizing your suggestions.";

    return $summary;
}

/**
 * Render a page's full front-end content for auditing — page-builder aware.
 *
 * apply_filters('the_content', $post->post_content) does NOT capture Elementor pages:
 * Elementor stores the layout in the `_elementor_data` post meta and leaves
 * post_content empty, so entire sections (problem/solution blocks, stat bars,
 * multi-step widgets, section-level CTAs) never reach the analyzer. When the page was
 * built with Elementor, render it through Elementor's own API so ALL sections are
 * included; otherwise fall back to the_content (Gutenberg, Divi, Beaver, classic).
 *
 * @param  WP_Post $post
 * @return string  Rendered HTML of the page body.
 */
function conversioniq_render_page_content( $post ) {
    if ( ! ( $post instanceof WP_Post ) ) {
        return '';
    }
    $post_id = $post->ID;

    if ( class_exists( '\\Elementor\\Plugin' ) ) {
        $is_elementor = ( get_post_meta( $post_id, '_elementor_edit_mode', true ) === 'builder' )
            || ! empty( get_post_meta( $post_id, '_elementor_data', true ) );
        if ( $is_elementor ) {
            try {
                $frontend = isset( \Elementor\Plugin::$instance->frontend ) ? \Elementor\Plugin::$instance->frontend : null;
                if ( $frontend && method_exists( $frontend, 'get_builder_content_for_display' ) ) {
                    $html = $frontend->get_builder_content_for_display( $post_id, false );
                    if ( is_string( $html ) && trim( wp_strip_all_tags( $html ) ) !== '' ) {
                        ciq_log( 'Content: rendered Elementor builder content (post ' . $post_id . ', ' . strlen( $html ) . ' chars)' );
                        return $html;
                    }
                    ciq_log( 'Content: Elementor render returned empty for post ' . $post_id . ' — falling back to the_content' );
                }
            } catch ( \Throwable $e ) {
                ciq_log( 'Content: Elementor render error for post ' . $post_id . ' — ' . $e->getMessage() . '; falling back to the_content' );
            }
        }
    }

    return apply_filters( 'the_content', $post->post_content );
}

/**
 * Extract readable body text from a fully rendered HTML page.
 *
 * This is used as a fallback for page-builder sites (Elementor, Divi, Beaver Builder)
 * where $post->post_content contains raw block/widget JSON rather than readable copy.
 * We strip non-content regions (head, nav, footer, scripts, styles) and return clean text.
 *
 * @param  string $html  Full rendered HTML of the page.
 * @return string        Normalised plain text of the main body content.
 */
function conversioniq_extract_body_text( $html ) {
    if ( empty( $html ) ) {
        return '';
    }

    // Remove regions that contain navigation/boilerplate, not page copy
    $remove_tags = array( 'head', 'script', 'style', 'nav', 'footer', 'aside', 'noscript' );
    foreach ( $remove_tags as $tag ) {
        $html = preg_replace( '/<' . $tag . '[\s>][\s\S]*?<\/' . $tag . '>/i', ' ', $html );
    }

    // ── Preserve structural markers before stripping tags ──────────────────
    // Headings — mark type so the AI knows section boundaries
    $html = preg_replace( '/<h1[^>]*>/i',  "\n[H1] ", $html );
    $html = preg_replace( '/<\/h1>/i',      "\n",      $html );
    $html = preg_replace( '/<h2[^>]*>/i',  "\n[H2] ", $html );
    $html = preg_replace( '/<\/h2>/i',      "\n",      $html );
    $html = preg_replace( '/<h3[^>]*>/i',  "\n[H3] ", $html );
    $html = preg_replace( '/<\/h3>/i',      "\n",      $html );
    $html = preg_replace( '/<h4[^>]*>/i',  "\n[H4] ", $html );
    $html = preg_replace( '/<\/h4>/i',      "\n",      $html );

    // Buttons and CTA links — mark so the AI knows these are interactive copy
    $html = preg_replace( '/<button[^>]*>/i',                                            "\n[BUTTON] ", $html );
    $html = preg_replace( '/<a[^>]*class="[^"]*\b(?:btn|button|cta)[^"]*"[^>]*>/i',     "\n[CTA] ",    $html );

    // Section boundaries — mark where major sections divide
    $html = preg_replace( '/<\/section>/i', "\n---\n", $html );

    // Paragraph and block breaks — preserve visual separation as newlines
    $html = preg_replace( '/<\/p>/i',   "\n", $html );
    $html = preg_replace( '/<br[^>]*>/i', "\n", $html );
    $html = preg_replace( '/<\/li>/i',  "\n", $html );

    // Strip all remaining HTML tags
    $text = wp_strip_all_tags( $html );

    // Decode HTML entities
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    // Collapse runs of spaces but preserve newlines (they are structural markers)
    $text = preg_replace( '/[^\S\n]+/', ' ', $text );

    // Collapse 3+ consecutive newlines to 2 to avoid excessive blank space
    $text = preg_replace( '/\n{3,}/', "\n\n", $text );

    return trim( $text );
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

        // Pending implementation reviews — powers the "Changes Pending" banner in the WP admin.
        // Cached per-user for 60s to avoid hammering Supabase on every page load.
        register_rest_route('conversioniq/v1', '/pending-reviews', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_pending_reviews',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ));

        // Apply approved implementation changes — authenticated by X-CIQ-API-Key.
        // Canonical slug (v2.5.0+). Legacy slug kept below for backward compatibility.
        register_rest_route('conversioniq/v1', '/implementations/apply', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_apply_changes',
            'permission_callback' => '__return_true',
        ));

        // Legacy slug — kept so older SaaS versions and direct curl calls still work.
        register_rest_route('conversioniq/v1', '/apply-changes', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_apply_changes',
            'permission_callback' => '__return_true',
        ));

        // Publish a draft created by apply/apply-changes — authenticated by X-CIQ-API-Key.
        // Canonical slug (v2.5.0+). Legacy slug kept below for backward compatibility.
        register_rest_route('conversioniq/v1', '/implementations/publish', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_publish_draft',
            'permission_callback' => '__return_true',
        ));

        // Legacy slug.
        register_rest_route('conversioniq/v1', '/publish-draft', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_publish_draft',
            'permission_callback' => '__return_true',
        ));

        // Wake endpoint — dashboard calls this immediately after queuing an audit job so the
        // plugin doesn't have to wait up to 2 minutes for the next scheduled cron tick.
        register_rest_route('conversioniq/v1', '/wake', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => function( WP_REST_Request $req ) {
                $stored_key = get_option( 'conversioniq_remote_secret', '' );
                if ( empty( $stored_key ) || $req->get_header( 'X-CIQ-API-Key' ) !== $stored_key ) {
                    return new WP_REST_Response( array( 'ok' => false, 'error' => 'unauthorized' ), 401 );
                }
                // Schedule a one-off poll to run right now, then kick the cron runner
                wp_schedule_single_event( time(), 'conversioniq_poll_audit_jobs' );
                if ( function_exists( 'spawn_cron' ) ) spawn_cron();
                return new WP_REST_Response( array( 'ok' => true ), 200 );
            },
        ));

        // Connectivity test — WP admin only. Tests outbound HTTPS from the WP server
        // to conversioniq-app.com/api/ai-proxy and returns raw curl diagnostics.
        register_rest_route('conversioniq/v1', '/connectivity-test', array(
            'methods'             => 'POST',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback'            => function( WP_REST_Request $req ) {
                $license_key = get_option( 'conversioniq_license_key', '' );
                $target_url  = ConversionIQ_AI::SAAS_API_URL . '/api/ai-proxy';

                // 1. DNS resolution check
                $host    = parse_url( $target_url, PHP_URL_HOST );
                $dns_ok  = checkdnsrr( $host, 'A' );
                $dns_ip  = gethostbyname( $host );

                // 2. Minimal POST — tiny payload, short timeout so it returns quickly
                $mini_body = wp_json_encode( array(
                    'model'    => 'gpt-4o',
                    'messages' => array( array( 'role' => 'user', 'content' => 'ping' ) ),
                    'max_tokens' => 1,
                ) );

                $start    = microtime( true );
                $response = wp_remote_post( $target_url, array(
                    'headers'   => array(
                        'X-License-Key' => $license_key ?: 'test-no-key',
                        'Content-Type'  => 'application/json',
                    ),
                    'body'      => $mini_body,
                    'timeout'   => 10,
                    'sslverify' => true,
                ) );
                $elapsed  = round( ( microtime( true ) - $start ) * 1000 );

                if ( is_wp_error( $response ) ) {
                    return new WP_REST_Response( array(
                        'ok'          => false,
                        'stage'       => 'http_request',
                        'error_code'  => $response->get_error_code(),
                        'error_msg'   => $response->get_error_message(),
                        'dns_ok'      => $dns_ok,
                        'dns_ip'      => $dns_ip,
                        'target_url'  => $target_url,
                        'elapsed_ms'  => $elapsed,
                    ), 200 );
                }

                $status = wp_remote_retrieve_response_code( $response );
                $body   = wp_remote_retrieve_body( $response );

                return new WP_REST_Response( array(
                    'ok'         => true,
                    'stage'      => 'response_received',
                    'http_status'=> $status,
                    'dns_ok'     => $dns_ok,
                    'dns_ip'     => $dns_ip,
                    'target_url' => $target_url,
                    'elapsed_ms' => $elapsed,
                    'body_preview' => substr( $body, 0, 300 ),
                ), 200 );
            },
        ));

        // Worker health — WP admin only. Proxies GET /api/ai-proxy/health from the SaaS
        // and returns the result so the diagnostic page can show queue depth and avg latency.
        register_rest_route('conversioniq/v1', '/worker-health', array(
            'methods'             => 'GET',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback'            => function() {
                $start    = microtime( true );
                $response = wp_remote_get( ConversionIQ_AI::SAAS_API_URL . '/api/ai-proxy/health', array(
                    'timeout'   => 8,
                    'sslverify' => true,
                ) );
                $elapsed = round( ( microtime( true ) - $start ) * 1000 );

                if ( is_wp_error( $response ) ) {
                    return new WP_REST_Response( array(
                        'ok'         => false,
                        'error'      => $response->get_error_message(),
                        'elapsed_ms' => $elapsed,
                    ), 200 );
                }

                $body   = json_decode( wp_remote_retrieve_body( $response ), true );
                $status = wp_remote_retrieve_response_code( $response );

                return new WP_REST_Response( array_merge(
                    $body ?: array(),
                    array( 'ok' => ( $status === 200 ), 'elapsed_ms' => $elapsed )
                ), 200 );
            },
        ));

        // Re-register sync endpoint — forces a fresh get-config POST to the SaaS so that
        // sync_endpoint and sync_secret are stored/updated on the SaaS side. This is what
        // the SaaS sync-plugins cron uses to know which WordPress sites to call.
        register_rest_route('conversioniq/v1', '/reregister-sync', array(
            'methods'             => 'POST',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback'            => function() {
                $license_key = get_option( 'conversioniq_license_key', '' );
                if ( empty( $license_key ) ) {
                    return new WP_REST_Response( array(
                        'ok'      => false,
                        'message' => 'No license key configured — cannot register.',
                    ), 200 );
                }

                $sync_secret   = conversioniq_get_sync_secret();
                $sync_endpoint = rest_url( 'conversioniq/v1/sync-daily' );

                $result = ConversionIQ_Config_Manager::sync_from_saas();

                ciq_log( '🔄 Re-register sync: sync_from_saas() returned ' . ( $result ? 'true' : 'false' )
                    . ' | sync_endpoint=' . $sync_endpoint );

                return new WP_REST_Response( array(
                    'ok'            => $result,
                    'message'       => $result
                        ? 'Successfully re-registered. The SaaS now has this site\'s sync_endpoint and sync_secret.'
                        : 'sync_from_saas() returned false — check debug logs for the HTTP error from /api/get-config.',
                    'sync_endpoint' => $sync_endpoint,
                    'secret_length' => strlen( $sync_secret ),
                ), 200 );
            },
        ));

        // Debug/diagnostic endpoint — WP admin only. Runs the full poll handler inline
        // and returns a detailed report. Use this to verify the cron pipeline works
        // without waiting for WP-Cron to fire naturally.
        register_rest_route('conversioniq/v1', '/debug-poll', array(
            'methods'             => 'POST',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback'            => function( WP_REST_Request $req ) {
                $org_id     = get_option( 'conversioniq_organization_id', '' );
                $has_secret = ! empty( get_option( 'conversioniq_remote_secret', '' ) );
                $cron_next  = wp_next_scheduled( 'conversioniq_poll_audit_jobs' );

                $debug = array(
                    'org_id'             => $org_id ?: '(not set)',
                    'has_remote_secret'  => $has_secret,
                    'cron_next_ts'       => $cron_next,
                    'cron_next_human'    => $cron_next ? gmdate( 'Y-m-d H:i:s', $cron_next ) . ' UTC' : 'not scheduled',
                    'cron_schedules'     => array_keys( wp_get_schedules() ),
                    'disable_wp_cron'    => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
                    'plugin_version'     => CONVERSION_IQ_VERSION,
                    'fetch_result'       => null,
                    'poll_handler_ran'   => false,
                );

                // Test the Supabase fetch directly and capture the raw job
                if ( $org_id ) {
                    $supabase            = new ConversionIQ_Supabase_Sync();
                    $job                 = $supabase->fetch_pending_job();
                    $debug['fetch_result'] = $job ? $job : '(no pending jobs returned)';

                    if ( $job ) {
                        // Run the full handler
                        conversioniq_poll_audit_jobs_handler();
                        $debug['poll_handler_ran'] = true;
                    }
                }

                return new WP_REST_Response( array( 'ok' => true, 'debug' => $debug ), 200 );
            },
        ));

        // ── Heatmap Routes ────────────────────────────────────────────────────

        // Public endpoint — receives batched click/scroll events from the tracker
        register_rest_route('conversioniq/v1', '/heatmap/record', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_heatmap_record',
            'permission_callback' => '__return_true',
        ));

        // Admin endpoint — returns aggregated click data for a given page URL
        register_rest_route('conversioniq/v1', '/heatmap/data', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_heatmap_data',
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ));

        // Admin endpoint — lists pages that have recorded heatmap events
        register_rest_route('conversioniq/v1', '/heatmap/pages', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_heatmap_pages',
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ));

        // Admin endpoint — proxies screenshot request to conversioniq-app.com
        register_rest_route('conversioniq/v1', '/heatmap/screenshot', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_heatmap_screenshot',
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ));

        // Admin endpoint — manually trigger the nightly heatmap sync (for testing/debugging)
        register_rest_route('conversioniq/v1', '/heatmap/trigger-sync', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_heatmap_trigger_sync',
            'permission_callback' => function () { return current_user_can('manage_options'); },
        ));

        // External cron endpoint — nightly sync triggered by a pre-shared secret key.
        // No WP session required. Safe to call from cron-job.org, server crontab, etc.
        // Pass ?backfill=1 to run the full 30-day backfill (used on first registration).
        register_rest_route('conversioniq/v1', '/sync-daily', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_external_sync_daily',
            'permission_callback' => '__return_true',
            'args'                => array(
                'secret' => array(
                    'required'          => true,
                    'validate_callback' => function( $v ) { return is_string( $v ) && strlen( $v ) > 0; },
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'backfill' => array(
                    'required'          => false,
                    'validate_callback' => function( $v ) { return in_array( $v, array( '0', '1', 0, 1, true, false ), true ); },
                    'sanitize_callback' => function( $v ) { return (bool) $v; },
                ),
            ),
        ));

        // SEO audit — on-page analysis for a specific page (Tier 1 + Tier 2 RUM CWV)
        register_rest_route('conversioniq/v1', '/seo-audit', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_run_seo_audit',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'args'                => array(
                'page_id' => array(
                    'required'          => true,
                    'validate_callback' => function( $v ) { return is_numeric( $v ) && $v > 0; },
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // SEO last result — returns the cached result from the most recent audit, no new analysis
        register_rest_route('conversioniq/v1', '/seo-last', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_get_last_seo_audit',
            'permission_callback' => function () { return current_user_can('manage_options'); },
            'args'                => array(
                'page_id' => array(
                    'required'          => true,
                    'validate_callback' => function( $v ) { return is_numeric( $v ) && $v > 0; },
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        // ── Traffic Intelligence (GA4 + GSC) ──────────────────────────────────

        // Connection status + OAuth URL
        register_rest_route( 'conversioniq/v1', '/traffic-status', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_traffic_status',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );

        // Cached summary (fast — returns transient data)
        register_rest_route( 'conversioniq/v1', '/traffic-summary', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_traffic_summary',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );

        // Force a fresh fetch (rate-limited to once per hour)
        register_rest_route( 'conversioniq/v1', '/traffic-refresh', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_traffic_refresh',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );

        // List available GSC sites for the connected Google account
        register_rest_route( 'conversioniq/v1', '/traffic-gsc-sites', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_traffic_gsc_sites',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );

        // List available GA4 properties
        register_rest_route( 'conversioniq/v1', '/traffic-ga4-properties', array(
            'methods'             => 'GET',
            'callback'            => 'conversioniq_traffic_ga4_properties',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );

        // Save selected GSC site + GA4 property
        register_rest_route( 'conversioniq/v1', '/traffic-save-property', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_traffic_save_property',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );

        // Disconnect Google (deletes tokens)
        register_rest_route( 'conversioniq/v1', '/traffic-disconnect', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_traffic_disconnect',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );

        // Debug: force-run the daily traffic sync cron inline (bypasses rate-limit)
        register_rest_route( 'conversioniq/v1', '/traffic-debug-sync', array(
            'methods'             => 'POST',
            'callback'            => 'conversioniq_traffic_debug_sync',
            'permission_callback' => function () { return current_user_can( 'manage_options' ); },
        ) );

        // Competitor analysis diagnostic: checks table, RLS, org-id, and URL parsing.
        // Call via: POST /wp-json/conversioniq/v1/test-competitor-analysis
        register_rest_route( 'conversioniq/v1', '/test-competitor-analysis', array(
            'methods'             => 'POST',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback'            => 'conversioniq_test_competitor_analysis',
        ) );
    });


/**
 * Diagnostic: test competitor analysis end-to-end without running a full audit.
 * Returns a detailed report: org-id, parsed URLs, table probe, sample upsert dry-run.
 */
function conversioniq_test_competitor_analysis() {
    $report = [];

    // ── 1. Organisation ID ─────────────────────────────────────────────────
    $org_id = get_option( 'conversioniq_organization_id', '' );
    $report['organization_id'] = $org_id ?: '(not set)';

    // ── 2. Business settings & competitor field ───────────────────────────
    $business        = json_decode( get_option( 'conversion_iq_settings', '{}' ), true );
    $competitors_raw = trim( $business['competitors'] ?? '' );
    $report['competitors_raw'] = $competitors_raw ?: '(empty — add competitors in Settings → Business Profile)';

    // ── 3. Parse URLs using the same logic as conversioniq_analyze_competitors ─
    $parsed_urls = [];
    foreach ( array_filter( array_map( 'trim', explode( ',', $competitors_raw ) ) ) as $entry ) {
        if ( preg_match( '/^https?:\/\//i', $entry ) ) {
            $parsed_urls[] = [ 'input' => $entry, 'resolved' => esc_url_raw( $entry ), 'method' => 'full_url' ];
        } elseif ( preg_match( '/^[a-z0-9][a-z0-9\-\.]+\.[a-z]{2,}$/i', $entry ) ) {
            $parsed_urls[] = [ 'input' => $entry, 'resolved' => 'https://' . $entry, 'method' => 'bare_domain' ];
        } elseif ( preg_match( '/^[a-z0-9][a-z0-9\s\-\_]+$/i', $entry ) ) {
            $slug = strtolower( preg_replace( '/[\s_]+/', '', $entry ) );
            $parsed_urls[] = [ 'input' => $entry, 'resolved' => 'https://' . $slug . '.com', 'method' => 'business_name' ];
        } else {
            $parsed_urls[] = [ 'input' => $entry, 'resolved' => null, 'method' => 'SKIPPED' ];
        }
    }
    $report['parsed_urls'] = $parsed_urls;

    // ── 4. Probe ciq_competitor_scores table (SELECT 0 rows) ─────────────
    $sync        = new ConversionIQ_Supabase_Sync();
    $supabase_url = 'https://spefdqiywnihehfhrood.supabase.co';
    $anon_key     = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InNwZWZkcWl5d25paGVoZmhyb29kIiwicm9sZSI6ImFub24iLCJpYXQiOjE3Njg5ODI4NDcsImV4cCI6MjA4NDU1ODg0N30.FHJRpodLKgwW6hexRqGXKfcVFS4pwntSq83yNyR74d8';

    $probe = wp_remote_get(
        $supabase_url . '/rest/v1/ciq_competitor_scores?limit=1',
        [
            'headers' => [
                'apikey'        => $anon_key,
                'Authorization' => 'Bearer ' . $anon_key,
            ],
            'timeout' => 10,
        ]
    );
    if ( is_wp_error( $probe ) ) {
        $report['table_probe'] = [ 'ok' => false, 'error' => $probe->get_error_message() ];
    } else {
        $probe_status = wp_remote_retrieve_response_code( $probe );
        $probe_body   = wp_remote_retrieve_body( $probe );
        $report['table_probe'] = [
            'http_status' => $probe_status,
            'ok'          => $probe_status === 200,
            'body_preview'=> substr( $probe_body, 0, 300 ),
            'hint'        => $probe_status === 404 ? 'TABLE DOES NOT EXIST — run the CREATE TABLE SQL in Supabase'
                           : ( $probe_status === 401 || $probe_status === 403 ? 'RLS or auth error — check anon key permissions'
                           : ( $probe_status === 200 ? 'Table exists and is readable by anon' : 'Unexpected status' ) ),
        ];
    }

    // ── 5. Dry-run INSERT (organisation_id required) ──────────────────────
    if ( $org_id ) {
        $test_payload = [
            'organization_id' => $org_id,
            'url'             => 'https://diagnostic-test.example.com',
            'name'            => 'Diagnostic Test',
            'overall_score'   => 0,
            'scores'          => [ 'note' => 'diagnostic dry-run — safe to delete' ],
            'analyzed_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
        ];
        $dry_run = wp_remote_post(
            $supabase_url . '/rest/v1/ciq_competitor_scores',
            [
                'headers' => [
                    'apikey'        => $anon_key,
                    'Authorization' => 'Bearer ' . $anon_key,
                    'Content-Type'  => 'application/json',
                    'Prefer'        => 'resolution=merge-duplicates,return=minimal',
                ],
                'body'    => json_encode( $test_payload ),
                'timeout' => 10,
            ]
        );
        if ( is_wp_error( $dry_run ) ) {
            $report['upsert_test'] = [ 'ok' => false, 'error' => $dry_run->get_error_message() ];
        } else {
            $dr_status = wp_remote_retrieve_response_code( $dry_run );
            $dr_body   = wp_remote_retrieve_body( $dry_run );
            $report['upsert_test'] = [
                'http_status'  => $dr_status,
                'ok'           => in_array( $dr_status, [ 200, 201, 204 ], true ),
                'body_preview' => substr( $dr_body, 0, 300 ),
                'hint'         => in_array( $dr_status, [ 200, 201, 204 ], true )
                    ? 'Upsert succeeded — data can be written to the table'
                    : ( $dr_status === 403 ? 'PERMISSION DENIED — add RLS policy for anon role'
                    : ( $dr_status === 404 ? 'Table not found — create it first'
                    : 'See body_preview for details' ) ),
            ];
        }
    } else {
        $report['upsert_test'] = [ 'skipped' => 'No organization_id — register the plugin first' ];
    }

    // ── 6. Active transients (cached analyses) ────────────────────────────
    $cached = [];
    foreach ( $parsed_urls as $pu ) {
        if ( $pu['resolved'] ) {
            $key = 'ciq_comp_' . md5( $pu['resolved'] );
            $val = get_transient( $key );
            if ( $val !== false ) {
                $cached[] = $pu['resolved'];
            }
        }
    }
    $report['cached_competitors'] = empty( $cached )
        ? 'None — all competitors will be re-analysed on next audit'
        : $cached;

    return new WP_REST_Response( $report, 200 );
}

function conversioniq_traffic_status() {
    $insights = new ConversionIQ_Traffic_Insights();
    return rest_ensure_response( $insights->get_status() );
}

function conversioniq_traffic_summary() {
    if ( ! ConversionIQ_Config_Manager::can( 'traffic_insights' ) ) {
        return new WP_REST_Response( array( 'error' => 'upgrade_required' ), 403 );
    }
    $insights = new ConversionIQ_Traffic_Insights();
    return rest_ensure_response( $insights->get_summary() );
}

function conversioniq_traffic_refresh() {
    if ( ! ConversionIQ_Config_Manager::can( 'traffic_insights' ) ) {
        return new WP_REST_Response( array( 'error' => 'upgrade_required' ), 403 );
    }
    // Rate-limit: one forced refresh per hour
    if ( get_transient( 'ciq_traffic_refresh_lock' ) ) {
        return new WP_REST_Response( array( 'error' => 'rate_limited', 'message' => 'Data was recently refreshed. Try again in an hour.' ), 429 );
    }
    set_transient( 'ciq_traffic_refresh_lock', 1, HOUR_IN_SECONDS );

    $insights = new ConversionIQ_Traffic_Insights();
    $data     = $insights->get_summary( true );
    return rest_ensure_response( $data );
}

function conversioniq_traffic_gsc_sites() {
    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response( $ga->get_gsc_sites() );
}

function conversioniq_traffic_ga4_properties() {
    $ga = new ConversionIQ_Google_Analytics();
    return rest_ensure_response( $ga->get_properties() );
}

function conversioniq_traffic_save_property( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    $ga     = new ConversionIQ_Google_Analytics();

    if ( ! empty( $params['gsc_site_url'] ) ) {
        $ga->save_gsc_property( $params['gsc_site_url'] );
    }

    if ( ! empty( $params['ga4_property_id'] ) ) {
        $ga->save_property( sanitize_text_field( $params['ga4_property_id'] ) );
        if ( ! empty( $params['ga4_property_name'] ) ) {
            $creds = get_option( 'conversioniq_ga_credentials', array() );
            $creds['property_name'] = sanitize_text_field( $params['ga4_property_name'] );
            update_option( 'conversioniq_ga_credentials', $creds );
        }
    }

    // Clear cached data so the next summary fetch uses the new property
    delete_transient( 'ciq_traffic_ga4' );
    delete_transient( 'ciq_traffic_gsc' );

    return rest_ensure_response( array( 'success' => true ) );
}

function conversioniq_traffic_disconnect() {
    $ga = new ConversionIQ_Google_Analytics();
    $ga->disconnect();
    delete_transient( 'ciq_traffic_ga4' );
    delete_transient( 'ciq_traffic_gsc' );
    delete_option( 'conversioniq_traffic_fetched_at' );
    return rest_ensure_response( array( 'success' => true ) );
}

function conversioniq_traffic_debug_sync() {
    $start    = microtime( true );
    $insights = new ConversionIQ_Traffic_Insights();
    $status   = $insights->get_status();

    if ( ! $status['has_tokens'] ) {
        return rest_ensure_response( array(
            'success' => false,
            'message' => 'Not connected to Google — no OAuth tokens stored.',
        ) );
    }

    // Clear rate-limit transient so a debug run is always allowed
    delete_transient( 'ciq_traffic_refresh_lock' );

    // Run the same logic as the daily cron
    $summary = $insights->get_summary( true );
    $elapsed = round( ( microtime( true ) - $start ) * 1000 );

    $has_ga4 = ! empty( $summary['ga4'] ) && ! empty( $summary['ga4']['sessions'] ?? null );
    $has_gsc = ! empty( $summary['gsc'] ) && ! empty( $summary['gsc']['total_clicks'] ?? null );

    return rest_ensure_response( array(
        'success'     => true,
        'elapsed_ms'  => $elapsed,
        'ga4_ok'      => $has_ga4,
        'gsc_ok'      => $has_gsc,
        'fetched_at'  => $summary['fetched_at'] ?? null,
        'ga4_sessions'=> $summary['ga4']['sessions'] ?? null,
        'gsc_clicks'  => $summary['gsc']['total_clicks'] ?? null,
        'errors'      => $summary['errors'] ?? array(),
        'verdict'     => $summary['verdict']['direction'] ?? 'no_data',
    ) );
}

function conversioniq_save_settings( WP_REST_Request $request )
{
    $params = $request->get_json_params();
    if (empty($params)) {
        return new WP_REST_Response(array('success' => false, 'message' => __('No settings provided', 'conversion-iq')), 400);
    }
    // Remove openai_api_key if submitted — AI calls are now proxied through
    // conversioniq-app.com so no AI key should be stored on the WP site.
    unset($params['openai_api_key']);

    // Save KnockKnock settings separately
    if (isset($params['knockknock_api_key'])) {
        update_option('conversioniq_knockknock_api_key', sanitize_text_field($params['knockknock_api_key']));
        unset($params['knockknock_api_key']);
    }

    update_option('conversion_iq_settings', wp_json_encode($params));

    return array('success' => true);
}

function conversioniq_get_settings()
{
    $v = get_option('conversion_iq_settings', '{}');
    $decoded = json_decode($v, true);

    // Add KnockKnock settings
    $decoded['knockknock_api_key']  = get_option('conversioniq_knockknock_api_key', '');
    $decoded['knockknock_last_sync'] = get_option('conversioniq_knockknock_last_sync', '');

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

/**
 * Aggregate Real User Metrics CWV from heatmap session rows for a given page.
 * Returns the same shape as conversioniq_fetch_core_web_vitals() so callers
 * can use either data source interchangeably.  Returns null if no RUM data.
 */
function conversioniq_get_rum_cwv( $page_url ) {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'conversioniq_heatmap_sessions';
    $cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT
            ROUND(AVG(lcp_ms))    AS lcp_ms,
            ROUND(AVG(cls), 3)    AS cls,
            ROUND(AVG(fcp_ms))    AS fcp_ms,
            ROUND(AVG(ttfb_ms))   AS ttfb_ms,
            ROUND(AVG(inp_ms))    AS inp_ms,
            COUNT(*)              AS sample_size
         FROM {$sessions_table}
         WHERE page_url = %s
           AND lcp_ms IS NOT NULL
           AND recorded_at >= %s",
        $page_url, $cutoff
    ), ARRAY_A );

    if ( ! $row || (int) $row['sample_size'] < 1 ) { return null; }

    return array(
        'lcp_ms'         => $row['lcp_ms']  ? (int) $row['lcp_ms']  : null,
        'cls'            => $row['cls']     !== null ? (float) $row['cls'] : null,
        'inp_ms'         => $row['inp_ms']  ? (int) $row['inp_ms']  : null,
        'fcp_ms'         => $row['fcp_ms']  ? (int) $row['fcp_ms']  : null,
        'ttfb_ms'        => $row['ttfb_ms'] ? (int) $row['ttfb_ms'] : null,
        'page_weight_kb' => null,
        'dom_elements'   => null,
        'perf_score'     => null,
        'strategy'       => 'rum',
        'sample_size'    => (int) $row['sample_size'],
        'measured_at'    => gmdate( 'Y-m-d H:i:s' ),
    );
}

/**
 * Fetch Core Web Vitals for a public URL via Google PageSpeed Insights API.
 *
 * Returns an array with lcp_ms, cls, inp_ms, ttfb_ms, page_weight_kb,
 * dom_elements, and perf_score, or null if the API call fails.
 *
 * Optionally uses the constant CONVERSIONIQ_PAGESPEED_KEY (or the
 * option conversioniq_pagespeed_key) to authenticate the request and
 * avoid anonymous rate-limits.
 */
/**
 * CWV is now fetched by the SaaS backend after audit sync.
 * This function is kept only to serve the RUM data path (heatmap sessions).
 * Returns aggregated Real User Metrics, or null if no data available.
 */
function conversioniq_fetch_core_web_vitals( $page_url ) {
    return conversioniq_get_rum_cwv( $page_url );
}

/**
 * Compute bounce rate and average time-on-page for a URL within a date window.
 *
 * "Bounce" = a heatmap session with zero recorded events.
 *
 * @param string $page_url   Exact page URL.
 * @param string $from_date  UTC datetime 'Y-m-d H:i:s'.
 * @param string $to_date    UTC datetime 'Y-m-d H:i:s'.
 * @return array {bounce_rate: float|null, avg_time_on_page_sec: float|null, session_count: int}
 */
function conversioniq_get_behavioral_metrics( $page_url, $from_date, $to_date ) {
    global $wpdb;
    $sessions_table = $wpdb->prefix . 'conversioniq_heatmap_sessions';
    $events_table   = $wpdb->prefix . 'conversioniq_heatmap_events';

    $total = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$sessions_table}
         WHERE page_url = %s AND created_at >= %s AND created_at <= %s",
        $page_url, $from_date, $to_date
    ) );

    if ( $total === 0 ) {
        return array( 'bounce_rate' => null, 'avg_time_on_page_sec' => null, 'session_count' => 0 );
    }

    // Bounced = no events recorded for that session
    $bounced = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$sessions_table} s
         WHERE s.page_url = %s AND s.created_at >= %s AND s.created_at <= %s
           AND NOT EXISTS (
               SELECT 1 FROM {$events_table} e WHERE e.session_id = s.session_id
           )",
        $page_url, $from_date, $to_date
    ) );

    $avg_time = (float) $wpdb->get_var( $wpdb->prepare(
        "SELECT AVG(time_on_page_sec) FROM {$sessions_table}
         WHERE page_url = %s AND created_at >= %s AND created_at <= %s
           AND time_on_page_sec IS NOT NULL AND time_on_page_sec > 0",
        $page_url, $from_date, $to_date
    ) );

    return array(
        'bounce_rate'          => round( $bounced / $total, 4 ),
        'avg_time_on_page_sec' => $avg_time > 0 ? round( $avg_time, 1 ) : null,
        'session_count'        => $total,
    );
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
                'message'       => sprintf(__('Weekly audit limit reached. Your plan allows %d audits in any rolling 7-day period; you can run another once one of your recent audits is more than 7 days old.', 'conversion-iq'), $audits_per_week),
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
    ciq_log( 'Audit: business settings loaded — keys: ' . implode( ', ', array_keys( $business ?: [] ) ) );
    ciq_log( 'Audit: competitors field = ' . json_encode( $business['competitors'] ?? '(not set)' ) );

$results = array();

    foreach ($pages as $page_id) {
        $post = get_post(intval($page_id));
        if (!$post)
            continue;

        // Get clean page content. conversioniq_render_page_content() is builder-aware:
        // it renders Elementor via its own API (post_content is empty for Elementor) and
        // falls back to the_content for Gutenberg/Divi/Beaver/classic — so ALL sections
        // reach the analyzer, not just whatever the_content happened to render.
        $rendered_content = conversioniq_render_page_content( $post );
        $content          = wp_strip_all_tags( $rendered_content );
        // Decode HTML entities (&amp; &nbsp; &#8211; etc.) so the AI reads clean prose.
        $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $content = trim( preg_replace( '/\s+/', ' ', $content ) );

        // Build HTML structure directly from the page-builder rendered content.
        // wp_remote_get to the site's own URL always times out on this hosting
        // environment (loopback blocked), so we use the rendered HTML we already
        // have from apply_filters( 'the_content' ) above — it contains all headings,
        // CTAs, testimonial blocks, and class/ID patterns that the structure
        // extractor needs, without any HTTP round-trip.
        $page_url = get_permalink($post);
        $html_structure = '';
        $html = $rendered_content; // reuse rendered output as our "html" source

        $html_structure = conversioniq_extract_html_structure( $rendered_content, $page_url );
        ciq_log( 'HTML structure extracted from rendered content (' . strlen( $html_structure ) . ' chars)' );

        // Fallback for page builders that store content in meta (not post_content):
        // if the stripped content is thin, extract body text from rendered HTML.
        if ( strlen( trim( $content ) ) < 300 ) {
            $fallback_text = conversioniq_extract_body_text( $rendered_content );
            if ( strlen( $fallback_text ) > strlen( $content ) ) {
                $content = $fallback_text;
                ciq_log( 'Content: rendered HTML body-text fallback applied (' . strlen( $content ) . ' chars) — DB content was thin' );
            }
        }

        // Supplement HTML-based trust signals with reviews stored in WordPress CPTs
        $cpt_reviews = conversioniq_get_cpt_reviews();
        if (!empty($cpt_reviews)) {
            $html_structure .= $cpt_reviews;
            ciq_log('CPT reviews appended to trust signal context');
        }

        // CWV is now fetched by the SaaS backend after sync — no client-side call needed.
        $core_web_vitals = null;

        // Calculate content hash for change detection (done here so we can use it
        // to decide whether to force a fresh screenshot before the AI call).
        // Content hash — include a fingerprint of the raw HTML head (stylesheet/asset
        // version strings) so that CSS or theme changes also trigger a fresh screenshot,
        // not just post_content changes.
        $content_hash = hash('sha256', $content . $html_structure . substr($rendered_content, 0, 2000));

        // Determine whether the page content has changed since the last audit.
        // If it has (or there is no previous audit), force a fresh screenshot so
        // GPT-4o sees the updated page — not a cached image of the old version.
        // If the content is identical, the cached screenshot is still accurate and
        // we reuse it to avoid an unnecessary Playwright call.
        $previous_hash = $wpdb->get_var( $wpdb->prepare(
            "SELECT content_hash FROM {$wpdb->prefix}conversioniq_audits
             WHERE page_id = %d AND content_hash IS NOT NULL
             ORDER BY created_at DESC LIMIT 1",
            $post->ID
        ) );
        $content_changed = ( $previous_hash === null || $previous_hash !== $content_hash );
        $force_screenshot = $content_changed;

        if ( $content_changed ) {
            ciq_log( 'CIQ Audit: content changed (or first audit) — forcing fresh screenshot for ' . $page_url );
        } else {
            ciq_log( 'CIQ Audit: content unchanged — reusing cached screenshot for ' . $page_url );
        }

        // Capture a screenshot of this page for visual AI analysis.
        $screenshot_result = conversioniq_capture_audit_screenshot( $page_url, $force_screenshot );

        // Abort the audit if the screenshot service detected a broken page.
        if ( is_wp_error( $screenshot_result ) && $screenshot_result->get_error_code() === 'page_broken' ) {
            return new WP_REST_Response( array(
                'success'    => false,
                'error_code' => 'page_broken',
                'message'    => $screenshot_result->get_error_message(),
            ), 422 );
        }

        $screenshot_url = is_wp_error( $screenshot_result ) ? null : $screenshot_result;
        if ( $screenshot_url ) {
            ciq_log( 'CIQ Audit: screenshot ready for GPT-4o visual analysis — ' . $page_url );
        } else {
            ciq_log( 'CIQ Audit: no screenshot available, proceeding with text-only analysis' );
        }

        // ── Sprint feedback loop: fetch open sprints before building AI payload ──
        $open_sprints   = [];
        $sprint_context = '';
        $sprint_metrics = []; // keyed by sprint id => ['before' => [...], 'after' => [...]]
        try {
            $sprint_sync  = new ConversionIQ_Supabase_Sync();
            $open_sprints = $sprint_sync->fetch_open_sprints( $page_url );
        } catch ( Exception $e ) {
            ciq_log( 'Sprint fetch error: ' . $e->getMessage() );
        }

        if ( ! empty( $open_sprints ) ) {
            ciq_log( 'Sprint feedback: ' . count( $open_sprints ) . ' open sprint(s) found for ' . $page_url );
            $sprint_lines = [];

            foreach ( $open_sprints as $sprint ) {
                $sprint_id = $sprint['id'] ?? null;
                $done_at   = $sprint['marked_done_at'] ?? null;
                $pre_score = isset( $sprint['pre_score'] ) ? (int) $sprint['pre_score'] : null;
                $behavioral_note = '';

                if ( $sprint_id && $done_at ) {
                    $done_ts     = strtotime( $done_at );
                    $before_from = gmdate( 'Y-m-d H:i:s', $done_ts - 30 * DAY_IN_SECONDS );
                    $before_to   = gmdate( 'Y-m-d H:i:s', $done_ts );
                    $after_from  = gmdate( 'Y-m-d H:i:s', $done_ts );
                    $after_to    = gmdate( 'Y-m-d H:i:s' );

                    $mb = conversioniq_get_behavioral_metrics( $page_url, $before_from, $before_to );
                    $ma = conversioniq_get_behavioral_metrics( $page_url, $after_from, $after_to );
                    $sprint_metrics[ $sprint_id ] = [ 'before' => $mb, 'after' => $ma ];

                    if ( $mb['session_count'] >= 5 && $ma['session_count'] >= 5 ) {
                        $br_delta = ( $ma['bounce_rate'] !== null && $mb['bounce_rate'] !== null )
                            ? round( ( $ma['bounce_rate'] - $mb['bounce_rate'] ) * 100, 1 ) : null;
                        $tp_delta = ( $ma['avg_time_on_page_sec'] !== null && $mb['avg_time_on_page_sec'] !== null )
                            ? round( $ma['avg_time_on_page_sec'] - $mb['avg_time_on_page_sec'] ) : null;
                        if ( $br_delta !== null ) {
                            $behavioral_note .= 'bounce rate ' . ( $br_delta > 0 ? '+' : '' ) . $br_delta . '%. ';
                        }
                        if ( $tp_delta !== null ) {
                            $behavioral_note .= 'avg time on page ' . ( $tp_delta > 0 ? '+' : '' ) . $tp_delta . 's. ';
                        }
                        if ( ! $behavioral_note ) {
                            $behavioral_note = 'no measurable behavioral change detected yet.';
                        }
                    } else {
                        $behavioral_note = 'insufficient post-change sessions (<5) — data still accumulating.';
                    }
                }

                $suggestions = $sprint['suggestions_implemented'] ?? [];
                if ( is_string( $suggestions ) ) {
                    $suggestions = json_decode( $suggestions, true ) ?: [];
                }
                $sug_texts = [];
                foreach ( (array) $suggestions as $sug ) {
                    $text = is_array( $sug )
                        ? ( $sug['suggestion_text'] ?? $sug['text'] ?? '' )
                        : (string) $sug;
                    if ( $text ) {
                        $sug_texts[] = '  - ' . $text;
                    }
                }

                $done_label    = $done_at ? gmdate( 'Y-m-d', strtotime( $done_at ) ) : 'recently';
                $sprint_lines[] = 'Sprint (implemented ' . $done_label
                    . ( $pre_score !== null ? ", pre-sprint score: {$pre_score}" : '' ) . '):'
                    . "\n" . ( $sug_texts ? implode( "\n", $sug_texts ) : '  (no suggestions listed)' )
                    . ( $behavioral_note ? "\n  Behavioral delta: {$behavioral_note}" : '' );
            }

            $sprint_context = "\n\nSPRINT FEEDBACK CONTEXT:\n"
                . "The user previously implemented the following CRO suggestions on this page. "
                . "Factor these changes into your re-audit and reflect any score improvements. "
                . "Include a \"sprint_assessment\" field in your JSON output: 1–3 sentences "
                . "assessing whether the implemented changes improved the page and what to prioritise next. "
                . "If behavioral data is insufficient, note that more time is needed.\n\n"
                . implode( "\n\n", $sprint_lines );

            ciq_log( 'Sprint context built (' . strlen( $sprint_context ) . ' chars)' );
        }
        // ── End sprint fetch ──────────────────────────────────────────────────

        // ── GSC page-level search intent (enriches copy rewrites with real queries) ──
        // Fetches the top search queries that actually drive traffic to this specific page.
        // Used by the AI to anchor copy rewrites in proven search demand.
        // Returns null gracefully if GSC is not connected — audit proceeds without it.
        $gsc_page_queries = null;
        try {
            $ga_instance = new ConversionIQ_Google_Analytics();
            if ( $ga_instance->is_gsc_connected() ) {
                $gsc_page_queries = $ga_instance->fetch_gsc_page_queries( $page_url, 90 );
                if ( $gsc_page_queries ) {
                    ciq_log( 'GSC page queries: ' . count( $gsc_page_queries ) . ' queries fetched for ' . $page_url );
                } else {
                    ciq_log( 'GSC page queries: none returned for ' . $page_url . ' (no organic traffic data)' );
                }
            } else {
                ciq_log( 'GSC page queries: skipped — GSC not connected' );
            }
        } catch ( Exception $e ) {
            ciq_log( 'GSC page queries: exception — ' . $e->getMessage() );
        }
        // ── End GSC fetch ─────────────────────────────────────────────────────

        // Deterministic, ordered copy inventory: hero + next 5 sections, full hero copy.
        // This becomes the authoritative section list the AI rewrites, so sections are
        // never skipped and the hero always yields heading + sub-heading + CTA.
        $copy_inventory = array();
        if ( class_exists( 'ConversionIQ_Copy_Inventory' ) ) {
            try {
                $copy_inventory = ConversionIQ_Copy_Inventory::extract( $post, 6, $rendered_content );
            } catch ( Throwable $inv_e ) {
                ciq_log( 'Copy inventory: extraction error — ' . $inv_e->getMessage() );
            }
        }

        $payload = array(
            'business' => $business,
            'page' => array(
                'title' => $post->post_title,
                'content' => $content,
                'url' => $page_url,
                'word_count' => str_word_count($content),
                'html_structure' => $html_structure,
                'screenshot_url' => $screenshot_url,
                'core_web_vitals' => $core_web_vitals,
                'sprint_context' => $sprint_context,
                'gsc_page_queries' => $gsc_page_queries,
                'copy_inventory' => $copy_inventory,
            ),
        );

        ciq_log('Running audit for: ' . $post->post_title);
        ciq_log('Content hash: ' . $content_hash);

        // Skip AI call if content is identical to the last audit — return the cached record instead.
        if ( ! $content_changed ) {
            $cached = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}conversioniq_audits
                 WHERE page_id = %d AND content_hash = %s
                 ORDER BY created_at DESC LIMIT 1",
                $post->ID,
                $content_hash
            ), ARRAY_A );

            if ( $cached ) {
                ciq_log( '⚡ Content unchanged — returning cached audit for "' . $post->post_title . '" (skipping AI call)' );
                $results[] = array(
                    'page_id'    => $post->ID,
                    'page_title' => $post->post_title,
                    'cached'     => true,
                    'audit_id'   => $cached['id'],
                );
                continue;
            }
        }

        $audit_start = microtime(true);
        try {
            $ai = ConversionIQ_AI::analyze($payload);
            $audit_time = round((microtime(true) - $audit_start), 2);

            // If AI analysis failed, do not save an empty record — skip this page entirely.
            if ( $ai === null ) {
                ciq_log( '❌ AI analysis returned null for "' . $post->post_title . '" — skipping save (no record created)' );
                $results[] = array(
                    'page_id'    => $post->ID,
                    'page_title' => $post->post_title,
                    'failed'     => true,
                    'error'      => 'AI analysis failed — no report created',
                );
                continue;
            }

            // Attach the detected page type so it can be stored and synced.
            $ai['page_type'] = ConversionIQ_AI::get_page_type( $post->post_title, $page_url );

            // Validate AI response structure
            if (!is_array($ai)) {
                throw new Exception('AI returned invalid response type: ' . gettype($ai));
            }

            // Generate a unique token for the public report URL
            $ai['report_token'] = bin2hex(random_bytes(16));

            // Attach Core Web Vitals to the audit record so it syncs to Supabase
            if ( $core_web_vitals ) {
                $ai['core_web_vitals'] = $core_web_vitals;
                ciq_log( 'CWV: attached to audit payload for Supabase sync (token will be set after report_token generation)' );
            } else {
                ciq_log( 'CWV: ⚠️ not attached — audit will sync without core_web_vitals' );
            }

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
                    'lead_intelligence'      => isset($ai['lead_intelligence_summary']) ? $ai['lead_intelligence_summary'] : null,
                    'audience_fit_analysis'  => isset($ai['audience_fit_analysis'])     ? $ai['audience_fit_analysis']     : null,
                    'cro_checklist'          => isset($ai['cro_checklist'])              ? $ai['cro_checklist']              : null,
                    'plan'                   => ConversionIQ_Config_Manager::get_plan(),
                    'page_type'              => $ai['page_type'] ?? null,
                ));

                // Track usage for analytics
                $supabase_sync->track_usage('analyze_page');

                if ($sync_success) {
                    $report_token = is_string( $sync_success ) ? $sync_success : ( $ai['report_token'] ?? '' );
                    ciq_log( 'Supabase sync: ✅ audit synced (token=' . substr( $report_token, 0, 8 ) . '… score=' . ( $ai['overall_score'] ?? '?' ) . ')' );

                    // Fire-and-forget: ask the SaaS backend to run PageSpeed and
                    // patch core_web_vitals on the audit row it just received.
                    $license_key = get_option( 'conversioniq_license_key', '' );

                    ciq_log( 'CWV trigger: checking prerequisites — license_key=' . ( $license_key ? 'present (' . strlen( $license_key ) . ' chars)' : 'MISSING ❌' ) . ' report_token=' . ( $report_token ? substr( $report_token, 0, 8 ) . '…' : 'MISSING ❌' ) );

                    if ( $license_key && $report_token ) {
                        $trigger_payload = array(
                            'url'          => $page_url,
                            'license_key'  => $license_key,
                            'report_token' => $report_token,
                        );
                        ciq_log( 'CWV trigger: dispatching POST to conversioniq-app.com/api/pagespeed — url=' . $page_url . ' token=' . substr( $report_token, 0, 8 ) . '… triggered_at=' . gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
                        $trigger_start = microtime( true );
                        wp_remote_post( 'https://conversioniq-app.com/api/pagespeed', array(
                            'timeout'   => 1,   // don't block the audit response
                            'blocking'  => false,
                            'headers'   => array( 'Content-Type' => 'application/json' ),
                            'body'      => wp_json_encode( $trigger_payload ),
                        ) );
                        $trigger_dispatch_ms = round( ( microtime( true ) - $trigger_start ) * 1000 );
                        ciq_log( 'CWV trigger: dispatched (non-blocking) in ' . $trigger_dispatch_ms . 'ms — SaaS will PATCH audits.core_web_vitals asynchronously' );
                    } elseif ( ! $license_key ) {
                        ciq_log( 'CWV trigger: ⚠️ skipped — no license_key stored (Settings → License Key)' );
                    } else {
                        ciq_log( 'CWV trigger: ⚠️ skipped — report_token was empty after sync' );
                    }
                } else {
                    ciq_log('Supabase sync: ❌ send_audit() returned false — audit NOT in Supabase, CWV trigger will NOT fire');
                }

                // ── Create implementation review from audit rewrites ──────────
                if ( ! empty( $ai['rewrites'] ) && $report_token ) {
                    try {
                        $supabase_sync->create_implementation_review_from_audit(
                            $report_token,
                            $page_url,
                            $post->post_title ?? '',
                            $ai['rewrites'],
                            isset( $post ) ? (int) $post->ID : 0
                        );
                    } catch ( Exception $review_ex ) {
                        ciq_log( 'create_impl_review: exception — ' . $review_ex->getMessage() );
                    }
                }

                // ── Sprint close: write post-audit results back to open sprints ──
                if ( ! empty( $open_sprints ) && $sync_success ) {
                    $post_audit_id  = is_string( $sync_success ) ? $sync_success : ( $ai['report_token'] ?? '' );
                    $post_score     = isset( $ai['overall_score'] ) ? (int) $ai['overall_score'] : null;
                    $sprint_assess  = isset( $ai['sprint_assessment'] ) ? $ai['sprint_assessment'] : null;

                    foreach ( $open_sprints as $sprint ) {
                        $sprint_id = $sprint['id'] ?? null;
                        if ( ! $sprint_id ) continue;

                        $pre_score_val = isset( $sprint['pre_score'] ) ? (int) $sprint['pre_score'] : null;
                        $score_delta   = ( $post_score !== null && $pre_score_val !== null )
                            ? ( $post_score - $pre_score_val ) : null;

                        $metrics = $sprint_metrics[ $sprint_id ] ?? [];
                        $mb      = $metrics['before'] ?? [];
                        $ma      = $metrics['after']  ?? [];

                        $close_data = array_filter( [
                            'post_audit_id'        => $post_audit_id ?: null,
                            'post_score'           => $post_score,
                            'score_delta'          => $score_delta,
                            'bounce_rate_before'   => $mb['bounce_rate'] ?? null,
                            'bounce_rate_after'    => $ma['bounce_rate'] ?? null,
                            'time_on_page_before'  => isset( $mb['avg_time_on_page_sec'] ) ? (int) $mb['avg_time_on_page_sec'] : null,
                            'time_on_page_after'   => isset( $ma['avg_time_on_page_sec'] ) ? (int) $ma['avg_time_on_page_sec'] : null,
                            'session_count_before' => $mb['session_count'] ?? null,
                            'session_count_after'  => $ma['session_count'] ?? null,
                            'ai_assessment'        => $sprint_assess,
                            'completed_at'         => gmdate( 'c' ),
                        ], fn( $v ) => $v !== null );

                        $closed = $supabase_sync->close_sprint( $sprint_id, $close_data );
                        ciq_log( 'Sprint close: ' . ( $closed ? '✅' : '❌' )
                            . ' sprint_id=' . $sprint_id
                            . ' score_delta=' . ( $score_delta !== null ? $score_delta : 'n/a' ) );
                    }
                }
                // ── End sprint close ──────────────────────────────────────────
            }
            catch (Exception $e) {
                ciq_log('Supabase sync exception - ' . $e->getMessage());
            }

            ciq_log('Audit completed for: ' . $post->post_title . ' in ' . $audit_time . 's');
        }
        catch (Exception $e) {
            $audit_time = round((microtime(true) - $audit_start), 2);
            ciq_log('Audit EXCEPTION for ' . $post->post_title . ': ' . $e->getMessage());
            // AI analysis failed — do not save a report of any kind
            $results[] = array(
                'page_id'    => $post->ID,
                'page_title' => $post->post_title,
                'page_url'   => $page_url,
                'failed'     => true,
                'error'      => 'AI analysis unavailable — audit could not be completed.',
                'created_at' => current_time('mysql'),
            );
        }
    }

    // Run competitor analysis after the REST response is sent.
    // WP-Cron is unreliable on loopback-blocked hosts (spawn_cron() itself
    // makes an HTTP request back to the site which always times out here).
    // register_shutdown_function() + fastcgi_finish_request() sends the
    // response immediately then continues processing in the background.
    $competitors_raw = trim( $business['competitors'] ?? '' );
    if ( ! empty( $competitors_raw ) ) {
        $business_snapshot = $business; // capture by value for the closure
        register_shutdown_function( function() use ( $business_snapshot ) {
            ciq_log( 'Competitors: shutdown function entered' );
            // On PHP-FPM / FastCGI hosts this flushes the response to the client
            // so they don't wait for competitor analysis to complete.
            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
                ciq_log( 'Competitors: fastcgi_finish_request() called' );
            } else {
                ciq_log( 'Competitors: fastcgi_finish_request() not available — running inline' );
            }
            // Allow analysis to run without hitting max_execution_time
            @set_time_limit( 120 );
            ciq_log( 'Competitors: shutdown background analysis starting' );
            conversioniq_analyze_competitors( $business_snapshot );
        } );
        ciq_log( 'Competitors: analysis registered for post-response execution' );
    } else {
        ciq_log( 'Competitors: skipped — competitors field is empty in business profile' );
    }

    // Run SEO audit for each successfully audited page in the background,
    // after the REST response is returned to the client.
    if ( ConversionIQ_Config_Manager::can( 'seo' ) ) {
        $seo_page_ids = [];
        foreach ( $results as $r ) {
            if ( empty( $r['failed'] ) && ! empty( $r['page_id'] ) ) {
                $seo_page_ids[] = (int) $r['page_id'];
            }
        }
        if ( ! empty( $seo_page_ids ) ) {
            register_shutdown_function( function() use ( $seo_page_ids ) {
                if ( function_exists( 'fastcgi_finish_request' ) ) {
                    fastcgi_finish_request();
                }
                @set_time_limit( 120 );
                ciq_log( 'SEO background: starting for ' . count( $seo_page_ids ) . ' page(s)' );
                $supabase = new ConversionIQ_Supabase_Sync();
                foreach ( $seo_page_ids as $pid ) {
                    $cached = get_transient( 'ciq_seo_last_' . $pid );
                    if ( $cached !== false ) {
                        ciq_log( 'SEO background: skipping page_id=' . $pid . ' — already audited (cached score=' . ( $cached['overall_score'] ?? '?' ) . ', expires in 7 days)' );
                        continue;
                    }
                    $seo_result = ConversionIQ_SEO_Analyzer::analyze( $pid );
                    if ( is_wp_error( $seo_result ) ) {
                        ciq_log( 'SEO background: error page_id=' . $pid . ' — ' . $seo_result->get_error_message() );
                        continue;
                    }
                    $supabase->send_seo_audit( $seo_result );
                    set_transient( 'ciq_seo_last_' . $pid, $seo_result, 7 * DAY_IN_SECONDS );
                    ciq_log( 'SEO background: ✅ page_id=' . $pid . ' score=' . $seo_result['overall_score'] );
                }
            } );
            ciq_log( 'SEO background: registered for post-response execution (' . count( $seo_page_ids ) . ' page(s))' );
        }
    }

    return rest_ensure_response(array('success' => true, 'results' => $results));
}

/**
 * Fetch, AI-score, and upsert each competitor URL from the business profile.
 *
 * Runs after the main audit loop. Results go to ciq_competitor_scores in
 * Supabase so the report's BenchmarkSection can display a Competitor Average
 * and per-competitor bar chart.
 *
 * Capped at 3 competitors per run. Each URL is skipped for 7 days after a
 * successful analysis to avoid redundant AI calls.
 *
 * @param array $business  Business profile from conversion_iq_settings option.
 */
function conversioniq_analyze_competitors( $business ) {
    $competitors_raw = trim( $business['competitors'] ?? '' );
    if ( empty( $competitors_raw ) ) {
        ciq_log( 'Competitors: none configured in business profile — skipping' );
        return;
    }

    // Parse comma-separated entries, preserving the original name as a hint for GPT.
    // Accepts: full URLs ("https://..."), bare domains ("competitor.com"),
    // and plain business names ("AirBNB" → url=https://airbnb.com, hint="AirBNB").
    $entries = [];
    foreach ( array_filter( array_map( 'trim', explode( ',', $competitors_raw ) ) ) as $entry ) {
        if ( preg_match( '/^https?:\/\//i', $entry ) ) {
            $entries[] = [ 'url' => esc_url_raw( $entry ), 'hint' => parse_url( $entry, PHP_URL_HOST ) ?: $entry ];
        } elseif ( preg_match( '/^[a-z0-9][a-z0-9\-\.]+\.[a-z]{2,}$/i', $entry ) ) {
            $entries[] = [ 'url' => 'https://' . $entry, 'hint' => $entry ];
        } elseif ( preg_match( '/^[a-z0-9][a-z0-9\s\-\_]+$/i', $entry ) ) {
            $slug = strtolower( preg_replace( '/[\s_]+/', '', $entry ) );
            $entries[] = [ 'url' => 'https://' . $slug . '.com', 'hint' => $entry ];
            ciq_log( 'Competitors: resolved "' . $entry . '" → https://' . $slug . '.com' );
        } else {
            ciq_log( 'Competitors: skipping "' . $entry . '" — not a recognizable URL, domain, or business name' );
        }
    }

    if ( empty( $entries ) ) {
        ciq_log( 'Competitors: no valid entries found — nothing to analyze' );
        return;
    }

    // Cap at 3 to keep total audit time reasonable
    $entries = array_slice( $entries, 0, 3 );
    ciq_log( 'Competitors: ' . count( $entries ) . ' to score via GPT knowledge (no scraping)' );

    $supabase_sync = new ConversionIQ_Supabase_Sync();

    foreach ( $entries as $entry_data ) {
        $competitor_url = $entry_data['url'];
        $name_hint      = $entry_data['hint'];

        $cache_key = 'ciq_comp_' . md5( $competitor_url );
        if ( get_transient( $cache_key ) ) {
            ciq_log( 'Competitors: ⏭ ' . $competitor_url . ' analyzed within last 7 days — skipping' );
            continue;
        }

        $comp_start = microtime( true );

        try {
            $ai = ConversionIQ_AI::score_competitor( $competitor_url, $name_hint, $business );

            if ( ! is_array( $ai ) || ! isset( $ai['overall_score'] ) ) {
                ciq_log( 'Competitors: ⚠️ AI returned unexpected response for ' . $competitor_url );
                continue;
            }

            // Use the real brand name GPT identified, falling back to our hint
            $display_name = ! empty( $ai['business_name'] ) ? $ai['business_name'] : $name_hint;

            $scores = [
                'clarity_score'       => isset( $ai['clarity_score'] )     ? intval( $ai['clarity_score'] )     : null,
                'emotional_score'     => isset( $ai['emotional_score'] )   ? intval( $ai['emotional_score'] )   : null,
                'cta_strength'        => isset( $ai['cta_strength'] )      ? intval( $ai['cta_strength'] )      : null,
                'readability_score'   => isset( $ai['readability_score'] ) ? intval( $ai['readability_score'] ) : null,
                'engagement_score'    => isset( $ai['engagement_score'] )  ? intval( $ai['engagement_score'] )  : null,
                'trust_score'         => isset( $ai['trust_score'] )       ? intval( $ai['trust_score'] )       : null,
                'competitive_insight' => $ai['competitive_insight'] ?? null,
            ];

            $upserted = $supabase_sync->upsert_competitor_score(
                $competitor_url,
                $display_name,
                intval( $ai['overall_score'] ),
                $scores
            );

            if ( $upserted ) {
                // Cache 7 days — don't re-analyze until likely stale
                set_transient( $cache_key, 1, 7 * DAY_IN_SECONDS );
            }

            $elapsed = round( microtime( true ) - $comp_start, 2 );
            ciq_log( 'Competitors: ' . ( $upserted ? '✅' : '⚠️ upsert failed —' ) . ' "' . $display_name . '" overall=' . $ai['overall_score'] . ' in ' . $elapsed . 's' );

        } catch ( Exception $e ) {
            ciq_log( 'Competitors: exception for ' . $competitor_url . ' — ' . $e->getMessage() );
        }
    }
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
        // Write merged data back to the local WP option so audits always read
        // the latest values — without this, competitors/etc set on the SaaS
        // side are only visible in the UI, never used by conversioniq_run_audit().
        update_option( 'conversion_iq_settings', wp_json_encode( $local ) );
        ciq_log( 'Business profile: synced to local WP option from ' . $source . ' — competitors=' . json_encode( $local['competitors'] ?? '(empty)' ) );
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

    // Call AI via SaaS proxy — no AI key stored on the WP site
    $license_key = get_option('conversioniq_license_key', '');
    if (empty($license_key)) {
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
            'X-License-Key' => $license_key,
            'Content-Type'  => 'application/json',
        ),
        'body' => wp_json_encode($ai_body),
        'timeout' => 60,
        'sslverify' => true,
    );

    ciq_log('Ã°Å¸Â¤â€“ Calling AI to extract business info...');
    $ai_response = wp_remote_post('https://conversioniq-app.com/api/ai-proxy', $ai_args);

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
            'plugin_version'    => defined('CONVERSION_IQ_VERSION') ? CONVERSION_IQ_VERSION : null,
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

    // Persist organization_id if the server returns one (may be missing on older activations)
    if (!empty($body['organization_id'])) {
        update_option('conversioniq_organization_id', sanitize_text_field($body['organization_id']));
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

    // Re-push endpoint + remote secret to Supabase so the dashboard can reach this site
    try {
        $supabase = new ConversionIQ_Supabase_Sync();
        $supabase->push_remote_credentials();
        ciq_log('ConversionIQ: push_remote_credentials on license refresh: success');
    } catch ( Exception $e ) {
        ciq_log('ConversionIQ: push_remote_credentials on license refresh: ' . $e->getMessage());
    }

    // Return fresh feature flags so the frontend can update without a page reload
    $features = class_exists('ConversionIQ_Config_Manager')
        ? ConversionIQ_Config_Manager::get_feature_flags()
        : array();

    // Pre-fetch screenshots for all tracked pages in the background
    conversioniq_heatmap_prefetch_screenshots();

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
            'plugin_version'    => defined('CONVERSION_IQ_VERSION') ? CONVERSION_IQ_VERSION : null,
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

    // Pre-fetch screenshots for all tracked pages in the background
    conversioniq_heatmap_prefetch_screenshots();

    // Schedule a follow-up config sync (mirrors what the plugin activation hook does)
    // so the SaaS backend reliably receives the plugin version and sync endpoint
    // even if the synchronous calls above raced with org setup on the SaaS side.
    if ( ! wp_next_scheduled( 'conversioniq_sync_config' ) ) {
        wp_schedule_single_event( time() + 30, 'conversioniq_sync_config' );
    }

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

            // Get page content — builder-aware (Elementor via its API, else the_content).
            $page_url = get_permalink($page_id);
            $rendered_content = conversioniq_render_page_content( $page );
            $content          = wp_strip_all_tags( $rendered_content );
            $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $content = trim( preg_replace( '/\s+/', ' ', $content ) );

            // Build HTML structure directly from the page-builder rendered content.
            $html_structure = '';
            $html_body = $rendered_content;
            $html_structure = conversioniq_extract_html_structure( $rendered_content, $page_url );

            if ( strlen( trim( $content ) ) < 300 ) {
                $fallback_text = conversioniq_extract_body_text( $rendered_content );
                if ( strlen( $fallback_text ) > strlen( $content ) ) {
                    $content = $fallback_text;
                    $log[] = '    Content: rendered HTML body-text fallback applied (' . strlen( $content ) . ' chars)';
                }
            }

            // Supplement HTML-based trust signals with reviews stored in WordPress CPTs
            $cpt_reviews = conversioniq_get_cpt_reviews();
            if (!empty($cpt_reviews)) {
                $html_structure .= $cpt_reviews;
            }

            // Get business settings
            $business_settings = get_option('conversion_iq_settings', '{}');
            $business = json_decode($business_settings, true);

            // Deterministic copy inventory (hero + next 5 sections, full hero copy).
            $copy_inventory = array();
            if ( class_exists( 'ConversionIQ_Copy_Inventory' ) ) {
                try {
                    $copy_inventory = ConversionIQ_Copy_Inventory::extract( $page, 6, $rendered_content );
                } catch ( Throwable $inv_e ) {
                    ciq_log( 'Copy inventory: extraction error — ' . $inv_e->getMessage() );
                }
            }

            // Prepare payload for AI analysis
            $payload = array(
                'business' => $business,
                'page' => array(
                    'title' => $page->post_title,
                    'content' => $content,
                    'url' => $page_url,
                    'word_count' => str_word_count($content),
                    'html_structure' => $html_structure,
                    'copy_inventory' => $copy_inventory,
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
                                'lead_intelligence'      => isset($ai_result['lead_intelligence_summary']) ? $ai_result['lead_intelligence_summary'] : null,
                                'audience_fit_analysis'  => isset($ai_result['audience_fit_analysis'])     ? $ai_result['audience_fit_analysis']     : null,
                                'cro_checklist'          => isset($ai_result['cro_checklist'])              ? $ai_result['cro_checklist']              : null,
                                'plan'                   => ConversionIQ_Config_Manager::get_plan(),
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

// ── Heatmap Callbacks ──────────────────────────────────────────────────────

/**
/**
 * Return true if a URL should never be recorded as a heatmap page.
 * Covers: WP admin/system paths, page-builder previews, draft previews.
 */
function ciq_is_internal_url( $url ) {
    if ( ! $url ) { return true; }

    $parsed = wp_parse_url( $url );
    if ( ! $parsed ) { return true; }

    // Block well-known WP admin / system paths
    $path = $parsed['path'] ?? '';
    $blocked_paths = array(
        '/wp-admin', '/wp-login.php', '/wp-cron.php',
        '/wp-json/',  '/xmlrpc.php',   '/feed',
    );
    foreach ( $blocked_paths as $bp ) {
        if ( strpos( $path, $bp ) !== false ) { return true; }
    }

    // Block builder / preview query-string params
    $qs_arr = array();
    if ( ! empty( $parsed['query'] ) ) {
        wp_parse_str( $parsed['query'], $qs_arr );
    }
    $blocked_params = array(
        'elementor-preview', 'elementor_library',
        'preview_id', 'preview_nonce',
        'et_pb_preview', 'fl_builder',
    );
    foreach ( $blocked_params as $bp ) {
        if ( isset( $qs_arr[ $bp ] ) ) { return true; }
    }
    // WP draft preview
    if ( isset( $qs_arr['preview'] ) && $qs_arr['preview'] === 'true' ) { return true; }

    return false;
}

/**
 * POST /heatmap/record
 * Public endpoint — receives batched events from the frontend tracker.
 * Rate-limited to 60 inserts per IP per minute to prevent abuse.
 */
function conversioniq_heatmap_record( WP_REST_Request $request ) {
    global $wpdb;

    // Rate limit: 60 batches per IP per minute
    $ip_hash  = md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
    $rate_key = 'ciq_hm_rate_' . $ip_hash;
    $count    = (int) get_transient( $rate_key );
    if ( $count >= 60 ) {
        return new WP_REST_Response( array( 'success' => false ), 429 );
    }
    set_transient( $rate_key, $count + 1, 60 );

    $body   = $request->get_json_params();
    $raw_url = isset( $body['page_url'] ) ? esc_url_raw( $body['page_url'] ) : '';
    $events  = isset( $body['events'] ) && is_array( $body['events'] ) ? $body['events'] : array();

    // Optional enrichment fields sent alongside click/scroll events
    $session_id_batch = isset( $body['session_id'] )
                        ? preg_replace( '/[^a-z0-9]/i', '', substr( $body['session_id'], 0, 100 ) )
                        : null;
    $device_info    = isset( $body['device_info'] )    && is_array( $body['device_info'] )    ? $body['device_info']    : null;
    $form_analytics = isset( $body['form_analytics'] ) && is_array( $body['form_analytics'] ) ? $body['form_analytics'] : array();
    $above_fold     = isset( $body['above_fold'] )     && is_array( $body['above_fold'] )     ? $body['above_fold']     : null;
    $cwv            = isset( $body['cwv'] )            && is_array( $body['cwv'] )            ? $body['cwv']            : null;
    $time_on_page_sec = isset( $body['time_on_page_sec'] ) ? min( 7200, absint( $body['time_on_page_sec'] ) ) : null;
    $traffic_source   = isset( $body['traffic_source'] )   && is_array( $body['traffic_source'] )   ? $body['traffic_source']   : null;

    // Validate URL — must be a valid http/https URL
    if ( ! $raw_url || ! preg_match( '/^https?:\/\//i', $raw_url ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid page_url' ), 400 );
    }

    // Reject private/internal IPs in the URL (SSRF guard)
    $host = wp_parse_url( $raw_url, PHP_URL_HOST );
    if ( $host && filter_var( $host, FILTER_VALIDATE_IP ) ) {
        if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return new WP_REST_Response( array( 'success' => false ), 400 );
        }
    }

    // Reject any URL that looks like an admin/builder/preview page
    if ( ciq_is_internal_url( $raw_url ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Internal URL not tracked' ), 400 );
    }

    // Strip volatile params so the same page always maps to a single URL.
    // Ad-network click IDs (fbclid, gclid, etc.) are unique per click — stripping
    // them here mirrors the JS tracker so MySQL always stores a canonical URL.
    $strip_params = array( 'ver', 'preview_nonce', 'reauth', 'redirect_to', '_wpnonce',
                           'fbclid', 'gclid', 'msclkid', 'ttclid', 'li_fat_id', 'igshid', 'mc_cid', 'mc_eid' );
    $parts        = wp_parse_url( $raw_url );
    if ( ! empty( $parts['query'] ) ) {
        wp_parse_str( $parts['query'], $qs_arr );
        foreach ( $strip_params as $sp ) { unset( $qs_arr[ $sp ] ); }
        $new_qs  = ! empty( $qs_arr ) ? '?' . http_build_query( $qs_arr ) : '';
        $raw_url = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' )
                   . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
                   . ( $parts['path'] ?? '/' )
                   . $new_qs;
    }

    // Limit to 50 events per batch
    $events = array_slice( $events, 0, 50 );

    $table = $wpdb->prefix . 'conversioniq_heatmap_events';
    $inserted = 0;

    foreach ( $events as $evt ) {
        $event_type  = in_array( $evt['type'] ?? '', array( 'click', 'scroll', 'move' ), true )
                       ? $evt['type'] : 'click';
        $x_pct       = isset( $evt['x_pct'] ) ? round( (float) $evt['x_pct'], 3 ) : null;
        $y_pct       = isset( $evt['y_pct'] ) ? round( (float) $evt['y_pct'], 3 ) : null;
        $element_tag = isset( $evt['element_tag'] ) ? sanitize_key( substr( $evt['element_tag'], 0, 50 ) ) : null;
        $element_txt = isset( $evt['element_text'] ) ? sanitize_text_field( substr( $evt['element_text'], 0, 100 ) ) : null;
        $session_id  = isset( $evt['session_id'] ) ? preg_replace( '/[^a-z0-9]/i', '', substr( $evt['session_id'], 0, 100 ) ) : null;
        $viewport_w  = isset( $evt['viewport_w'] ) ? absint( $evt['viewport_w'] ) : null;
        $viewport_h  = isset( $evt['viewport_h'] ) ? absint( $evt['viewport_h'] ) : null;

        // Sanity-check coordinate range (0–100%)
        if ( $x_pct !== null && ( $x_pct < 0 || $x_pct > 100 ) ) continue;
        if ( $y_pct !== null && ( $y_pct < 0 || $y_pct > 100 ) ) continue;

        $wpdb->insert( $table, array(
            'page_url'     => $raw_url,
            'event_type'   => $event_type,
            'x_pct'        => $x_pct,
            'y_pct'        => $y_pct,
            'element_tag'  => $element_tag,
            'element_text' => $element_txt,
            'session_id'   => $session_id,
            'viewport_w'   => $viewport_w,
            'viewport_h'   => $viewport_h,
            'recorded_at'  => current_time( 'mysql', 1 ),
        ), array( '%s', '%s', '%f', '%f', '%s', '%s', '%s', '%d', '%d', '%s' ) );
        $inserted++;
    }

    // Resolve the session identifier: prefer batch-level id, fall back to first event
    $effective_session = $session_id_batch;
    if ( ! $effective_session && ! empty( $events ) && isset( $events[0]['session_id'] ) ) {
        $effective_session = preg_replace( '/[^a-z0-9]/i', '', substr( $events[0]['session_id'], 0, 100 ) );
    }

    // Persist device info, above-fold snapshot, form analytics, RUM CWV, and time-on-page
    if ( $effective_session && ( $device_info || $above_fold || ! empty( $form_analytics ) || $cwv || $time_on_page_sec !== null ) ) {
        conversioniq_heatmap_store_enrichment( $raw_url, $effective_session, $device_info, $above_fold, $form_analytics, $cwv, $time_on_page_sec, $traffic_source );
    }

    return new WP_REST_Response( array( 'success' => true, 'inserted' => $inserted ), 200 );
}

/**
 * Persist enrichment data from heatmap batches (device info, above-fold,
 * form analytics) into their respective tables.
 *
 * @param string $raw_url           Normalised page URL.
 * @param string|null $session_id   Session identifier from batch payload.
 * @param array|null $device_info   Device / browser metadata.
 * @param array|null $above_fold    Above-the-fold element snapshot.
 * @param array $form_analytics     Array of per-form tracking objects.
 */
function conversioniq_heatmap_store_enrichment( $raw_url, $session_id, $device_info, $above_fold, $form_analytics, $cwv = null, $time_on_page_sec = null, $traffic_source = null ) {
    global $wpdb;

    if ( ! $session_id ) { return; }

    // ── Device / browser session ─────────────────────────────────────────
    $sessions_table = $wpdb->prefix . 'conversioniq_heatmap_sessions';
    if ( $device_info ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$sessions_table} WHERE session_id = %s LIMIT 1",
            $session_id
        ) );
        if ( ! $exists ) {
            $row = array(
                'session_id'  => $session_id,
                'page_url'    => $raw_url,
                'device_type' => sanitize_key( substr( $device_info['device_type'] ?? 'unknown', 0, 20 ) ),
                'browser'     => sanitize_key( substr( $device_info['browser']      ?? 'unknown', 0, 20 ) ),
                'screen_w'    => absint( $device_info['screen_w'] ?? 0 ),
                'screen_h'    => absint( $device_info['screen_h'] ?? 0 ),
                'pixel_ratio' => round( (float) ( $device_info['pixel_ratio'] ?? 1 ), 1 ),
                'recorded_at' => current_time( 'mysql', 1 ),
            );
            $fmt = array( '%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s' );
            if ( $cwv ) {
                $row['lcp_ms']  = isset( $cwv['lcp_ms']  ) ? absint( $cwv['lcp_ms']  ) : null;
                $row['cls']     = isset( $cwv['cls']     ) ? round( (float) $cwv['cls'], 3 ) : null;
                $row['fcp_ms']  = isset( $cwv['fcp_ms']  ) ? absint( $cwv['fcp_ms']  ) : null;
                $row['ttfb_ms'] = isset( $cwv['ttfb_ms'] ) ? absint( $cwv['ttfb_ms'] ) : null;
                $row['inp_ms']  = isset( $cwv['inp_ms']  ) ? absint( $cwv['inp_ms']  ) : null;
                array_push( $fmt, '%d', '%f', '%d', '%d', '%d' );
            }
            if ( $traffic_source ) {
                $row['referrer']     = isset( $traffic_source['referrer'] ) ? esc_url_raw( substr( $traffic_source['referrer'], 0, 500 ) ) : null;
                $row['utm_source']   = isset( $traffic_source['utm_source'] ) ? sanitize_text_field( substr( $traffic_source['utm_source'], 0, 100 ) ) : null;
                $row['utm_medium']   = isset( $traffic_source['utm_medium'] ) ? sanitize_text_field( substr( $traffic_source['utm_medium'], 0, 100 ) ) : null;
                $row['utm_campaign'] = isset( $traffic_source['utm_campaign'] ) ? sanitize_text_field( substr( $traffic_source['utm_campaign'], 0, 100 ) ) : null;
                array_push( $fmt, '%s', '%s', '%s', '%s' );
            }
            if ( $time_on_page_sec !== null ) {
                $row['time_on_page_sec'] = $time_on_page_sec;
                $fmt[] = '%d';
            }
            $result = $wpdb->insert( $sessions_table, $row, $fmt );
            if ( $result === false ) {
                ciq_log( 'Heatmap session INSERT failed: session=' . $session_id . ' url=' . $raw_url . ' error=' . $wpdb->last_error );
            } else {
                ciq_log( 'Heatmap session INSERT ok: session=' . $session_id . ' url=' . $raw_url );
            }
        } else {
            ciq_log( 'Heatmap session already exists, skipping INSERT: session=' . $session_id );
        }
    } else {
        ciq_log( 'Heatmap session: no device_info in batch, skipping session INSERT for session=' . $session_id . ' url=' . $raw_url );
    }

    // ── Update RUM CWV on existing session row ────────────────────────────
    // CWV can arrive in later batches (pagehide) after the session row was
    // already created, so UPDATE whenever we have fresh values.
    if ( $cwv ) {
        $has_any = ( isset( $cwv['lcp_ms'] ) && $cwv['lcp_ms'] ) ||
                   ( isset( $cwv['fcp_ms'] ) && $cwv['fcp_ms'] ) ||
                   ( isset( $cwv['ttfb_ms'] ) && $cwv['ttfb_ms'] );
        if ( $has_any ) {
            $wpdb->update(
                $sessions_table,
                array(
                    'lcp_ms'  => isset( $cwv['lcp_ms']  ) ? absint( $cwv['lcp_ms']  ) : null,
                    'cls'     => isset( $cwv['cls']     ) ? round( (float) $cwv['cls'], 3 ) : null,
                    'fcp_ms'  => isset( $cwv['fcp_ms']  ) ? absint( $cwv['fcp_ms']  ) : null,
                    'ttfb_ms' => isset( $cwv['ttfb_ms'] ) ? absint( $cwv['ttfb_ms'] ) : null,
                    'inp_ms'  => isset( $cwv['inp_ms']  ) ? absint( $cwv['inp_ms']  ) : null,
                ),
                array( 'session_id' => $session_id ),
                array( '%d', '%f', '%d', '%d', '%d' ),
                array( '%s' )
            );
        }
    }

    // ── Update time_on_page_sec when provided (arrives in pagehide batch) ────────
    if ( $time_on_page_sec !== null ) {
        $wpdb->update(
            $sessions_table,
            array( 'time_on_page_sec' => $time_on_page_sec ),
            array( 'session_id' => $session_id ),
            array( '%d' ),
            array( '%s' )
        );
    }

    // ── Above-the-fold snapshot ──────────────────────────────────────────
    if ( $above_fold ) {
        $atf_table = $wpdb->prefix . 'conversioniq_above_fold';
        // One snapshot per session is enough
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$atf_table} WHERE session_id = %s LIMIT 1",
            $session_id
        ) );
        if ( ! $exists ) {
            $elements_json = wp_json_encode( $above_fold['elements'] ?? array() );
            $wpdb->insert( $atf_table, array(
                'session_id'      => $session_id,
                'page_url'        => $raw_url,
                'viewport_height' => absint( $above_fold['viewport_height'] ?? 0 ),
                'elements'        => $elements_json,
                'recorded_at'     => current_time( 'mysql', 1 ),
            ), array( '%s', '%s', '%d', '%s', '%s' ) );
        }
    }

    // ── Form analytics ───────────────────────────────────────────────────
    if ( ! empty( $form_analytics ) ) {
        $form_table = $wpdb->prefix . 'conversioniq_form_analytics';
        foreach ( $form_analytics as $fa ) {
            if ( ! is_array( $fa ) ) { continue; }
            $form_id = sanitize_text_field( substr( $fa['id'] ?? 'form_unknown', 0, 80 ) );
            // Upsert: one row per session+form_id
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$form_table} WHERE session_id = %s AND form_id = %s LIMIT 1",
                $session_id, $form_id
            ) );
            $insert_row = array(
                'session_id'     => $session_id,
                'page_url'       => $raw_url,
                'form_id'        => $form_id,
                'starts'         => absint( $fa['starts']       ?? 0 ),
                'completions'    => absint( $fa['completions']  ?? 0 ),
                'time_sec'       => isset( $fa['time_sec'] ) ? absint( $fa['time_sec'] ) : null,
                'drop_off_field' => isset( $fa['drop_off_field'] ) ? sanitize_text_field( substr( $fa['drop_off_field'], 0, 60 ) ) : null,
                'recorded_at'    => current_time( 'mysql', 1 ),
            );
            // Use %s for nullable fields — wpdb serialises null correctly with %s.
            // Never use 'NULL' as a format specifier; it is not a valid wpdb token.
            $insert_formats = array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' );

            if ( $exists ) {
                $update_data = array(
                    'starts'         => $insert_row['starts'],
                    'completions'    => $insert_row['completions'],
                    'time_sec'       => $insert_row['time_sec'],
                    'drop_off_field' => $insert_row['drop_off_field'],
                    'recorded_at'    => $insert_row['recorded_at'],
                );
                $result = $wpdb->update(
                    $form_table,
                    $update_data,
                    array( 'session_id' => $session_id, 'form_id' => $form_id ),
                    array( '%d', '%d', '%s', '%s', '%s' ),
                    array( '%s', '%s' )
                );
                if ( $result === false ) {
                    ciq_log( 'Form analytics UPDATE failed: session=' . $session_id . ' form=' . $form_id . ' error=' . $wpdb->last_error );
                }
            } else {
                $result = $wpdb->insert( $form_table, $insert_row, $insert_formats );
                if ( $result === false ) {
                    ciq_log( 'Form analytics INSERT failed: session=' . $session_id . ' form=' . $form_id . ' error=' . $wpdb->last_error );
                } else {
                    ciq_log( 'Form analytics INSERT ok: session=' . $session_id . ' form=' . $form_id );
                }
            }
        }
    }
}

/**
 * GET /heatmap/data?page_url=X&days=30&event_type=click
 * Admin only — returns aggregated heatmap points for a page.
 */
function conversioniq_heatmap_data( WP_REST_Request $request ) {
    global $wpdb;

    $page_url   = esc_url_raw( $request->get_param( 'page_url' ) ?? '' );
    $days       = max( 1, min( 365, (int) ( $request->get_param( 'days' ) ?? 30 ) ) );
    $event_type = $request->get_param( 'event_type' ) ?? 'click';
    $event_type = in_array( $event_type, array( 'click', 'scroll', 'move' ), true ) ? $event_type : 'click';

    if ( ! $page_url ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'page_url required' ), 400 );
    }

    $table   = $wpdb->prefix . 'conversioniq_heatmap_events';
    $cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

    // Raw points: each click as an individual coordinate
    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT x_pct, y_pct, element_tag, element_text
         FROM $table
         WHERE page_url = %s
           AND event_type = %s
           AND recorded_at >= %s
           AND x_pct IS NOT NULL
           AND y_pct IS NOT NULL
         ORDER BY recorded_at DESC
         LIMIT 2000",
        $page_url,
        $event_type,
        $cutoff
    ), ARRAY_A );

    // Stats
    $total_clicks = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE page_url = %s AND event_type = %s AND recorded_at >= %s",
        $page_url, $event_type, $cutoff
    ) );

    $total_sessions = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(DISTINCT session_id) FROM $table WHERE page_url = %s AND recorded_at >= %s AND session_id IS NOT NULL",
        $page_url, $cutoff
    ) );

    // Top clicked elements
    $top_elements = $wpdb->get_results( $wpdb->prepare(
        "SELECT element_tag, element_text, COUNT(*) as clicks
         FROM $table
         WHERE page_url = %s AND event_type = %s AND recorded_at >= %s
           AND element_tag IS NOT NULL
         GROUP BY element_tag, element_text
         ORDER BY clicks DESC
         LIMIT 10",
        $page_url, $event_type, $cutoff
    ), ARRAY_A );

    return new WP_REST_Response( array(
        'success'        => true,
        'page_url'       => $page_url,
        'points'         => $rows,
        'total_events'   => (int) $total_clicks,
        'total_sessions' => (int) $total_sessions,
        'top_elements'   => $top_elements,
        'days'           => $days,
    ), 200 );
}

/**
 * GET /heatmap/pages
 * Admin only — returns distinct page URLs that have recorded events.
 */
function conversioniq_heatmap_pages( WP_REST_Request $request ) {
    global $wpdb;

    $table = $wpdb->prefix . 'conversioniq_heatmap_events';
    $rows  = $wpdb->get_results(
        "SELECT page_url, COUNT(*) as total_events,
                COUNT(DISTINCT session_id) as total_sessions,
                MAX(recorded_at) as last_event
         FROM $table
         WHERE page_url NOT LIKE '%/wp-admin%'
           AND page_url NOT LIKE '%/wp-login.php%'
           AND page_url NOT LIKE '%/wp-json/%'
           AND page_url NOT LIKE '%elementor-preview=%'
           AND page_url NOT LIKE '%preview_id=%'
           AND page_url NOT LIKE '%fl_builder=%'
           AND page_url NOT LIKE '%et_pb_preview=%'
           AND page_url NOT LIKE '%preview=true%'
         GROUP BY page_url
         ORDER BY total_events DESC
         LIMIT 100",
        ARRAY_A
    );

    return new WP_REST_Response( array(
        'success' => true,
        'pages'   => $rows ?: array(),
    ), 200 );
}

/**
 * Capture a screenshot of a page URL via the SaaS screenshot service.
 * Called during each audit run so GPT-4o can use visual evidence alongside HTML text.
 *
 * @param string $page_url     The public URL of the page to screenshot.
 * @param bool   $force_refresh True to bypass cache and capture a fresh screenshot
 *                              (used when page content has changed since last audit).
 *                              False to return a cached screenshot if one exists.
 * @return string|null          The public screenshot URL, or null if capture failed.
 */
function conversioniq_capture_audit_screenshot( $page_url, $force_refresh = false ) {
    $license_key = get_option( 'conversioniq_license_key', '' );
    $api_key     = get_option( 'conversioniq_api_key', '' );
    $org_id      = get_option( 'conversioniq_organization_id', '' );

    if ( ! $license_key || ! $api_key ) {
        ciq_log( 'CIQ screenshot: skipped — no license/api_key configured.' );
        return null;
    }

    $response = wp_remote_post( 'https://conversioniq-app.com/api/heatmap/screenshot', array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body'    => wp_json_encode( array(
            'page_url'        => $page_url,
            'license_key'     => $license_key,
            'site_url'        => get_site_url(),
            'organization_id' => $org_id,
            'force_refresh'   => $force_refresh,
        ) ),
        'timeout' => 60,
    ) );

    if ( is_wp_error( $response ) ) {
        ciq_log( 'CIQ screenshot error: ' . $response->get_error_message() );
        return null;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 200 && ! empty( $data['success'] ) && ! empty( $data['screenshot_url'] ) ) {
        $source = ! empty( $data['from_cache'] ) ? 'cached' : 'new capture';
        ciq_log( 'CIQ screenshot [' . $source . ']: ' . $data['screenshot_url'] );
        return $data['screenshot_url'];
    }

    // Page health check: SaaS detected broken page content (PHP errors, DB errors, etc.)
    // Return a WP_Error so the audit can surface a helpful message rather than proceeding
    // with a broken screenshot.
    if ( isset( $data['error_code'] ) && $data['error_code'] === 'page_broken' ) {
        $msg = ! empty( $data['message'] ) ? $data['message'] : 'Your page appears broken (PHP or database errors detected). Please clear your site cache and retry.';
        ciq_log( 'CIQ screenshot: page_broken detected — ' . $msg );
        return new WP_Error( 'page_broken', $msg );
    }

    // On a 5xx error retry once after a short pause — the screenshot service
    // sometimes returns a transient 500 on the first attempt but succeeds immediately
    // on a second try (cold-start / race condition on the SaaS side).
    if ( $code >= 500 ) {
        ciq_log( 'CIQ screenshot: HTTP ' . $code . ' — retrying once in 3s…' );
        sleep( 3 );
        $retry = wp_remote_post( 'https://conversioniq-app.com/api/heatmap/screenshot', array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( array(
                'page_url'        => $page_url,
                'license_key'     => $license_key,
                'site_url'        => get_site_url(),
                'organization_id' => $org_id,
                'force_refresh'   => $force_refresh,
            ) ),
            'timeout' => 60,
        ) );
        if ( ! is_wp_error( $retry ) ) {
            $retry_code = wp_remote_retrieve_response_code( $retry );
            $retry_data = json_decode( wp_remote_retrieve_body( $retry ), true );
            if ( $retry_code === 200 && ! empty( $retry_data['success'] ) && ! empty( $retry_data['screenshot_url'] ) ) {
                ciq_log( 'CIQ screenshot [retry OK]: ' . $retry_data['screenshot_url'] );
                return $retry_data['screenshot_url'];
            }
            ciq_log( 'CIQ screenshot retry also failed (HTTP ' . $retry_code . '): ' . wp_json_encode( $retry_data ) );
        } else {
            ciq_log( 'CIQ screenshot retry error: ' . $retry->get_error_message() );
        }
    }

    ciq_log( 'CIQ screenshot unavailable (HTTP ' . $code . '): ' . wp_json_encode( $data ) );
    return null;
}

/**
/**
 * Pre-fetch screenshots for all pages that already have heatmap click data.
 * Called automatically on license activation and refresh.
 * Uses force_refresh=false so it only requests a new screenshot when one
 * does not already exist on the server (cache-first).
 * Fires via wp_schedule_single_event so the HTTP response is not delayed.
 */
function conversioniq_heatmap_prefetch_screenshots() {
    $api_key = get_option( 'conversioniq_api_key', '' );
    $org_id  = get_option( 'conversioniq_organization_id', '' );

    if ( ! $api_key || ! $org_id ) {
        return; // License not fully activated yet — nothing to do.
    }

    // Schedule the actual work to run after the current request completes
    // so the license activate/refresh response is returned immediately.
    if ( ! wp_next_scheduled( 'conversioniq_heatmap_prefetch_event' ) ) {
        wp_schedule_single_event( time() + 5, 'conversioniq_heatmap_prefetch_event' );
    }
}
add_action( 'conversioniq_heatmap_prefetch_event', 'conversioniq_heatmap_do_prefetch' );

function conversioniq_heatmap_do_prefetch() {
    global $wpdb;

    $license_key = get_option( 'conversioniq_license_key', '' );
    $api_key     = get_option( 'conversioniq_api_key', '' );
    $org_id      = get_option( 'conversioniq_organization_id', '' );

    if ( ! $license_key || ! $api_key ) {
        return;
    }

    $table = $wpdb->prefix . 'conversioniq_heatmap_events';

    // Get all distinct pages that have at least 1 click event in the last 90 days
    $pages = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT page_url FROM {$table}
         WHERE recorded_at >= %s
         ORDER BY MAX(recorded_at) DESC
         LIMIT 20",
        gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
    ) );

    if ( empty( $pages ) ) {
        ciq_log( 'ConversionIQ Heatmap: No tracked pages found for screenshot pre-fetch.' );
        return;
    }

    $remote_url = 'https://conversioniq-app.com/api/heatmap/screenshot';
    $fetched    = 0;
    $skipped    = 0;

    foreach ( $pages as $page_url ) {
        if ( ! filter_var( $page_url, FILTER_VALIDATE_URL ) ) {
            continue;
        }

        $response = wp_remote_post( $remote_url, array(
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body'    => wp_json_encode( array(
                'page_url'        => $page_url,
                'license_key'     => $license_key,
                'site_url'        => get_site_url(),
                'organization_id' => $org_id,
                'force_refresh'   => false,
            ) ),
            'timeout' => 60,
        ) );

        if ( is_wp_error( $response ) ) {
            ciq_log( 'ConversionIQ Heatmap prefetch error for ' . $page_url . ': ' . $response->get_error_message() );
            continue;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 200 && ! empty( $data['success'] ) ) {
            $source = ! empty( $data['from_cache'] ) ? 'cached' : 'new';
            ciq_log( "ConversionIQ Heatmap prefetch [{$source}]: {$page_url}" );
            $fetched++;
        } else {
            ciq_log( "ConversionIQ Heatmap prefetch skipped ({$code}): {$page_url}" );
            $skipped++;
        }
    }

    ciq_log( "ConversionIQ Heatmap prefetch complete — {$fetched} fetched, {$skipped} skipped." );
}

/**
 * POST /heatmap/screenshot
 * Admin only — proxies screenshot request to conversioniq-app.com.
 * Uses the stored api_key and organization_id.
 */
function conversioniq_heatmap_screenshot( WP_REST_Request $request ) {    $body          = $request->get_json_params();
    $page_url      = isset( $body['page_url'] ) ? esc_url_raw( $body['page_url'] ) : '';
    $force_refresh = ! empty( $body['force_refresh'] );

    if ( ! $page_url || ! preg_match( '/^https?:\/\//i', $page_url ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Invalid or missing page_url' ), 400 );
    }

    $api_key     = get_option( 'conversioniq_api_key', '' );
    $org_id      = get_option( 'conversioniq_organization_id', '' );
    $license_key = get_option( 'conversioniq_license_key', '' );

    if ( ! $license_key ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'License not activated. Please activate your license to use heatmaps.',
        ), 403 );
    }

    $remote_url = 'https://conversioniq-app.com/api/heatmap/screenshot';
    $payload    = array(
        'page_url'        => $page_url,
        'license_key'     => $license_key,
        'site_url'        => get_site_url(),
        'organization_id' => $org_id,
        'force_refresh'   => $force_refresh,
    );

    $response = wp_remote_post( $remote_url, array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body'    => wp_json_encode( $payload ),
        'timeout' => 60,
    ) );

    if ( is_wp_error( $response ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Could not reach screenshot service: ' . $response->get_error_message(),
        ), 500 );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 422 ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Bot challenge detected on the target page — screenshot unavailable.',
        ), 422 );
    }

    if ( $code !== 200 || empty( $data['success'] ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => $data['message'] ?? 'Screenshot capture failed.',
        ), $code >= 400 ? $code : 500 );
    }

    return new WP_REST_Response( array(
        'success'        => true,
        'screenshot_url' => $data['screenshot_url'],
        'page_width'     => $data['page_width'] ?? 1440,
        'page_height'    => $data['page_height'] ?? null,
        'captured_at'    => $data['captured_at'] ?? null,
        'from_cache'     => $data['from_cache'] ?? false,
    ), 200 );
}

/**
 * Heatmap daily summary sync
 *
 * Runs once per day via WP-Cron. Aggregates yesterday's heatmap events per page
 * into a single summary row and pushes it to the conversioniq-app.com platform
 * so cross-site analytics are available without storing raw coordinates remotely.
 *
 * Summary row shape (mirrors the Supabase heatmap_summaries table):
 *   organization_id, site_url, page_url, date,
 *   total_clicks, total_sessions,
 *   scroll_25, scroll_50, scroll_75, scroll_90, scroll_100
 */
// ── Heatmap: manual sync trigger (admin debug) ──────────────────────────────

/**
 * Get (or auto-generate on first use) the external-cron secret key.
 */
function conversioniq_get_sync_secret() {
    $key = get_option( 'conversioniq_sync_secret_key', '' );
    if ( empty( $key ) ) {
        $key = wp_generate_password( 32, false ); // 32 alphanumeric chars, no special chars
        update_option( 'conversioniq_sync_secret_key', $key, false ); // autoload=false
    }
    return $key;
}

/**
 * External cron endpoint: GET /wp-json/conversioniq/v1/sync-daily?secret=KEY
 *
 * Syncs yesterday's heatmap + enrichment data to Supabase.
 * No WP session required — authenticated via the pre-shared secret key.
 */
function conversioniq_external_sync_daily( WP_REST_Request $request ) {
    $provided = $request->get_param( 'secret' );
    $expected = get_option( 'conversioniq_sync_secret_key', '' );

    if ( empty( $expected ) || ! hash_equals( $expected, (string) $provided ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Invalid or missing secret key.',
        ), 403 );
    }

    $backfill = (bool) $request->get_param( 'backfill' );

    if ( $backfill ) {
        // Full 30-day backfill — used on first registration by the SaaS backend.
        ciq_log( '🕐 External cron: 30-day backfill triggered' );
        $response = conversioniq_heatmap_trigger_sync();
        $data     = $response->get_data();
        return new WP_REST_Response( array(
            'success'   => $data['success'] ?? true,
            'message'   => 'Backfill complete.',
            'synced_at' => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
        ), 200 );
    }

    $yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
    ciq_log( '🕐 External cron: running daily sync for ' . $yesterday );
    conversioniq_heatmap_sync_daily( $yesterday );

    return new WP_REST_Response( array(
        'success'   => true,
        'message'   => 'Sync complete.',
        'date'      => $yesterday,
        'synced_at' => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
    ), 200 );
}

function conversioniq_heatmap_trigger_sync( WP_REST_Request $request = null ) {
    global $wpdb;

    // If a single date is passed (e.g. 'yesterday' or 'YYYY-MM-DD'), run just that day.
    $single_date = null;
    if ( $request ) {
        $raw = $request->get_param( 'date' );
        if ( $raw === 'yesterday' ) {
            $single_date = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
        } elseif ( $raw && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
            $single_date = $raw;
        }
    }

    if ( $single_date ) {
        ciq_log( '🔧 Heatmap trigger-sync: single-day sync triggered by admin for ' . $single_date . '.' );
    } else {
        ciq_log( '🔧 Heatmap trigger-sync: 30-day backfill triggered by admin.' );
    }

    $api_key = get_option( 'conversioniq_api_key', '' );
    $org_id  = get_option( 'conversioniq_organization_id', '' );

    $cron_ts     = wp_next_scheduled( 'conversioniq_heatmap_sync' );
    $diagnostics = array(
        'api_key_set'    => ! empty( $api_key ),
        'org_id_set'     => ! empty( $org_id ),
        'cron_scheduled' => (bool) $cron_ts,
        'cron_next_utc'  => $cron_ts
                                ? gmdate( 'Y-m-d H:i:s', $cron_ts ) . ' UTC'
                                : 'not scheduled (admin_init fallback is active)',
        'last_sync_date' => get_option( 'conversioniq_heatmap_last_sync_date', 'never' ),
        'today_utc'      => gmdate( 'Y-m-d' ),
    );

    $sessions_table = $wpdb->prefix . 'conversioniq_heatmap_sessions';
    $mysql_sessions_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sessions_table}" );

    if ( ! $api_key || ! $org_id ) {
        ciq_log( '🔧 Heatmap trigger-sync: aborting — missing api_key or org_id.' );
        return new WP_REST_Response( array(
            'success'              => false,
            'message'              => 'No API key or organization ID — activate your license first.',
            'mysql_sessions_total' => $mysql_sessions_total,
            'diagnostics'          => $diagnostics,
        ), 400 );
    }

    $table        = $wpdb->prefix . 'conversioniq_heatmap_events';
    $synced_dates  = array();
    $skipped_dates = array();

    // Build the list of dates to process: single day or last 30 days.
    $dates_to_check = array();
    if ( $single_date ) {
        $dates_to_check[] = $single_date;
    } else {
        for ( $i = 1; $i <= 30; $i++ ) {
            $dates_to_check[] = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
        }
    }

    foreach ( $dates_to_check as $date ) {
        $day_start = $date . ' 00:00:00';
        $day_end   = $date . ' 23:59:59';

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE recorded_at BETWEEN %s AND %s
               AND page_url LIKE 'http%%'
               AND page_url NOT LIKE '%%/wp-admin%%'
               AND page_url NOT LIKE '%%/wp-json/%%'",
            $day_start, $day_end
        ) );

        if ( $count > 0 ) {
            ciq_log( '🔧 Heatmap trigger-sync: syncing ' . $date . ' (' . $count . ' events)' );
            conversioniq_heatmap_sync_daily( $date );
            $synced_dates[] = $date . ' (' . $count . ' events)';
        } else {
            ciq_log( '🔧 Heatmap trigger-sync: no events for ' . $date . ' — skipping.' );
            $skipped_dates[] = $date;
        }
    }

    $synced_count = count( $synced_dates );
    ciq_log( '🔧 Heatmap trigger-sync: ' . ( $single_date ? 'single-day sync' : 'backfill' ) . ' complete — ' . $synced_count . ' day(s) synced.' );

    // Enrichment sync: sessions, form analytics, above-fold.
    $label = $single_date ? 'single day ' . $single_date : 'today + last 30 days';
    ciq_log( '🔧 Heatmap trigger-sync: running enrichment sync for ' . $label . '.' );
    $supabase_enrichment = new ConversionIQ_Supabase_Sync();
    $enrichment_dates = array();

    $enrichment_dates_to_check = $single_date
        ? array( $single_date )
        : array_merge( array( gmdate( 'Y-m-d' ) ), $dates_to_check ); // today + last 30

    foreach ( $enrichment_dates_to_check as $edate ) {
        $eday_start = $edate . ' 00:00:00';
        $eday_end   = $edate . ' 23:59:59';

        $sessions_table = $wpdb->prefix . 'conversioniq_heatmap_sessions';
        $ecount = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$sessions_table} WHERE recorded_at BETWEEN %s AND %s",
            $eday_start, $eday_end
        ) );

        if ( $ecount > 0 ) {
            $supabase_enrichment->sync_enrichment_data( $edate );
            $enrichment_dates[] = $edate . ' (' . $ecount . ' sessions)';
        }
    }
    ciq_log( '🔧 Enrichment sync complete — ' . count( $enrichment_dates ) . ' day(s) synced.' );

    $diagnostics['synced_dates']      = $synced_dates;
    $diagnostics['skipped_dates']     = $skipped_dates;
    $diagnostics['enrichment_dates']  = $enrichment_dates;

    $mode_label = $single_date ? "single-day sync for {$single_date}" : '30-day backfill';
    return new WP_REST_Response( array(
        'success'              => true,
        'message'              => ucfirst( $mode_label ) . " complete: {$synced_count} heatmap day(s) + " . count( $enrichment_dates ) . ' enrichment day(s) synced to Supabase. Check debug logs for details.',
        'mysql_sessions_total' => $mysql_sessions_total,
        'diagnostics'          => $diagnostics,
    ), 200 );
}

// ── Heatmap: nightly summary sync to Supabase ───────────────────────────────

function conversioniq_heatmap_sync_daily( $date = null ) {
    global $wpdb;

    $api_key  = get_option( 'conversioniq_api_key', '' );
    $org_id   = get_option( 'conversioniq_organization_id', '' );
    $site_url = get_site_url();

    // For the normal daily auto-run (no specific date), mark today so the
    // admin_init guard doesn't re-fire later the same UTC day.
    if ( $date === null ) {
        update_option( 'conversioniq_heatmap_last_sync_date', gmdate( 'Y-m-d' ) );
    }

    // Nothing to sync without a license
    if ( ! $api_key || ! $org_id ) {
        ciq_log( '🔄 Heatmap sync_daily: aborting — no api_key or org_id.' );
        return;
    }

    $table     = $wpdb->prefix . 'conversioniq_heatmap_events';
    $yesterday = $date ?? gmdate( 'Y-m-d', strtotime( '-1 day' ) );
    $day_start = $yesterday . ' 00:00:00';
    $day_end   = $yesterday . ' 23:59:59';

    ciq_log( '🔄 Heatmap sync_daily: syncing date=' . $yesterday );

    // All distinct pages that had any event on this date — excluding internal/builder URLs
    // and any rows with corrupted/non-URL page_url values (e.g. bare integers).
    // NOTE: literal % signs inside $wpdb->prepare() MUST be doubled (%%) per WP docs,
    // otherwise WordPress misinterprets %f, %e etc. as printf format specifiers.
    $pages = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT page_url FROM {$table}
         WHERE recorded_at BETWEEN %s AND %s
           AND page_url LIKE 'http%%'
           AND page_url NOT LIKE '%%/wp-admin%%'
           AND page_url NOT LIKE '%%/wp-login.php%%'
           AND page_url NOT LIKE '%%/wp-json/%%'
           AND page_url NOT LIKE '%%elementor-preview=%%'
           AND page_url NOT LIKE '%%preview_id=%%'
           AND page_url NOT LIKE '%%fl_builder=%%'
           AND page_url NOT LIKE '%%et_pb_preview=%%'
           AND page_url NOT LIKE '%%preview=true%%'
         LIMIT 500",
        $day_start,
        $day_end
    ) );

    if ( empty( $pages ) ) {
        ciq_log( '🔄 Heatmap sync_daily: no valid events for ' . $yesterday . ' — skipping heatmap summary; still running enrichment sync.' );
        // Still sync enrichment data (sessions, form analytics, above-fold) even when
        // there are no click/scroll events for this date.
        $supabase_enrichment = new ConversionIQ_Supabase_Sync();
        $supabase_enrichment->sync_enrichment_data( $yesterday );
        return;
    }

    ciq_log( '🔄 Heatmap sync_daily: found ' . count( $pages ) . ' distinct page(s) to sync: ' . implode( ', ', $pages ) );

    $summaries = array();

    foreach ( $pages as $page_url ) {

        // Total clicks (click events only)
        $click_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS clicks
             FROM {$table}
             WHERE page_url = %s AND event_type = 'click'
               AND recorded_at BETWEEN %s AND %s",
            $page_url, $day_start, $day_end
        ), ARRAY_A );

        // Total unique sessions across ALL event types (clicks + scrolls).
        // Using click-only sessions was causing pages with scroll-only visitors
        // (e.g. bounced paid-traffic sessions) to report total_sessions = 0.
        $total_sessions_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id) FROM {$table}
             WHERE page_url = %s AND session_id IS NOT NULL
               AND recorded_at BETWEEN %s AND %s",
            $page_url, $day_start, $day_end
        ) );

        // Scroll milestone counts
        // Count distinct sessions that fired a scroll event at each milestone.
        // The tracker stores element_text = '25%', '50%', '75%', '90%', '100%'.
        // Use LIKE 'XX%' to be safe against any whitespace/encoding variation,
        // but anchor to a digit boundary so '100%' doesn\'t match '10%'.
        $scroll_counts = array();
        foreach ( array( 25, 50, 75, 90, 100 ) as $m ) {
            $scroll_counts[ $m ] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(DISTINCT session_id) FROM {$table}
                 WHERE page_url = %s AND event_type = 'scroll'
                   AND element_text = %s
                   AND recorded_at BETWEEN %s AND %s",
                $page_url,
                (string) $m . '%',
                $day_start,
                $day_end
            ) );
        }

        // Top clicked elements — tag + text + count, stored as JSONB on the remote side
        $top_element_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT element_tag, element_text, COUNT(*) AS clicks
             FROM {$table}
             WHERE page_url = %s AND event_type = 'click'
               AND recorded_at BETWEEN %s AND %s
               AND element_tag IS NOT NULL
             GROUP BY element_tag, element_text
             ORDER BY clicks DESC
             LIMIT 10",
            $page_url, $day_start, $day_end
        ), ARRAY_A );

        $top_elements = array_map( function( $row ) {
            return array(
                'tag'    => $row['element_tag'],
                'text'   => $row['element_text'],
                'clicks' => (int) $row['clicks'],
            );
        }, $top_element_rows ?: array() );

        // Bounce sessions: sessions that only visited this one page during the day
        $hm_sessions_table = $wpdb->prefix . 'conversioniq_heatmap_sessions';
        $bounce_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT s.session_id
                FROM {$hm_sessions_table} AS s
                WHERE s.page_url = %s
                  AND s.recorded_at BETWEEN %s AND %s
                  AND (
                      SELECT COUNT(DISTINCT e.page_url)
                      FROM {$table} AS e
                      WHERE e.session_id = s.session_id
                        AND e.recorded_at BETWEEN %s AND %s
                  ) = 1
            ) AS bounced",
            $page_url, $day_start, $day_end, $day_start, $day_end
        ) );

        // Average time on page (seconds) — only sessions with a recorded value
        $avg_time_raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(time_on_page_sec)
             FROM {$hm_sessions_table}
             WHERE page_url = %s
               AND recorded_at BETWEEN %s AND %s
               AND time_on_page_sec IS NOT NULL
               AND time_on_page_sec > 0",
            $page_url, $day_start, $day_end
        ) );
        $avg_time_sec = $avg_time_raw !== null ? (int) round( (float) $avg_time_raw ) : null;

        // Traffic source breakdown (top 10 by session count)
        $traffic_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT COALESCE(NULLIF(utm_source,''), 'direct') AS source,
                    COUNT(*) AS sessions
             FROM {$hm_sessions_table}
             WHERE page_url = %s
               AND recorded_at BETWEEN %s AND %s
             GROUP BY source
             ORDER BY sessions DESC
             LIMIT 10",
            $page_url, $day_start, $day_end
        ), ARRAY_A );
        $traffic_sources = array_map( function( $r ) {
            return array( 'source' => $r['source'], 'sessions' => (int) $r['sessions'] );
        }, $traffic_rows ?: array() );

        $bounce_rate = $total_sessions_count > 0 ? round( $bounce_count / $total_sessions_count, 4 ) : null;

        $total_clicks = (int) ( $click_row['clicks'] ?? 0 );

        ciq_log( sprintf(
            '🔄   [%s] clicks=%d sessions=%d scroll=(%d/%d/%d/%d/%d) bounce=%d avg_time=%s',
            $page_url,
            $total_clicks,
            $total_sessions_count,
            $scroll_counts[25], $scroll_counts[50], $scroll_counts[75], $scroll_counts[90], $scroll_counts[100],
            $bounce_count,
            $avg_time_sec !== null ? $avg_time_sec . 's' : 'n/a'
        ) );

        $summaries[] = array(
            'organization_id'      => $org_id,
            'site_url'             => $site_url,
            'page_url'             => $page_url,
            'date'                 => $yesterday,
            'total_clicks'         => $total_clicks,
            'total_sessions'       => $total_sessions_count,
            'scroll_25'            => $scroll_counts[25],
            'scroll_50'            => $scroll_counts[50],
            'scroll_75'            => $scroll_counts[75],
            'scroll_90'            => $scroll_counts[90],
            'scroll_100'           => $scroll_counts[100],
            'top_elements'         => $top_elements,
            'avg_time_on_page_sec' => $avg_time_sec,
            'bounce_sessions'      => $bounce_count,
            'bounce_rate'          => $bounce_rate,
            'traffic_sources'      => $traffic_sources,
        );
    }

    if ( empty( $summaries ) ) {
        ciq_log( '🔄 Heatmap sync_daily: no summaries built for ' . $yesterday . ' — nothing to POST.' );
        return;
    }

    $payload = array(
        'organization_id' => $org_id,
        'site_url'        => $site_url,
        'date'            => $yesterday,
        'summaries'       => $summaries,
    );
    $payload_json = wp_json_encode( $payload );

    ciq_log( '🔄 Heatmap sync_daily: built ' . count( $summaries ) . ' summary record(s) for ' . $yesterday . '. Payload size=' . strlen( $payload_json ) . ' bytes. POSTing to SaaS…' );

    $response = wp_remote_post( 'https://conversioniq-app.com/api/heatmap/sync-summary', array(
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body'    => $payload_json,
        'timeout' => 30,
    ) );

    if ( is_wp_error( $response ) ) {
        ciq_log( '🔄 ❌ Heatmap sync_daily: WP_Error — ' . $response->get_error_message() );
        return;
    }

    $code      = wp_remote_retrieve_response_code( $response );
    $resp_body = wp_remote_retrieve_body( $response );
    if ( $code === 200 ) {
        ciq_log( '🔄 ✅ Heatmap sync_daily: HTTP 200 — pushed ' . count( $summaries ) . ' page summary(s) for ' . $yesterday . '. Response: ' . $resp_body );
    } else {
        ciq_log( '🔄 ❌ Heatmap sync_daily: HTTP ' . $code . ' — ' . $resp_body );
    }

    // Sync enrichment data (device sessions, form analytics, above-fold) to Supabase
    $supabase = new ConversionIQ_Supabase_Sync();
    $supabase->sync_enrichment_data( $yesterday );
}

// ── SEO Audit endpoint ────────────────────────────────────────────────────

/**
 * GET /conversioniq/v1/seo-audit?page_id=<id>
 *
 * Runs the deterministic on-page SEO analyzer (Tier 1) and appends Real
 * User Metrics Core Web Vitals where available (Tier 2).
 */
function conversioniq_run_seo_audit( WP_REST_Request $request ) {
    $page_id = (int) $request->get_param( 'page_id' );

    // Validate: must be a published page or post
    $post = get_post( $page_id );
    if ( ! $post || $post->post_status !== 'publish' || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
        ciq_log( 'SEO REST: 404 for page_id=' . $page_id );
        return new WP_REST_Response(
            array( 'success' => false, 'message' => 'Page not found or not published.' ),
            404
        );
    }

    // Rate-limit: one SEO audit per page per 60 seconds per user
    $user_id      = get_current_user_id();
    $throttle_key = 'ciq_seo_lock_' . $user_id . '_' . $page_id;
    if ( get_transient( $throttle_key ) ) {
        ciq_log( 'SEO REST: rate-limited user=' . $user_id . ' page_id=' . $page_id );
        return new WP_REST_Response(
            array( 'success' => false, 'message' => 'Please wait before re-running the SEO audit for this page.' ),
            429
        );
    }
    set_transient( $throttle_key, 1, 60 );

    ciq_log( 'SEO REST: triggered by user=' . $user_id . ' page_id=' . $page_id . ' ("' . $post->post_title . '")' );

    $result = ConversionIQ_SEO_Analyzer::analyze( $page_id );

    if ( is_wp_error( $result ) ) {
        ciq_log( 'SEO REST: analyzer error — ' . $result->get_error_message() );
        return new WP_REST_Response(
            array( 'success' => false, 'message' => $result->get_error_message() ),
            400
        );
    }

    // Sync to Supabase so the SaaS dashboard can display SEO results
    $supabase = new ConversionIQ_Supabase_Sync();
    $synced   = $supabase->send_seo_audit( $result );

    // Cache locally so the admin tab can reload instantly without re-running the analysis
    set_transient( 'ciq_seo_last_' . $page_id, $result, 7 * DAY_IN_SECONDS );

    ciq_log( 'SEO REST: complete — score=' . $result['overall_score'] . ' supabase=' . ( $synced ? 'ok' : 'failed' ) );

    return new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
}

/**
 * GET /conversioniq/v1/seo-last?page_id=<id>
 *
 * Returns the locally-cached result of the most recent SEO audit for this page.
 * Returns { success: true, data: null } if no audit has been run yet.
 * Never triggers a new analysis.
 */
function conversioniq_get_last_seo_audit( WP_REST_Request $request ) {
    $page_id = (int) $request->get_param( 'page_id' );
    $cached  = get_transient( 'ciq_seo_last_' . $page_id );

    if ( $cached && is_array( $cached ) ) {
        return new WP_REST_Response( array( 'success' => true, 'data' => $cached ), 200 );
    }

    return new WP_REST_Response( array( 'success' => true, 'data' => null ), 200 );
}

// ── Implementation Apply & Publish ───────────────────────────────────────────

/**
 * GET /pending-reviews
 *
 * Returns the org's pending implementation-review batches so the WP admin panel
 * can render a "Changes Pending" banner that deep-links to the SaaS.
 *
 * Response:
 *   {
 *     count             : int,      // number of pending review batches
 *     total_changes     : int,      // total change objects across all pending batches
 *     first_page_title  : string,   // page title of the most recent pending batch (for the banner)
 *     latest_review_id  : string,   // UUID of the most recent pending batch (for deep-link)
 *     organization_id   : string,   // org UUID (for deep-link)
 *     reviews           : array     // full list (up to 5) — [{id,page_url,page_title,changes_count,created_at}]
 *   }
 *
 * Cached for 60s per user via transient to keep page loads snappy and avoid
 * hammering Supabase — the SaaS side generates review batches asynchronously
 * so a stale cache is fine.
 */
function conversioniq_pending_reviews( WP_REST_Request $request ) {
    $user_id       = get_current_user_id();
    $transient_key = 'ciq_pending_reviews_' . intval( $user_id );

    // Allow a caller to bust the cache after an audit completes (?refresh=1).
    if ( intval( $request->get_param( 'refresh' ) ) !== 1 ) {
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            return new WP_REST_Response( $cached, 200 );
        }
    }

    if ( ! class_exists( 'ConversionIQ_Supabase_Sync' ) ) {
        $empty = array(
            'count'            => 0,
            'total_changes'    => 0,
            'first_page_title' => '',
            'latest_review_id' => '',
            'organization_id'  => '',
            'reviews'          => array(),
        );
        return new WP_REST_Response( $empty, 200 );
    }

    $supabase = new ConversionIQ_Supabase_Sync();
    $reviews  = $supabase->fetch_pending_implementation_reviews( 5 );

    $total_changes = 0;
    foreach ( $reviews as $r ) {
        $total_changes += intval( $r['changes_count'] );
    }

    $first_title = '';
    $latest_id   = '';
    if ( ! empty( $reviews[0] ) ) {
        $first_title = ! empty( $reviews[0]['page_title'] ) ? $reviews[0]['page_title'] : $reviews[0]['page_url'];
        $latest_id   = $reviews[0]['id'];
    }

    $payload = array(
        'count'            => count( $reviews ),
        'total_changes'    => $total_changes,
        'first_page_title' => $first_title,
        'latest_review_id' => $latest_id,
        'organization_id'  => $supabase->get_organization_id(),
        'reviews'          => $reviews,
    );

    set_transient( $transient_key, $payload, 60 );

    return new WP_REST_Response( $payload, 200 );
}

/**
 * POST /apply-changes
 *
 * Applies a set of approved implementation changes to a WordPress post.
 * Authenticated by X-CIQ-API-Key header (same secret as /remote-audit).
 * Called by the SaaS dashboard when the user approves changes.
 *
 * Required body:
 *   review_id   : string  — UUID of the implementation_reviews row
 *   audit_token : string  — report_token of the source audit
 *   post_id     : int     — WP post ID to apply changes to
 *   pre_score   : int     — overall_score from the source audit (for sprint tracking)
 *   changes     : array   — approved change objects (decision = "approved")
 *
 * Returns:
 *   { success, draft_url, plugin_version, results: [{change_id, status, error_code, error_message}] }
 */
function conversioniq_apply_changes( WP_REST_Request $request ) {
    // ── Auth ──────────────────────────────────────────────────────────────
    $provided_key = $request->get_header( 'X-CIQ-API-Key' );
    $stored_key   = get_option( 'conversioniq_remote_secret', '' );

    if ( empty( $provided_key ) || empty( $stored_key ) || ! hash_equals( $stored_key, $provided_key ) ) {
        ciq_log( 'apply-changes: 401 — invalid or missing X-CIQ-API-Key' );
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Unauthorized' ), 401 );
    }

    // ── Rate limit: one apply per post per 60 seconds ─────────────────────
    $body    = $request->get_json_params();
    $post_id = absint( $body['post_id'] ?? 0 );
    if ( $post_id <= 0 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'post_id is required and must be a positive integer.' ), 400 );
    }

    $rate_key = 'ciq_apply_lock_' . $post_id;
    if ( get_transient( $rate_key ) ) {
        ciq_log( 'apply-changes: 429 — rate limited for post_id=' . $post_id );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'An apply operation for this page is already in progress. Try again in 60 seconds.',
        ), 429 );
    }
    set_transient( $rate_key, 1, 60 );

    // ── Validate post ─────────────────────────────────────────────────────
    $post = get_post( $post_id );
    if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'draft', 'private' ), true ) ) {
        ciq_log( 'apply-changes: post_not_found — post_id=' . $post_id );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Post ID ' . $post_id . ' not found or is not a published/draft post.',
        ), 404 );
    }

    $changes   = $body['changes']   ?? array();
    $pre_score = intval( $body['pre_score'] ?? 0 );
    $page_url  = get_permalink( $post );

    if ( empty( $changes ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'No changes provided.' ), 400 );
    }

    ciq_log( 'apply-changes: post_id=' . $post_id . ' changes=' . count( $changes ) . ' pre_score=' . $pre_score );

    // ── Apply changes ─────────────────────────────────────────────────────
    $applier = new ConversionIQ_Implementation_Applier();
    $result  = $applier->apply_all( $changes, $post );

    // ── Create sprint row for impact measurement ──────────────────────────
    if ( $result['success'] && $pre_score > 0 ) {
        try {
            $applied_titles = array();
            foreach ( $changes as $change ) {
                if ( ! empty( $change['title'] ) ) {
                    $applied_titles[] = sanitize_text_field( $change['title'] );
                }
            }
            $supabase = new ConversionIQ_Supabase_Sync();
            $sprint_id = $supabase->create_sprint_for_implementation( $page_url, $pre_score, $applied_titles );
            if ( $sprint_id ) {
                ciq_log( 'apply-changes: sprint created — sprint_id=' . $sprint_id );
            }
        } catch ( Exception $e ) {
            ciq_log( 'apply-changes: sprint creation exception — ' . $e->getMessage() );
        }
    }

    // Log each per-change result
    foreach ( $result['results'] as $r ) {
        $status = $r['status'];
        $id     = $r['change_id'];
        $err    = $r['error_message'] ? ' — ' . $r['error_message'] : '';
        if ( $status === 'applied' ) {
            ciq_log( 'apply-changes: ✅ ' . $status . ' change_id=' . $id );
        } else {
            ciq_log( 'apply-changes: ' . ( $status === 'failed' ? '❌' : '⚠️' ) . ' ' . $status . ' change_id=' . $id . $err );
        }
    }

    return new WP_REST_Response( array_merge( $result, array(
        'plugin_version' => defined( 'CONVERSION_IQ_VERSION' ) ? CONVERSION_IQ_VERSION : 'unknown',
    ) ), 200 );
}

/**
 * POST /publish-draft
 *
 * Publishes a WordPress draft created by /apply-changes.
 * Authenticated by X-CIQ-API-Key header.
 *
 * Required body:
 *   draft_id : int  — ID of the WP draft post to publish
 *
 * Returns:
 *   { success, published_url }
 */
function conversioniq_publish_draft( WP_REST_Request $request ) {
    // ── Auth ──────────────────────────────────────────────────────────────
    $provided_key = $request->get_header( 'X-CIQ-API-Key' );
    $stored_key   = get_option( 'conversioniq_remote_secret', '' );

    if ( empty( $provided_key ) || empty( $stored_key ) || ! hash_equals( $stored_key, $provided_key ) ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Unauthorized' ), 401 );
    }

    $body     = $request->get_json_params();
    $draft_id = absint( $body['draft_id'] ?? 0 );

    if ( $draft_id <= 0 ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'draft_id is required.' ), 400 );
    }

    $draft = get_post( $draft_id );
    if ( ! $draft ) {
        return new WP_REST_Response( array( 'success' => false, 'message' => 'Draft ID ' . $draft_id . ' not found.' ), 404 );
    }

    if ( $draft->post_status !== 'draft' ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Post ' . $draft_id . ' is not a draft (status: ' . $draft->post_status . ').',
        ), 400 );
    }

    // Confirm this was created by CIQ (has the source reference meta)
    $source_id = get_post_meta( $draft_id, '_ciq_source_post_id', true );
    if ( ! $source_id ) {
        ciq_log( 'publish-draft: draft_id=' . $draft_id . ' has no _ciq_source_post_id — refusing to publish' );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'This draft was not created by Conversion IQ and cannot be published via this endpoint.',
        ), 403 );
    }

    $updated = wp_update_post( array(
        'ID'          => $draft_id,
        'post_status' => 'publish',
    ), true );

    if ( is_wp_error( $updated ) ) {
        ciq_log( 'publish-draft: wp_update_post failed — ' . $updated->get_error_message() );
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Failed to publish: ' . $updated->get_error_message(),
        ), 500 );
    }

    $published_url = get_permalink( $draft_id );
    ciq_log( 'publish-draft: ✅ published draft_id=' . $draft_id . ' source_id=' . $source_id . ' url=' . $published_url );

    return new WP_REST_Response( array(
        'success'       => true,
        'live_url'      => $published_url,  // dashboard expects live_url
        'published_url' => $published_url,  // kept for backward compat
        'draft_id'      => $draft_id,
        'source_id'     => (int) $source_id,
    ), 200 );
}
