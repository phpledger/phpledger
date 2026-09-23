# Reuse the published production Apache/PHP runtime; no database or app bootstrap is loaded.
ARG NOTICE_BASE_IMAGE=ghcr.io/phpledger/phpledger:1.2.1
FROM ${NOTICE_BASE_IMAGE}
USER root
RUN a2enmod remoteip && mkdir -p /var/lib/phpledger-notices && chown www-data:www-data /var/lib/phpledger-notices && chmod 700 /var/lib/phpledger-notices
COPY www/installation-service /opt/installation-service
COPY tools/installation-notice-summary.php /opt/installation-service/summary.php
COPY docker/installation-notice-apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/installation-notice.ini /usr/local/etc/php/conf.d/zz-installation-notice.ini
COPY docker/installation-notice-maintenance.sh /opt/installation-service/maintenance.sh
RUN chmod -R a+rX /opt/installation-service && chmod 755 /opt/installation-service/maintenance.sh
ENV PL_NOTICE_DIRECTORY=/var/lib/phpledger-notices PL_NOTICE_SERVICE_ROOT=/opt/installation-service
USER www-data
HEALTHCHECK --interval=30s --timeout=3s CMD php -r 'exit(is_dir(getenv("PL_NOTICE_DIRECTORY")) && is_writable(getenv("PL_NOTICE_DIRECTORY")) ? 0 : 1);'
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["apache2-foreground"]
