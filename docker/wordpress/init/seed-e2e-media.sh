#!/bin/sh
set -eu

cd /var/www/html

mkdir -p wp-content/uploads/e2e
cp /init/fixtures/e2e-orphan.png wp-content/uploads/e2e/e2e-orphan.png

wp eval-file /init/seed-e2e-media.php
