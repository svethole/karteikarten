#!/bin/sh
# Holt den neuesten Stand und gleicht danach die eingebauten Projekte ab.
#   cd /var/www/karteikarten && sudo ./aktualisieren.sh
set -e
cd "$(dirname "$0")"
git pull --ff-only
sudo -u www-data php bin/abgleich.php | sed 's/^/  /'
echo "✓ Aktuell: $(git log -1 --format='%h %s')"
