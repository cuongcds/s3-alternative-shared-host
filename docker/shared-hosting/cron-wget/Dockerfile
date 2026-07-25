FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        cron wget ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY docker/shared-hosting/cron-wget/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
