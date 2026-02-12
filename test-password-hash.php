<?php
/**
 * Password Hash Generator for Conversion IQ Admin Account
 * 
 * Usage: Run this file in your browser or via PHP CLI to generate password hashes
 * Example: php test-password-hash.php
 */

// Set the password you want to hash
$password = 'password';  // Change this to generate a hash for a different password

// Generate the hash using PHP's password_hash (bcrypt by default)
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\n";
echo "=================================================\n";
echo "CONVERSION IQ - PASSWORD HASH GENERATOR\n";
echo "=================================================\n\n";
echo "Password: " . $password . "\n";
echo "Hash:     " . $hash . "\n\n";
echo "=================================================\n";
echo "VERIFICATION TEST\n";
echo "=================================================\n";

// Test the hash to make sure it works
if (password_verify($password, $hash)) {
    echo "✓ Password verification: SUCCESS\n";
} else {
    echo "✗ Password verification: FAILED\n";
}

echo "\n";
echo "=================================================\n";
echo "TO USE THIS HASH IN SUPABASE\n";
echo "=================================================\n";
echo "Run this SQL query in Supabase SQL Editor:\n\n";
echo "UPDATE organizations \n";
echo "SET password_hash = '" . $hash . "', \n";
echo "    updated_at = NOW() \n";
echo "WHERE username = 'admin';\n\n";
echo "=================================================\n\n";

// Test with the default admin hash
echo "=================================================\n";
echo "TESTING DEFAULT ADMIN HASH\n";
echo "=================================================\n";
$default_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
$default_password = 'password';

if (password_verify($default_password, $default_hash)) {
    echo "✓ Default admin password verification: SUCCESS\n";
    echo "Password 'password' works with the default hash\n";
} else {
    echo "✗ Default admin password verification: FAILED\n";
}
echo "\n";
?>
