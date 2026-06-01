<?php
/**
 * Catch-all: redirect bare domain / unknown paths to the SaaS dashboard
 */
header( 'Location: https://app.conversioniq.com', true, 301 );
exit;
