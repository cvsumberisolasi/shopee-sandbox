FROM php:8.3-cli-alpine

WORKDIR /app

# Copy backend files
COPY backend/ /app/backend/

# Create data directory for SQLite
RUN mkdir -p /var/data && chown -R www-data:www-data /var/data

EXPOSE $PORT

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t /app/backend/public /app/backend/public/router.php"]
