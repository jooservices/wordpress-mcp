#!/bin/sh
set -eu

echo "Waiting for WordPress files..."
for i in $(seq 1 60); do
  if [ -f /var/www/html/wp-config.php ] || [ -f /var/www/html/wp-config-docker.php ]; then
    break
  fi
  sleep 2
done

cd /var/www/html

if ! wp core is-installed 2>/dev/null; then
  echo "Installing WordPress..."
  wp core install \
    --url="http://localhost:8080" \
    --title="WordPress MCP Dev" \
    --admin_user="admin" \
    --admin_password="admin123!" \
    --admin_email="admin@example.com" \
    --skip-email
fi

echo "Installing plugin dependencies..."
if [ ! -d /var/www/html/wp-content/plugins/wordpress-chatgpt/vendor ]; then
  echo "Warning: plugin vendor/ missing. Run 'make install' before 'make up'."
fi

echo "Activating plugin..."
wp plugin activate wordpress-chatgpt || true

echo "Configuring permalinks..."
wp rewrite structure '/%postname%/' --hard
wp rewrite flush --hard

echo "Seeding sample content..."
wp post create --post_title="Hello from WordPress MCP" --post_content="Sample published post about Laravel queues." --post_status=publish --porcelain || true
wp post create --post_title="Draft MCP Test" --post_content="A draft post for testing." --post_status=draft --porcelain || true

echo "Seeding dev connection..."
wp eval-file /init/seed-connection.php

echo "Setup complete."
