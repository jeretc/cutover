# Cutover live demo: report site + old shop + migrated shop in one container (Node serves, PHP runs the shops).
FROM node:20-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends php-cli php-curl php-mbstring unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY agent ./agent
COPY server ./server
COPY web ./web
COPY samples ./samples
# PayPal PHP Server SDK for the migrated shop (versions pinned by composer.lock)
RUN cd samples/migrated-php-shop-out && composer install --no-dev --no-interaction --no-progress
ENV PORT=10000
EXPOSE 10000
CMD ["node", "server/server.mjs"]
