FROM php:8.3-cli

WORKDIR /app

COPY public ./public

ENV PORT=10000

EXPOSE 10000

CMD php -S 0.0.0.0:${PORT} -t public public/index.php
