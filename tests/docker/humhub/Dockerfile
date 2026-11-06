# Development image: the official HumHub 1.19 image plus the Legal Tools
# module, so the module can be tested together with first-login legal pages.
#
# Built by tests/docker-compose.yml. The module goes to @app/modules
# (/opt/humhub/protected/modules), which HumHub loads by default and which is
# not hidden by the /data volume.

FROM humhub/humhub:1.19

USER root

# Pinned version, tested with HumHub 1.19.
ARG LEGAL_VERSION=1.8.1

RUN set -eux; \
    file="legal-v${LEGAL_VERSION}.zip"; \
    curl -fsSL "https://marketplace.humhub.com/downloads/free/${file}" -o "/tmp/${file}"; \
    php -r '$z = new ZipArchive(); if ($z->open($argv[1]) !== true || !$z->extractTo($argv[2])) { exit(1); }' \
        "/tmp/${file}" /opt/humhub/protected/modules; \
    rm "/tmp/${file}"; \
    grep -q "\"version\": \"${LEGAL_VERSION}\"" /opt/humhub/protected/modules/legal/module.json
