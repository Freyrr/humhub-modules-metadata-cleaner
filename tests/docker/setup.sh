#!/bin/bash
# One-shot setup of the development HumHub, run inside the humhub container:
#
#   docker compose -f tests/docker-compose.yml exec humhub bash /opt/dev/setup.sh
#
# 1. Installs HumHub if the web installer was not run yet (admin / Admin1234!).
# 2. Sets Italian as the default language.
# 3. Enables Metadata Cleaner and Legal Tools.
# 4. Configures Legal Tools as described in the EISocialHub guide
#    docs/12-testi-legali-e-banner.md, with the page texts read from that
#    guide (EISocialHub repository mounted in /eisocialhub).
#
# Safe to run again: it only re-applies settings and texts.
set -euo pipefail

GUIDE=/eisocialhub/docs/12-testi-legali-e-banner.md
ADMIN_PASSWORD='Admin1234!'

if ! /app/yii settings/list-module admin 2>&1 | grep -q installationId; then
    echo "Installing HumHub"
    /app/yii installer/install-db
    /app/yii installer/write-site-config "HumHub dev" "humhub@example.com"
    /app/yii installer/create-admin-account admin admin@example.com "$ADMIN_PASSWORD"
    /app/yii installer/set-base-url "${HUMHUB_BASE_URL:-http://localhost:8080}"
    echo "Admin account: admin / $ADMIN_PASSWORD"
fi

/app/yii settings/set base defaultLanguage it > /dev/null

/app/yii module/enable metadata-cleaner || true
/app/yii module/enable legal || true

if [ ! -f "$GUIDE" ]; then
    echo "Legal texts not imported: $GUIDE not found." >&2
    echo "Set EISOCIALHUB_DIR to the EISocialHub repository and recreate the container." >&2
    exit 1
fi

# Settings of section 3 of the guide. "Legal update" stays off until a new
# version of the texts is published (section 5).
/app/yii settings/set legal defaultLanguage it > /dev/null
/app/yii settings/set legal enabledPages "imprint,terms,privacy,cookies" > /dev/null
/app/yii settings/set legal externalLinks "icon,modal" > /dev/null
/app/yii settings/set legal showPagesAfterRegistration 1 > /dev/null
/app/yii settings/set legal showAgeCheck 0 > /dev/null
/app/yii settings/set legal exportUserData 0 > /dev/null

echo "Importing the legal texts from $GUIDE"
php /opt/dev/legal/import-pages.php "$GUIDE" it

/app/yii cache/flush-all > /dev/null
echo "Done. Open ${HUMHUB_BASE_URL:-http://localhost:8080}"
