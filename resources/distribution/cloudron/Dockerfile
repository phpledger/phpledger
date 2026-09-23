# Cloudron wrapper around the published PHP Ledger image (docs/CONTAINER.md). Cloudron
# runs one container per app - there is no second `db` service the way
# compose.production.yaml runs one - so this maps Cloudron's mysql addon onto the same
# PL_DB_* contract in start.sh rather than bundling a second MySQL process here.
# See NOTES.md for what that substitution cannot reproduce.
#
# Build and publish this image yourself before referencing it from CloudronVersions.json
# (its "dockerImage" field is a placeholder here - see NOTES.md); this file only prepares
# the wrapper, it does not build or push anything.
FROM ghcr.io/phpledger/phpledger:1.3.0

# COPY --chmod sets the executable bit at copy time: the base image already switches to
# the non-root `www-data` USER (docs/CONTAINER.md, "Non-root and read-only"), which
# cannot chmod a file a later RUN step would otherwise leave root-owned.
COPY --chmod=755 start.sh /app/pkg/start.sh

CMD ["/app/pkg/start.sh"]
